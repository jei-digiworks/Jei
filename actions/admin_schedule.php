<?php
// actions/admin_schedule.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!is_admin_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
    exit;
}

$admin_id = $_SESSION['admin_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'generate_schedule') {
    $monday_date = $_POST['monday_date'] ?? '';

    if (empty($monday_date) || !strtotime($monday_date)) {
        echo json_encode(['success' => false, 'message' => 'Valid Monday date is required.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("CALL sp_generate_weekly_schedule(?, ?)");
        $stmt->execute([$monday_date, $admin_id]);

        log_audit($pdo, $admin_id, 'generate_weekly_schedule', 'schedules', null, null, ['monday_date' => $monday_date]);

        echo json_encode(['success' => true, 'message' => 'Weekly schedule generated successfully!']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'toggle_lock') {
    $slot_id = (int)($_POST['slot_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($slot_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid slot ID.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM schedules WHERE id = ? LIMIT 1");
        $stmt->execute([$slot_id]);
        $slot = $stmt->fetch();

        if (!$slot) {
            echo json_encode(['success' => false, 'message' => 'Selected slot does not exist.']);
            exit;
        }

        if (!in_array($slot['status'], ['available', 'locked'])) {
            echo json_encode(['success' => false, 'message' => 'Cannot toggle slot in current state (' . $slot['status'] . ').']);
            exit;
        }

        // Prevent unlocking game night slots (Tue/Thu 6PM+)
        if ($slot['status'] === 'locked' && $slot['is_game_night']) {
            echo json_encode(['success' => false, 'message' => 'Game Night slots are permanently locked and cannot be unlocked.']);
            exit;
        }

        $new_status = ($slot['status'] === 'available') ? 'locked' : 'available';

        $stmt_up = $pdo->prepare("UPDATE schedules SET status = ?, notes = ? WHERE id = ?");
        $stmt_up->execute([$new_status, $notes ? $notes : null, $slot_id]);

        log_audit($pdo, $admin_id, 'toggle_lock_slot', 'schedules', $slot_id, ['status' => $slot['status']], ['status' => $new_status, 'notes' => $notes]);

        echo json_encode(['success' => true, 'new_status' => $new_status, 'message' => 'Slot status updated successfully!']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'manual_booking') {
    $schedule_id = (int)($_POST['schedule_id'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $pax = (int)($_POST['pax'] ?? 1);
    $add_coaching = ($_POST['add_coaching'] === 'true' || $_POST['add_coaching'] === '1' || $_POST['add_coaching'] === 1);

    if ($schedule_id <= 0 || empty($email) || empty($full_name)) {
        echo json_encode(['success' => false, 'message' => 'Schedule ID, Email, and Name are required.']);
        exit;
    }

    try {
        // Retrieve schedule
        $stmt = $pdo->prepare("SELECT * FROM schedules WHERE id = ? LIMIT 1");
        $stmt->execute([$schedule_id]);
        $schedule = $stmt->fetch();

        if (!$schedule) {
            echo json_encode(['success' => false, 'message' => 'Selected slot does not exist.']);
            exit;
        }

        if ($schedule['status'] !== 'available') {
            echo json_encode(['success' => false, 'message' => 'Selected slot is not available.']);
            exit;
        }

        // Block manual bookings on game night time slots (Tue/Thu 7PM+)
        $slot_dow = (int)date('N', strtotime($schedule['session_date'])); // 2=Tue, 4=Thu
        $slot_hour = (int)date('H', strtotime($schedule['start_time']));
        if (($slot_dow === 2 || $slot_dow === 4) && ($slot_hour >= 19 || $slot_hour === 0)) {
            echo json_encode(['success' => false, 'message' => 'This time slot is reserved for Game Night and cannot be used for bookings.']);
            exit;
        }

        // Start transaction
        $pdo->beginTransaction();

        // 1. Resolve or create guest player
        $stmt_user = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt_user->execute([$email]);
        $user = $stmt_user->fetch();

        if ($user) {
            $user_id = $user['id'];
        } else {
            // Register as walk-in guest
            $stmt_ins = $pdo->prepare("
                INSERT INTO users (email, password_hash, full_name, phone, role, email_verified) 
                VALUES (?, NULL, ?, ?, 'guest', 0)
            ");
            $stmt_ins->execute([$email, $full_name, $phone]);
            $user_id = $pdo->lastInsertId();
        }

        // 2. Compute fees
        $fees = calculate_booking_fees($pdo, (float)$schedule['duration_hours'], $pax, $add_coaching);
        $booking_code = generate_booking_code($pdo);

        // 3. Create Booking (confirmed)
        $stmt_book = $pdo->prepare("
            INSERT INTO bookings 
              (booking_code, user_id, schedule_id, pricing_config_id, processed_by_admin_id, pax, duration_hours, coaching_fee, court_fee, total_fee, status, confirmed_at) 
            VALUES 
              (?, ?, ?, (SELECT id FROM pricing_config WHERE is_active = 1 LIMIT 1), ?, ?, ?, ?, ?, ?, 'confirmed', NOW())
        ");
        $stmt_book->execute([
            $booking_code,
            $user_id,
            $schedule_id,
            $admin_id,
            $pax,
            $schedule['duration_hours'],
            $fees['coaching_fee'],
            $fees['court_fee'],
            $fees['total_fee']
        ]);
        $booking_id = $pdo->lastInsertId();

        // 4. Create Payment (paid instantly as cash)
        $stmt_pay = $pdo->prepare("
            INSERT INTO payments 
              (booking_id, method, status, amount, paid_at) 
            VALUES 
              (?, 'pay_later', 'paid', ?, NOW())
        ");
        $stmt_pay->execute([$booking_id, $fees['total_fee']]);

        // 5. Update Schedule status
        $stmt_sched = $pdo->prepare("UPDATE schedules SET status = 'confirmed' WHERE id = ?");
        $stmt_sched->execute([$schedule_id]);

        // 6. Create Session & Attendance checklists
        $stmt_sess = $pdo->prepare("INSERT INTO sessions (booking_id, admin_id, status) VALUES (?, ?, 'scheduled')");
        $stmt_sess->execute([$booking_id, $admin_id]);
        $session_id = $pdo->lastInsertId();

        $stmt_att = $pdo->prepare("
            INSERT INTO attendance (session_id, booking_id, user_id, attendee_name, status) 
            VALUES (?, ?, ?, ?, 'absent')
        ");
        // Principal
        $stmt_att->execute([$session_id, $booking_id, $user_id, $full_name]);
        // Companions
        for ($i = 1; $i < $pax; $i++) {
            $stmt_att->execute([$session_id, $booking_id, null, $full_name . ' +' . $i]);
        }

        $pdo->commit();

        log_audit($pdo, $admin_id, 'manual_walkin_booking', 'bookings', $booking_id);

        create_notification($pdo, $user_id, null, $booking_id, 'booking_confirmed', "Your manual walk-in booking " . $booking_code . " has been logged by the facility administration.");

        echo json_encode([
            'success' => true,
            'booking_code' => $booking_code,
            'message' => 'Walk-in guest booking placed successfully!'
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action request.']);
