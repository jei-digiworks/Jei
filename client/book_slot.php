<?php
// client/book_slot.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

require_client();

$change_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'force_change_password') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $change_error = 'Please fill in all password fields.';
    } elseif ($new_password !== $confirm_password) {
        $change_error = 'Passwords do not match.';
    } elseif (strlen($new_password) < 8) {
        $change_error = 'Password must be at least 8 characters long.';
    } else {
        $user_id = $_SESSION['user_id'];
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
        
        try {
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, is_temp_password = 0 WHERE id = ?");
            $stmt->execute([$new_hash, $user_id]);
            
            unset($_SESSION['must_change_password']);
            $_SESSION['register_success_msg'] = 'Password updated successfully! Welcome to the Player Portal.';
            header("Location: book_slot.php");
            exit;
        } catch (PDOException $e) {
            $change_error = 'Failed to update password: ' . $e->getMessage();
        }
    }
}

// Run cron simulation on load
run_cron_simulator($pdo);

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// Fetch verified user email from database
$stmt_user_email = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
$stmt_user_email->execute([$user_id]);
$user_email = $stmt_user_email->fetchColumn();

// Retrieve dynamic pricing configurations
$stmt_rates = $pdo->query("SELECT coaching_rate_per_pax_hour, court_rate_per_hour FROM pricing_config WHERE is_active = 1 LIMIT 1");
$rates = $stmt_rates->fetch();
$court_rate = (float)($rates['court_rate_per_hour'] ?? 500.00);
$coaching_rate = (float)($rates['coaching_rate_per_pax_hour'] ?? 350.00);

// Get minimum advance days constraint
$stmt_config = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'min_booking_advance_days' LIMIT 1");
$min_advance_days = (int)($stmt_config->fetchColumn() ?? 7);

$first_bookable_date = date('Y-m-d', strtotime("+$min_advance_days days"));
$first_bookable_formatted = date('F d, Y', strtotime($first_bookable_date));

// Fetch unread notifications count
$stmt_notif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt_notif->execute([$user_id]);
$unread_count = $stmt_notif->fetchColumn();

// Fetch open bookable months (for bookability)
$stmt_months = $pdo->query("SELECT month_year FROM bookable_months WHERE is_open = 1 ORDER BY month_year ASC");
$open_months = $stmt_months->fetchAll(PDO::FETCH_COLUMN);

// Fetch ALL bookable months (for navigation — full range configured by admin)
$stmt_all_months = $pdo->query("SELECT month_year FROM bookable_months ORDER BY month_year ASC");
$all_months_list = $stmt_all_months->fetchAll(PDO::FETCH_COLUMN);

// Nav min = first month admin ever configured (e.g. 2026-01)
// Nav max = last month admin configured (e.g. 2028-01)
$nav_min_month = !empty($all_months_list) ? $all_months_list[0] : date('Y-m');
$nav_max_month = !empty($all_months_list) ? end($all_months_list) : date('Y-m');

