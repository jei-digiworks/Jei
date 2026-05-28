<?php
// auth/forgot_password.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

// Run cron simulator to keep schedules clean
run_cron_simulator($pdo);

$error = '';
$success = '';
$step = 1; // 1: Request OTP, 2: Verify OTP, 3: Reset Password

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

// Auto-advance to Step 2 if email and token are supplied via link
if (!empty($email) && !empty($token) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $step = 2;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'request_otp') {
        if (empty($email)) {
            $error = 'Please enter your registered email address.';
        } else {
            // Check if it is an Admin
            $stmt_admin = $pdo->prepare("SELECT * FROM admins WHERE email = ? AND is_active = 1 LIMIT 1");
            $stmt_admin->execute([$email]);
            $admin = $stmt_admin->fetch();

            // Check if it is a Verified Athlete
            $stmt_user = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'client' AND email_verified = 1 LIMIT 1");
            $stmt_user->execute([$email]);
            $user = $stmt_user->fetch();

            if ($admin || $user) {
                $user_type = $admin ? 'admin' : 'client';
                
                // Generate secure 6-digit numeric OTP
                $otp = (string)mt_rand(100000, 999999);
                
                // Delete previous reset attempts for this email
                $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
                
                // Insert new reset OTP (expires in 15 minutes)
                $stmt_insert = $pdo->prepare("
                    INSERT INTO password_resets (email, token, user_type, expires_at) 
                    VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
                ");
                $stmt_insert->execute([$email, $otp, $user_type]);

                // Create recovery URL link
                $reset_link = "http://localhost/RDG/auth/forgot_password.php?email=" . urlencode($email) . "&token=" . urlencode($otp);

                // Prepare beautiful, formal recovery HTML email based on user type
                if ($user_type === 'admin') {
                    $subject = "RDG Admin Alert: Security Credentials Recovery Code";
                    $body_html = "
                        <h2 style='color: #154212; border-bottom: 2px solid #154212; padding-bottom: 8px;'>Administrative Password Recovery</h2>
                        <p>Dear RDG Tennis Administrator,</p>
                        <p>This is an official security dispatch regarding a request to recover the password for the primary <strong>Administrator Portal Profile</strong> (`rdgtennislesson@gmail.com`).</p>
                        <p>To verify your identity and authorize the administrative password override, please use the secure 6-digit One-Time Password (OTP) generated below:</p>
                        
                        <div class='highlight-box' style='text-align: center; font-size: 32px; font-family: monospace; letter-spacing: 6px; padding: 18px; background-color: #FFE500; border: 3px solid #154212; box-shadow: 6px 6px 0px #154212; margin: 20px 0; font-weight: bold;'>
                            " . $otp . "
                        </div>
                        
                        <p>Alternatively, you may bypass manual code entry and securely change the administrative password directly by clicking the secure button link below:</p>
                        
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='" . $reset_link . "' class='btn' style='background-color: #FFE500; color: #154212; text-decoration: none; padding: 15px 35px; border: 3px solid #154212; box-shadow: 6px 6px 0px #154212; font-weight: 900; font-size: 14px; text-transform: uppercase; display: inline-block;'>Reset Admin Password Safely &rarr;</a>
                        </div>
                        
                        <p style='font-size: 12px; color: #ba1a1a; margin-top: 25px;'><strong>URGENT SECURITY NOTICE:</strong> If you did not initiate this recovery request, please execute security protocols immediately to secure the database, as someone may be attempting unauthorized administrative access.</p>
                        
                        <hr style='border: 0; border-top: 2px solid #e5e2e1; margin: 30px 0;' />
                        <p style='font-size: 12px; color: #72796e; text-align: center;'>Respect and Dominate the Game<br/><strong>RDG Security Operations</strong></p>
                    ";
                } else {
                    $subject = "Official Password Recovery Assistance - RDG Tennis System";
                    $body_html = "
                        <h2 style='color: #154212; border-bottom: 2px solid #154212; padding-bottom: 8px;'>Account Password Reset Assistance</h2>
                        <p>Dear RDG Tennis Member,</p>
                        <p>We received an official request to recover the password associated with this email address for your RDG Tennis System player profile. Security is our absolute priority, and we have generated a secure One-Time Password (OTP) to allow you to authenticate your session safely.</p>
                        
                        <p>Please enter the following 6-digit verification code in the password recovery window:</p>
                        
                        <div class='highlight-box' style='text-align: center; font-size: 32px; font-family: monospace; letter-spacing: 6px; padding: 18px; background-color: #FFE500; border: 3px solid #154212; box-shadow: 6px 6px 0px #154212; margin: 20px 0; font-weight: bold;'>
                            " . $otp . "
                        </div>
                        
                        <p>Alternatively, you can bypass manual code entry and securely change your password directly by clicking the secure button link below:</p>
                        
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='" . $reset_link . "' class='btn' style='background-color: #FFE500; color: #154212; text-decoration: none; padding: 15px 35px; border: 3px solid #154212; box-shadow: 6px 6px 0px #154212; font-weight: 900; font-size: 14px; text-transform: uppercase; display: inline-block;'>Reset Password Safely &rarr;</a>
                        </div>
                        
                        <p style='font-size: 12px; color: #72796e; margin-top: 25px;'><strong>Please Note:</strong> This security code and recovery link are strictly confidential and will expire in exactly 15 minutes. If you did not initiate this request, no action is required; your current credentials remain completely secure and active.</p>
                        
                        <hr style='border: 0; border-top: 2px solid #e5e2e1; margin: 30px 0;' />
                        <p style='font-size: 12px; color: #72796e; text-align: center;'>Respect and Dominate the Game<br/><strong>RDG Tennis Club Administration</strong></p>
                    ";
                }

                // Dispatch email
                if (send_html_email($email, $subject, $body_html)) {
                    $success = 'A recovery OTP and reset link have been sent to your verified email address.';
                    $step = 2;
                } else {
                    $error = 'Failed to transmit recovery email. Please check your system configuration.';
                }
            } else {
                $error = 'This email address is not registered or verified in our system.';
            }
        }
    } 
    elseif ($action === 'verify_otp') {
        if (empty($email) || empty($token)) {
            $error = 'Verification failed. Missing email or OTP code.';
        } else {
            // Validate OTP
            $stmt = $pdo->prepare("
                SELECT * FROM password_resets 
                 WHERE email = ? 
                   AND token = ? 
                   AND expires_at > NOW() 
                 LIMIT 1
            ");
            $stmt->execute([$email, $token]);
            $reset_request = $stmt->fetch();

            if ($reset_request) {
                // OTP valid! Save token in session state to authorize password override
                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_user_type'] = $reset_request['user_type'];
                $step = 3;
            } else {
                $error = 'Invalid, incorrect, or expired OTP code. Please request a new recovery OTP.';
            }
        }
    } 
    elseif ($action === 'reset_password') {
        $session_email = $_SESSION['reset_email'] ?? '';
        $session_user_type = $_SESSION['reset_user_type'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($session_email) || empty($session_user_type)) {
            $error = 'Session expired. Please restart the password recovery process.';
            $step = 1;
        } elseif (empty($new_password) || empty($confirm_password)) {
            $error = 'Please fill out all password fields.';
            $step = 3;
        } elseif ($new_password !== $confirm_password) {
            $error = 'Passwords do not match.';
            $step = 3;
        } elseif (strlen($new_password) < 6) {
            $error = 'Password must be at least 6 characters long.';
            $step = 3;
        } else {
            // Hash the new password
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);

            if ($session_user_type === 'admin') {
                $stmt_upd = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE email = ?");
                $stmt_upd->execute([$hashed, $session_email]);
            } else {
                $stmt_upd = $pdo->prepare("UPDATE users SET password_hash = ?, is_temp_password = 0 WHERE email = ? AND role = 'client'");
                $stmt_upd->execute([$hashed, $session_email]);
            }

            // Clean up reset requests
            $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$session_email]);

            // Notify user of successful change based on user type
            if ($session_user_type === 'admin') {
                $subject_confirm = "RDG Admin Alert: Administrative Password Changed Successfully";
                $confirm_body = "
                    <h2 style='color: #154212; border-bottom: 2px solid #154212; padding-bottom: 8px;'>Admin Security Notification: Credentials Updated</h2>
                    <p>Dear RDG Tennis Administrator,</p>
                    <p>This is a critical security confirmation that the login password for the primary <strong>Administrator Portal Profile</strong> (`rdgtennislesson@gmail.com`) was changed successfully.</p>
                    
                    <div class='highlight-box' style='padding: 20px; background-color: #f6f3f2; border: 2px solid #154212; margin: 20px 0; font-family: monospace; line-height: 1.6;'>
                        <strong>Account Type:</strong> Main System Administrator<br/>
                        <strong>Security Status:</strong> Credentials Updated Successfully<br/>
                        <strong>Timestamp:</strong> " . date('Y-m-d H:i:s T') . "
                    </div>
                    
                    <p>You can now proceed to log in to the Admin Portal using the updated credentials.</p>
                    
                    <p style='font-size: 12px; color: #ba1a1a; margin-top: 25px;'><strong>CRITICAL SECURITY NOTICE:</strong> If you did not perform or authorize this password update, please initiate emergency data security protocols immediately and check server audit logs.</p>
                    
                    <hr style='border: 0; border-top: 2px solid #e5e2e1; margin: 30px 0;' />
                    <p style='font-size: 12px; color: #72796e; text-align: center;'>Respect and Dominate the Game<br/><strong>RDG Security Operations</strong></p>
                ";
            } else {
                $subject_confirm = "Security Notification: Password Changed Successfully - RDG Tennis System";
                $confirm_body = "
                    <h2 style='color: #154212; border-bottom: 2px solid #154212; padding-bottom: 8px;'>Security Notification: Password Updated</h2>
                    <p>Dear RDG Tennis Member,</p>
                    <p>This email serves as official confirmation that the security credentials for your RDG Tennis System profile were updated successfully.</p>
                    
                    <div class='highlight-box' style='padding: 20px; background-color: #f6f3f2; border: 2px solid #154212; margin: 20px 0; font-family: monospace; line-height: 1.6;'>
                        <strong>Account Email:</strong> " . htmlspecialchars($session_email) . "<br/>
                        <strong>Security Status:</strong> Password Changed Successfully<br/>
                        <strong>Timestamp:</strong> " . date('Y-m-d H:i:s T') . "
                    </div>
                    
                    <p>You can now proceed to log in with your new password credentials.</p>
                    
                    <p style='font-size: 12px; color: #ba1a1a; margin-top: 25px;'><strong>Security Alert:</strong> If you did not authorize or perform this password reset, please contact the RDG Tennis Club Administration immediately to secure your account.</p>
                    
                    <hr style='border: 0; border-top: 2px solid #e5e2e1; margin: 30px 0;' />
                    <p style='font-size: 12px; color: #72796e; text-align: center;'>Respect and Dominate the Game<br/><strong>RDG Tennis Club Administration</strong></p>
                ";
            }
            send_html_email($session_email, $subject_confirm, $confirm_body);

            // Clean session variables
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_user_type']);

            $_SESSION['reset_success_msg'] = 'Your password has been successfully reset! You can now log in with your new password.';
            header("Location: /RDG/auth/login.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>RDG Tennis - Password Recovery</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700;900&family=Lexend:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script id="tailwind-config">
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    primary: "#154212",
                    "primary-container": "#2d5a27",
                    accent: "#FFB800",
                    background: "#fcf9f8",
                    "on-background": "#1c1b1b"
                },
                borderRadius: {
                    DEFAULT: "0px"
                }
            }
        }
    }
