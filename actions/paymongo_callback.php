<?php
// actions/paymongo_callback.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

// Parse parameters
$session_id   = $_GET['session_id'] ?? '';
$booking_code = $_GET['booking_code'] ?? '';

if (empty($session_id) || empty($booking_code)) {
    header("Location: ../client/my_bookings.php?payment=error&message=Missing+parameters");
    exit;
}

try {
    // 1. Fetch booking and corresponding payment details
    $stmt = $pdo->prepare("
        SELECT b.*, p.id AS payment_id, p.status AS payment_status, p.paymongo_intent_id
          FROM bookings b
          JOIN payments p ON p.booking_id = b.id
         WHERE b.booking_code = ?
    ");
    $stmt->execute([$booking_code]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        header("Location: ../client/my_bookings.php?payment=error&message=Booking+not+found");
        exit;
    }

    // If the booking is already confirmed, just redirect to success page
    if ($booking['status'] === 'confirmed' && $booking['payment_status'] === 'paid') {
        header("Location: ../client/my_bookings.php?payment=success&code=" . urlencode($booking_code));
        exit;
    }

    // 2. Query PayMongo API to verify Checkout Session status
    $session_res = retrieve_paymongo_session($session_id);
    
    if (!$session_res['success']) {
        header("Location: ../client/my_bookings.php?payment=cancel&code=" . urlencode($booking_code) . "&message=" . urlencode($session_res['message']));
        exit;
    }

    $status = $session_res['status']; // 'active', 'completed', etc.

    if ($status === 'completed') {
        // Fetch user information to seed accurate companion and attendance logs
        $stmt_user = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $stmt_user->execute([$booking['user_id']]);
        $user_details = $stmt_user->fetch();
        $name = $user_details['full_name'] ?? 'Trainee';

        // 3. Start database transaction to lock everything safely
        $pdo->beginTransaction();

        // A. Confirm Booking
        $stmt_b_up = $pdo->prepare("UPDATE bookings SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?");
        $stmt_b_up->execute([$booking['id']]);

        // B. Confirm Payment
        $stmt_p_up = $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE id = ?");
        $stmt_p_up->execute([$booking['payment_id']]);

        // C. Find and confirm all consecutive schedules booked under this session
        $stmt_sch_select = $pdo->prepare("
            SELECT id FROM schedules 
             WHERE session_date = (SELECT session_date FROM schedules WHERE id = ?) 
               AND start_time >= (SELECT start_time FROM schedules WHERE id = ?)
               AND start_time < ADDTIME((SELECT start_time FROM schedules WHERE id = ?), SEC_TO_TIME(? * 3600))
        ");
        $stmt_sch_select->execute([$booking['schedule_id'], $booking['schedule_id'], $booking['schedule_id'], $booking['duration_hours']]);
        $schedules_to_update = $stmt_sch_select->fetchAll(PDO::FETCH_COLUMN);

        $stmt_sch_up = $pdo->prepare("UPDATE schedules SET status = 'confirmed', reserved_until = NULL WHERE id = ?");
        foreach ($schedules_to_update as $sid) {
            $stmt_sch_up->execute([$sid]);
        }

        // D. Create Training Session
        $stmt_sess = $pdo->prepare("
            INSERT INTO sessions (booking_id, admin_id, status) 
            VALUES (?, 1, 'scheduled')
        ");
        $stmt_sess->execute([$booking['id']]);
        $session_id_db = $pdo->lastInsertId();

        // E. Seed Attendance log (principal player + companion guests)
        $stmt_att = $pdo->prepare("
            INSERT INTO attendance (session_id, booking_id, user_id, attendee_name, status) 
            VALUES (?, ?, ?, ?, 'absent')
        ");
        // Principal
        $stmt_att->execute([$session_id_db, $booking['id'], $booking['user_id'], $name]);
        // Companions
        for ($i = 1; $i < (int)$booking['pax']; $i++) {
            $stmt_att->execute([$session_id_db, $booking['id'], null, $name . ' +' . $i]);
        }

        // F. Log event transaction
        $stmt_tx = $pdo->prepare("
            INSERT INTO payment_transactions 
              (payment_id, paymongo_ref, event_type, payload) 
            VALUES 
              (?, ?, 'success', ?)
        ");
        $stmt_tx->execute([$booking['payment_id'], $session_id, json_encode($session_res['raw_payload'])]);

        $pdo->commit();

        // 4. Send system notifications
        // Retrieve slot times to build a nice user notification list
        $stmt_sched_times = $pdo->prepare("SELECT start_time FROM schedules WHERE id IN (" . implode(',', array_fill(0, count($schedules_to_update), '?')) . ") ORDER BY start_time ASC");
        $stmt_sched_times->execute($schedules_to_update);
        $sched_times = $stmt_sched_times->fetchAll(PDO::FETCH_COLUMN);
        $slot_list = implode(', ', array_map(fn($t) => date('h:i A', strtotime($t)), $sched_times));
        
        $session_date_formatted = date('M d, Y', strtotime($booking['booked_at']));
        $notif_msg = "Your court booking " . $booking_code . " is confirmed! Date: " . $session_date_formatted . " (" . $booking['duration_hours'] . " hr(s): " . $slot_list . ")";

        create_notification($pdo, $booking['user_id'], null, $booking['id'], 'booking_confirmed', $notif_msg);
        create_notification($pdo, null, 1, $booking['id'], 'booking_confirmed', "Booking " . $booking_code . " paid successfully via PayMongo.");

        // ── EMAIL athlete ─────────────────────────────────────────
        $email_to = $user_details['email'] ?? '';
        if (!empty($email_to)) {
            $email_body = '
            <h2>Your Court Booking is Confirmed!</h2>
            <p>Hi ' . htmlspecialchars($name) . ',</p>
            <p>Excellent news! Your payment has been processed successfully through PayMongo, and your training court session is officially **confirmed and locked**.</p>

            <div class="highlight-box" style="background-color: #bcf0ae; border-color: #154212; color: #002201;">
                ✓ Transaction Settled: Your training session has been scheduled, and the coach has been notified.
            </div>

            <table class="details-table">
                <tr>
                    <th>Booking Reference</th>
                    <td>' . htmlspecialchars($booking_code) . '</td>
                </tr>
                <tr>
                    <th>Court Date</th>
                    <td>' . $session_date_formatted . '</td>
                </tr>
                <tr>
                    <th>Time Block</th>
                    <td>' . htmlspecialchars($slot_list) . ' (' . $booking['duration_hours'] . ' hour(s))</td>
                </tr>
                <tr>
                    <th>Players (Pax)</th>
                    <td>' . $booking['pax'] . ' Pax</td>
                </tr>
                <tr>
                    <th>Payment Method</th>
                    <td>PayMongo Checkout</td>
                </tr>
                <tr>
                    <th>Amount Settled</th>
                    <td style="font-weight: 900; color: #154212;">₱' . number_format($booking['total_fee'], 2) . '</td>
                </tr>
            </table>

            <p style="text-align: center;">
                <a href="http://localhost/RDG/client/my_bookings.php" class="btn" style="color: #154212;">View My Schedule</a>
            </p>

            <div class="policy-box">
                <h3>Cancellation & Handover Policy</h3>
                <ul>
                    <li><strong>48h Rule:</strong> A minimum of 48 hours notice is required for all cancellations to qualify for a full refund or credit waiver.</li>
                    <li><strong>Court Transitions:</strong> Please be respectful of court hours. Finish your session 2 minutes prior to the hour to allow a smooth transition for subsequent players.</li>
                </ul>
            </div>';
            send_html_email($email_to, "Confirmed! Your RDG Tennis court booking is locked.", $email_body);
        }

        header("Location: ../client/my_bookings.php?payment=success&code=" . urlencode($booking_code));
        exit;
    } else {
        // Unpaid or cancelled
        $pdo->beginTransaction();
        $stmt_tx = $pdo->prepare("
            INSERT INTO payment_transactions 
              (payment_id, paymongo_ref, event_type, payload) 
            VALUES 
              (?, ?, 'failed', ?)
        ");
        $stmt_tx->execute([$booking['payment_id'], $session_id, json_encode($session_res['raw_payload'])]);
        $pdo->commit();

        header("Location: ../client/my_bookings.php?payment=cancel&code=" . urlencode($booking_code));
        exit;
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("PayMongo Callback Exception: " . $e->getMessage());
    header("Location: ../client/my_bookings.php?payment=error&message=" . urlencode($e->getMessage()));
    exit;
}
