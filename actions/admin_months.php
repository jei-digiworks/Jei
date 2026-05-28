<?php
// actions/admin_months.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

require_admin();

$admin_id = $_SESSION['admin_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'toggle_month') {
    $month_year = trim($_POST['month_year'] ?? '');

    if (empty($month_year) || !preg_match('/^\d{4}-\d{2}$/', $month_year)) {
        echo json_encode(['success' => false, 'message' => 'Valid Month Year (YYYY-MM) is required.']);
        exit;
    }

    try {
        // Fetch current status
        $stmt = $pdo->prepare("SELECT is_open FROM bookable_months WHERE month_year = ? LIMIT 1");
        $stmt->execute([$month_year]);
        $current_status = $stmt->fetchColumn();

        if ($current_status === false) {
            // If month doesn't exist yet, insert it
            $new_status = 1;
            $stmt_ins = $pdo->prepare("INSERT INTO bookable_months (month_year, is_open) VALUES (?, 1)");
            $stmt_ins->execute([$month_year]);
            log_audit($pdo, $admin_id, 'open_month', 'bookable_months', null, null, ['month_year' => $month_year, 'is_open' => 1]);
        } else {
            $new_status = $current_status ? 0 : 1;
            $stmt_up = $pdo->prepare("UPDATE bookable_months SET is_open = ? WHERE month_year = ?");
            $stmt_up->execute([$new_status, $month_year]);
            log_audit($pdo, $admin_id, 'toggle_month_status', 'bookable_months', null, ['is_open' => $current_status], ['month_year' => $month_year, 'is_open' => $new_status]);
        }

        echo json_encode([
            'success' => true, 
            'is_open' => $new_status,
            'message' => 'Month booking availability ' . ($new_status ? 'opened' : 'closed') . ' successfully!'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'toggle_sunday_lock') {
    $current_locked = trim($_POST['current_locked'] ?? '1');
    $new_status = ($current_locked === '1') ? '0' : '1';

    try {
        $pdo->beginTransaction();

        // 1. Update system config
        $stmt_up = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = 'sunday_locked'");
        $stmt_up->execute([$new_status]);

        // 2. Perform dynamic slot locking/unlocking updates
        if ($new_status === '1') {
            // Locking: Change all 'available' Sunday slots to 'locked'
            $stmt_lock = $pdo->prepare("
                UPDATE schedules 
                SET status = 'locked' 
                WHERE WEEKDAY(session_date) = 6 AND status = 'available'
            ");
            $stmt_lock->execute();
            log_audit($pdo, $admin_id, 'lock_sunday_slots', 'schedules', null, ['sunday_locked' => '0'], ['sunday_locked' => '1']);
        } else {
            // Unlocking: Change all 'locked' Sunday slots back to 'available'
            // Guard: DO NOT unlock slots marked as game night or already booked/reserved
            $stmt_unlock = $pdo->prepare("
                UPDATE schedules 
                SET status = 'available' 
                WHERE WEEKDAY(session_date) = 6 
                  AND status = 'locked' 
                  AND is_game_night = 0
            ");
            $stmt_unlock->execute();
            log_audit($pdo, $admin_id, 'unlock_sunday_slots', 'schedules', null, ['sunday_locked' => '1'], ['sunday_locked' => '0']);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Sundays have been ' . ($new_status === '1' ? 'LOCKED' : 'UNLOCKED') . ' successfully!'
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'generate_monthly_slots') {
    $month_year = trim($_POST['month_year'] ?? '');

    if (empty($month_year) || !preg_match('/^\d{4}-\d{2}$/', $month_year)) {
        echo json_encode(['success' => false, 'message' => 'Valid Month Year (YYYY-MM) is required.']);
        exit;
    }

    try {
        // Find all Mondays in this month
        $year = (int)substr($month_year, 0, 4);
        $month = (int)substr($month_year, 5, 2);

        $mondays = [];
        $start_date = new DateTime("$year-$month-01");
        $end_date = new DateTime("$year-$month-01");
        $end_date->modify('last day of this month');

        // Loop through all days of the month to find Mondays
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

        foreach ($period as $date) {
            if ($date->format('N') === '1') { // 1 = Monday
                $mondays[] = $date->format('Y-m-d');
            }
        }

        // Also check if the previous month's last Monday overflows into this month
        $prev_monday = clone $start_date;
        if ($prev_monday->format('N') !== '1') {
            $prev_monday->modify('last Monday');
            if ($prev_monday->format('m') !== $start_date->format('m')) {
                $mondays[] = $prev_monday->format('Y-m-d');
            }
        }

        $mondays = array_unique($mondays);
        sort($mondays);

        $pdo->beginTransaction();
        foreach ($mondays as $monday) {
            $stmt = $pdo->prepare("CALL sp_generate_weekly_schedule(?, ?)");
            $stmt->execute([$monday, $admin_id]);
        }
        $pdo->commit();

        log_audit($pdo, $admin_id, 'generate_monthly_slots', 'schedules', null, null, ['month_year' => $month_year, 'mondays' => $mondays]);

        echo json_encode([
            'success' => true,
            'message' => 'Schedules generated successfully for all weeks of ' . date('F Y', strtotime("$month_year-01")) . '!'
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'add_month') {
    $month_year = trim($_POST['month_year'] ?? '');
    $is_open    = (int)($_POST['is_open'] ?? 0);

    if (empty($month_year) || !preg_match('/^\d{4}-\d{2}$/', $month_year)) {
        echo json_encode(['success' => false, 'message' => 'Valid Month Year (YYYY-MM) is required.']);
        exit;
    }

    try {
        // Check if already exists
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM bookable_months WHERE month_year = ?");
        $stmt_check->execute([$month_year]);
        if ((int)$stmt_check->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'This month already exists in the system.']);
            exit;
        }

        $stmt_ins = $pdo->prepare("INSERT INTO bookable_months (month_year, is_open) VALUES (?, ?)");
        $stmt_ins->execute([$month_year, $is_open]);

        log_audit($pdo, $admin_id, 'add_month', 'bookable_months', null, null, [
            'month_year' => $month_year,
            'is_open'    => $is_open
        ]);

        echo json_encode([
            'success' => true,
            'message' => date('F Y', strtotime($month_year . '-01')) . ' has been added to the system!'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
exit;