</script>
<style>
    body {
        background-image: linear-gradient(#e5e7eb 1px, transparent 1px), linear-gradient(90deg, #e5e7eb 1px, transparent 1px);
        background-size: 64px 64px;
        font-family: 'Lexend', sans-serif;
    }
    .headline {
        font-family: 'Space Grotesk', sans-serif;
    }
</style>
</head>
<body class="bg-background text-on-background min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-md bg-white border-4 border-primary shadow-[8px_8px_0px_rgba(21,66,18,1)] overflow-hidden">
        
        <!-- Header / Logo -->
        <div class="bg-primary text-white p-6 text-center border-b-4 border-primary flex flex-col items-center">
            <img alt="RDG Tennis Logo" class="h-14 w-auto object-contain mb-3" src="/RDG/RDG Logo.jpg"/>
            <h1 class="headline text-xl font-black tracking-tighter uppercase text-accent">Account Recovery</h1>
            <p class="text-[10px] uppercase tracking-widest text-[#a1d494] mt-1">RDG Tennis System</p>
        </div>

        <!-- System Notifications -->
        <?php if (!empty($error)): ?>
            <div class="bg-red-50 border-b-4 border-red-500 text-red-700 p-4 font-bold text-xs uppercase flex items-center gap-3">
                <span class="material-symbols-outlined text-red-500 font-bold">error</span>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="bg-green-50 border-b-4 border-green-500 text-green-700 p-4 font-bold text-xs uppercase flex items-center gap-3">
                <span class="material-symbols-outlined text-green-500 font-bold">check_circle</span>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <!-- STEP 1: REQUEST CODE -->
        <?php if ($step === 1): ?>
            <form method="POST" class="p-6 space-y-6">
                <input type="hidden" name="action" value="request_otp"/>
                
                <div class="space-y-2">
                    <p class="text-xs text-zinc-500 font-medium">Enter your registered email address below, and we will send you a secure One-Time Password (OTP) to recover your profile.</p>
                </div>

                <div>
                    <label class="block headline font-bold uppercase text-xs tracking-wider mb-2 text-primary">Registered Email</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-primary text-lg">mail</span>
                        <input type="email" name="email" required class="w-full pl-10 pr-4 py-3 border-2 border-primary focus:ring-0 focus:border-accent text-sm" placeholder="e.g. player@example.com" value="<?= htmlspecialchars($email) ?>"/>
                    </div>
                </div>

                <div class="flex flex-col gap-3">
                    <button type="submit" class="w-full bg-primary text-white border-2 border-primary py-4 headline uppercase tracking-widest font-black flex items-center justify-center gap-3 hover:bg-accent hover:text-primary transition-all duration-200 shadow-[4px_4px_0px_rgba(21,66,18,0.2)] hover:shadow-none">
                        Send Recovery Code <span class="material-symbols-outlined text-lg">send</span>
                    </button>
                    
                    <a href="login.php" class="text-center font-bold text-xs text-zinc-500 uppercase hover:underline py-2">
                        &larr; Back to Login
                    </a>
                </div>
            </form>
        <?php endif; ?>

        <!-- STEP 2: VERIFY CODE -->
        <?php if ($step === 2): ?>
            <form method="POST" class="p-6 space-y-6">
                <input type="hidden" name="action" value="verify_otp"/>
                <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>"/>
                
                <div class="space-y-2">
                    <p class="text-xs text-zinc-500 font-medium">A security code has been transmitted to <strong class="text-primary"><?= htmlspecialchars($email) ?></strong>. Enter the 6-digit OTP below to verify your identity.</p>
                </div>

                <div>
                    <label class="block headline font-bold uppercase text-xs tracking-wider mb-2 text-primary">6-Digit OTP Recovery Code</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-primary text-lg">pin</span>
                        <input type="text" maxlength="6" name="token" required class="w-full pl-10 pr-4 py-3 border-2 border-primary font-mono tracking-widest text-center text-lg focus:ring-0 focus:border-accent font-bold" placeholder="123456" value="<?= htmlspecialchars($token) ?>"/>
                    </div>
                </div>

                <div class="flex flex-col gap-3">
                    <button type="submit" class="w-full bg-accent text-primary border-2 border-primary py-4 headline uppercase tracking-widest font-black flex items-center justify-center gap-3 hover:bg-primary hover:text-white transition-all duration-200 shadow-[4px_4px_0px_rgba(21,66,18,0.2)] hover:shadow-none">
                        Verify Identity <span class="material-symbols-outlined text-lg">verified_user</span>
                    </button>
                    
                    <button type="submit" name="action" value="request_otp" class="text-center font-bold text-xs text-zinc-500 uppercase hover:underline py-2">
                        Resend Code &larr;
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <!-- STEP 3: RESET PASSWORD -->
        <?php if ($step === 3): ?>
            <form method="POST" class="p-6 space-y-6">
                <input type="hidden" name="action" value="reset_password"/>
                
                <div class="space-y-2">
                    <p class="text-xs text-zinc-500 font-medium">Identity verified successfully. Please choose a secure, new password for your account.</p>
                </div>

                <div>
                    <label class="block headline font-bold uppercase text-xs tracking-wider mb-2 text-primary">New Password</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-primary text-lg">lock</span>
                        <input type="password" minlength="6" name="new_password" required class="w-full pl-10 pr-4 py-3 border-2 border-primary focus:ring-0 focus:border-accent text-sm" placeholder="At least 6 characters"/>
                    </div>
                </div>

                <div>
                    <label class="block headline font-bold uppercase text-xs tracking-wider mb-2 text-primary">Confirm New Password</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-primary text-lg">lock_reset</span>
                        <input type="password" minlength="6" name="confirm_password" required class="w-full pl-10 pr-4 py-3 border-2 border-primary focus:ring-0 focus:border-accent text-sm" placeholder="Re-type new password"/>
                    </div>
                </div>

                <div class="flex flex-col gap-3">
                    <button type="submit" class="w-full bg-primary text-white border-2 border-primary py-4 headline uppercase tracking-widest font-black flex items-center justify-center gap-3 hover:bg-accent hover:text-primary transition-all duration-200 shadow-[4px_4px_0px_rgba(21,66,18,0.2)] hover:shadow-none">
                        Save New Password <span class="material-symbols-outlined text-lg">save</span>
                    </button>
                </div>
            </form>
        <?php endif; ?>

    </div>
</body>
</html>
