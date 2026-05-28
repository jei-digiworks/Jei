<?php
// auth/login.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

// Run cron simulator to keep schedules clean
run_cron_simulator($pdo);

if (is_client_logged_in()) {
    header("Location: /RDG/client/book_slot.php");
    exit;
} elseif (is_admin_logged_in()) {
    header("Location: /RDG/admin/dashboard.php");
    exit;
}

$error = '';
$active_tab = 'client'; // default tab

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $login_type = $_POST['login_type'] ?? 'client';
    $active_tab = $login_type;

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all credentials.';
    } else {
        if ($login_type === 'admin') {
            // Admin authentication
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE email = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$email]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password_hash'])) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_name'] = $admin['full_name'];

                // Audit log
                log_audit($pdo, $admin['id'], 'login', 'admins', $admin['id']);

                $redirect = $_SESSION['redirect_url'] ?? '/RDG/admin/dashboard.php';
                unset($_SESSION['redirect_url']);
                header("Location: $redirect");
                exit;
            } else {
                $error = 'Invalid email or password for Admin Panel.';
            }
        } else {
            // Client authentication
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'client' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];

                if (!empty($user['is_temp_password'])) {
                    $_SESSION['must_change_password'] = true;
                    header("Location: /RDG/client/book_slot.php");
                    exit;
                }

                $redirect = $_SESSION['redirect_url'] ?? '/RDG/client/book_slot.php';
                unset($_SESSION['redirect_url']);
                header("Location: $redirect");
                exit;
            } else {
                $error = 'Invalid email or password for Athlete Portal.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>RDG Tennis - Login Gate</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700;900&family=Lexend:wght@300;400;500;600;700&display=swap"
        rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap"
        rel="stylesheet" />
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
            <img alt="RDG Tennis Logo" class="h-16 w-auto object-contain mb-3" src="/RDG/RDG Logo.jpg" />
            <p class="text-xs uppercase tracking-widest text-[#a1d494] mt-1">RESPECT and DOMINATE the
                GAME</p>
        </div>

        <!-- Error Notification -->
        <?php if (!empty($error)): ?>
            <div
                class="bg-red-50 border-y-4 border-red-500 text-red-700 p-4 font-bold text-xs uppercase flex items-center gap-3">
                <span class="material-symbols-outlined text-red-500 font-bold">error</span>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Tab Controls -->
        <div class="grid grid-cols-2 border-b-4 border-primary">
            <button onclick="switchTab('client')" id="tab-client"
                class="py-4 font-bold uppercase tracking-wider text-xs border-r-2 border-primary transition-all duration-150 <?= $active_tab === 'client' ? 'bg-accent text-primary' : 'bg-zinc-50 text-zinc-500 hover:bg-zinc-100' ?>">
                <span class="material-symbols-outlined align-middle mr-1 text-sm">sports_tennis</span> Athlete Portal
            </button>
            <button onclick="switchTab('admin')" id="tab-admin"
                class="py-4 font-bold uppercase tracking-wider text-xs transition-all duration-150 <?= $active_tab === 'admin' ? 'bg-accent text-primary' : 'bg-zinc-50 text-zinc-500 hover:bg-zinc-100' ?>">
                <span class="material-symbols-outlined align-middle mr-1 text-sm">manage_accounts</span> Admin Portal
            </button>
        </div>

        <!-- Login Form -->
        <form method="POST" action="login.php" class="p-6 space-y-6">
            <input type="hidden" name="login_type" id="login-type-input" value="<?= htmlspecialchars($active_tab) ?>" />

            <div>
                <label class="block headline font-bold uppercase text-xs tracking-wider mb-2 text-primary">Email
                    Address</label>
                <div class="relative">
                    <span
                        class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-primary text-lg">mail</span>
                    <input type="email" name="email" required
                        class="w-full pl-10 pr-4 py-3 border-2 border-primary focus:ring-0 focus:border-accent text-sm"
                        placeholder="e.g. player@example.com" value="<?= htmlspecialchars($email ?? '') ?>" />
                </div>
            </div>

            <div>
                <div class="mb-2">
                    <label
                        class="block headline font-bold uppercase text-xs tracking-wider text-primary">Password</label>
                </div>
                <div class="relative">
                    <span
                        class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-primary text-lg">lock</span>
                    <input type="password" name="password" required
                        class="w-full pl-10 pr-4 py-3 border-2 border-primary focus:ring-0 focus:border-accent text-sm"
                        placeholder="••••••••" />
                </div>
                <div class="mt-2 text-right">
                    <a href="/RDG/auth/forgot_password.php"
                        class="text-[10px] font-black text-primary underline hover:text-accent uppercase tracking-wider">Forgot Password?</a>
                </div>
            </div>

            <button type="submit"
                class="w-full bg-primary text-white border-2 border-primary py-4 headline uppercase tracking-widest font-black flex items-center justify-center gap-3 hover:bg-accent hover:text-primary transition-all duration-200 shadow-[4px_4px_0px_rgba(21,66,18,0.2)] hover:shadow-none">
                Access Gateway <span class="material-symbols-outlined text-lg">login</span>
            </button>
        </form>

        <!-- Register Link for Clients -->
        <div id="register-footer"
            class="p-6 bg-zinc-50 border-t-2 border-primary text-center text-xs <?= $active_tab === 'client' ? '' : 'hidden' ?>">
            <span class="text-zinc-600">New athlete?</span>
            <a href="register.php" class="font-bold text-primary underline hover:text-accent ml-1 uppercase">Register
                Account &rarr;</a>
        </div>
    </div>

    <script>
        function switchTab(type) {
            document.getElementById('login-type-input').value = type;

            const clientBtn = document.getElementById('tab-client');
            const adminBtn = document.getElementById('tab-admin');
            const regFooter = document.getElementById('register-footer');

            if (type === 'client') {
                clientBtn.className = 'py-4 font-bold uppercase tracking-wider text-xs border-r-2 border-primary transition-all duration-150 bg-accent text-primary';
                adminBtn.className = 'py-4 font-bold uppercase tracking-wider text-xs transition-all duration-150 bg-zinc-50 text-zinc-500 hover:bg-zinc-100';
                regFooter.classList.remove('hidden');
            } else {
                clientBtn.className = 'py-4 font-bold uppercase tracking-wider text-xs border-r-2 border-primary transition-all duration-150 bg-zinc-50 text-zinc-500 hover:bg-zinc-100';
                adminBtn.className = 'py-4 font-bold uppercase tracking-wider text-xs transition-all duration-150 bg-accent text-primary';
                regFooter.classList.add('hidden');
            }
        }
    </script>
</body>

</html>