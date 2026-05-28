<?php
// admin/dashboard.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_admin();
run_cron_simulator($pdo);

$admin_id = $_SESSION['admin_id'];
// Auto-sync session name from DB if it is still the old name
if (isset($_SESSION['admin_name']) && $_SESSION['admin_name'] === 'Ronnie Garcia') {
    $stmt_admin = $pdo->prepare("SELECT full_name FROM admins WHERE id = ? LIMIT 1");
    $stmt_admin->execute([$admin_id]);
    $db_name = $stmt_admin->fetchColumn();
    if ($db_name) {
        $_SESSION['admin_name'] = $db_name;
    }
}
$admin_name = $_SESSION['admin_name'] ?? 'RDG ADMIN';

// Fetch stats today (excluding midnight 00:00)
$slots_today = (int)$pdo->query("SELECT COUNT(*) FROM schedules WHERE session_date = CURDATE() AND start_time != '00:00:00'")->fetchColumn();
$booked_today = (int)$pdo->query("SELECT COUNT(*) FROM schedules WHERE session_date = CURDATE() AND status = 'confirmed' AND start_time != '00:00:00'")->fetchColumn();
$reserved_today = (int)$pdo->query("SELECT COUNT(*) FROM schedules WHERE session_date = CURDATE() AND status = 'reserved' AND start_time != '00:00:00'")->fetchColumn();

