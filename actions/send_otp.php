<?php
// actions/send_otp.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$email = trim($_POST['email'] ?? '');
$full_name = trim($_POST['full_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Email address is required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if (empty($full_name)) {
    echo json_encode(['success' => false, 'message' => 'Full name is required before sending OTP.']);
    exit;
}

// Strictly validate phone number (PH Format: starting with 09 and exactly 11 digits)
if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Phone number is required.']);
    exit;
}
if (!preg_match('/^09\d{9}$/', $phone)) {
    echo json_encode(['success' => false, 'message' => 'Phone number must follow strict PH format (e.g., 09171234567, 11 digits starting with 09).']);
    exit;
}

try {
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'An account with this email address already exists.']);
        exit;
    }

    // Check if phone number already exists (optional, but good practice)
    $stmt_ph = $pdo->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
    $stmt_ph->execute([$phone]);
    if ($stmt_ph->fetch()) {
        echo json_encode(['success' => false, 'message' => 'An account with this phone number already exists.']);
        exit;
    }

    // Generate 6-digit OTP
    $otp = mt_rand(100000, 999999);
    
    // Store in Session
    $_SESSION['reg_otp'] = $otp;
    $_SESSION['reg_otp_email'] = $email;
    $_SESSION['reg_otp_time'] = time();

    // Send beautiful neobrutalist HTML email
    $email_body = '
    <h2>Verify Your Email Address</h2>
    <p>Hi ' . htmlspecialchars($full_name) . ',</p>
    <p>Thank you for initiating your athlete profile registration at RDG Tennis Facility. Please use the following One-Time Password (OTP) to verify your email address and complete your signup:</p>

    <div style="background-color: #FFE500; border: 3px solid #154212; padding: 20px; margin: 25px 0; box-shadow: 4px 4px 0px #154212; color: #154212; font-weight: 900; font-size: 32px; letter-spacing: 5px; text-align: center; font-family: \'Space Grotesk\', sans-serif;">
        ' . $otp . '
    </div>

    <p>This verification OTP is valid for the next 10 minutes. Enter this code into the registration form to finalize your profile setup.</p>
    
    <p>After successful verification, the system will automatically generate a secure temporary password and email it directly to you for logging in.</p>
    
    <div style="background-color: #f6f3f2; border-left: 5px solid #154212; padding: 15px; margin: 20px 0; font-size: 13px; color: #42493e;">
        <strong>Safety Note:</strong> If you did not request this OTP, please disregard this email. Your email remains safe.
    </div>';

    $subject = "Your RDG Tennis Verification OTP: " . $otp;
    $sent = send_html_email($email, $subject, $email_body);

    if ($sent) {
        echo json_encode(['success' => true, 'message' => 'Verification OTP sent successfully to ' . htmlspecialchars($email) . '!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP email. Please verify your mail settings.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
