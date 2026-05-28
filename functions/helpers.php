<?php
// functions/helpers.php

// ── Philippine Time (PHT / UTC+8) ─────────────────────────────────
// Must be set before ANY date()/strtotime() call in the entire app.
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load private credentials (git-ignored)
if (file_exists(__DIR__ . '/../config/credentials.php')) {
    require_once __DIR__ . '/../config/credentials.php';
} else {
    require_once __DIR__ . '/../config/credentials.example.php';
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';


/**
 * Checks if client is logged in.
 */
function is_client_logged_in() {
    return isset($_SESSION['user_id']) && $_SESSION['role'] === 'client';
}

/**
 * Checks if admin is logged in.
 */
function is_admin_logged_in() {
    return isset($_SESSION['admin_id']);
}

/**
 * Enforces client authentication. Redirects to login if not authenticated.
 */
function require_client() {
    if (!is_client_logged_in()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: /RDG/auth/login.php");
        exit;
    }

    if (!empty($_SESSION['must_change_password'])) {
        $current_page = basename($_SERVER['SCRIPT_NAME']);
        if ($current_page !== 'book_slot.php') {
            header("Location: /RDG/client/book_slot.php");
            exit;
        }
    }
}

/**
 * Enforces admin authentication. Redirects to login if not authenticated.
 */
function require_admin() {
    if (!is_admin_logged_in()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: /RDG/auth/login.php");
        exit;
    }
}

/**
 * Runs a simulated cron/scheduler routine on load.
 * This guarantees the 24-hour Pay Later limits and slot releases are updated immediately.
 */
function run_cron_simulator($pdo) {
    try {
        $admin_email = 'rdgtennislesson@gmail.com';

        // 1. Find unpaid payments past their due date to email admin before marking them overdue
        $stmt_overdue = $pdo->query("
            SELECT p.id, p.amount, b.booking_code, u.full_name AS client_name
              FROM payments p
              JOIN bookings b ON p.booking_id = b.id
              JOIN users u ON b.user_id = u.id
             WHERE p.status = 'unpaid' 
               AND p.due_date IS NOT NULL 
               AND p.due_date < NOW()
        ");
        $overdue_records = $stmt_overdue->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($overdue_records)) {
            $pdo->query("
                UPDATE payments 
                   SET status = 'overdue' 
                 WHERE status = 'unpaid' 
                   AND due_date IS NOT NULL 
                   AND due_date < NOW()
            ");

            // Email admin about this happening
            $subject = "RDG System Action: Overdue Accounts Alert (" . count($overdue_records) . " records)";
            $body = "
                <h2>System Automation: Overdue Payments Logged</h2>
                <p>The system has automatically marked the following unpaid booking payments as <strong>OVERDUE</strong> after their due dates passed:</p>
                <table class='details-table'>
                    <thead>
                        <tr><th>Booking Code</th><th>Client Name</th><th>Due Amount</th></tr>
                    </thead>
                    <tbody>
            ";
            foreach ($overdue_records as $rec) {
                $body .= "<tr><td>" . htmlspecialchars($rec['booking_code']) . "</td><td>" . htmlspecialchars($rec['client_name']) . "</td><td>₱" . number_format($rec['amount'], 2) . "</td></tr>";
            }
            $body .= "
                    </tbody>
                </table>
                <p>No action is required from you; the system has suspended active booking privileges for these slots.</p>
            ";
            send_html_email($admin_email, $subject, $body);
        }

        // 2. Find expired reserved schedules
        $stmt = $pdo->query("
            SELECT id FROM schedules 
             WHERE status = 'reserved' 
               AND reserved_until IS NOT NULL 
               AND reserved_until < NOW()
        ");
        $expired_schedule_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($expired_schedule_ids)) {
            $placeholders = implode(',', array_fill(0, count($expired_schedule_ids), '?'));
            
            // Get booking info to send cancellation notification & email admin
            $stmt_b = $pdo->prepare("
                SELECT b.id, b.user_id, b.booking_code, u.full_name AS client_name 
                  FROM bookings b
                  JOIN users u ON b.user_id = u.id
                 WHERE b.schedule_id IN ($placeholders)
            ");
            $stmt_b->execute($expired_schedule_ids);
            $cancelled_bookings = $stmt_b->fetchAll();

            $pdo->beginTransaction();

            foreach ($cancelled_bookings as $booking) {
                // Insert cancellation notice
                create_notification(
                    $pdo, 
                    $booking['user_id'], 
                    null, 
                    $booking['id'], 
                    'cancelled', 
                    "Your booking " . $booking['booking_code'] . " was cancelled because the 24h Pay Later limit expired."
                );
            }

            // Nullify booking references in notifications first due to FK constraints
            $stmt_notif = $pdo->prepare("UPDATE notifications SET booking_id = NULL WHERE booking_id IN (SELECT id FROM bookings WHERE schedule_id IN ($placeholders))");
            $stmt_notif->execute($expired_schedule_ids);

            // Delete payments first due to FK constraints
            $stmt_pay = $pdo->prepare("DELETE FROM payments WHERE booking_id IN (SELECT id FROM bookings WHERE schedule_id IN ($placeholders))");
            $stmt_pay->execute($expired_schedule_ids);

            // Cancel or delete bookings
            $stmt_del = $pdo->prepare("DELETE FROM bookings WHERE schedule_id IN ($placeholders)");
            $stmt_del->execute($expired_schedule_ids);

            // Release schedules back to available
            $stmt_sch = $pdo->prepare("
                UPDATE schedules 
                   SET status = 'available', reserved_until = NULL 
                 WHERE id IN ($placeholders)
            ");
            $stmt_sch->execute($expired_schedule_ids);

            $pdo->commit();

            // Email admin about expired cancellations happening
            if (!empty($cancelled_bookings)) {
                $subject = "RDG System Action: Expired Booking Cancellations (" . count($cancelled_bookings) . " slots released)";
                $body = "
                    <h2>System Automation: Expired Bookings Cancelled</h2>
                    <p>The system has automatically cancelled the following reserved bookings and released the court slots back to 'available' because the 24-hour Pay Later payment limit expired:</p>
                    <table class='details-table'>
                        <thead>
                            <tr><th>Booking Code</th><th>Client Name</th></tr>
                        </thead>
                        <tbody>
                ";
                foreach ($cancelled_bookings as $b) {
                    $body .= "<tr><td>" . htmlspecialchars($b['booking_code']) . "</td><td>" . htmlspecialchars($b['client_name']) . "</td></tr>";
                }
                $body .= "
                        </tbody>
                    </table>
                    <p>These court slots have been successfully returned to the public schedule.</p>
                ";
                send_html_email($admin_email, $subject, $body);
            }
        }

        // 3. Automatically lock past months (months strictly before current month in PHT)
        $current_month = date('Y-m');
        $stmt_auto_lock = $pdo->prepare("UPDATE bookable_months SET is_open = 0 WHERE month_year < ? AND is_open = 1");
        $stmt_auto_lock->execute([$current_month]);

        // 4. Send Email Reminders (1 Day and 3 Hours before schedule)
        $stmt_reminders = $pdo->query("
            SELECT 
                b.id AS booking_id,
                b.booking_code,
                b.reminded_1day_admin,
                b.reminded_3hr_admin,
                b.reminded_1day_player,
                b.reminded_3hr_player,
                b.total_fee,
                b.status AS booking_status,
                u.full_name AS client_name,
                u.email AS client_email,
                u.email_verified,
                s.session_date,
                s.start_time,
                s.end_time
            FROM bookings b
            JOIN schedules s ON b.schedule_id = s.id
            JOIN users u ON b.user_id = u.id
            WHERE b.status IN ('confirmed', 'reserved')
              AND (b.reminded_1day_admin = 0 OR b.reminded_3hr_admin = 0 OR b.reminded_1day_player = 0 OR b.reminded_3hr_player = 0)
        ");
        $pending_reminders = $stmt_reminders->fetchAll(PDO::FETCH_ASSOC);

        $now = time();

        foreach ($pending_reminders as $rem) {
            $session_start_str = $rem['session_date'] . ' ' . $rem['start_time'];
            $session_start_time = strtotime($session_start_str);
            $time_diff = $session_start_time - $now;

            // A. 1-Day Reminder (less than or equal to 24 hours (86400s), and still in the future)
            if ($time_diff > 0 && $time_diff <= 86400) {
                // Admin 1-Day Reminder
                if ($rem['reminded_1day_admin'] == 0) {
                    $subject = "RDG Admin Alert: Player Schedule Alert (24 Hours) - " . $rem['booking_code'];
                    $body = "
                        <h2 style='color: #154212; border-bottom: 2px solid #154212; padding-bottom: 8px;'>Upcoming Player Session Alert</h2>
                        <p>Dear RDG Admin,</p>
                        <p>This is your official <strong>24-hour facility alert</strong> regarding an upcoming booked session scheduled on our courts tomorrow.</p>
                        <p>Please review the booking details below and make sure the facility, coaching personnel, and court configurations are prepared for the player's arrival.</p>
                        <table class='details-table'>
                            <tr><th>Booking Reference</th><td>" . htmlspecialchars($rem['booking_code']) . "</td></tr>
                            <tr><th>Player / Account</th><td>" . htmlspecialchars($rem['client_name']) . " (" . htmlspecialchars($rem['client_email']) . ")</td></tr>
                            <tr><th>Schedule Date</th><td>" . date('F d, Y', strtotime($rem['session_date'])) . "</td></tr>
                            <tr><th>Session Time</th><td>" . date('h:i A', strtotime($rem['start_time'])) . " - " . date('h:i A', strtotime($rem['end_time'])) . "</td></tr>
                            <tr><th>Booking Status</th><td>" . strtoupper($rem['booking_status']) . "</td></tr>
                            <tr><th>Total Fee</th><td>₱" . number_format($rem['total_fee'], 2) . "</td></tr>
                        </table>
                        <p>Court preparations should be scheduled accordingly. Ensure physical staff are fully notified.</p>
                    ";
                    if (send_html_email($admin_email, $subject, $body)) {
                        $pdo->prepare("UPDATE bookings SET reminded_1day_admin = 1 WHERE id = ?")->execute([$rem['booking_id']]);
                    }
                }

                // Player 1-Day Reminder (only if verified)
                if ($rem['reminded_1day_player'] == 0 && $rem['email_verified']) {
                    $subject = "RDG Tennis: Session Reminder (24 Hours) - " . $rem['booking_code'];
                    
                    $pay_warning = '';
                    if ($rem['booking_status'] === 'reserved') {
                        $pay_warning = "
                            <div class='policy-box' style='text-align: center; border: 2px solid #ba1a1a; color: #ba1a1a; background-color: #ffdad6; padding: 15px; margin: 20px 0;'>
                                <h3 style='margin-top: 0; color: #ba1a1a; font-size: 14px;'>⚠️ UNPAID RESERVATION WARNING</h3>
                                <p style='font-size: 12px; font-weight: bold;'>This session is currently UNPAID. Settle your balance immediately to lock in your court slot:</p>
                                <a href='http://localhost/RDG/client/pay.php?code=" . urlencode($rem['booking_code']) . "' class='btn' style='background-color: #FFE500; color: #154212; border: 2px solid #154212; text-decoration: none; padding: 10px 20px; font-weight: bold; text-transform: uppercase; display: inline-block; box-shadow: 2px 2px 0px #154212; font-size: 11px;'>Settle Balance Now &rarr;</a>
                            </div>
                        ";
                    }

                    $body = "
                        <h2 style='color: #154212; border-bottom: 2px solid #154212; padding-bottom: 8px;'>Upcoming Court Session Reminder</h2>
                        <p>Dear RDG Tennis Member,</p>
                        <p>This is your official <strong>24-hour training reminder</strong> that you have an upcoming tennis session scheduled at our facility tomorrow. We look forward to seeing you on the court!</p>
                        <table class='details-table'>
                            <tr><th>Booking Reference</th><td>" . htmlspecialchars($rem['booking_code']) . "</td></tr>
                            <tr><th>Schedule Date</th><td>" . date('F d, Y', strtotime($rem['session_date'])) . "</td></tr>
                            <tr><th>Session Time</th><td>" . date('h:i A', strtotime($rem['start_time'])) . " - " . date('h:i A', strtotime($rem['end_time'])) . "</td></tr>
                            <tr><th>Total Amount</th><td>₱" . number_format($rem['total_fee'], 2) . "</td></tr>
                        </table>
                        " . $pay_warning . "
                        <div class='highlight-box'>
                            Please arrive at least 10 minutes prior to your time block with your standard training gear to ensure a prompt start.
                        </div>
                    ";
                    if (send_html_email($rem['client_email'], $subject, $body)) {
                        $pdo->prepare("UPDATE bookings SET reminded_1day_player = 1 WHERE id = ?")->execute([$rem['booking_id']]);
                    }
                }
            }

            // B. 3-Hour Reminder (less than or equal to 3 hours (10800s), and still in the future)
            if ($time_diff > 0 && $time_diff <= 10800) {
                // Admin 3-Hour Reminder
                if ($rem['reminded_3hr_admin'] == 0) {
                    $subject = "RDG Admin URGENT: Court Session Starting in 3 Hours - " . $rem['booking_code'];
                    $body = "
                        <h2 style='color: #154212; border-bottom: 2px solid #154212; padding-bottom: 8px;'>URGENT 3-Hour Facility Alert</h2>
                        <p>Dear RDG Admin,</p>
                        <p>This is an <strong>URGENT 3-hour facility alert</strong>. An upcoming player session is scheduled to begin on our courts in exactly three hours.</p>
                        <p>Please initiate final facility checks immediately. Ensure that the assigned courts are cleared, prepared, and that assigned coaching personnel are ready for active deployment.</p>
                        <table class='details-table'>
                            <tr><th>Booking Reference</th><td>" . htmlspecialchars($rem['booking_code']) . "</td></tr>
                            <tr><th>Player / Account</th><td>" . htmlspecialchars($rem['client_name']) . "</td></tr>
                            <tr><th>Session Time</th><td>" . date('h:i A', strtotime($rem['start_time'])) . " - " . date('h:i A', strtotime($rem['end_time'])) . "</td></tr>
                            <tr><th>Current Status</th><td>" . strtoupper($rem['booking_status']) . "</td></tr>
                        </table>
                        <p>Immediate execution of final court preparation procedures is required.</p>
                    ";
                    if (send_html_email($admin_email, $subject, $body)) {
                        $pdo->prepare("UPDATE bookings SET reminded_3hr_admin = 1 WHERE id = ?")->execute([$rem['booking_id']]);
                    }
                }

                // Player 3-Hour Reminder (only if verified)
                if ($rem['reminded_3hr_player'] == 0 && $rem['email_verified']) {
                    $subject = "RDG Tennis URGENT: Your Session Starts in 3 Hours! - " . $rem['booking_code'];
                    
                    $pay_warning = '';
                    if ($rem['booking_status'] === 'reserved') {
                        $pay_warning = "
                            <div class='policy-box' style='text-align: center; border: 2px solid #ba1a1a; color: #ba1a1a; background-color: #ffdad6; padding: 15px; margin: 20px 0;'>
                                <h3 style='margin-top: 0; color: #ba1a1a; font-size: 14px;'>⚠️ URGENT UNPAID RESERVATION WARNING</h3>
                                <p style='font-size: 12px; font-weight: bold;'>This session starting in 3 hours is UNPAID. Settle your balance immediately to secure your court:</p>
                                <a href='http://localhost/RDG/client/pay.php?code=" . urlencode($rem['booking_code']) . "' class='btn' style='background-color: #FFE500; color: #154212; border: 2px solid #154212; text-decoration: none; padding: 10px 20px; font-weight: bold; text-transform: uppercase; display: inline-block; box-shadow: 2px 2px 0px #154212; font-size: 11px;'>Settle Balance Now &rarr;</a>
                            </div>
                        ";
                    }

                    $body = "
                        <h2 style='color: #154212; border-bottom: 2px solid #154212; padding-bottom: 8px;'>Urgent 3-Hour Training Session Reminder</h2>
                        <p>Dear RDG Tennis Member,</p>
                        <p>This is an urgent reminder that your scheduled tennis session at the RDG Tennis Facility is set to start in exactly three hours!</p>
                        <p>Our dedicated team is currently preparing your court and ensuring everything is in perfect order for your arrival.</p>
                        <table class='details-table'>
                            <tr><th>Booking Reference</th><td>" . htmlspecialchars($rem['booking_code']) . "</td></tr>
                            <tr><th>Session Time</th><td>" . date('h:i A', strtotime($rem['start_time'])) . " - " . date('h:i A', strtotime($rem['end_time'])) . "</td></tr>
                        </table>
                        " . $pay_warning . "
                        <div class='highlight-box'>
                            Please make sure to be at the courts on time so you can enjoy your full booked session. We look forward to seeing you soon!
                        </div>
                    ";
                    if (send_html_email($rem['client_email'], $subject, $body)) {
                        $pdo->prepare("UPDATE bookings SET reminded_3hr_player = 1 WHERE id = ?")->execute([$rem['booking_id']]);
                    }
                }
            }
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Fail silently on public pages, log error if needed
    }
}

/**
 * Computes booking fees based on active configuration
 */
function calculate_booking_fees($pdo, $duration_hours, $pax, $add_coaching, $exclude_court_fee = false) {
    // Fetch active configuration
    $stmt = $pdo->query("SELECT coaching_rate_per_pax_hour, court_rate_per_hour FROM pricing_config WHERE is_active = 1 LIMIT 1");
    $rates = $stmt->fetch();
    
    if (!$rates) {
        // Fallbacks
        $coaching_rate = 800.00;
        $court_rate = 500.00;
    } else {
        $coaching_rate = (float)$rates['coaching_rate_per_pax_hour'];
        $court_rate = (float)$rates['court_rate_per_hour'];
    }

    $court_fee = $exclude_court_fee ? 0.00 : ($court_rate * $duration_hours * $pax);
    $coaching_fee = $add_coaching ? ($coaching_rate * $pax * $duration_hours) : 0.00;
    $total_fee = $court_fee + $coaching_fee;

    return [
        'court_fee' => $court_fee,
        'coaching_fee' => $coaching_fee,
        'total_fee' => $total_fee,
        'coaching_rate' => $coaching_rate,
        'court_rate' => $court_rate
    ];
}

/**
 * Generates a standard unique booking code.
 */
function generate_booking_code($pdo) {
    $prefix = "BK-" . date("Y") . "-";
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE booking_code LIKE ?");
    $stmt->execute([$prefix . "%"]);
    $count = $stmt->fetchColumn() + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

/**
 * Logs an administrative action in the audit logs.
 */
function log_audit($pdo, $admin_id, $action, $entity_type, $entity_id, $old_vals = null, $new_vals = null) {
    $stmt = $pdo->prepare("
        INSERT INTO admin_audit_log 
          (admin_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) 
        VALUES 
          (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $admin_id,
        $action,
        $entity_type,
        $entity_id,
        $old_vals ? json_encode($old_vals) : null,
        $new_vals ? json_encode($new_vals) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}

/**
 * Generates a system-level notification.
 */
function create_notification($pdo, $user_id, $admin_id, $booking_id, $type, $message, $channel = 'in_app') {
    $stmt = $pdo->prepare("
        INSERT INTO notifications 
          (user_id, admin_id, booking_id, type, channel, message, is_read, sent_at) 
        VALUES 
          (?, ?, ?, ?, ?, ?, 0, NOW())
    ");
    $stmt->execute([$user_id, $admin_id, $booking_id, $type, $channel, $message]);
}

/**
 * Formats currency values
 */
function format_php($amount) {
    return "&#8369;" . number_format($amount, 2);
}

// PayMongo Production Keys (defined in config/credentials.php)
if (!defined('PAYMONGO_SECRET_KEY')) {
    define('PAYMONGO_SECRET_KEY', 'YOUR_PAYMONGO_SECRET_KEY');
}
if (!defined('PAYMONGO_PUBLIC_KEY')) {
    define('PAYMONGO_PUBLIC_KEY', 'YOUR_PAYMONGO_PUBLIC_KEY');
}

/**
 * Creates a PayMongo Checkout Session
 */
function create_paymongo_checkout($booking_code, $total_fee, $email, $name, $phone = '') {
    $url = 'https://api.paymongo.com/v1/checkout_sessions';
    
    // Amount must be in cents (subunit)
    $amount_in_cents = intval(round($total_fee * 100));
    
    // Standardize phone format if present, PayMongo expects international format or clean string
    $phone_clean = preg_replace('/[^0-9+]/', '', $phone);
    if (empty($phone_clean) || strlen($phone_clean) < 7) {
        $phone_clean = '639000000000'; // Standard PH phone fallback
    }
    
    $payload = [
        'data' => [
            'attributes' => [
                'billing' => [
                    'email' => $email,
                    'name' => $name,
                    'phone' => $phone_clean
                ],
                'payment_method_types' => ['card', 'gcash', 'paymaya', 'grab_pay', 'qrph', 'dob', 'billease'],
                'line_items' => [
                    [
                        'currency' => 'PHP',
                        'amount' => $amount_in_cents,
                        'description' => 'RDG Tennis Court & Coaching Session',
                        'name' => 'Court Booking: ' . $booking_code,
                        'quantity' => 1
                    ]
                ],
                'success_url' => 'http://localhost/RDG/actions/paymongo_callback.php?session_id={CHECKOUT_SESSION_ID}&booking_code=' . urlencode($booking_code),
                'cancel_url' => 'http://localhost/RDG/client/my_bookings.php?payment=cancel&code=' . urlencode($booking_code),
                'description' => 'Court Booking Reservation: ' . $booking_code,
                'show_description' => true,
                'show_line_items' => true
            ]
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':')
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $res_data = json_decode($response, true);
        if (isset($res_data['data']['attributes']['checkout_url'])) {
            return [
                'success' => true,
                'session_id' => $res_data['data']['id'],
                'checkout_url' => $res_data['data']['attributes']['checkout_url']
            ];
        }
    }
    
    error_log("PayMongo Session Creation Failed (HTTP $http_code): " . $response);
    return [
        'success' => false,
        'message' => 'PayMongo session creation failed. HTTP: ' . $http_code . ' Response: ' . $response
    ];
}

/**
 * Retrieves a PayMongo Checkout Session to verify payment state
 */
function retrieve_paymongo_session($session_id) {
    $url = 'https://api.paymongo.com/v1/checkout_sessions/' . $session_id;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':')
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $res_data = json_decode($response, true);
        return [
            'success' => true,
            'status' => $res_data['data']['attributes']['status'] ?? 'unknown',
            'payments' => $res_data['data']['attributes']['payments'] ?? [],
            'raw_payload' => $res_data
        ];
    }
    
    error_log("PayMongo Session Retrieval Failed (HTTP $http_code): " . $response);
    return [
        'success' => false,
        'message' => 'PayMongo session retrieval failed. HTTP: ' . $http_code
    ];
}

/**
 * Sends a beautifully designed neobrutalist HTML email to the athlete
 */
function send_html_email($to_email, $subject, $body_html) {
    // If not sent to admin directly, and not an OTP verification email, copy the admin to keep them in the loop about happenings
    $admin_email = 'rdgtennislesson@gmail.com';
    if ($to_email !== $admin_email && stripos($subject, 'OTP') === false && stripos($subject, 'Verification') === false) {
        $admin_subject = "[RDG System Activity] Copy to Admin - " . $subject;
        $admin_body_html = "
            <h2>System Activity Logged</h2>
            <p><strong>Recipient:</strong> " . htmlspecialchars($to_email) . "</p>
            <p><strong>Action Type:</strong> Transmitted Notification</p>
            <hr style='border: 1px solid #154212; margin: 20px 0;'/>
            " . $body_html;
        
        send_html_email($admin_email, $admin_subject, $admin_body_html);
    }

    // Auto-logging so developers can easily inspect all dispatched emails in logs/emails.log
    try {
        $log_dir = __DIR__ . '/../logs';
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0777, true);
        }
        $log_file = $log_dir . '/emails.log';
        $log_entry = "[" . date('Y-m-d H:i:s') . "] TO: $to_email | SUBJECT: $subject\n";
        
        $clean_body = strip_tags(str_replace(['<p>', '<h2', '<h3', '<li>', '<tr>', '</td>'], ["\n", "\n## ", "\n### ", "\n- ", "\n", " | "], $body_html));
        $clean_body = preg_replace("/\n\s*\n+/", "\n\n", $clean_body);
        
        $log_entry .= "BODY PREVIEW:\n$clean_body\n";
        $log_entry .= str_repeat("-", 80) . "\n\n";
        
        @file_put_contents($log_file, $log_entry, FILE_APPEND);
    } catch (Exception $e) {
        // Fail silently
    }

    $from_name = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'RDG Tennis Lesson';
    $from_email = defined('SMTP_FROM') ? SMTP_FROM : 'no-reply@rdgtennis.com';

    // Standard HTML email headers
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <" . $from_email . ">\r\n";
    $headers .= "Reply-To: support@rdgtennis.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    // Standard HTML email wrapper template with premium neobrutalist layout
    $full_html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($subject) . '</title>
        <style>
            body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; background-color: #F6F3F2; color: #1C1B1B; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 40px auto; background-color: #FFFFFF; border: 4px solid #154212; box-shadow: 8px 8px 0px #154212; padding: 0; }
            .header { background-color: #154212; color: #FFFFFF; padding: 30px; text-align: center; border-bottom: 4px solid #154212; }
            .header h1 { font-family: "Space Grotesk", sans-serif; font-size: 28px; font-weight: 900; margin: 0; text-transform: uppercase; letter-spacing: -1px; }
            .header p { font-size: 14px; margin: 5px 0 0 0; color: #bcf0ae; text-transform: uppercase; font-weight: bold; letter-spacing: 1px; }
            .content { padding: 40px 30px; line-height: 1.6; font-size: 15px; }
            .content h2 { font-family: "Space Grotesk", sans-serif; font-size: 20px; font-weight: 700; color: #154212; margin-top: 0; text-transform: uppercase; border-left: 5px solid #154212; padding-left: 10px; }
            .highlight-box { background-color: #FFE500; border: 2px solid #154212; padding: 20px; margin: 25px 0; box-shadow: 4px 4px 0px #154212; color: #154212; font-weight: bold; }
            .details-table { width: 100%; border-collapse: collapse; margin: 25px 0; border: 2px solid #154212; }
            .details-table th { background-color: #f0eded; padding: 12px; font-weight: bold; text-transform: uppercase; font-size: 11px; border: 1px solid #154212; color: #154212; text-align: left; }
            .details-table td { padding: 12px; border: 1px solid #154212; font-size: 14px; color: #42493e; }
            .btn { display: inline-block; background-color: #FFE500; color: #154212; text-decoration: none; padding: 15px 30px; font-weight: 900; text-transform: uppercase; border: 2px solid #154212; box-shadow: 4px 4px 0px #154212; margin: 20px 0; font-size: 13px; text-align: center; }
            .policy-box { background-color: #ffdad6; border: 2px solid #ba1a1a; padding: 20px; margin: 25px 0; color: #ba1a1a; font-size: 13px; }
            .policy-box h3 { margin-top: 0; text-transform: uppercase; font-size: 14px; }
            .footer { background-color: #f6f3f2; color: #72796e; padding: 20px 30px; text-align: center; font-size: 11px; border-top: 2px solid #e5e2e1; }
            .footer a { color: #154212; text-decoration: underline; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>RDG Tennis Portal</h1>
                <p>RESPECT and DOMINATE the GAME</p>
            </div>
            <div class="content">
                ' . $body_html . '
            </div>
            <div class="footer">
                <p>This is an automated system email from ' . htmlspecialchars($from_name) . '.</p>
                <p><a href="http://localhost/RDG/client/policies.php">View Facility Policies</a></p>
            </div>
        </div>
    </body>
    </html>';

    // A. Attempt real SMTP sending if enabled
    if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
        $smtp_sent = send_smtp_email_socket($to_email, $subject, $full_html);
        if ($smtp_sent) {
            return true;
        }
        return false;
    }

    // B. Fallback to native PHP mail() function
    $sent = @mail($to_email, $subject, $full_html, $headers);

    // If running on localhost or locally, always report success to prevent lack of SMTP servers from blocking workflows
    $is_localhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1']) || (empty($_SERVER['HTTP_HOST']) && php_sapi_name() === 'cli');
    if ($is_localhost) {
        return true;
    }

    return $sent;
}

/**
 * Socket-based SMTP mail client with SSL/TLS and Auth LOGIN support.
 * Designed to execute real email delivery without external PEAR/composer libraries.
 */
function send_smtp_email_socket($to, $subject, $body_html) {
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $username = SMTP_USER;
    $password = str_replace(' ', '', SMTP_PASS); // Strip spaces from Gmail App Passwords if copied with spaces
    $secure = SMTP_SECURE;
    $from = SMTP_FROM;
    $from_name = SMTP_FROM_NAME;

    $timeout = 10;
    
    // Connect to SMTP server
    $socket_url = $host;
    if ($secure === 'ssl') {
        $socket_url = 'ssl://' . $host;
    }
    
    $socket = @fsockopen($socket_url, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        error_log("SMTP Connection failed: $errstr ($errno)");
        return false;
    }
    
    // Read SMTP greeting
    fgets($socket, 515);
    
    // Helper closure to send SMTP commands and wait for full responses
    $send_cmd = function($cmd) use ($socket) {
        fputs($socket, $cmd . "\r\n");
        $resp = '';
        while ($line = fgets($socket, 515)) {
            $resp .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $resp;
    };
    
    // Say EHLO
    $send_cmd("EHLO localhost");
    
    // Upgrade to TLS if secure parameter is tls
    if ($secure === 'tls') {
        $starttls = $send_cmd("STARTTLS");
        if (strpos($starttls, '220') === false) {
            error_log("SMTP STARTTLS failed: $starttls");
            fclose($socket);
            return false;
        }
        // Negotiate TLS crypto stream handshake
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log("SMTP encryption negotiation failed.");
            fclose($socket);
            return false;
        }
        // Re-greet server over encrypted line
        $send_cmd("EHLO localhost");
    }
    
    // Perform AUTH LOGIN procedure if auth enabled
    if (defined('SMTP_AUTH') && SMTP_AUTH) {
        $auth = $send_cmd("AUTH LOGIN");
        if (strpos($auth, '334') === false) {
            error_log("SMTP AUTH LOGIN init failed: $auth");
            fclose($socket);
            return false;
        }
        
        $user_resp = $send_cmd(base64_encode($username));
        if (strpos($user_resp, '334') === false) {
            error_log("SMTP Username base64 rejected: $user_resp");
            fclose($socket);
            return false;
        }
        
        $pass_resp = $send_cmd(base64_encode($password));
        if (strpos($pass_resp, '235') === false) {
            error_log("SMTP Password base64 rejected: $pass_resp");
            fclose($socket);
            return false;
        }
    }
    
    // Declare Envelope Sender
    $mail_from = $send_cmd("MAIL FROM:<$from>");
    if (strpos($mail_from, '250') === false) {
        error_log("SMTP MAIL FROM failed: $mail_from");
        fclose($socket);
        return false;
    }
    
    // Declare Recipient
    $rcpt_to = $send_cmd("RCPT TO:<$to>");
    if (strpos($rcpt_to, '250') === false) {
        error_log("SMTP RCPT TO failed: $rcpt_to");
        fclose($socket);
        return false;
    }
    
    // Declare Data Initiation
    $data_init = $send_cmd("DATA");
    if (strpos($data_init, '354') === false) {
        error_log("SMTP DATA init failed: $data_init");
        fclose($socket);
        return false;
    }
    
    // Stream complete email payload
    $content = "MIME-Version: 1.0\r\n";
    $content .= "Content-Type: text/html; charset=UTF-8\r\n";
    $content .= "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <$from>\r\n";
    $content .= "To: <$to>\r\n";
    $content .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $content .= "Date: " . date('r') . "\r\n";
    $content .= "X-Mailer: PHP-Socket-SMTP\r\n";
    $content .= "\r\n";
    $content .= $body_html;
    $content .= "\r\n.";
    
    $data_send = $send_cmd($content);
    if (strpos($data_send, '250') === false) {
        error_log("SMTP DATA send failed: $data_send");
        fclose($socket);
        return false;
    }
    
    // Close session nicely
    $send_cmd("QUIT");
    fclose($socket);
    return true;
}

/**
 * Automatically reconcile a pending PayMongo payment for a reserved booking.
 * Checks PayMongo session status, confirms the booking and payment, creates the session, seeds attendance, and sends email.
 */
function reconcile_paymongo_payment($pdo, $booking_id) {
    // Fetch details
    $stmt = $pdo->prepare("
        SELECT b.*, p.id AS payment_id, p.status AS payment_status, p.paymongo_intent_id
          FROM bookings b
          JOIN payments p ON p.booking_id = b.id
         WHERE b.id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking || empty($booking['paymongo_intent_id'])) {
        return false;
    }

    $session_res = retrieve_paymongo_session($booking['paymongo_intent_id']);
    
    if (!$session_res['success']) {
        return false;
    }

    $status = $session_res['status'];
    $payments = $session_res['payments'] ?? [];

    $is_paid = ($status === 'completed');
    if (!$is_paid) {
        foreach ($payments as $pay) {
            if (($pay['attributes']['status'] ?? '') === 'paid') {
                $is_paid = true;
                break;
            }
        }
        $intent_status = $session_res['raw_payload']['data']['attributes']['payment_intent']['attributes']['status'] ?? '';
        if ($intent_status === 'succeeded') {
            $is_paid = true;
        }
    }

    if ($is_paid) {
        // Double check to make sure it's not already paid or confirmed in the DB to avoid redundant executions
        if ($booking['status'] === 'confirmed' && $booking['payment_status'] === 'paid') {
            return true;
        }

        $stmt_user = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $stmt_user->execute([$booking['user_id']]);
        $user_details = $stmt_user->fetch();
        $name = $user_details['full_name'] ?? 'Trainee';

        $pdo->beginTransaction();

        try {
            // A. Confirm Booking
            $pdo->prepare("UPDATE bookings SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?")
                ->execute([$booking['id']]);

            // B. Confirm Payment
            $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = NOW() WHERE id = ?")
                ->execute([$booking['payment_id']]);

            // C. Find consecutive schedules
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
            $pdo->prepare("INSERT IGNORE INTO sessions (booking_id, admin_id, status) VALUES (?, 1, 'scheduled')")
                ->execute([$booking['id']]);
            
            $stmt_chk_sess = $pdo->prepare("SELECT id FROM sessions WHERE booking_id = ? LIMIT 1");
            $stmt_chk_sess->execute([$booking['id']]);
            $session_id_db = $stmt_chk_sess->fetchColumn();

            // E. Seed Attendance log
            $stmt_att = $pdo->prepare("
                INSERT IGNORE INTO attendance (session_id, booking_id, user_id, attendee_name, status) 
                VALUES (?, ?, ?, ?, 'absent')
            ");
            $stmt_att->execute([$session_id_db, $booking['id'], $booking['user_id'], $name]);
            for ($i = 1; $i < (int)$booking['pax']; $i++) {
                $stmt_att->execute([$session_id_db, $booking['id'], null, $name . ' +' . $i]);
            }

            // F. Log event transaction
            $pdo->prepare("INSERT INTO payment_transactions (payment_id, paymongo_ref, event_type, payload) VALUES (?, ?, 'success', ?)")
                ->execute([$booking['payment_id'], $booking['paymongo_intent_id'], json_encode($session_res['raw_payload'])]);

            $pdo->commit();

            // Send notification
            $stmt_sched_times = $pdo->prepare("SELECT start_time FROM schedules WHERE id IN (" . implode(',', array_fill(0, count($schedules_to_update), '?')) . ") ORDER BY start_time ASC");
            $stmt_sched_times->execute($schedules_to_update);
            $sched_times = $stmt_sched_times->fetchAll(PDO::FETCH_COLUMN);
            $slot_list = implode(', ', array_map(fn($t) => date('h:i A', strtotime($t)), $sched_times));
            $session_date_formatted = date('M d, Y', strtotime($booking['booked_at']));
            $notif_msg = "Your court booking " . $booking['booking_code'] . " is confirmed! Date: " . $session_date_formatted . " (" . $booking['duration_hours'] . " hr(s): " . $slot_list . ")";

            create_notification($pdo, $booking['user_id'], null, $booking['id'], 'booking_confirmed', $notif_msg);
            create_notification($pdo, null, 1, $booking['id'], 'booking_confirmed', "Booking " . $booking['booking_code'] . " paid successfully via PayMongo.");

            // G. Send HTML receipt email
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
                        <td>' . htmlspecialchars($booking['booking_code']) . '</td>
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

            return true;
        } catch (Exception $ex) {
            $pdo->rollBack();
            error_log("Reconciliation transaction failed: " . $ex->getMessage());
            return false;
        }
    }
    return false;
}


