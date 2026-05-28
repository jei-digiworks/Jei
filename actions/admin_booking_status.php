<?php
// actions/admin_booking_status.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

run_cron_simulator($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$booking_id = (int)($_POST['booking_id'] ?? $_GET['booking_id'] ?? 0);

if ($booking_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
    exit;
}

if ($action === 'confirm_payment') {
    if (!is_admin_logged_in()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
        exit;
    }

    $admin_id = $_SESSION['admin_id'];

    try {
        $stmt = $pdo->prepare("
            SELECT b.*, p.status AS payment_status, p.method AS payment_method, s.id AS schedule_id 
              FROM bookings b
              JOIN payments p ON p.booking_id = b.id
              JOIN schedules s ON s.id = b.schedule_id
             WHERE b.id = ? LIMIT 1
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();

        if (!$booking) {
            echo json_encode(['success' => false, 'message' => 'Booking not found.']);
            exit;
        }

        if ($booking['payment_status'] === 'paid') {
            echo json_encode(['success' => false, 'message' => 'Booking is already paid.']);
            exit;
        }

        $pdo->beginTransaction();

        // 1. Update Payment
        $stmt_pay = $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE booking_id = ?");
        $stmt_pay->execute([$booking_id]);

        // 2. Update Booking and Schedule status
        if ($booking['status'] === 'reserved') {
            $stmt_book = $pdo->prepare("UPDATE bookings SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?");
            $stmt_book->execute([$booking_id]);

            $stmt_sched = $pdo->prepare("UPDATE schedules SET status = 'confirmed', reserved_until = NULL WHERE id = ?");
            $stmt_sched->execute([$booking['schedule_id']]);

            // 3. Create Session & Attendance logs
            $stmt_sess = $pdo->prepare("INSERT IGNORE INTO sessions (booking_id, admin_id, status) VALUES (?, 1, 'scheduled')");
            $stmt_sess->execute([$booking_id]);
            
            // Check if sessions inserted, get session id
            $stmt_chk_sess = $pdo->prepare("SELECT id FROM sessions WHERE booking_id = ? LIMIT 1");
            $stmt_chk_sess->execute([$booking_id]);
            $session_id = $stmt_chk_sess->fetchColumn();

            // Fetch user info for attendance logs
            $stmt_usr = $pdo->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
            $stmt_usr->execute([$booking['user_id']]);
            $client_name = $stmt_usr->fetchColumn();

            // Principal check-in sheet
            $stmt_att = $pdo->prepare("
                INSERT IGNORE INTO attendance (session_id, booking_id, user_id, attendee_name, status) 
                VALUES (?, ?, ?, ?, 'absent')
            ");
            $stmt_att->execute([$session_id, $booking_id, $booking['user_id'], $client_name]);

            // Companion sheets
            for ($i = 1; $i < $booking['pax']; $i++) {
                $stmt_att->execute([$session_id, $booking_id, null, $client_name . ' +' . $i]);
            }
        }

        $pdo->commit();

        log_audit($pdo, $admin_id, 'confirm_payment', 'bookings', $booking_id);

        create_notification(
            $pdo, 
            $booking['user_id'], 
            null, 
            $booking_id, 
            'booking_confirmed', 
            "Your payment for booking " . $booking['booking_code'] . " was verified manually by staff. Court is locked in!"
        );

        echo json_encode(['success' => true, 'message' => 'Payment manually confirmed! Slot locked in.']);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'cancel_booking') {
    $reason = trim($_POST['reason'] ?? 'Requested by user');

    $is_admin = is_admin_logged_in();
    $is_client = is_client_logged_in();

    if (!$is_admin && !$is_client) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT b.*, p.status AS payment_status, p.method AS payment_method, 
                   s.session_date, s.start_time, s.id AS schedule_id
              FROM bookings b
              JOIN payments p ON p.booking_id = b.id
              JOIN schedules s ON s.id = b.schedule_id
             WHERE b.id = ? LIMIT 1
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();

        if (!$booking) {
            echo json_encode(['success' => false, 'message' => 'Booking not found.']);
            exit;
        }

        if ($booking['status'] === 'cancelled' || $booking['status'] === 'completed') {
            echo json_encode(['success' => false, 'message' => 'Cannot cancel a session that is already ' . $booking['status'] . '.']);
            exit;
        }

        // If client-side cancellation, enforce 48h limit
        if ($is_client && !$is_admin) {
            // Check user ID match
            if ((int)$booking['user_id'] !== (int)$_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized cancellation request.']);
                exit;
            }

            $session_datetime = strtotime($booking['session_date'] . ' ' . $booking['start_time']);
            $current_time = time();
            $difference_hours = ($session_datetime - $current_time) / 3600;

            if ($difference_hours < 48) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Cancellation policy violation. Sessions within 48 hours of starting cannot be self-cancelled. Please contact administration.'
                ]);
                exit;
            }
        }

        // Proceed to cancel booking
        $pdo->beginTransaction();

        // 1. Update Booking
        $stmt_book = $pdo->prepare("
            UPDATE bookings 
               SET status = 'cancelled', 
                   cancelled_reason = ?, 
                   cancelled_at = NOW() 
             WHERE id = ?
        ");
        $stmt_book->execute([$reason, $booking_id]);

        // 2. Update Payment status
        $new_pay_status = ($booking['payment_status'] === 'paid') ? 'refunded' : $booking['payment_status'];
        $stmt_pay = $pdo->prepare("UPDATE payments SET status = ? WHERE booking_id = ?");
        $stmt_pay->execute([$new_pay_status, $booking_id]);

        // 3. Release Schedule slots (including consecutive slots booked under this session)
        $stmt_sch_select = $pdo->prepare("
            SELECT id FROM schedules 
             WHERE session_date = (SELECT session_date FROM schedules WHERE id = ?) 
               AND start_time >= (SELECT start_time FROM schedules WHERE id = ?)
               AND start_time < ADDTIME((SELECT start_time FROM schedules WHERE id = ?), SEC_TO_TIME(? * 3600))
        ");
        $stmt_sch_select->execute([$booking['schedule_id'], $booking['schedule_id'], $booking['schedule_id'], $booking['duration_hours']]);
        $schedules_to_release = $stmt_sch_select->fetchAll(PDO::FETCH_COLUMN);

        $stmt_sched = $pdo->prepare("UPDATE schedules SET status = 'available', reserved_until = NULL WHERE id = ?");
        foreach ($schedules_to_release as $sid) {
            $stmt_sched->execute([$sid]);
        }

        // 4. Remove active session and attendance lists
        $stmt_sess_del = $pdo->prepare("DELETE FROM sessions WHERE booking_id = ?");
        $stmt_sess_del->execute([$booking_id]);

        // 5. Refund user package session if used
        if ($booking['payment_method'] === 'package' && $booking['user_package_id']) {
            $stmt_pkg_inc = $pdo->prepare("
                UPDATE user_packages 
                   SET sessions_remaining = sessions_remaining + 1,
                       status = 'active'
                 WHERE id = ?
            ");
            $stmt_pkg_inc->execute([$booking['user_package_id']]);
        }

        $pdo->commit();

        // Audit Logging
        if ($is_admin) {
            log_audit($pdo, $_SESSION['admin_id'], 'cancel_booking', 'bookings', $booking_id, null, ['reason' => $reason]);
        }

        // Notification triggers
        $user_msg = "Your court booking " . $booking['booking_code'] . " has been successfully cancelled.";
        if ($new_pay_status === 'refunded') {
            $user_msg .= " A refund of " . format_php($booking['total_fee']) . " has been credited back to your payment account.";
        }
        create_notification($pdo, $booking['user_id'], null, $booking_id, 'cancelled', $user_msg);
        
        if ($is_client) {
            create_notification($pdo, null, 1, $booking_id, 'cancelled', "Athlete " . $_SESSION['user_name'] . " cancelled booking " . $booking['booking_code'] . ". Reason: " . $reason);
        }

        echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully! Slot released back to public.']);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'approve_cancellation') {
    if (!is_admin_logged_in()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
        exit;
    }

    $admin_id = $_SESSION['admin_id'];
    $reason = trim($_POST['reason'] ?? 'Approved by Admin');

    try {
        $stmt = $pdo->prepare("
            SELECT b.*, p.status AS payment_status, p.method AS payment_method, 
                   s.session_date, s.start_time, s.id AS schedule_id
              FROM bookings b
              JOIN payments p ON p.booking_id = b.id
              JOIN schedules s ON s.id = b.schedule_id
             WHERE b.id = ? LIMIT 1
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();

        if (!$booking) {
            echo json_encode(['success' => false, 'message' => 'Booking not found.']);
            exit;
        }

        if ($booking['status'] === 'cancelled') {
            echo json_encode(['success' => false, 'message' => 'Booking is already cancelled.']);
            exit;
        }

        // Proceed to approve cancel booking
        $pdo->beginTransaction();

        // 1. Update Booking
        $stmt_book = $pdo->prepare("
            UPDATE bookings 
               SET status = 'cancelled', 
                   cancelled_reason = ?, 
                   cancelled_at = NOW() 
             WHERE id = ?
        ");
        $stmt_book->execute(['Approved: ' . $reason, $booking_id]);

        // 2. Update Payment status
        $new_pay_status = ($booking['payment_status'] === 'paid') ? 'refunded' : $booking['payment_status'];
        $stmt_pay = $pdo->prepare("UPDATE payments SET status = ? WHERE booking_id = ?");
        $stmt_pay->execute([$new_pay_status, $booking_id]);

        // 3. Release Schedule slots (including consecutive slots booked under this session)
        $stmt_sch_select = $pdo->prepare("
            SELECT id FROM schedules 
             WHERE session_date = (SELECT session_date FROM schedules WHERE id = ?) 
               AND start_time >= (SELECT start_time FROM schedules WHERE id = ?)
               AND start_time < ADDTIME((SELECT start_time FROM schedules WHERE id = ?), SEC_TO_TIME(? * 3600))
        ");
        $stmt_sch_select->execute([$booking['schedule_id'], $booking['schedule_id'], $booking['schedule_id'], $booking['duration_hours']]);
        $schedules_to_release = $stmt_sch_select->fetchAll(PDO::FETCH_COLUMN);

        $stmt_sched = $pdo->prepare("UPDATE schedules SET status = 'available', reserved_until = NULL WHERE id = ?");
        foreach ($schedules_to_release as $sid) {
            $stmt_sched->execute([$sid]);
        }

        // 4. Remove active session and attendance lists
        $stmt_sess_del = $pdo->prepare("DELETE FROM sessions WHERE booking_id = ?");
        $stmt_sess_del->execute([$booking_id]);

        // 5. Refund user package session if used
        if ($booking['payment_method'] === 'package' && $booking['user_package_id']) {
            $stmt_pkg_inc = $pdo->prepare("
                UPDATE user_packages 
                   SET sessions_remaining = sessions_remaining + 1,
                       status = 'active'
                 WHERE id = ?
            ");
            $stmt_pkg_inc->execute([$booking['user_package_id']]);
        }

        $pdo->commit();

        log_audit($pdo, $admin_id, 'approve_cancellation', 'bookings', $booking_id, null, ['reason' => $reason]);

        // Notification triggers
        $user_msg = "Your cancellation request for booking " . $booking['booking_code'] . " was APPROVED by Admin. Reason: " . $reason;
        if ($new_pay_status === 'refunded') {
            $user_msg .= " A refund of " . format_php($booking['total_fee']) . " has been credited back to your account.";
        }
        create_notification($pdo, $booking['user_id'], null, null, 'cancelled', $user_msg);

        // ── EMAIL athlete ─────────────────────────────────────────
        $stmt_user_email = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $stmt_user_email->execute([$booking['user_id']]);
        $user_details = $stmt_user_email->fetch();
        $email = $user_details['email'] ?? '';
        $name = $user_details['full_name'] ?? 'Trainee';

        if (!empty($email)) {
            $email_body = '
            <h2>Cancellation Request Approved</h2>
            <p>Hi ' . htmlspecialchars($name) . ',</p>
            <p>Your cancellation request for booking **' . htmlspecialchars($booking['booking_code']) . '** has been **APPROVED** by the administrator.</p>
            <p><strong>Reason/Note:</strong> ' . htmlspecialchars($reason) . '</p>
            <p>The time slots have been released back to the court scheduler.</p>';
            send_html_email($email, "Approved: Booking " . $booking['booking_code'] . " cancellation request approved", $email_body);
        }

        echo json_encode(['success' => true, 'message' => 'Cancellation request approved successfully!']);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'reject_cancellation') {
    if (!is_admin_logged_in()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
        exit;
    }

    $admin_id = $_SESSION['admin_id'];
    $reason = trim($_POST['reason'] ?? 'Rejected by Admin');

    try {
        $stmt = $pdo->prepare("
            SELECT b.*, s.session_date, s.start_time
              FROM bookings b
              JOIN schedules s ON s.id = b.schedule_id
             WHERE b.id = ? LIMIT 1
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();

        if (!$booking) {
            echo json_encode(['success' => false, 'message' => 'Booking not found.']);
            exit;
        }

        $pdo->beginTransaction();

        // 1. Update Booking cancelled_reason back to null
        $stmt_book = $pdo->prepare("
            UPDATE bookings 
               SET cancelled_reason = ?
             WHERE id = ?
        ");
        $stmt_book->execute([null, $booking_id]);

        $pdo->commit();

        log_audit($pdo, $admin_id, 'reject_cancellation', 'bookings', $booking_id, null, ['reason' => $reason]);

        // Notification triggers
        $user_msg = "Your cancellation request for booking " . $booking['booking_code'] . " was REJECTED by Admin. Reason: " . $reason;
        create_notification($pdo, $booking['user_id'], null, $booking_id, 'booking_confirmed', $user_msg);

        // ── EMAIL athlete ─────────────────────────────────────────
        $stmt_user_email = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $stmt_user_email->execute([$booking['user_id']]);
        $user_details = $stmt_user_email->fetch();
        $email = $user_details['email'] ?? '';
        $name = $user_details['full_name'] ?? 'Trainee';

        if (!empty($email)) {
            $email_body = '
            <h2>Cancellation Request Rejected</h2>
            <p>Hi ' . htmlspecialchars($name) . ',</p>
            <p>Your cancellation request for booking **' . htmlspecialchars($booking['booking_code']) . '** has been **REJECTED** by the administrator. The booking remains active.</p>
            <p><strong>Reason/Note:</strong> ' . htmlspecialchars($reason) . '</p>
            <p>Please check your schedule in the Player Portal.</p>';
            send_html_email($email, "Rejected: Booking " . $booking['booking_code'] . " cancellation request rejected", $email_body);
        }

        echo json_encode(['success' => true, 'message' => 'Cancellation request rejected. Booking remains active.']);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action request.']);
