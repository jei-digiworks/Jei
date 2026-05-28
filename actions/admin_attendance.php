<?php
// actions/admin_attendance.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!is_admin_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
    exit;
}

$admin_id = $_SESSION['admin_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'get_session_athletes') {
    $schedule_id = (int)($_GET['schedule_id'] ?? 0);

    if ($schedule_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Valid schedule ID is required.']);
        exit;
    }

    try {
        // Fetch session and attendance rows
        $stmt = $pdo->prepare("
            SELECT a.id, a.attendee_name, a.status, a.marked_at, u.role
              FROM attendance a
              JOIN sessions s ON s.id = a.session_id
              JOIN bookings b ON b.id = s.booking_id
              LEFT JOIN users u ON u.id = a.user_id
             WHERE b.schedule_id = ?
        ");
        $stmt->execute([$schedule_id]);
        $athletes = $stmt->fetchAll();

        $formatted = [];
        foreach ($athletes as $athlete) {
            $formatted[] = [
                'id' => $athlete['id'],
                'attendee_name' => $athlete['attendee_name'],
                'status' => $athlete['status'],
                'role' => $athlete['role'] ?? 'guest',
                'marked_at' => $athlete['marked_at'] ? date('h:i A', strtotime($athlete['marked_at'])) : null
            ];
        }

        $stmt_status = $pdo->prepare("SELECT status FROM bookings WHERE schedule_id = ? LIMIT 1");
        $stmt_status->execute([$schedule_id]);
        $booking_status = $stmt_status->fetchColumn() ?: '';

        echo json_encode([
            'success' => true, 
            'athletes' => $formatted,
            'booking_status' => $booking_status
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'mark_attendance') {
    $attendance_id = (int)($_POST['attendance_id'] ?? 0);
    $status = $_POST['status'] ?? 'absent'; // present, absent

    if ($attendance_id <= 0 || !in_array($status, ['present', 'absent'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
        exit;
    }

    try {
        $marked_at = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("UPDATE attendance SET status = ?, marked_at = ? WHERE id = ?");
        $stmt->execute([$status, $marked_at, $attendance_id]);

        log_audit($pdo, $admin_id, 'mark_attendance', 'attendance', $attendance_id, null, ['status' => $status]);

        echo json_encode([
            'success' => true, 
            'message' => 'Attendance status updated successfully!',
            'status' => $status,
            'marked_at' => $marked_at ? date('h:i A', strtotime($marked_at)) : null
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'complete_session') {
    $schedule_id = (int)($_POST['schedule_id'] ?? 0);

    if ($schedule_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Valid schedule ID is required.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Get booking ID and status
        $stmt_bk = $pdo->prepare("SELECT id, user_id, booking_code FROM bookings WHERE schedule_id = ? LIMIT 1");
        $stmt_bk->execute([$schedule_id]);
        $booking = $stmt_bk->fetch();

        if (!$booking) {
            echo json_encode(['success' => false, 'message' => 'Booking not found for this schedule.']);
            exit;
        }

        $booking_id = $booking['id'];

        // 2. Update Booking Status
        $stmt_up_bk = $pdo->prepare("UPDATE bookings SET status = 'completed', completed_at = NOW() WHERE id = ?");
        $stmt_up_bk->execute([$booking_id]);

        // 3. Update Session Status
        $stmt_up_sess = $pdo->prepare("UPDATE sessions SET status = 'completed', ended_at = NOW() WHERE booking_id = ?");
        $stmt_up_sess->execute([$booking_id]);

        $pdo->commit();

        log_audit($pdo, $admin_id, 'complete_session', 'bookings', $booking_id);

        create_notification($pdo, $booking['user_id'], null, $booking_id, 'completed', 
            "Your training session for booking " . $booking['booking_code'] . " has been marked as completed. Well done on your practice today!");

        echo json_encode(['success' => true, 'message' => 'Session marked as completed successfully!']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Failed to complete session: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action request.']);