// Fetch paid monthly revenue (excluding package payments)
$revenue_month = (float)$pdo->query("
    SELECT COALESCE(SUM(amount), 0)
      FROM payments
     WHERE status = 'paid'
       AND DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
       AND method != 'package'
")->fetchColumn();

// Fetch global dashboard metrics
$total_athletes = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'client'")->fetchColumn();
$total_bookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status != 'cancelled'")->fetchColumn();
$pending_payments = (int)$pdo->query("
    SELECT COUNT(*) 
      FROM payments p
      JOIN bookings b ON b.id = p.booking_id
     WHERE p.status = 'unpaid' AND b.status != 'cancelled'
")->fetchColumn();

// Trainees today (confirmed, reserved, or completed active trainees today)
$stmt_trainees = $pdo->query("
    SELECT COALESCE(SUM(b.pax), 0) 
      FROM bookings b 
      JOIN schedules s ON b.schedule_id = s.id 
     WHERE s.session_date = CURDATE() AND b.status IN ('confirmed', 'reserved', 'completed') AND s.start_time != '00:00:00'
");
$trainees_today = (int)$stmt_trainees->fetchColumn();

// Fetch all schedule slots today (from 8:00 AM to 12:00 MN)
$stmt_sched_today = $pdo->query("
    SELECT s.*, b.pax, (b.coaching_fee > 0) AS add_coaching, u.full_name AS client_name, b.booking_code
      FROM schedules s
      LEFT JOIN bookings b ON b.schedule_id = s.id AND b.status IN ('confirmed', 'reserved', 'completed')
      LEFT JOIN users u ON b.user_id = u.id
     WHERE s.session_date = CURDATE() AND s.start_time != '00:00:00'
     ORDER BY s.start_time ASC
");
$upcoming = $stmt_sched_today->fetchAll(PDO::FETCH_ASSOC);


// Recent activities (Audit Logs, Athlete Registrations, and Athlete Bookings)
$stmt_activities = $pdo->query("
    SELECT 'admin_action' AS type, action AS detail, performed_at AS event_time, adm.full_name AS person_name
      FROM admin_audit_log a
      LEFT JOIN admins adm ON a.admin_id = adm.id
    
    UNION ALL
    
    SELECT 'user_register' AS type, email AS detail, created_at AS event_time, full_name AS person_name
      FROM users
     WHERE role = 'client'
     
    UNION ALL
    
    SELECT 'user_booking' AS type, CONCAT(booking_code, ' (', status, ', ₱', FORMAT(total_fee, 2), ')') AS detail, booked_at AS event_time, u.full_name AS person_name
      FROM bookings b
      JOIN users u ON b.user_id = u.id
      
    ORDER BY event_time DESC
    LIMIT 10
");
$activities = $stmt_activities->fetchAll(PDO::FETCH_ASSOC);

// Map activities to human-readable summaries
$activity_list = [];
foreach ($activities as $act) {
    $timestamp = strtotime($act['event_time']);
    $time_str = date('h:i A', $timestamp);
    if (date('Y-m-d', $timestamp) !== date('Y-m-d')) {
        $time_str = date('M d, h:i A', $timestamp);
    }
    
    $message = "";
    $icon = "info";
    $bg_cls = "bg-zinc-100 text-zinc-700";
    
    switch ($act['type']) {
        case 'user_register':
            $message = "Athlete <strong>" . htmlspecialchars($act['person_name']) . "</strong> (" . htmlspecialchars($act['detail']) . ") registered a new profile.";
            $icon = "how_to_reg";
            $bg_cls = "bg-green-100 text-green-700";
            break;
        case 'user_booking':
            $message = "Athlete <strong>" . htmlspecialchars($act['person_name']) . "</strong> placed booking " . htmlspecialchars($act['detail']);
            $icon = "sports_tennis";
            $bg_cls = "bg-blue-100 text-blue-700";
            break;
        case 'admin_action':
            $icon = "shield";
            $bg_cls = "bg-purple-100 text-purple-700";
            switch ($act['detail']) {
                case 'confirm_payment':
                    $message = "Admin manually confirmed payment for a booking.";
                    $icon = "receipt_long";
                    $bg_cls = "bg-amber-100 text-amber-700";
                    break;
                case 'manual_walkin_booking':
                    $message = "Admin registered a new walk-in guest booking.";
                    $icon = "person_add";
                    $bg_cls = "bg-green-100 text-green-700";
                    break;
                case 'cancel_booking':
                    $message = "Admin cancelled booking.";
                    $icon = "cancel";
                    $bg_cls = "bg-red-100 text-red-700";
                    break;
                case 'toggle_lock_slot':
                    $message = "Admin toggled schedule slot lock status.";
                    $icon = "lock";
                    $bg_cls = "bg-zinc-100 text-zinc-700";
                    break;
                default:
                    $message = "Admin executed action: " . htmlspecialchars($act['detail']);
                    break;
            }
            break;
    }
    
    $activity_list[] = [
        'message' => $message,
        'time' => $time_str,
        'icon' => $icon,
        'bg_cls' => $bg_cls
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>RDG Tennis - Admin Dashboard</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700;900&family=Lexend:wght@300;400;500;600;700&family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
    .court-grid{background-image:linear-gradient(#e5e7eb 1px,transparent 1px),linear-gradient(90deg,#e5e7eb 1px,transparent 1px);background-size:64px 64px;}
    .material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
    .fill-icon{font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;}
    @keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
    .fade-in{animation:fadeIn .3s ease both}
    @keyframes slideInRight{from{transform:translateX(100%)}to{transform:translateX(0)}}
    @keyframes slideOutRight{from{transform:translateX(0)}to{transform:translateX(100%)}}
    .drawer-in{animation:slideInRight .3s cubic-bezier(.22,1,.36,1) forwards}
    .drawer-out{animation:slideOutRight .25s ease-in forwards}
    .metric-card{cursor:pointer;transition:all .15s ease}
    .metric-card:hover{transform:translateY(-2px);box-shadow:6px 6px 0px rgba(21,66,18,1)}
    .metric-card:active{transform:translateY(0);box-shadow:2px 2px 0px rgba(21,66,18,1)}
    
    /* Neobrutalist custom scrollbar styling */
    .neobrutal-scrollbar::-webkit-scrollbar {
        width: 8px;
    }
    .neobrutal-scrollbar::-webkit-scrollbar-track {
        background: #f6f3f2;
        border-left: 2px solid #154212;
    }
    .neobrutal-scrollbar::-webkit-scrollbar-thumb {
        background: #154212;
        border: 1px solid #FFB800;
    }
    .neobrutal-scrollbar::-webkit-scrollbar-thumb:hover {
        background: #FFB800;
    }
</style>
<script>
tailwind.config={darkMode:"class",theme:{extend:{
    colors:{"primary":"#154212","primary-container":"#2d5a27","accent":"#FFB800","on-surface":"#1c1b1b","surface-container-low":"#f6f3f2","outline-variant":"#c2c9bb","secondary":"#7d5700","error":"#ba1a1a"},
    borderRadius:{DEFAULT:"0px",full:"9999px"},
    fontFamily:{headline:["Space Grotesk"],body:["Lexend"]}
}}}
</script>
</head>
<body class="bg-[#fcf9f8] text-[#1c1b1b] font-body court-grid min-h-screen">

<!-- TopBar -->
<header class="fixed top-0 left-0 right-0 z-50 bg-white flex justify-between items-center px-6 h-20 border-b-2 border-primary">
    <div class="flex items-center gap-3">
        <img alt="RDG" class="h-10 w-auto" src="/RDG/RDG Logo.jpg"/>
    </div>
    <div class="flex items-center gap-4">
        <button class="relative p-2 hover:bg-[#f6f3f2] transition-colors">
            <span class="material-symbols-outlined text-2xl">notifications</span>
        </button>
        <div class="h-8 w-px bg-[#c2c9bb]"></div>
        <span class="hidden md:inline font-bold text-xs uppercase text-primary"><?= htmlspecialchars($admin_name) ?></span>
        <a href="/RDG/auth/logout.php" class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-accent hover:opacity-80 transition-opacity">
            <span class="material-symbols-outlined text-xl">logout</span>
        </a>
    </div>
</header>

<!-- Sidebar -->
<aside class="fixed left-0 top-20 h-[calc(100vh-80px)] w-64 bg-[#F8F9FA] border-r-2 border-primary flex flex-col py-4 z-40">
    <div class="px-6 py-4 mb-2 border-b border-zinc-200">
        <p class="font-headline font-bold uppercase text-sm text-primary">Admin Panel</p>
        <p class="text-xs text-[#42493e] font-bold uppercase">Court Management</p>
    </div>
    <nav class="flex flex-col gap-1">
        <a class="bg-[#FFE500] text-primary border-l-4 border-primary px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm" href="dashboard.php"><span class="material-symbols-outlined fill-icon">grid_view</span> Dashboard</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="schedule.php"><span class="material-symbols-outlined">calendar_today</span> Schedule</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="bookings.php"><span class="material-symbols-outlined">payments</span> Bookings</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="attendance.php"><span class="material-symbols-outlined">fact_check</span> Attendance</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="cancellations.php"><span class="material-symbols-outlined">cancel</span> Requests</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="months.php"><span class="material-symbols-outlined">calendar_month</span> Months</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="reports.php"><span class="material-symbols-outlined">analytics</span> Reports</a>
    </nav>
    <div class="mt-auto px-4">
        <div class="bg-primary p-4 flex flex-col gap-2 border-2 border-primary">
            <span class="text-accent font-black text-[10px] tracking-wider">SYSTEM STATUS</span>
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-green-400 animate-pulse"></span>
                <span class="text-white font-bold text-xs uppercase">All Courts Online</span>
            </div>
        </div>
    </div>
</aside>

<!-- Main -->
<main class="ml-64 pt-20 min-h-screen p-8">
    <div class="max-w-7xl mx-auto space-y-8 fade-in">
        <!-- Hero Section -->
        <section class="grid grid-cols-1 md:grid-cols-12 gap-6 items-center border-b-4 border-primary pb-6">
            <div class="md:col-span-8 space-y-4">
                <h1 class="font-headline font-black text-5xl text-primary uppercase tracking-tighter">Court Overview</h1>
                <p class="text-[#42493e]">Manage daily slot allocations, process walk-ins, track revenue, and monitor athletic training attendance.</p>
                <div class="flex flex-wrap gap-2">
                    <button onclick="openReportModal()" class="flex items-center gap-2 bg-[#FFE500] hover:bg-accent text-primary font-headline font-black uppercase text-xs px-4 py-3 border-2 border-primary shadow-[3px_3px_0px_rgba(21,66,18,1)] transition-all active:translate-y-[1px] active:shadow-none">
                        <span class="material-symbols-outlined text-sm font-black">analytics</span>
                        Generate Activity Report
                    </button>
                </div>
            </div>
            <div class="md:col-span-4 bg-primary p-6 flex flex-col justify-center items-center text-center border-2 border-primary shadow-[4px_4px_0px_rgba(21,66,18,0.3)]">
                <span class="text-accent font-black uppercase text-xs tracking-wider">Active Athletes Today</span>
                <span class="text-5xl text-white font-black mt-1"><?= $trainees_today ?></span>
                <span class="text-zinc-300 font-bold text-[10px] uppercase mt-2">Locked check-in sheets</span>
            </div>
        </section>

        <!-- Metrics Grid -->
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <div onclick="openDrawer('slots_today')" class="metric-card bg-white p-6 border-2 border-primary relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-primary"></div>
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Slots Today</p>
                <div class="flex justify-between items-end mt-2">
                    <span class="font-headline font-black text-4xl text-primary"><?= $slots_today ?></span>
                    <span class="material-symbols-outlined text-primary text-3xl">sports_tennis</span>
                </div>
            </div>
            <div onclick="openDrawer('booked_today')" class="metric-card bg-white p-6 border-2 border-primary relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-accent"></div>
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Booked Slots</p>
                <div class="flex justify-between items-end mt-2">
                    <span class="font-headline font-black text-4xl text-primary"><?= $booked_today ?></span>
                    <span class="material-symbols-outlined text-accent text-3xl">check_circle</span>
                </div>
            </div>
            <div onclick="openDrawer('reserved_today')" class="metric-card bg-white p-6 border-2 border-primary relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-zinc-300"></div>
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Reserved Slots</p>
                <div class="flex justify-between items-end mt-2">
                    <span class="font-headline font-black text-4xl text-primary"><?= $reserved_today ?></span>
                    <span class="material-symbols-outlined text-zinc-400 text-3xl">pending</span>
                </div>
            </div>
            <div onclick="openDrawer('revenue_month')" class="metric-card bg-white p-6 border-2 border-primary relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-primary"></div>
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Revenue This Month</p>
                <div class="flex justify-between items-end mt-2">
                    <span class="font-headline font-black text-3xl text-primary">&#8369;<?= number_format($revenue_month, 2) ?></span>
                    <span class="material-symbols-outlined text-primary text-3xl">payments</span>
                </div>
            </div>
        </section>

        <!-- Global Portal Metrics -->
        <section class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            <div onclick="openDrawer('registered_athletes')" class="metric-card bg-white p-6 border-2 border-primary relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-green-500"></div>
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Registered Athletes</p>
                <div class="flex justify-between items-end mt-2">
                    <span class="font-headline font-black text-4xl text-primary"><?= $total_athletes ?></span>
                    <span class="material-symbols-outlined text-green-500 text-3xl font-bold">groups</span>
                </div>
            </div>
            <div onclick="openDrawer('total_bookings')" class="metric-card bg-white p-6 border-2 border-primary relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-blue-500"></div>
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Total Bookings Placed</p>
                <div class="flex justify-between items-end mt-2">
                    <span class="font-headline font-black text-4xl text-primary"><?= $total_bookings ?></span>
                    <span class="material-symbols-outlined text-blue-500 text-3xl font-bold">book_online</span>
                </div>
            </div>
            <div onclick="openDrawer('unpaid_bookings')" class="metric-card bg-white p-6 border-2 border-primary relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-red-500"></div>
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Unpaid Bookings (Action Needed)</p>
                <div class="flex justify-between items-end mt-2">
                    <span class="font-headline font-black text-4xl text-primary"><?= $pending_payments ?></span>
                    <span class="material-symbols-outlined text-red-500 text-3xl font-bold">pending_actions</span>
                </div>
            </div>
        </section>

        <!-- Stacked Horizontal Landscape Bento Layout -->
        <section class="space-y-6">
            <!-- 1. Daily Schedule Status (Horizontal Landscape Box) -->
            <div class="bg-white border-2 border-primary p-6 shadow-[4px_4px_0px_rgba(21,66,18,1)] flex flex-col">
                <div class="flex items-center justify-between mb-6 border-b-2 border-primary pb-4">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-accent fill-icon">schedule</span>
                        <h3 class="font-headline font-bold text-xl text-primary uppercase">Daily Schedule Status</h3>
                    </div>
                    <span class="text-xs text-zinc-400 font-bold uppercase">Today's Timeline</span>
                </div>
                <div class="max-h-[380px] overflow-y-auto pr-2 neobrutal-scrollbar pb-2">
                    <?php if (empty($upcoming)): ?>
                        <div class="p-6 text-center text-zinc-400 text-xs italic">No schedules allocated for today.</div>
                    <?php endif; ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($upcoming as $up):
                            $border_color = 'border-zinc-300';
                            $disp_status = $up['status'];
                            if (isset($up['booking_code'])) {
                                if ($up['status'] === 'confirmed') {
                                    $border_color = 'border-[#4a8f3c]';
                                    $disp_status = 'confirmed';
                                } else {
                                    $border_color = 'border-accent';
                                    $disp_status = 'reserved';
                                }
                            } elseif ($up['status'] === 'locked') {
                                $border_color = 'border-red-400';
                            }
                            $duration = (float)$up['duration_hours'];
                        ?>
                            <div class="p-4 bg-[#f6f3f2] border-l-4 <?= $border_color ?> border-2 border-primary relative flex flex-col justify-between min-h-[110px] hover:scale-[1.01] transition-transform duration-100">
                                <div class="flex justify-between items-start mb-2">
                                    <span class="text-xs font-headline font-black text-primary uppercase"><?= date('h:i A', strtotime($up['start_time'])) ?></span>
                                    <span class="text-[9px] bg-primary text-white px-2 py-0.5 font-bold uppercase tracking-wider shrink-0"><?= $disp_status ?></span>
                                </div>
                                <p class="text-xs font-bold text-primary mb-2 line-clamp-1">
                                    <?php if ($up['status'] === 'locked'): ?>
                                        LOCK: <?= htmlspecialchars($up['notes'] ?? 'Maintenance') ?>
                                    <?php else: ?>
                                        <?= $up['client_name'] ? htmlspecialchars($up['client_name']) : 'Available Slot' ?>
                                    <?php endif; ?>
                                </p>
                                <span class="text-[9px] font-bold text-zinc-400 uppercase"><?= $duration ?> Hours • <?= $up['pax'] ?? 0 ?> Pax • Coach: <?= $up['add_coaching'] ? 'Yes' : 'No' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <a href="schedule.php" class="block w-full mt-6 bg-primary text-white font-headline font-black text-xs py-4 uppercase tracking-widest text-center hover:bg-accent hover:text-primary transition-colors shrink-0 shadow-[2px_2px_0px_rgba(21,66,18,1)]">
                    Manage Calendar Matrix
                </a>
            </div>

            <!-- 2. Recent System Logs (Horizontal Landscape Box) -->
            <div class="bg-white border-2 border-primary p-6 shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="flex justify-between items-center mb-6 border-b-2 border-primary pb-4">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">history</span>
                        <h3 class="font-headline font-bold text-xl text-primary uppercase">Recent System Logs</h3>
                    </div>
                    <span class="text-xs text-zinc-400 font-bold uppercase">Audit Stream</span>
                </div>
                <div class="divide-y space-y-4">
                    <?php if (empty($activity_list)): ?>
                        <div class="p-6 text-center text-zinc-400 text-sm">No activity recorded today.</div>
                    <?php endif; ?>
                    <?php foreach ($activity_list as $act): ?>
                        <div class="flex items-start gap-4 pt-4 first:pt-0">
                            <div class="w-10 h-10 flex items-center justify-center border-2 border-primary <?= $act['bg_cls'] ?> shrink-0">
                                <span class="material-symbols-outlined text-xl"><?= $act['icon'] ?></span>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-bold text-primary"><?= $act['message'] ?></p>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase"><?= $act['time'] ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>
</main>

<!-- Detail Drawer Overlay -->
<div id="drawer-overlay" onclick="closeDrawer()" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 transition-opacity"></div>

<!-- Detail Drawer Panel -->
<div id="drawer-panel" class="hidden fixed top-0 right-0 h-full w-full max-w-md bg-white border-l-4 border-primary z-50 flex flex-col shadow-[-8px_0_24px_rgba(0,0,0,0.15)]">
    <!-- Drawer Header -->
    <div class="bg-primary px-6 py-5 flex items-start justify-between gap-4 shrink-0">
        <div class="flex items-center gap-3 min-w-0">
            <span id="drawer-icon" class="material-symbols-outlined text-accent text-2xl shrink-0">info</span>
            <div class="min-w-0">
                <h3 id="drawer-title" class="font-headline font-black text-white uppercase text-sm truncate">Loading...</h3>
                <p id="drawer-count" class="text-[10px] text-zinc-300 uppercase font-bold mt-0.5">0 Records</p>
            </div>
        </div>
        <button onclick="closeDrawer()" class="text-white hover:text-accent transition-colors shrink-0 mt-0.5">
            <span class="material-symbols-outlined text-2xl">close</span>
        </button>
    </div>
    <!-- Drawer Search Bar (Neobrutalist) -->
    <div id="drawer-search-container" class="px-5 py-3 border-b-2 border-primary bg-[#fcf9f8] shrink-0 flex gap-2">
        <div class="relative flex-1">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-zinc-400 pointer-events-none">
                <span class="material-symbols-outlined text-sm">search</span>
            </span>
            <input type="text" id="drawer-search-input" oninput="filterAndRenderDrawerItems()" placeholder="Search..." class="w-full pl-9 pr-3 py-2 border-2 border-primary bg-white text-xs font-bold uppercase placeholder-zinc-400 focus:outline-none focus:ring-0 focus:border-primary shadow-[2px_2px_0px_rgba(21,66,18,1)]" />
        </div>
        <select id="drawer-filter-select" onchange="filterAndRenderDrawerItems()" class="hidden w-[140px] border-2 border-primary bg-white px-2 py-2 text-xs font-bold uppercase focus:outline-none focus:ring-0 focus:border-primary shadow-[2px_2px_0px_rgba(21,66,18,1)]">
            <!-- Populated dynamically -->
        </select>
    </div>
    <!-- Drawer Body -->
    <div id="drawer-body" class="flex-1 overflow-y-auto p-5 space-y-3">
        <div class="flex items-center justify-center h-32 text-zinc-400 text-sm">
            <span class="material-symbols-outlined animate-spin mr-2">progress_activity</span> Loading records...
        </div>
    </div>
    <!-- Drawer Footer -->
    <div class="px-6 py-3 border-t-2 border-primary bg-[#f6f3f2] shrink-0">
        <p class="text-[9px] text-zinc-400 font-bold uppercase text-center">Click a card above to view its detailed breakdown</p>
    </div>
</div>

<!-- Activity Report Generator Modal -->
<div id="report-modal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border-4 border-primary max-w-md w-full shadow-[8px_8px_0px_rgba(21,66,18,1)] fade-in">
        <!-- Header -->
        <div class="bg-primary px-6 py-4 flex justify-between items-center border-b-2 border-primary">
            <div>
                <h3 class="font-headline font-black text-white uppercase text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm font-black text-accent">analytics</span>
                    Generate Activity Report
                </h3>
                <p class="text-[10px] text-zinc-300 uppercase font-bold">System Performance & Utilization PDF</p>
            </div>
            <button onclick="closeReportModal()" class="text-white hover:text-accent"><span class="material-symbols-outlined">close</span></button>
        </div>
        <!-- Form Content -->
        <div class="p-6 space-y-5">
            <div class="space-y-1">
                <label for="report-preset" class="block text-[10px] font-bold text-primary uppercase">Report Period / Preset</label>
                <select id="report-preset" onchange="toggleReportCustomFields()" class="w-full border-2 border-primary p-2.5 text-sm font-bold font-headline focus:ring-0 focus:border-accent text-primary">
                    <option value="daily">Today (Daily Summary)</option>
                    <option value="weekly">This Week (Weekly Summary)</option>
                    <option value="monthly" selected>This Month (Monthly Summary)</option>
                    <option value="custom">Custom Filter Range</option>
                </select>
            </div>

            <!-- Custom date filters (hidden by default) -->
            <div id="report-custom-fields" class="hidden grid grid-cols-3 gap-2.5">
                <div class="space-y-1">
                    <label for="report-month" class="block text-[9px] font-bold text-zinc-400 uppercase">Month</label>
                    <select id="report-month" class="w-full border-2 border-primary p-2 text-xs font-bold font-headline focus:ring-0 text-primary">
                        <option value="01">Jan</option>
                        <option value="02">Feb</option>
                        <option value="03">Mar</option>
                        <option value="04">Apr</option>
                        <option value="05" selected>May</option>
                        <option value="06">Jun</option>
                        <option value="07">Jul</option>
                        <option value="08">Aug</option>
                        <option value="09">Sep</option>
                        <option value="10">Oct</option>
                        <option value="11">Nov</option>
                        <option value="12">Dec</option>
                    </select>
                </div>
                <div class="space-y-1">
                    <label for="report-day" class="block text-[9px] font-bold text-zinc-400 uppercase">Day</label>
                    <select id="report-day" class="w-full border-2 border-primary p-2 text-xs font-bold font-headline focus:ring-0 text-primary">
                        <option value="all" selected>All</option>
                        <?php for ($i = 1; $i <= 31; $i++): ?>
                            <option value="<?= $i ?>"><?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="space-y-1">
                    <label for="report-year" class="block text-[9px] font-bold text-zinc-400 uppercase">Year</label>
                    <select id="report-year" class="w-full border-2 border-primary p-2 text-xs font-bold font-headline focus:ring-0 text-primary">
                        <option value="2025">2025</option>
                        <option value="2026" selected>2026</option>
                        <option value="2027">2027</option>
                        <option value="2028">2028</option>
                    </select>
                </div>
            </div>

            <div class="pt-2 flex flex-col gap-2">
                <button onclick="submitReportModal(true)"
                        class="w-full bg-[#FFE500] text-primary font-headline font-black uppercase text-xs py-3 border-2 border-primary hover:bg-accent transition-colors flex items-center justify-center gap-2 shadow-[3px_3px_0px_rgba(21,66,18,1)]">
                    <span class="material-symbols-outlined text-sm font-black">print</span>
                    Print / Save PDF Report
                </button>
                <button onclick="submitReportModal(false)"
                        class="w-full bg-white text-primary font-headline font-black uppercase text-xs py-3 border-2 border-primary hover:bg-zinc-50 transition-colors flex items-center justify-center gap-2 shadow-[3px_3px_0px_rgba(21,66,18,1)]">
                    <span class="material-symbols-outlined text-sm font-black">table_view</span>
                    Open Activity Dashboard View
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ── Drawer Logic ──────────────────────────────────────────────
const BADGE_STYLES = {
    'available':  'bg-[#f6f3f2] text-zinc-500 border border-zinc-300',
    'confirmed':  'bg-[#4a8f3c] text-white',
    'completed':  'bg-primary text-white',
    'reserved':   'bg-accent text-primary',
    'locked':     'bg-red-100 text-red-600 border border-red-300',
    'paid':       'bg-[#4a8f3c] text-white',
    'unpaid':     'bg-red-500 text-white',
    'overdue':    'bg-red-600 text-white',
    'verified':   'bg-[#4a8f3c] text-white',
    'unverified': 'bg-zinc-400 text-white',
};

let activeDrawerItems = [];
let activeMetric = '';

function openDrawer(metric) {
    activeMetric = metric;
    const overlay = document.getElementById('drawer-overlay');
    const panel = document.getElementById('drawer-panel');
    const body = document.getElementById('drawer-body');
    const title = document.getElementById('drawer-title');
    const icon = document.getElementById('drawer-icon');
    const count = document.getElementById('drawer-count');

    // Reset drawer search input values
    const searchInput = document.getElementById('drawer-search-input');
    if (searchInput) {
        searchInput.value = '';
    }
    const filterSelect = document.getElementById('drawer-filter-select');
    if (filterSelect) {
        filterSelect.classList.add('hidden');
        filterSelect.innerHTML = '';
    }

    // Show with animation
    overlay.classList.remove('hidden');
    panel.classList.remove('hidden', 'drawer-out');
    panel.classList.add('drawer-in');

    // Loading state
    title.textContent = 'Loading...';
    icon.textContent = 'progress_activity';
    count.textContent = '';
    body.innerHTML = `<div class="flex items-center justify-center h-32 text-zinc-400 text-sm">
        <span class="material-symbols-outlined animate-spin mr-2">progress_activity</span> Loading records...
    </div>`;

    fetch(`../actions/admin_dashboard.php?metric=${metric}`)
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                body.innerHTML = `<div class="p-6 text-center text-red-500 text-sm">${d.message}</div>`;
                return;
            }

            title.textContent = d.title;
            icon.textContent = d.icon;
            activeDrawerItems = d.items;

            renderFilterControls(metric);
            filterAndRenderDrawerItems();
        })
        .catch(() => {
            body.innerHTML = `<div class="p-6 text-center text-red-500 text-sm">Failed to load detail data.</div>`;
        });
}

function renderFilterControls(metric) {
    const filterSelect = document.getElementById('drawer-filter-select');
    if (!filterSelect) return;

    if (metric === 'total_bookings') {
        filterSelect.innerHTML = `
            <option value="all">All Statuses</option>
            <option value="confirmed">Confirmed</option>
            <option value="reserved">Reserved</option>
            <option value="completed">Completed</option>
        `;
        filterSelect.value = 'all';
        filterSelect.classList.remove('hidden');
    } else {
        filterSelect.classList.add('hidden');
        filterSelect.innerHTML = '';
    }
}

function filterAndRenderDrawerItems() {
    const query = document.getElementById('drawer-search-input').value.trim().toLowerCase();
    const filterSelect = document.getElementById('drawer-filter-select');
    const filterVal = (filterSelect && !filterSelect.classList.contains('hidden')) ? filterSelect.value : 'all';
    
    const body = document.getElementById('drawer-body');
    const countEl = document.getElementById('drawer-count');

    let filtered = activeDrawerItems;

    // Apply text search
    if (query) {
        filtered = filtered.filter(item => {
            const labelMatch = item.label && item.label.toLowerCase().includes(query);
            const subMatch = item.sub && item.sub.toLowerCase().includes(query);
            const timeMatch = item.time && item.time.toLowerCase().includes(query);
            return labelMatch || subMatch || timeMatch;
        });
    }

    // Apply filter select
    if (activeMetric === 'registered_athletes') {
        if (filterVal === 'verified') {
            filtered = filtered.filter(item => item.verified === true || item.badge === 'verified');
        } else if (filterVal === 'unverified') {
            filtered = filtered.filter(item => item.verified === false || item.badge === 'unverified');
        }
    } else if (activeMetric === 'booked_today') {
        if (filterVal === 'coaching') {
            filtered = filtered.filter(item => item.coaching === true || (item.sub && item.sub.includes('Coaching')));
        } else if (filterVal === 'no_coaching') {
            filtered = filtered.filter(item => item.coaching === false || (item.sub && !item.sub.includes('Coaching')));
        }
    } else if (activeMetric === 'total_bookings') {
        if (filterVal !== 'all') {
            filtered = filtered.filter(item => item.badge === filterVal || item.status === filterVal);
        }
    }

    // Render count
    countEl.textContent = filtered.length + ' Record' + (filtered.length !== 1 ? 's' : '') + ((query || (filterSelect && filterVal !== 'all')) ? ' (filtered)' : '');

    if (filtered.length === 0) {
        body.innerHTML = `<div class="flex flex-col items-center justify-center py-16 text-zinc-400">
            <span class="material-symbols-outlined text-5xl mb-3">inbox</span>
            <p class="text-sm font-bold">No records found</p>
            <p class="text-xs mt-1">Try resetting your search or filter options.</p>
        </div>`;
        return;
    }

    body.innerHTML = filtered.map((item, i) => {
        const badgeCls = BADGE_STYLES[item.badge] || 'bg-zinc-200 text-zinc-600';
        return `<div class="drawer-item bg-[#f6f3f2] border-2 border-primary p-4 fade-in" style="animation-delay:${i * 25}ms">
            <div class="flex items-start justify-between gap-2 mb-1.5">
                <span class="text-[10px] font-headline font-black text-zinc-400 uppercase">${item.time}</span>
                <span class="px-2 py-0.5 text-[8px] font-black uppercase tracking-wider shrink-0 ${badgeCls}">${item.badge}</span>
            </div>
            <p class="text-sm font-bold text-primary leading-snug">${item.label}</p>
            ${item.sub ? `<p class="text-[10px] font-bold text-zinc-400 uppercase mt-1">${item.sub}</p>` : ''}
        </div>`;
    }).join('');
}

function closeDrawer() {
    const overlay = document.getElementById('drawer-overlay');
    const panel = document.getElementById('drawer-panel');
    panel.classList.remove('drawer-in');
    panel.classList.add('drawer-out');
    overlay.classList.add('hidden');
    setTimeout(() => { panel.classList.add('hidden'); }, 260);
}

// ── Report Modal Logic ───────────────────────────────────────
function openReportModal() {
    document.getElementById('report-modal').classList.remove('hidden');
    // Pre-fill fields with current date month & year
    const now = new Date();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const y = String(now.getFullYear());
    const reportMonth = document.getElementById('report-month');
    const reportYear = document.getElementById('report-year');
    if (reportMonth) reportMonth.value = m;
    if (reportYear) reportYear.value = y;
}

function closeReportModal() {
    document.getElementById('report-modal').classList.add('hidden');
}

function toggleReportCustomFields() {
    const preset = document.getElementById('report-preset').value;
    const customFields = document.getElementById('report-custom-fields');
    if (preset === 'custom') {
        customFields.classList.remove('hidden');
    } else {
        customFields.classList.add('hidden');
    }
}

function submitReportModal(shouldPrint) {
    const preset = document.getElementById('report-preset').value;
    let url = 'reports.php';
    
    if (preset === 'custom') {
        const month = document.getElementById('report-month').value;
        const day = document.getElementById('report-day').value;
        const year = document.getElementById('report-year').value;
        url += `?filter_year=${year}&filter_month=${month}&filter_day=${day}`;
    } else {
        url += `?preset=${preset}`;
    }
    
    if (shouldPrint) {
        url += (url.includes('?') ? '&' : '?') + 'autoprint=1';
    }
    
    closeReportModal();
    window.location.href = url;
}

// Close drawer or report modal on Escape key
document.addEventListener('keydown', e => { 
    if (e.key === 'Escape') {
        closeDrawer(); 
        closeReportModal();
    }
});
</script>
</body>
</html>
