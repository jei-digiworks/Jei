<?php
// actions/client_booking.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Run cron simulator to handle expired reserves on request
run_cron_simulator($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Get Live Booking Config (open months + sunday lock) ──────────
if ($action === 'get_config') {
    try {
        // Fetch only open months (for bookability check)
        $stmt_open = $pdo->query("SELECT month_year FROM bookable_months WHERE is_open = 1 ORDER BY month_year ASC");
        $open_months = $stmt_open->fetchAll(PDO::FETCH_COLUMN);

        // Fetch ALL months in DB (for navigation bounds)
        $stmt_all = $pdo->query("SELECT month_year FROM bookable_months ORDER BY month_year ASC");
        $all_months = $stmt_all->fetchAll(PDO::FETCH_COLUMN);

        // min_month = first month admin configured (full navigation range)
        // max_month = last month admin configured
        $min_month = !empty($all_months) ? $all_months[0] : date('Y-m');
        $max_month = !empty($all_months) ? end($all_months) : date('Y-m');

        $stmt_sun = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'sunday_locked' LIMIT 1");
        $sunday_locked = $stmt_sun->fetchColumn();
        if ($sunday_locked === false) $sunday_locked = '1';

        $stmt_adv = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'min_booking_advance_days' LIMIT 1");
        $adv_days = (int)($stmt_adv->fetchColumn() ?? 7);

        echo json_encode([
            'success'              => true,
            'open_months'          => $open_months,
            'all_months'           => $all_months,
            'min_month'            => $min_month,
            'max_month'            => $max_month,
            'sunday_locked'        => $sunday_locked,
            // PHT-accurate date values so client JS can refresh the "today" boundary
            'today_pht'            => date('Y-m-d'),              // current PHT date
            'first_bookable_date'  => date('Y-m-d', strtotime("+$adv_days days")),
        ]);


    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}


if ($action === 'get_slots') {
    $date = $_GET['date'] ?? '';
    if (empty($date)) {
        echo json_encode(['success' => false, 'message' => 'Date is required.']);
        exit;
    }


    try {
        $stmt = $pdo->prepare("
            SELECT id, start_time, end_time, status, is_game_night 
              FROM schedules 
             WHERE session_date = ? 
             ORDER BY CASE WHEN start_time = '00:00:00' THEN 1 ELSE 0 END ASC, start_time ASC
        ");
        $stmt->execute([$date]);
        $slots = $stmt->fetchAll();

        // Standardize output
        $formatted_slots = [];
        $current_time = time();
        $is_today = ($date === date('Y-m-d'));

        // Check if selected date is a Tuesday (2) or Thursday (4)
        $day_of_week = (int)date('N', strtotime($date));
        $is_tue_or_thu = ($day_of_week === 2 || $day_of_week === 4);

        // Check if the month is open for bookings
        $stmt_check_month = $pdo->prepare("SELECT is_open FROM bookable_months WHERE month_year = ? LIMIT 1");
        $stmt_check_month->execute([substr($date, 0, 7)]);
        $month_open = $stmt_check_month->fetchColumn();
        $is_month_locked = ($month_open === false || (int)$month_open === 0);

        foreach ($slots as $slot) {
            $start_str = $date . ' ' . $slot['start_time'];
            $start_ts = strtotime($start_str);
            
            $status = $slot['status'];
            $is_game_night = (bool)$slot['is_game_night'];

            if ($is_month_locked) {
                $status = 'locked';
            } else {
                // Auto-lock and flag as Game Night from 7pm (19:00) onward, and also midnight (00:00), on Tuesdays and Thursdays
                $start_hour = (int)date('H', strtotime($slot['start_time']));
                if ($is_tue_or_thu && ($start_hour >= 19 || $start_hour === 0)) {
                    $status = 'locked';
                    $is_game_night = true;
                }

                // If slot is in the past today, mark it as locked or unavailable
                if ($is_today && $start_ts < $current_time) {
                    $status = 'locked';
                }
            }

            $formatted_slots[] = [
                'id' => $slot['id'],
                'start_time' => date('h:i A', strtotime($slot['start_time'])),
                'end_time' => date('h:i A', strtotime($slot['end_time'])),
                'status' => $status,
                'is_game_night' => $is_game_night
            ];
        }

        echo json_encode(['success' => true, 'slots' => $formatted_slots]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'confirm_booking') {
    if (!is_client_logged_in()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login first.']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $schedule_ids_raw = $_POST['schedule_ids'] ?? [];
    $schedule_ids = array_values(array_filter(array_map('intval', (array)$schedule_ids_raw), fn($id) => $id > 0));
    $pax = (int)($_POST['pax'] ?? 1);
    $add_coaching = true; // Professional Coaching is automatically selected/forced by default
    $exclude_court_fee = (isset($_POST['exclude_court_fee']) && ($_POST['exclude_court_fee'] === 'true' || $_POST['exclude_court_fee'] === '1' || $_POST['exclude_court_fee'] === 1));
    $payment_method = $_POST['payment_method'] ?? 'pay_later'; // pay_now, pay_later

    if (empty($schedule_ids) || $pax < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters. Please select at least one slot.']);
        exit;
    }

    if (count($schedule_ids) !== 2) {
        echo json_encode(['success' => false, 'message' => 'Strict selection rule violated: You must select exactly two time slots to place a booking.']);
        exit;
    }

    try {
        // Retrieve and validate all selected schedule slots
        $placeholders = implode(',', array_fill(0, count($schedule_ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM schedules WHERE id IN ($placeholders) ORDER BY start_time ASC");
        $stmt->execute($schedule_ids);
        $schedules = $stmt->fetchAll();

        if (count($schedules) !== count($schedule_ids)) {
            echo json_encode(['success' => false, 'message' => 'One or more selected slots do not exist.']);
            exit;
        }

        // Validate all are available and on the same date
        $session_date = $schedules[0]['session_date'];
        
        // Enforce bookable months guard
        $stmt_check_month = $pdo->prepare("SELECT is_open FROM bookable_months WHERE month_year = ? LIMIT 1");
        $stmt_check_month->execute([substr($session_date, 0, 7)]);
        $month_open = $stmt_check_month->fetchColumn();
        if (!$month_open) {
            echo json_encode(['success' => false, 'message' => 'Bookings for this month are currently closed by administration.']);
            exit;
        }

        // Enforce Sunday lock guard
        $stmt_sun_check = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'sunday_locked' LIMIT 1");
        $sunday_locked_val = $stmt_sun_check->fetchColumn();
        if ($sunday_locked_val === '1' || $sunday_locked_val === 1) {
            foreach ($schedules as $sched) {
                $day_of_week = (int)date('N', strtotime($sched['session_date']));
                if ($day_of_week === 7) {
                    echo json_encode(['success' => false, 'message' => 'Sundays are currently locked for bookings by the administration.']);
                    exit;
                }
            }
        }

        foreach ($schedules as $sched) {
            // Block bookings during auto-locked Game Nights (Tue/Thu 7pm+ or midnight)
            $day_of_week = (int)date('N', strtotime($sched['session_date']));
            $start_hour = (int)date('H', strtotime($sched['start_time']));
            if (($day_of_week === 2 || $day_of_week === 4) && ($start_hour >= 19 || $start_hour === 0)) {
                $t = date('h:i A', strtotime($sched['start_time']));
                echo json_encode(['success' => false, 'message' => "Slot $t falls on a Tuesday/Thursday Game Night and is unavailable for booking."]);
                exit;
            }

            if ($sched['status'] !== 'available') {
                $t = date('h:i A', strtotime($sched['start_time']));
                echo json_encode(['success' => false, 'message' => "Slot $t is no longer available. Please re-select."]);
                exit;
            }
            if ($sched['session_date'] !== $session_date) {
                echo json_encode(['success' => false, 'message' => 'All selected slots must be on the same date.']);
                exit;
            }
        }

        $primary_schedule    = $schedules[0];
        $total_duration_hours = count($schedules); // each 1-hour block = 1 hour

        // Compute fees based on total hours
        $fees = calculate_booking_fees($pdo, (float)$total_duration_hours, $pax, $add_coaching, $exclude_court_fee);
        
        $user_package_id = null;

        // If package checkout, verify they have an active package
        if ($payment_method === 'package') {
            $stmt = $pdo->prepare("
                SELECT id, sessions_remaining FROM user_packages 
                 WHERE user_id = ? AND status = 'active' AND sessions_remaining > 0 AND expires_at > NOW() 
                 ORDER BY expires_at ASC LIMIT 1
            ");
            $stmt->execute([$user_id]);
            $package = $stmt->fetch();

            if (!$package) {
                echo json_encode(['success' => false, 'message' => 'No active coaching packages found with sessions remaining.']);
                exit;
            }
            $user_package_id = $package['id'];
        }

        // Start transaction
        $pdo->beginTransaction();

        $booking_code = generate_booking_code($pdo);
        $booking_status = ($payment_method === 'pay_later') ? 'reserved' : (($payment_method === 'package') ? 'confirmed' : 'reserved');

        // Fetch user billing information
        $stmt_user = $pdo->prepare("SELECT email, full_name, phone FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $user_details = $stmt_user->fetch();
        $email = $user_details['email'] ?? '';
        $name = $user_details['full_name'] ?? $_SESSION['user_name'];
        $phone = $user_details['phone'] ?? '';

        // 1. Insert booking
        $stmt_book = $pdo->prepare("
            INSERT INTO bookings 
              (booking_code, user_id, schedule_id, pricing_config_id, user_package_id, pax, duration_hours, coaching_fee, court_fee, total_fee, status, confirmed_at) 
            VALUES 
              (?, ?, ?, (SELECT id FROM pricing_config WHERE is_active = 1 LIMIT 1), ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $confirmed_at = ($booking_status === 'confirmed') ? date('Y-m-d H:i:s') : null;
        
        $stmt_book->execute([
            $booking_code,
            $user_id,
            $primary_schedule['id'],
            $user_package_id,
            $pax,
            $total_duration_hours,
            $fees['coaching_fee'],
            $fees['court_fee'],
            $fees['total_fee'],
            $booking_status,
            $confirmed_at
        ]);
        
        $booking_id = $pdo->lastInsertId();

        // 2. Insert payment
        $stmt_pay = $pdo->prepare("
            INSERT INTO payments 
              (booking_id, method, status, amount, due_date, paid_at, paymongo_intent_id) 
            VALUES 
              (?, ?, ?, ?, ?, ?, ?)
        ");

        $pay_status = 'unpaid';
        $paid_at = null;
        $due_date = null;
        $paymongo_intent = null;

        if ($payment_method === 'pay_now') {
            $pay_status = 'unpaid';
            $due_date = date('Y-m-d H:i:s', strtotime('+24 hours'));
        } elseif ($payment_method === 'package') {
            $pay_status = 'paid';
            $paid_at = date('Y-m-d H:i:s');
        } else {
            // pay_later: must pay within 24h
            $due_date = date('Y-m-d H:i:s', strtotime('+24 hours'));
        }

        $stmt_pay->execute([
            $booking_id,
            $payment_method,
            $pay_status,
            $fees['total_fee'],
            $due_date,
            $paid_at,
            $paymongo_intent
        ]);
        
        $payment_id = $pdo->lastInsertId();

        // Initialize Checkout Session if Pay Now
        $checkout_url = null;
        if ($payment_method === 'pay_now' && $fees['total_fee'] > 0) {
            $checkout_res = create_paymongo_checkout($booking_code, $fees['total_fee'], $email, $name, $phone);
            if (!$checkout_res['success']) {
                throw new Exception($checkout_res['message']);
            }
            $paymongo_intent = $checkout_res['session_id'];
            $checkout_url = $checkout_res['checkout_url'];

            // Save the checkout session ID
            $pdo->prepare("UPDATE payments SET paymongo_intent_id = ? WHERE id = ?")->execute([$paymongo_intent, $payment_id]);

            // Log checkout initiation
            $stmt_tx = $pdo->prepare("
                INSERT INTO payment_transactions 
                  (payment_id, paymongo_ref, event_type, payload) 
                VALUES 
                  (?, ?, 'checkout', ?)
            ");
            $payload = json_encode(['amount' => $fees['total_fee'] * 100, 'currency' => 'PHP', 'status' => 'checkout_initiated', 'session_id' => $paymongo_intent]);
            $stmt_tx->execute([$payment_id, $paymongo_intent, $payload]);
        }

        // If package checkout, decrement remaining sessions
        if ($payment_method === 'package' && $user_package_id) {
            $stmt_pkg_dec = $pdo->prepare("
                UPDATE user_packages 
                   SET sessions_remaining = sessions_remaining - 1,
                       status = CASE WHEN sessions_remaining - 1 = 0 THEN 'consumed' ELSE status END
                 WHERE id = ?
            ");
            $stmt_pkg_dec->execute([$user_package_id]);
        }

        // 3. Update ALL selected schedules to the new status
        $sched_status  = ($booking_status === 'confirmed') ? 'confirmed' : 'reserved';
        $reserved_until = ($booking_status === 'reserved') ? date('Y-m-d H:i:s', strtotime('+24 hours')) : null;

        $stmt_sched_up = $pdo->prepare("UPDATE schedules SET status = ?, reserved_until = ? WHERE id = ?");
        foreach ($schedule_ids as $sid) {
            $stmt_sched_up->execute([$sched_status, $reserved_until, $sid]);
        }

        // 4. Create Session (if confirmed / package)
        if ($booking_status === 'confirmed') {
            $stmt_sess = $pdo->prepare("
                INSERT INTO sessions (booking_id, admin_id, status) 
                VALUES (?, 1, 'scheduled')
            ");
            $stmt_sess->execute([$booking_id]);
            $session_id = $pdo->lastInsertId();

            // Seed attendance logs for player and player companions
            $stmt_att = $pdo->prepare("
                INSERT INTO attendance (session_id, booking_id, user_id, attendee_name, status) 
                VALUES (?, ?, ?, ?, 'absent')
            ");
            
            // Principal player
            $stmt_att->execute([$session_id, $booking_id, $user_id, $_SESSION['user_name']]);
            
            // Companion pax
            for ($i = 1; $i < $pax; $i++) {
                $stmt_att->execute([$session_id, $booking_id, null, $_SESSION['user_name'] . ' +' . $i]);
            }
        }

        $pdo->commit();

        // 5. Generate dynamic notifications
        $slot_list = implode(', ', array_map(
            fn($s) => date('h:i A', strtotime($s['start_time'])),
            $schedules
        ));

        if ($payment_method === 'pay_now' && !empty($checkout_url)) {
            // For Pay Now, return redirect URL
            echo json_encode([
                'success' => true,
                'booking_code' => $booking_code,
                'status' => 'reserved',
                'redirect_url' => $checkout_url,
                'message' => 'Redirecting to secure PayMongo checkout...'
            ]);
        } else {
            // Standard Pay Later or Package Checkout
            $notif_msg = ($booking_status === 'confirmed')
                ? "Your court booking " . $booking_code . " on " . date('M d', strtotime($session_date)) . " (" . $total_duration_hours . " hr(s): " . $slot_list . ") is confirmed!"
                : "Your court slot has been reserved under " . $booking_code . ". Please settle your Pay Later balance within 24h to lock it in.";

            create_notification($pdo, $user_id, null, $booking_id, 'booking_confirmed', $notif_msg);
            
            // Admin notification
            create_notification($pdo, null, 1, $booking_id, 'booking_confirmed', "New booking " . $booking_code . " placed by " . $_SESSION['user_name'] . " (" . strtoupper($payment_method) . ")");

            // ── EMAIL athlete ─────────────────────────────────────────
            if ($payment_method === 'pay_later') {
                $session_date_formatted = date('M d, Y', strtotime($session_date));
                $email_body = '
                <h2>Reserved Court Slot - Action Required!</h2>
                <p>Hi ' . htmlspecialchars($name) . ',</p>
                <p>We have successfully received your reservation request. Your selected court time slot has been put on hold temporarily. To secure this booking and lock in your court, you must settle your payment within the next 24 hours.</p>

                <div class="highlight-box">
                    ⚠ Pay Later Balance Deadline: Please pay within 24 hours of booking, or our automated scheduler will release the slots back for public booking.
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
                        <td>' . htmlspecialchars($slot_list) . ' (' . $total_duration_hours . ' hour(s))</td>
                    </tr>
                    <tr>
                        <th>Players (Pax)</th>
                        <td>' . $pax . ' Pax</td>
                    </tr>
                    <tr>
                        <th>Total Amount Due</th>
                        <td style="font-weight: 900; color: #154212;">₱' . number_format($fees['total_fee'], 2) . '</td>
                    </tr>
                </table>

                <p style="text-align: center;">
                    <a href="http://localhost/RDG/client/pay.php?code=' . urlencode($booking_code) . '" class="btn" style="color: #154212;">Pay Balance Now</a>
                </p>

                <div class="policy-box">
                    <h3>Cancellation & Hold Policy</h3>
                    <ul>
                        <li><strong>Hold Limit:</strong> Pay Later holds expire exactly 24 hours after reservation creation.</li>
                        <li><strong>Cancellation Window:</strong> Free cancellations and rescheduling requests are only allowed up to 48 hours before the reserved session starts. If you request cancellation within 48 hours, fees are non-refundable.</li>
                    </ul>
                </div>';
                send_html_email($email, "Action Required: Your RDG Tennis reservation is held for 24 hours!", $email_body);
            } elseif ($payment_method === 'package') {
                $session_date_formatted = date('M d, Y', strtotime($session_date));
                $email_body = '
                <h2>Your Court Booking is Confirmed!</h2>
                <p>Hi ' . htmlspecialchars($name) . ',</p>
                <p>Excellent news! Your training court session has been successfully booked using your package sessions and is **confirmed and locked**.</p>

                <div class="highlight-box" style="background-color: #bcf0ae; border-color: #154212; color: #002201;">
                    ✓ Sessions Remaining: Your session is locked, and the coach has been notified.
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
                        <td>' . htmlspecialchars($slot_list) . ' (' . $total_duration_hours . ' hour(s))</td>
                    </tr>
                    <tr>
                        <th>Players (Pax)</th>
                        <td>' . $pax . ' Pax</td>
                    </tr>
                    <tr>
                        <th>Payment Method</th>
                        <td>Training Package Deduction</td>
                    </tr>
                </table>

                <p style="text-align: center;">
                    <a href="http://localhost/RDG/client/my_bookings.php" class="btn" style="color: #154212;">View My Schedule</a>
                </p>';
                send_html_email($email, "Confirmed! Your RDG Tennis court booking is locked.", $email_body);
            }

            echo json_encode([
                'success' => true,
                'booking_code' => $booking_code,
                'status' => $booking_status,
                'message' => 'Booking successfully created!'
            ]);
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
    }

    exit;
}

// ── Pay Now (from My Bookings page) ──────────────────────────────
if ($action === 'pay_now') {
    if (!is_client_logged_in()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
    }
    $user_id    = $_SESSION['user_id'];
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    if ($booking_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid booking ID.']); exit; }

    try {
        // Verify booking belongs to user and is still unpaid/reserved
        $stmt = $pdo->prepare("
            SELECT b.*, p.id AS payment_id, p.status AS payment_status
              FROM bookings b
              JOIN payments p ON p.booking_id = b.id
             WHERE b.id = ? AND b.user_id = ? AND b.status IN ('reserved','pending_payment')
        ");
        $stmt->execute([$booking_id, $user_id]);
        $booking = $stmt->fetch();

        if (!$booking) {
            echo json_encode(['success'=>false,'message'=>'Booking not found or already paid.']); exit;
        }

        // Fetch user billing details
        $stmt_user = $pdo->prepare("SELECT email, full_name, phone FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $user_details = $stmt_user->fetch();
        $email = $user_details['email'] ?? '';
        $name = $user_details['full_name'] ?? $_SESSION['user_name'];
        $phone = $user_details['phone'] ?? '';

        // Create Checkout Session
        $checkout_res = create_paymongo_checkout($booking['booking_code'], $booking['total_fee'], $email, $name, $phone);
        if (!$checkout_res['success']) {
            throw new Exception($checkout_res['message']);
        }
        $session_id = $checkout_res['session_id'];
        $checkout_url = $checkout_res['checkout_url'];

        $pdo->beginTransaction();

        // Update payment with the new session ID
        $pdo->prepare("UPDATE payments SET paymongo_intent_id = ? WHERE id = ?")
            ->execute([$session_id, $booking['payment_id']]);

        // Log checkout initiation
        $stmt_tx = $pdo->prepare("
            INSERT INTO payment_transactions 
              (payment_id, paymongo_ref, event_type, payload) 
            VALUES 
              (?, ?, 'checkout', ?)
        ");
        $payload = json_encode(['amount' => $booking['total_fee'] * 100, 'currency' => 'PHP', 'status' => 'checkout_initiated', 'session_id' => $session_id]);
        $stmt_tx->execute([$booking['payment_id'], $session_id, $payload]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'redirect_url' => $checkout_url,
            'message' => 'Redirecting to secure PayMongo checkout...'
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'Transaction failed: '.$e->getMessage()]);
    }
    exit;
}

// ── Request Waiver ───────────────────────────────────────────────
if ($action === 'request_waiver') {
    if (!is_client_logged_in()) {
        echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit;
    }
    $user_id = $_SESSION['user_id'];
    $user_name = $_SESSION['user_name'];
    
    try {
        create_notification($pdo, null, 1, null, 'payment_reminder', 
            "Athlete " . $user_name . " has requested a cancellation/payment policy waiver.");
        
        echo json_encode(['success'=>true,'message'=>'Waiver request submitted to facility manager.']);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>'Failed to submit request: '.$e->getMessage()]);
    }
    exit;
}

// ── Cancel Booking (48h rule enforced) ───────────────────────────
if ($action === 'cancel_booking') {
    if (!is_client_logged_in()) {
        echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit;
    }
    $user_id    = $_SESSION['user_id'];
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    if ($booking_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid booking ID.']); exit; }

    try {
        // Load booking + schedule
        $stmt = $pdo->prepare("
            SELECT b.*, s.session_date, s.start_time, s.id AS schedule_id
              FROM bookings b
              JOIN schedules s ON b.schedule_id = s.id
             WHERE b.id = ? AND b.user_id = ? AND b.status IN ('reserved','confirmed','pending_payment')
        ");
        $stmt->execute([$booking_id, $user_id]);
        $booking = $stmt->fetch();

        if (!$booking) {
            echo json_encode(['success'=>false,'message'=>'Booking not found or already cancelled.']); exit;
        }

        // Check if already pending cancellation
        if ($booking['cancelled_reason'] === 'Pending Admin Approval') {
            echo json_encode(['success'=>false,'message'=>'Cancellation request is already pending admin approval.']); exit;
        }

        // Enforce 48h cancellation window
        $slot_ts = strtotime($booking['session_date'].' '.$booking['start_time']);
        if (($slot_ts - time()) <= (48 * 3600)) {
            echo json_encode(['success'=>false,'message'=>'Cancellation window has closed (must be >48h before slot).']); exit;
        }

        $pdo->beginTransaction();

        // Simply update the booking's cancelled_reason to signify that cancellation is pending
        // DO NOT delete the booking, DO NOT release the schedules! Keep them reserved/blocked!
        $stmt_up = $pdo->prepare("UPDATE bookings SET cancelled_reason = 'Pending Admin Approval' WHERE id = ?");
        $stmt_up->execute([$booking_id]);

        $pdo->commit();

        // Notify client and admin (pass null for booking_id to avoid FK constraint issues during state transitions)
        create_notification($pdo, $user_id, null, null, 'cancelled',
            "Your cancellation request for booking " . $booking['booking_code'] . " has been submitted. Waiting for admin approval.");

        create_notification($pdo, null, 1, null, 'cancelled',
            "Athlete " . $_SESSION['user_name'] . " has requested cancellation for booking " . $booking['booking_code'] . ".");

        // ── EMAIL athlete ─────────────────────────────────────────
        $stmt_user_email = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $stmt_user_email->execute([$user_id]);
        $user_details = $stmt_user_email->fetch();
        $email = $user_details['email'] ?? '';
        $name = $user_details['full_name'] ?? $_SESSION['user_name'];

        if (!empty($email)) {
            $session_date_formatted = date('M d, Y', strtotime($booking['session_date']));
            $start_fmt = date('h:i A', strtotime($booking['start_time']));
            $email_body = '
            <h2>Cancellation Request Submitted</h2>
            <p>Hi ' . htmlspecialchars($name) . ',</p>
            <p>We have received your cancellation request for booking reference **' . htmlspecialchars($booking['booking_code']) . '**.</p>

            <p>Your request has been queued for review by the facility administrator. The calendar time slots will remain reserved and blocked on your schedule until the cancellation request is formally processed by the administrator.</p>

            <table class="details-table">
                <tr>
                    <th>Booking Reference</th>
                    <td>' . htmlspecialchars($booking['booking_code']) . '</td>
                </tr>
                <tr>
                    <th>Court Date</th>
                    <td>' . $session_date_formatted . '</td>
                </tr>
                <tr>
                    <th>Time Block</th>
                    <td>' . $start_fmt . '</td>
                </tr>
                <tr>
                    <th>Review Status</th>
                    <td style="font-weight: bold; color: #b8860b;">Pending Admin Review</td>
                </tr>
            </table>

            <div class="policy-box" style="background-color: #fff9e6; border-color: #b8860b; color: #8a6d3b;">
                <h3>RDG Tennis Official Cancellation Policies</h3>
                <ul>
                    <li><strong>The 48-Hour Cancellation Rule:</strong> Players must submit cancellation requests at least 48 hours prior to the scheduled slot to receive a full refund or facility booking credit.</li>
                    <li><strong>Late Cancellations:</strong> Sessions cancelled within 48 hours are non-refundable and will forfeit any deposits paid, unless a special policy waiver is requested and approved by the manager.</li>
                    <li><strong>Waivers:</strong> You can formally request policy waivers in the policies section of the Player Portal for emergency cases.</li>
                </ul>
            </div>';
            send_html_email($email, "Cancellation Review: Booking " . $booking['booking_code'] . " cancellation request submitted", $email_body);
        }

        echo json_encode(['success'=>true, 'pending'=>true, 'message'=>'Cancellation request submitted! Waiting for admin approval.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'Transaction failed: '.$e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action request.']);