// Fetch Sunday Locked configuration
$stmt_sun = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'sunday_locked' LIMIT 1");
$sunday_locked = $stmt_sun->fetchColumn();
if ($sunday_locked === false) {
    $sunday_locked = '1'; // Default to locked if not set
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>RDG Tennis - Book a Slot</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700;900&family=Lexend:wght@300;400;500;600;700&family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
    .court-grid {
        background-image: linear-gradient(#e5e7eb 1px, transparent 1px), linear-gradient(90deg, #e5e7eb 1px, transparent 1px);
        background-size: 64px 64px;
    }
    .material-symbols-outlined {
        font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
    }
    input:focus, select:focus {
        outline: none;
        border-color: #154212 !important;
        box-shadow: none;
    }
</style>
<script id="tailwind-config">
    tailwind.config = {
        darkMode: "class",
        theme: {
            extend: {
                "colors": {
                    "tertiary-fixed-dim": "#c5c7c8","tertiary-container": "#4e5051","surface-variant": "#e5e2e1","on-error-container": "#93000a","on-primary-fixed-variant": "#23501e","on-secondary-container": "#704e00","on-secondary": "#ffffff","surface-container-lowest": "#ffffff","inverse-surface": "#313030","secondary-fixed-dim": "#fabc46","primary-fixed-dim": "#a1d494","on-tertiary-fixed": "#191c1d","on-surface": "#1c1b1b","surface-dim": "#dcd9d9","primary-fixed": "#bcf0ae","surface-container-high": "#eae7e7","secondary-container": "#fdbe49","surface-container": "#f0eded","primary": "#154212","on-primary-container": "#9dd090","error-container": "#ffdad6","on-tertiary-container": "#c1c2c3","surface-container-highest": "#e5e2e1","on-primary-fixed": "#002201","error": "#ba1a1a","surface-container-low": "#f6f3f2","on-secondary-fixed-variant": "#5f4100","primary-container": "#2d5a27","tertiary": "#37393a","secondary-fixed": "#ffdeab","background": "#fcf9f8","inverse-primary": "#a1d494","outline-variant": "#c2c9bb","outline": "#72796e","on-background": "#1c1b1b","on-primary": "#ffffff","on-error": "#ffffff","on-secondary-fixed": "#271900","surface-bright": "#fcf9f8","surface": "#fcf9f8","on-tertiary": "#ffffff","on-surface-variant": "#42493e","surface-tint": "#3b6934","inverse-on-surface": "#f3f0ef","secondary": "#7d5700","on-tertiary-fixed-variant": "#454748","tertiary-fixed": "#e1e3e4"
                },
                "borderRadius": {"DEFAULT": "0px","lg": "0px","xl": "0px","full": "9999px"},
                "spacing": {"md": "24px","sm": "16px","lg": "48px","gutter": "24px","margin": "32px","xs": "8px","base": "4px","xl": "64px"},
                "fontFamily": {"headline-lg": ["Space Grotesk"],"table-data": ["Lexend"],"body-lg": ["Lexend"],"body-md": ["Lexend"],"headline-md": ["Space Grotesk"],"label-bold": ["Lexend"],"headline-xl": ["Space Grotesk"],"body-sm": ["Lexend"]},
                "fontSize": {"headline-lg": ["32px", {"lineHeight": "1.2","letterSpacing": "-0.01em","fontWeight": "700"}],"table-data": ["14px", {"lineHeight": "1.2","fontWeight": "450"}],"body-lg": ["18px", {"lineHeight": "1.6","fontWeight": "400"}],"body-md": ["16px", {"lineHeight": "1.5","fontWeight": "400"}],"headline-md": ["24px", {"lineHeight": "1.2","fontWeight": "600"}],"label-bold": ["12px", {"lineHeight": "1","letterSpacing": "0.05em","fontWeight": "600"}],"headline-xl": ["48px", {"lineHeight": "1.1","letterSpacing": "-0.02em","fontWeight": "700"}],"body-sm": ["14px", {"lineHeight": "1.4","fontWeight": "400"}]}
            }
        }
    }
</script>
</head>
<body class="bg-background text-on-background font-body-md court-grid min-h-screen">
<?php if (!empty($_SESSION['must_change_password'])): ?>
    <!-- Fullscreen Password Change Overlay Modal -->
    <div class="fixed inset-0 z-[100] bg-black/80 backdrop-blur-md flex items-center justify-center p-4">
        <div class="bg-white border-4 border-primary max-w-md w-full p-6 shadow-[8px_8px_0px_rgba(21,66,18,1)] flex flex-col space-y-6">
            
            <!-- Header -->
            <div class="flex items-center gap-3 border-b-4 border-primary pb-4">
                <span class="material-symbols-outlined text-primary text-4xl font-bold">lock_reset</span>
                <div>
                    <h3 class="font-['Space_Grotesk'] font-black text-lg uppercase text-primary leading-none">Security Required</h3>
                    <span class="text-[9px] text-zinc-500 font-bold uppercase tracking-wider">Change Your Password to Proceed</span>
                </div>
            </div>
            
            <p class="text-xs text-zinc-600 font-bold leading-relaxed">
                You logged in using a secure temporary password. For security reasons, you **must update your password** before accessing the player portal.
            </p>

            <!-- Error Banner -->
            <?php if (!empty($change_error)): ?>
                <div class="bg-red-50 border-2 border-red-500 text-red-700 p-3 font-bold text-xs uppercase flex items-center gap-2">
                    <span class="material-symbols-outlined text-red-500 text-sm font-bold">error</span>
                    <span><?= htmlspecialchars($change_error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" action="book_slot.php" class="space-y-4">
                <input type="hidden" name="action" value="force_change_password"/>
                
                <div>
                    <label class="block font-['Space_Grotesk'] font-bold uppercase text-xs tracking-wider mb-2 text-primary">New Password *</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-primary text-lg">lock</span>
                        <input type="password" name="new_password" required minlength="8" class="w-full pl-10 pr-4 py-3 border-2 border-primary focus:ring-0 focus:border-accent text-sm" placeholder="At least 8 characters"/>
                    </div>
                </div>

                <div>
                    <label class="block font-['Space_Grotesk'] font-bold uppercase text-xs tracking-wider mb-2 text-primary">Confirm New Password *</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-primary text-lg">lock</span>
                        <input type="password" name="confirm_password" required minlength="8" class="w-full pl-10 pr-4 py-3 border-2 border-primary focus:ring-0 focus:border-accent text-sm" placeholder="Repeat new password"/>
                    </div>
                </div>

                <button type="submit" class="w-full bg-primary text-white border-2 border-primary py-4 font-['Space_Grotesk'] uppercase tracking-widest font-black flex items-center justify-center gap-3 hover:bg-[#FFB800] hover:text-primary transition-all duration-200 shadow-[4px_4px_0px_rgba(21,66,18,0.2)] hover:shadow-none">
                    Update Password & Login <span class="material-symbols-outlined text-lg">vpn_key</span>
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>
<!-- TopAppBar -->
<header class="fixed top-0 left-0 right-0 z-50 bg-white dark:bg-zinc-950 flex justify-between items-center w-full px-6 h-20 border-b-2 border-primary transition-colors duration-150 ease-linear">
    <div class="flex items-center gap-4">
        <img alt="RDG Tennis Logo" class="h-10 w-auto object-contain" src="/RDG/RDG Logo.jpg"/>
    </div>
    <div class="flex items-center gap-4">
        <button onclick="toggleNotifDropdown()" class="relative flex items-center justify-center p-2 hover:bg-surface-container-low transition-colors duration-200">
            <span class="material-symbols-outlined text-2xl text-on-surface">notifications</span>
            <?php if ($unread_count > 0): ?>
                <span class="absolute top-2 right-2 w-2.5 h-2.5 bg-accent rounded-full border border-white"></span>
            <?php endif; ?>
        </button>
        <div id="notif-dropdown" class="hidden absolute top-16 right-24 w-80 bg-white border-2 border-primary shadow-[4px_4px_0px_rgba(21,66,18,1)] z-50 py-2">
            <div class="px-4 py-2 border-b-2 border-primary flex justify-between items-center bg-zinc-50">
                <span class="font-['Space_Grotesk'] font-bold text-xs uppercase text-primary">Notifications</span>
                <button onclick="markAllNotificationsRead()" class="text-[10px] font-bold text-primary hover:underline uppercase">Mark all read</button>
            </div>
            <div id="notif-items" class="divide-y divide-zinc-100 max-h-60 overflow-y-auto">
                <!-- Loaded via AJAX -->
            </div>
        </div>
        <div class="h-8 w-[1px] bg-outline-variant mx-2"></div>
        <div class="flex items-center gap-2">
            <span class="hidden md:inline font-bold text-xs uppercase tracking-wider text-primary"><?= htmlspecialchars($user_name) ?></span>
            <a href="/RDG/auth/logout.php" class="flex items-center justify-center w-10 h-10 rounded-full bg-primary text-accent hover:opacity-90 transition-opacity duration-200" title="Logout">
                <span class="material-symbols-outlined text-xl">logout</span>
            </a>
        </div>
    </div>
</header>

<!-- SideNavBar -->
<aside class="fixed left-0 top-20 h-[calc(100vh-80px)] w-64 bg-[#F8F9FA] dark:bg-zinc-900 border-r-2 border-primary flex flex-col gap-1 py-4 z-40">
    <div class="px-6 py-4 mb-4 border-b border-[#E5E7EB]">
        <p class="font-['Space_Grotesk'] font-bold uppercase text-sm text-primary">Player Portal</p>
        <p class="text-xs text-on-surface-variant font-label-bold">RDG Athlete</p>
    </div>
    <nav class="flex flex-col gap-1">
        <a class="bg-[#FFE500] text-primary border-l-4 border-primary px-6 py-3 flex items-center gap-3 transition-all duration-200 font-['Space_Grotesk'] font-bold uppercase text-sm" href="book_slot.php">
            <span class="material-symbols-outlined">sports_tennis</span> Book Slot
        </a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 transition-all duration-200 font-['Space_Grotesk'] font-bold uppercase text-sm" href="history.php">
            <span class="material-symbols-outlined">history</span> History
        </a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 transition-all duration-200 font-['Space_Grotesk'] font-bold uppercase text-sm" href="my_bookings.php">
            <span class="material-symbols-outlined">confirmation_number</span> My Bookings
        </a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 transition-all duration-200 font-['Space_Grotesk'] font-bold uppercase text-sm" href="policies.php">
            <span class="material-symbols-outlined">policy</span> Policies
        </a>
    </nav>
</aside>

<!-- Main Content Area -->
<main class="ml-64 pt-20 min-h-screen p-gutter">
    <div class="max-w-6xl mx-auto space-y-lg">
        <?php if (!empty($_SESSION['register_success_msg'])): ?>
            <div class="bg-yellow-50 border-4 border-primary p-4 shadow-[4px_4px_0px_rgba(21,66,18,1)] flex items-center justify-between gap-4 mb-6">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-primary text-2xl font-bold">check_circle</span>
                    <span class="font-bold text-xs uppercase text-primary"><?= $_SESSION['register_success_msg'] ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-primary hover:text-accent font-bold text-xs uppercase">Close</button>
            </div>
            <?php unset($_SESSION['register_success_msg']); ?>
        <?php endif; ?>
        <!-- Page Header -->
        <div class="flex justify-between items-end border-b-4 border-primary pb-4 bg-white/80 backdrop-blur-sm p-6 sticky top-20 z-30">
            <div>
                <h1 class="font-headline-xl text-primary uppercase tracking-tighter">Reserve Your Court</h1>
                <p class="font-body-lg text-on-surface-variant max-w-xl">
                    Experience professional-grade tennis facilities. Choose your time, select your coaching preference, and step onto the court.
                    <br/><strong class="text-primary">Note: Advance bookings must be placed at least <?= $min_advance_days ?> days ahead. First bookable date is: <?= $first_bookable_formatted ?>.</strong>
                </p>
            </div>
        </div>

        <!-- Pricing Section -->
        <section class="grid grid-cols-1 lg:grid-cols-12 gap-gutter items-start">
            <div class="lg:col-span-12">
                <div class="flex items-center gap-4 mb-6">
                    <div class="w-2 h-8 bg-primary"></div>
                    <h2 class="font-headline-md text-primary uppercase font-black">Hourly Court Rates</h2>
                </div>
                <div class="overflow-x-auto border-2 border-primary shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                    <table class="w-full text-left">
                        <thead class="bg-primary text-white border-b-2 border-primary">
                            <tr>
                                <th class="p-4 font-label-bold uppercase tracking-widest text-xs">Service Level</th>
                                <th class="p-4 font-label-bold uppercase tracking-widest text-xs">Peak Hours</th>
                                <th class="p-4 font-label-bold uppercase tracking-widest text-xs">Off-Peak</th>
                            </tr>
                        </thead>
                        <tbody class="font-table-data">
                            <tr class="bg-white border-b border-outline-variant">
                                <td class="p-4 font-bold">Player Coaching Add-on</td>
                                <td class="p-4"><?= format_php($coaching_rate) ?> / hr per pax</td>
                                <td class="p-4"><?= format_php($coaching_rate) ?> / hr per pax</td>
                            </tr>
                            <tr class="bg-white">
                                <td class="p-4 font-bold">Court Rental (Base)</td>
                                <td class="p-4"><?= format_php($court_rate) ?> / hr</td>
                                <td class="p-4"><?= format_php($court_rate) ?> / hr</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Booking Flow Grid -->
        <div class="grid grid-cols-12 gap-gutter items-start">
            <!-- Step 1 & 2 -->
            <div class="col-span-12 lg:col-span-8 space-y-md">
                
                <!-- Calendar Section -->
                <section class="bg-white border-2 border-primary p-md relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                    <div class="absolute -top-3 -left-3 bg-primary text-white px-3 py-1 font-headline-md text-sm uppercase">Step 01: Date</div>
                    <div class="flex items-center justify-between mb-sm border-b border-[#E5E7EB] pb-xs">
                        <h2 id="calendar-title" class="font-headline-md text-primary uppercase font-bold">Loading...</h2>
                        <div class="flex gap-2">
                            <button id="btn-prev-month" onclick="prevMonth()" class="p-1 border border-primary hover:bg-[#F8F9FA] disabled:opacity-30 disabled:cursor-not-allowed"><span class="material-symbols-outlined align-middle">chevron_left</span></button>
                            <button id="btn-next-month" onclick="nextMonth()" class="p-1 border border-primary hover:bg-[#F8F9FA] disabled:opacity-30 disabled:cursor-not-allowed"><span class="material-symbols-outlined align-middle">chevron_right</span></button>
                        </div>
                    </div>

                    
                    <div class="grid grid-cols-7 gap-1 text-center font-label-bold text-on-surface-variant mb-2 text-xs">
                        <div>MON</div><div>TUE</div><div>WED</div><div>THU</div><div>FRI</div><div>SAT</div><div>SUN</div>
                    </div>
                    <div id="calendar-days" class="grid grid-cols-7 gap-1">
                        <!-- Loaded via JS -->
                    </div>

                </section>

                <!-- Slot Picker Section -->
                <section class="bg-white border-2 border-primary p-md relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                    <div class="absolute -top-3 -left-3 bg-primary text-white px-3 py-1 font-headline-md text-sm uppercase">Step 02: Slot Selection</div>
                    <div class="flex items-center justify-between mb-sm border-b border-[#E5E7EB] pb-xs">
                        <h2 id="slots-selected-date" class="font-headline-md text-primary uppercase font-bold">Please Select a Date</h2>
                        <div class="flex items-center gap-2">
                            <span id="slots-count-badge" class="hidden font-label-bold text-primary bg-accent px-2 py-1 text-xs">0 SLOTS OPEN</span>
                            <button onclick="manualRefreshSlots()" title="Refresh slots"
                                    class="flex items-center gap-1 px-2 py-1 border border-primary text-primary text-[9px] font-black uppercase hover:bg-primary hover:text-white transition-colors">
                                <span class="material-symbols-outlined text-xs" id="refresh-icon">sync</span>
                                <span id="slot-live-countdown">Live</span>
                            </button>
                        </div>
                    </div>
                    <div id="slots-grid" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-4 gap-sm min-h-[80px] items-center justify-center text-center text-zinc-400 text-xs">
                        Please select an available booking date above to load session blocks.
                    </div>
                </section>
            </div>

            <!-- Step 3: Summary -->
            <div class="col-span-12 lg:col-span-4 sticky top-[160px]">
                <div class="bg-[#F8F9FA] border-4 border-primary relative shadow-[6px_6px_0px_rgba(21,66,18,1)]">
                    <div class="absolute -top-3 -left-3 bg-primary text-white px-3 py-1 font-headline-md text-sm uppercase">Step 03: Summary</div>
                    <div class="p-6 pt-10 space-y-6">
                        
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-primary text-3xl">event_available</span>
                            <div>
                                <p class="font-headline-md text-sm uppercase font-black">RDG Tennis Facility</p>
                                <p id="summary-datetime" class="font-body-sm text-on-surface-variant font-bold">No date or slot selected.</p>
                            </div>
                        </div>
                        
                        <hr class="border-primary/20"/>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="font-label-bold uppercase block mb-1 text-primary text-xs">Players (Pax)</label>
                                <div class="flex items-center gap-4">
                                    <button onclick="decrementPax()" class="w-10 h-10 border-2 border-primary flex items-center justify-center font-bold hover:bg-primary hover:text-white">-</button>
                                    <span id="pax-display" class="font-headline-md text-primary font-black">1</span>
                                    <button onclick="incrementPax()" class="w-10 h-10 border-2 border-primary flex items-center justify-center font-bold hover:bg-primary hover:text-white">+</button>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 border border-outline-variant bg-zinc-50 select-none">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-primary">sports</span>
                                    <div>
                                        <p class="font-label-bold uppercase text-[10px] text-primary">Professional Coaching</p>
                                        <p class="text-xs text-zinc-500 font-bold">Automatically Selected</p>
                                    </div>
                                </div>
                                <input id="coaching-checkbox" checked disabled class="w-6 h-6 text-primary border-2 border-primary rounded-none focus:ring-0 cursor-not-allowed bg-accent" type="checkbox"/>
                            </div>

                            <!-- Already Paid Court Rental Checkbox -->
                            <div class="flex items-center justify-between p-3 border border-outline-variant bg-white">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-primary">payments</span>
                                    <div>
                                        <p class="font-label-bold uppercase text-[10px] text-primary">Court Fee Paid</p>
                                        <p class="text-xs text-zinc-500">Already paid court rental</p>
                                    </div>
                                </div>
                                <input id="court-paid-checkbox" onchange="toggleCourtPaid()" class="w-6 h-6 text-primary border-2 border-primary rounded-none focus:ring-0 cursor-pointer" type="checkbox"/>
                            </div>
                        </div>

                        <!-- Live Cost Breakdown -->
                        <div class="bg-white p-4 border-2 border-primary space-y-2">
                            <div class="flex justify-between font-body-sm">
                                <span>Court Fee</span>
                                <span id="breakdown-court" class="font-table-data font-bold">&#8369;0.00</span>
                            </div>
                            <div class="flex justify-between font-body-sm text-primary">
                                <span>Coaching Add-on</span>
                                <span id="breakdown-coaching" class="font-table-data font-bold">&#8369;0.00</span>
                            </div>
                            <hr class="border-dashed border-[#E5E7EB] my-2"/>
                            <div class="flex justify-between items-end">
                                <span class="font-headline-md text-xs uppercase font-black text-primary">Total Fee</span>
                                <span id="breakdown-total" class="font-headline-lg text-primary font-black">&#8369;0.00</span>
                            </div>
                        </div>

                        <!-- Payment Methods Selector -->
                        <div class="space-y-3">
                            <label class="block font-label-bold uppercase text-[10px] tracking-wider text-primary">Payment Method</label>
                            <div class="grid grid-cols-2 border-2 border-primary text-center">
                                <button onclick="selectPaymentMethod('pay_now')" id="method-pay-now" class="py-2.5 bg-primary text-white font-headline-md text-[10px] uppercase font-bold flex items-center justify-center gap-1">
                                    <span class="material-symbols-outlined text-xs">payments</span> Pay Now
                                </button>
                                <button onclick="selectPaymentMethod('pay_later')" id="method-pay-later" class="py-2.5 bg-white text-primary border-l-2 border-primary font-headline-md text-[10px] uppercase font-bold">
                                    Pay Later
                                </button>
                            </div>
                            <p id="payment-policy-note" class="text-[10px] text-center italic text-on-surface-variant px-4">
                                * Pay Now processes dynamic checkout via secure PayMongo mock engine instantly.
                            </p>
                        </div>

                        <!-- Selection Warning Banner -->
                        <div id="selection-warning" class="hidden bg-red-50 border-2 border-red-500 text-red-700 p-3 font-bold text-xs uppercase flex items-start gap-2">
                            <span class="material-symbols-outlined text-red-500 text-sm font-bold mt-0.5">warning</span>
                            <span id="selection-warning-text">Strict selection rule: You must select exactly two time slots to book.</span>
                        </div>

                        <button onclick="handleConfirmBooking()" class="w-full bg-accent text-primary border-2 border-primary py-4 font-headline-md uppercase tracking-widest font-black flex items-center justify-center gap-3 hover:bg-primary hover:text-white transition-all">
                            Confirm Booking <span class="material-symbols-outlined font-black">arrow_forward</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Mock PayMongo Card Payment Overlay Modal -->
<div id="paymongo-modal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border-4 border-primary max-w-sm w-full p-6 shadow-[8px_8px_0px_rgba(21,66,18,1)] flex flex-col space-y-6">
        <div class="flex items-center gap-3 border-b-2 border-primary pb-3">
            <span class="material-symbols-outlined text-primary text-3xl">credit_card</span>
            <div>
                <h3 class="font-['Space_Grotesk'] font-bold text-sm uppercase text-primary leading-none">PayMongo Secure Checkout</h3>
                <span class="text-[9px] text-zinc-500 font-bold uppercase tracking-wider">Test Sandbox Environment</span>
            </div>
        </div>
        <div class="bg-zinc-50 p-3 border border-primary text-center">
            <span class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest">Amount Due</span>
            <p id="modal-payment-amount" class="font-['Space_Grotesk'] font-black text-2xl text-primary">&#8369;0.00</p>
        </div>
        <div class="space-y-4">
            <div>
                <label class="block font-label-bold uppercase text-[9px] text-primary mb-1">Card Number</label>
                <input id="card-number" class="w-full border-2 border-primary p-2 focus:ring-0 focus:border-accent text-sm tracking-widest" type="text" placeholder="4111 •••• •••• 1111" maxlength="19"/>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-label-bold uppercase text-[9px] text-primary mb-1">Expiry Date</label>
                    <input id="card-expiry" class="w-full border-2 border-primary p-2 focus:ring-0 focus:border-accent text-sm text-center" type="text" placeholder="MM/YY" maxlength="5"/>
                </div>
                <div>
                    <label class="block font-label-bold uppercase text-[9px] text-primary mb-1">CVV</label>
                    <input id="card-cvv" class="w-full border-2 border-primary p-2 focus:ring-0 focus:border-accent text-sm text-center" type="password" placeholder="•••" maxlength="3"/>
                </div>
            </div>
        </div>
        <div class="flex items-center justify-center gap-2 grayscale opacity-60">
            <span class="text-[9px] font-label-bold uppercase font-bold text-zinc-600">Verified by</span>
            <span class="font-black text-xs tracking-tighter text-primary">PAYMONGO</span>
        </div>
        <div id="modal-error" class="hidden text-[10px] text-red-700 font-bold uppercase"></div>
        <div class="grid grid-cols-2 gap-3">
            <button onclick="closePaymentModal()" class="py-2.5 border-2 border-primary font-bold text-xs uppercase hover:bg-zinc-50">Cancel</button>
            <button onclick="processMockPayment()" id="modal-pay-btn" class="py-2.5 bg-primary text-white border-2 border-primary font-bold text-xs uppercase flex items-center justify-center gap-2 hover:bg-accent hover:text-primary transition-all">
                Pay Now <span class="material-symbols-outlined text-sm">lock</span>
            </button>
        </div>
    </div>
</div>

<!-- Booking Outcome Modal (Success) -->
<div id="outcome-modal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border-4 border-primary max-w-sm w-full p-6 shadow-[8px_8px_0px_rgba(21,66,18,1)] text-center flex flex-col space-y-6 items-center">
        <span class="material-symbols-outlined text-6xl text-accent" style="font-variation-settings: 'FILL' 1;">check_circle</span>
        <div>
            <h3 id="outcome-title" class="font-['Space_Grotesk'] font-black text-xl uppercase text-primary tracking-tight">Booking Confirmed!</h3>
            <p id="outcome-message" class="text-xs text-zinc-600 mt-2 px-2">Your reservation has been processed successfully.</p>
        </div>
        <div class="bg-zinc-50 border border-primary p-4 w-full">
            <span class="text-[9px] font-bold text-zinc-400 uppercase tracking-widest">Booking Reference</span>
            <p id="outcome-booking-code" class="font-['Space_Grotesk'] font-black text-xl text-primary tracking-widest mt-1">BK-2026-0000</p>
        </div>
        <div class="w-full flex flex-col gap-2">
            <a href="my_bookings.php" class="w-full py-3 bg-primary text-white font-bold text-xs uppercase tracking-widest text-center hover:bg-accent hover:text-primary transition-colors">Go to My Bookings</a>
            <button onclick="location.reload()" class="w-full py-3 border-2 border-primary font-bold text-xs uppercase tracking-widest hover:bg-zinc-50">Book Another Slot</button>
        </div>
    </div>
</div>

<script>
    // Variables seeded from database config (PHP renders initial values)
    const courtHourlyRate    = <?= $court_rate ?>;
    const coachingHourlyRate = <?= $coaching_rate ?>;
    const minAdvanceDays     = <?= $min_advance_days ?>;

    // PHP computes these using Asia/Manila (PHT / UTC+8) — safe for all browsers
    const firstBookableDate  = "<?= $first_bookable_date ?>";   // e.g. '2026-06-03'
    const todayPHT           = "<?= date('Y-m-d') ?>";           // current PHT date string
    const nowPHTHour         = <?= (int)date('G') ?>;            // current PHT hour (0-23)

    const userVerifiedEmail  = "<?= htmlspecialchars($user_email) ?>";

    // These are live-synced with admin portal — initial seed from PHP, then refreshed via AJAX
    let openMonths   = <?= json_encode($open_months) ?>;        // only open months
    let allMonths    = <?= json_encode($all_months_list) ?>;    // ALL months (nav bounds)
    let minNavMonth  = "<?= $nav_min_month ?>";                 // e.g. '2026-01'
    let maxNavMonth  = "<?= $nav_max_month ?>";                 // e.g. '2028-01'
    let sundayLocked = <?= json_encode($sunday_locked) ?>;

    // Calendar states — start at current month or closest configured month
    let currentDate  = new Date(todayPHT.substring(0, 7) + '-01T00:00:00');
    let selectedDate = firstBookableDate;  // first bookable date used for slot auto-load only
    let selectedSlotIds   = [];
    let selectedSlotTimes = [];
    
    // Summary states
    let paxCount = 1;
    let coachingEnabled = true;
    let courtPaid = false;
    let paymentMethod = 'pay_now';

    // ── Live config sync from admin portal ──────────────────────
    // Note: firstBookableDate is re-seeded from server each poll so midnight
    //       PHT rollover is handled automatically while page is open.
    let firstBookableDateLive = firstBookableDate; // mutable shadow of the const

    async function fetchLiveConfig() {
        try {
            const res = await fetch('../actions/client_booking.php?action=get_config');
            const data = await res.json();
            if (data.success) {
                openMonths   = data.open_months;
                sundayLocked = data.sunday_locked;
                if (data.all_months && data.all_months.length) {
                    allMonths   = data.all_months;
                    minNavMonth = data.min_month;
                    maxNavMonth = data.max_month;
                }
                // Refresh the first bookable date (PHT) in case day rolled over at midnight
                if (data.first_bookable_date) {
                    firstBookableDateLive = data.first_bookable_date;
                }
            }
        } catch(e) {
            // Silently fall back to PHP-seeded values if fetch fails
        }
    }

    // Helper: get the current live firstBookableDate (refreshed by polling)
    function getFirstBookableDate() {
        return firstBookableDateLive;
    }


    document.addEventListener('DOMContentLoaded', async () => {
        // Fetch live config first so calendar reflects latest admin changes
        await fetchLiveConfig();

        // Open at the current month in PHT (bounded by configured navigation range)
        const currentPHTMonth = todayPHT.substring(0, 7);
        let startMonth = currentPHTMonth;
        if (startMonth < minNavMonth) startMonth = minNavMonth;
        if (startMonth > maxNavMonth) startMonth = maxNavMonth;
        currentDate = new Date(startMonth + '-01T00:00:00');

        renderCalendar();
        clearSlotsGrid(); // wait for user to pick a date
        loadNotifications();
    });



    // ── CALENDAR RENDERER ──────────────────────────────────────────
    function renderCalendar() {
        const daysContainer = document.getElementById('calendar-days');
        const titleContainer = document.getElementById('calendar-title');
        
        daysContainer.innerHTML = '';
        const year  = currentDate.getFullYear();
        const month = currentDate.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;

        // Header month title
        const monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
        titleContainer.innerText = `${monthNames[month]} ${year}`;

        // Update prev/next nav button states based on bounds
        const btnPrev = document.getElementById('btn-prev-month');
        const btnNext = document.getElementById('btn-next-month');
        if (btnPrev) btnPrev.disabled = (monthStr <= minNavMonth);
        if (btnNext) btnNext.disabled = (monthStr >= maxNavMonth);

        // Get first day of month
        let firstDayIndex = new Date(year, month, 1).getDay();
        firstDayIndex = firstDayIndex === 0 ? 6 : firstDayIndex - 1;

        const totalDays = new Date(year, month + 1, 0).getDate();
        const prevMonthTotalDays = new Date(year, month, 0).getDate();

        // 1. Previous month buffer days
        for (let i = firstDayIndex - 1; i >= 0; i--) {
            const dayNum = prevMonthTotalDays - i;
            daysContainer.innerHTML += `<div class="h-16 flex items-center justify-center border border-[#E5E7EB] opacity-30 text-xs">${dayNum}</div>`;
        }

        // 2. Real month days
        // ── All date comparisons use YYYY-MM-DD strings from PHP/Asia/Manila ──
        // todayPHT  = current PHT date (e.g. '2026-05-28')
        // fbDate    = first bookable date = today + min_advance_days (e.g. '2026-05-29')
        const isMonthOpen     = openMonths.includes(monthStr);
        const fbDate          = getFirstBookableDate(); // live PHT value (refreshed every 30s)

        for (let day = 1; day <= totalDays; day++) {
            const dayStr     = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const isSelected = (dayStr === selectedDate);

            // PAST: strictly before today in PHT (dates that have already passed)
            const isPastDate = dayStr < todayPHT;

            // SOON: today or between today and firstBookableDate (advance window)
            const isTooSoon  = !isPastDate && dayStr < fbDate;

            // Sunday check
            const [dy, dm, dd] = dayStr.split('-').map(Number);
            const dowDate      = new Date(dy, dm - 1, dd);
            const isSunday     = (dowDate.getDay() === 0);
            const isSunBlocked = isSunday && (sundayLocked === '1' || sundayLocked === 1 || sundayLocked === 'true');

            // A date is bookable when: not past, not too soon, month open, not Sunday-locked
            const isBookable = !isPastDate && !isTooSoon && isMonthOpen && !isSunBlocked;

            let bgClass      = "border border-[#E5E7EB] hover:bg-zinc-50 cursor-pointer";
            let txtClass     = "text-primary font-bold";
            let statusText   = "AVAILABLE";
            let disabledAttr = "";

            if (!isBookable) {
                bgClass      = "border border-[#E5E7EB] bg-zinc-50 opacity-40 cursor-not-allowed";
                txtClass     = "text-zinc-400";
                disabledAttr = "disabled";

                if (isPastDate) {
                    statusText = "PAST";        // Dates already gone (before today)
                } else if (isTooSoon) {
                    statusText = "SOON";        // Future dates within the advance window
                } else if (isSunBlocked) {
                    statusText = "SUNDAY";      // Admin locked Sundays
                } else if (!isMonthOpen) {
                    statusText = "CLOSED";      // Admin hasn't opened this month
                } else {
                    statusText = "LOCKED";
                }
            } else if (isSelected) {
                bgClass    = "border-2 border-primary bg-accent transition-colors shadow-[2px_2px_0px_rgba(21,66,18,1)]";
                statusText = "SELECTED";
            }

            daysContainer.innerHTML += `
                <button ${disabledAttr} onclick="selectDate('${dayStr}')" class="h-16 flex flex-col items-center justify-center transition-all ${bgClass}">
                    <span class="text-sm font-black ${txtClass}">${day}</span>
                    <span class="text-[8px] tracking-wider font-bold ${isSelected ? 'text-primary' : 'text-zinc-400'}">${statusText}</span>
                </button>
            `;
        }
    }


    function prevMonth() {
        const year  = currentDate.getFullYear();
        const month = currentDate.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;
        // Do not go before the first month in the system
        if (monthStr <= minNavMonth) return;
        currentDate.setMonth(month - 1);
        fetchLiveConfig().then(() => {
            renderCalendar();
            clearSlotsGrid();
        });
    }

    function nextMonth() {
        const year  = currentDate.getFullYear();
        const month = currentDate.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;
        // Do not go past the last month in the system
        if (monthStr >= maxNavMonth) return;
        currentDate.setMonth(month + 1);
        fetchLiveConfig().then(() => {
            renderCalendar();
            clearSlotsGrid();
        });
    }

    function clearSlotsGrid() {
        selectedSlotIds = [];
        selectedSlotTimes = [];
        document.getElementById('slots-selected-date').innerText = "Please Select a Date";
        const badge = document.getElementById('slots-count-badge');
        if (badge) {
            badge.classList.add('hidden');
        }
        
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;
        const isMonthOpen = openMonths.includes(monthStr);

        const gridElement = document.getElementById('slots-grid');
        if (!isMonthOpen) {
            gridElement.innerHTML = '<div class="col-span-4 p-4 text-center text-red-500 font-bold border-2 border-dashed border-red-300 bg-red-50 leading-relaxed uppercase tracking-wider text-[10px]">This month is currently locked by the administration and is not yet open for bookings.</div>';
        } else {
            gridElement.innerHTML = 'Please select an available booking date above to load session blocks.';
        }

        updateSummaryPrice();
    }

    // --- DATE & SLOTS LOADERS ---
    function selectDate(dateString) {
        selectedDate = dateString;
        selectedSlotIds = [];
        selectedSlotTimes = [];
        
        // Format selected date for display
        const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        const displayDate = new Date(dateString).toLocaleDateString('en-US', dateOptions);
        
        document.getElementById('slots-selected-date').innerText = displayDate;
        document.getElementById('summary-datetime').innerText = "Please select a time slot.";
        
        renderCalendar();
        loadSlotsForDate(dateString);
        updateSummaryPrice();
    }

    function loadSlotsForDate(dateString) {
        const slotsGrid = document.getElementById('slots-grid');
        const badge = document.getElementById('slots-count-badge');
        
        slotsGrid.innerHTML = '<div class="col-span-4 p-4 text-center text-primary font-bold">Loading slots...</div>';

        fetch(`../actions/client_booking.php?action=get_slots&date=${dateString}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    slotsGrid.innerHTML = `<div class="col-span-4 text-red-500 font-bold p-4">${data.message}</div>`;
                    return;
                }

                const slots = data.slots;
                if (slots.length === 0) {
                    slotsGrid.innerHTML = '<div class="col-span-4 p-4 text-zinc-400">No session slots have been generated for this date by administration.</div>';
                    badge.classList.add('hidden');
                    return;
                }

                slotsGrid.innerHTML = '';
                let openCount = 0;

                slots.forEach(slot => {
                    let btnClass = "border-2 border-primary hover:bg-[#F8F9FA] transition-all";
                    let label = "SELECT";
                    let labelClass = "text-primary";
                    let clickHandler = `selectSlot(${slot.id}, '${slot.start_time}', '${slot.end_time}')`;  // 1-hour block
                    let isDisabled = false;

                    if (slot.status === 'locked' || slot.is_game_night) {
                        btnClass = "border-2 border-red-200 bg-red-50 opacity-40 cursor-not-allowed";
                        label = slot.is_game_night ? "GAME NIGHT" : "CLOSED";
                        labelClass = "text-red-600 font-bold";
                        isDisabled = true;
                    } else if (slot.status === 'reserved') {
                        btnClass = "border-2 border-amber-200 bg-amber-50 opacity-40 cursor-not-allowed";
                        label = "RESERVED";
                        labelClass = "text-amber-600";
                        isDisabled = true;
                    } else if (slot.status === 'confirmed' || slot.status === 'booked') {
                        btnClass = "border-2 border-red-200 bg-red-50 opacity-40 cursor-not-allowed";
                        label = "BOOKED";
                        labelClass = "text-red-600";
                        isDisabled = true;
                    } else {
                        openCount++;
                    }

                    if (selectedSlotIds.includes(slot.id)) {
                        btnClass = "border-2 border-primary bg-primary text-white shadow-[2px_2px_0px_rgba(255,184,0,1)]";
                        label = "✓ SELECTED";
                        labelClass = "text-accent";
                    }

                    slotsGrid.innerHTML += `
                        <button ${isDisabled ? 'disabled' : ''} onclick="${clickHandler}" class="p-4 text-center ${btnClass} flex flex-col items-center">
                            <span class="block font-black text-sm">${slot.start_time}</span>
                            <span class="text-[9px] uppercase font-bold tracking-widest mt-1 ${labelClass}">${label}</span>
                        </button>
                    `;
                });

                if (openCount > 0) {
                    let badgeText = `${openCount} SLOTS OPEN`;
                    badge.innerText = badgeText;
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
            })
            .catch(err => {
                slotsGrid.innerHTML = '<div class="col-span-4 text-red-500 font-bold p-4">Error loading available slots.</div>';
            });
    }

    function selectSlot(id, start, end) {
        const idx = selectedSlotIds.indexOf(id);
        if (idx === -1) {
            // Add to selection
            selectedSlotIds.push(id);
            selectedSlotTimes.push({ start, end });
        } else {
            // Remove from selection (toggle off)
            selectedSlotIds.splice(idx, 1);
            selectedSlotTimes.splice(idx, 1);
        }
        updateSummaryDatetime();
        loadSlotsForDate(selectedDate);
        updateSummaryPrice();
    }

    function timeToMinutes(timeStr) {
        if (!timeStr) return 0;
        const parts = timeStr.trim().split(/\s+/);
        if (parts.length < 2) return 0;
        const timeVal = parts[0];
        const ampm = parts[1].toUpperCase();
        
        const timeParts = timeVal.split(':');
        let hours = parseInt(timeParts[0], 10);
        let minutes = parseInt(timeParts[1], 10);
        
        if (ampm === 'PM' && hours < 12) hours += 12;
        if (ampm === 'AM' && hours === 12) hours = 0;
        
        return hours * 60 + minutes;
    }

    function updateSummaryDatetime() {
        const dateOptions = { month: 'short', day: 'numeric' };
        const formattedDate = new Date(selectedDate).toLocaleDateString('en-US', dateOptions);
        if (selectedSlotIds.length === 0) {
            document.getElementById('summary-datetime').innerText = 'Please select a time slot.';
        } else {
            // Sort selected times by start time and merge into a single unified range
            const sortedTimes = [...selectedSlotTimes].sort((a, b) => timeToMinutes(a.start) - timeToMinutes(b.start));
            const rangeStart = sortedTimes[0].start;
            const rangeEnd = sortedTimes[sortedTimes.length - 1].end;
            
            document.getElementById('summary-datetime').innerText =
                `${formattedDate} • ${rangeStart}–${rangeEnd}`;
        }
    }

    // --- PAX & SERVICES CONTROLS ---
    function incrementPax() {
        paxCount++;
        document.getElementById('pax-display').innerText = paxCount;
        updateSummaryPrice();
    }

    // Dynamic checks
    function decrementPax() {
        if (paxCount > 1) {
            paxCount--;
            document.getElementById('pax-display').innerText = paxCount;
            updateSummaryPrice();
        }
    }

    function toggleCoaching() {
        coachingEnabled = document.getElementById('coaching-checkbox').checked;
        updateSummaryPrice();
    }

    function toggleCourtPaid() {
        courtPaid = document.getElementById('court-paid-checkbox').checked;
        updateSummaryPrice();
    }

    function selectPaymentMethod(method) {
        paymentMethod = method;
        
        const payNowBtn = document.getElementById('method-pay-now');
        const payLaterBtn = document.getElementById('method-pay-later');
        const policyNote = document.getElementById('payment-policy-note');

        // Reset
        payNowBtn.className = "py-2.5 bg-white text-primary font-headline-md text-[10px] uppercase font-bold";
        payLaterBtn.className = "py-2.5 bg-white text-primary border-l-2 border-primary font-headline-md text-[10px] uppercase font-bold";

        if (method === 'pay_now') {
            payNowBtn.className = "py-2.5 bg-primary text-white font-headline-md text-[10px] uppercase font-bold flex items-center justify-center gap-1";
            policyNote.innerHTML = "* Pay Now processes checkout via PayMongo sandbox. Locks court instantly.";
        } else if (method === 'pay_later') {
            payLaterBtn.className = "py-2.5 bg-primary text-white border-l-2 border-primary font-headline-md text-[10px] uppercase font-bold";
            policyNote.innerHTML = "* Pay Later bookings must be paid within 24h of booking, or the reservation expires automatically.";
        }
    }

    // --- PRICE COMPUTATION ENGINE ---
    function updateSummaryPrice() {
        const warningBanner = document.getElementById('selection-warning');
        const warningText = document.getElementById('selection-warning-text');

        if (selectedSlotIds.length === 0) {
            document.getElementById('breakdown-court').innerHTML = '&#8369;0.00';
            document.getElementById('breakdown-coaching').innerHTML = '&#8369;0.00';
            document.getElementById('breakdown-total').innerHTML = '&#8369;0.00';
            if (warningBanner) warningBanner.classList.add('hidden');
            return;
        }

        // Toggle warning banner based on strictly 2 slots requirement
        if (selectedSlotIds.length !== 2) {
            if (warningBanner) {
                warningBanner.classList.remove('hidden');
                if (selectedSlotIds.length === 1) {
                    warningText.innerText = "Strict Rule: You have selected 1 slot. You must select exactly two slots to book (e.g., a 2-hour session).";
                } else {
                    warningText.innerText = `Strict Rule: You have selected ${selectedSlotIds.length} slots. You must select exactly two slots to book.`;
                }
            }
        } else {
            if (warningBanner) warningBanner.classList.add('hidden');
        }

        const totalHours = selectedSlotIds.length; // each block = 1 hour
        const courtFee = courtPaid ? 0.00 : (courtHourlyRate * totalHours * paxCount);
        const coachingFee = coachingEnabled ? (coachingHourlyRate * paxCount * totalHours) : 0.00;
        const totalFee = courtFee + coachingFee;

        document.getElementById('breakdown-court').innerHTML = `&#8369;${courtFee.toLocaleString('en-US', {minimumFractionDigits: 2})}`;
        document.getElementById('breakdown-coaching').innerHTML = `&#8369;${coachingFee.toLocaleString('en-US', {minimumFractionDigits: 2})}`;
        document.getElementById('breakdown-total').innerHTML = `&#8369;${totalFee.toLocaleString('en-US', {minimumFractionDigits: 2})}`;
    }

    // --- BOOKING OPERATIONS ---
    function handleConfirmBooking() {
        if (selectedSlotIds.length === 0) {
            alert("Please select a date and available court slots before confirming.");
            return;
        }

        if (selectedSlotIds.length !== 2) {
            alert("Strict Booking Rule: You must select exactly two time slots to book (e.g., a 2-hour session).");
            return;
        }

        // Directly call AJAX booking which handles either redirection or instant reservation
        submitBookingAJAX();
    }

    function closePaymentModal() {
        document.getElementById('paymongo-modal').classList.add('hidden');
        document.getElementById('modal-error').classList.add('hidden');
    }

    function submitBookingAJAX() {
        const formData = new FormData();
        formData.append('action', 'confirm_booking');
        selectedSlotIds.forEach(id => formData.append('schedule_ids[]', id));
        formData.append('pax', paxCount);
        formData.append('add_coaching', coachingEnabled ? '1' : '0');
        formData.append('exclude_court_fee', courtPaid ? '1' : '0');
        formData.append('payment_method', paymentMethod);

        // Show a loader or disable button if needed, standard alerts otherwise
        const confirmBtn = document.querySelector('button[onclick="handleConfirmBooking()"]');
        const originalHtml = confirmBtn.innerHTML;
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = `Processing Checkout... <span class="material-symbols-outlined text-sm animate-spin">progress_activity</span>`;

        fetch('../actions/client_booking.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = originalHtml;

            if (data.success) {
                if (data.redirect_url) {
                    // Redirect to secure live PayMongo checkout page
                    window.location.href = data.redirect_url;
                    return;
                }
                
                // Show dynamic outcome modal (for Pay Later)
                document.getElementById('outcome-title').innerText = "RESERVATION PLACED!";
                document.getElementById('outcome-booking-code').innerText = data.booking_code;
                document.getElementById('outcome-message').innerHTML = `
                    A hold confirmation slip has been sent to your verified email:<br>
                    <strong class="text-primary font-black underline">${userVerifiedEmail}</strong>.<br><br>
                    <span class="text-red-600 font-bold block bg-red-50 border border-red-200 p-3 leading-normal">
                        ⚠️ IMPORTANT: You only have 24 hours to pay your outstanding balance. If unpaid, the system will automatically release your slot.
                    </span>
                `;
                document.getElementById('outcome-modal').classList.remove('hidden');
            } else {
                alert("Error placing booking: " + data.message);
            }
        })
        .catch(err => {
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = originalHtml;
            alert("Network error: Could not complete reservation. Please try again.");
        });
    }

    // --- NOTIFICATION MANAGEMENT ---
    function toggleNotifDropdown() {
        const dropdown = document.getElementById('notif-dropdown');
        dropdown.classList.toggle('hidden');
        if (!dropdown.classList.contains('hidden')) {
            loadNotifications();
        }
    }

    function loadNotifications() {
        const container = document.getElementById('notif-items');
        container.innerHTML = '<div class="p-4 text-center text-xs text-zinc-400">Loading notifications...</div>';

        fetch('../actions/get_notifications.php?action=get_unread')
            .then(res => res.json())
            .then(data => {
                if (!data.success || data.notifications.length === 0) {
                    container.innerHTML = '<div class="p-4 text-center text-xs text-zinc-400">You have no unread notifications.</div>';
                    return;
                }

                container.innerHTML = '';
                data.notifications.forEach(notif => {
                    container.innerHTML += `
                        <div class="p-4 hover:bg-zinc-50 transition-colors flex justify-between items-start gap-2">
                            <div>
                                <p class="text-xs font-bold text-primary">${notif.message}</p>
                                <span class="text-[9px] text-zinc-400 uppercase font-black">${notif.time_ago}</span>
                            </div>
                            <button onclick="markNotificationRead(${notif.id})" class="text-zinc-300 hover:text-primary"><span class="material-symbols-outlined text-sm">close</span></button>
                        </div>
                    `;
                });
            });
    }

    function markNotificationRead(id) {
        const formData = new FormData();
        formData.append('notification_id', id);

        fetch('../actions/get_notifications.php?action=mark_read', {
            method: 'POST',
            body: formData
        })
        .then(() => {
            loadNotifications();
            // decrement indicator if possible
            location.reload();
        });
    }

    function markAllNotificationsRead() {
        const formData = new FormData();
        formData.append('notification_id', 'all');

        fetch('../actions/get_notifications.php?action=mark_read', {
            method: 'POST',
            body: formData
        })
        .then(() => {
            loadNotifications();
            location.reload();
        });
    }

    // ── CLIENT-SIDE LIVE SYNC ────────────────────────────────────────
    // Polls every 30 seconds:
    //   1. Refreshes open months / sunday lock config (calendar state)
    //   2. Refreshes slots for the currently selected date
    // This keeps the athlete portal in sync with whatever the admin changes.

    let slotCountdown  = 30;
    let slotPollActive = false;

    function manualRefreshSlots() {
        slotCountdown = 30;
        const icon = document.getElementById('refresh-icon');
        if (icon) { icon.classList.add('animate-spin'); setTimeout(() => icon.classList.remove('animate-spin'), 800); }
        fetchLiveConfig().then(() => {
            renderCalendar();
            if (selectedDate) loadSlotsForDate(selectedDate);
        });
    }

    async function liveSlotPoll() {
        // Refresh config (open months / sunday lock)
        await fetchLiveConfig();
        renderCalendar();
        // Refresh slot list for selected date (keeps slot statuses current)
        if (selectedDate) {
            loadSlotsForDate(selectedDate);
        }
        slotCountdown = 30;
    }

    // Countdown ticker for slot live badge
    setInterval(() => {
        slotCountdown--;
        const el = document.getElementById('slot-live-countdown');
        if (el) {
            if (slotCountdown > 0) {
                el.textContent = selectedDate ? `${slotCountdown}s` : 'Live';
            } else {
                el.textContent = 'Syncing...';
            }
        }
        if (slotCountdown <= 0) {
            slotCountdown = 30;
            liveSlotPoll();
        }
    }, 1000);

    // Initial live poll after 5 seconds (let page settle)
    setTimeout(liveSlotPoll, 5000);

</script>
</body>
</html>
