<?php
// admin/reports.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_admin();
run_cron_simulator($pdo);

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'RDG ADMIN';

// Handle Presets and Filter Inputs
$preset = $_GET['preset'] ?? '';
$selected_year = $_GET['filter_year'] ?? '';
$selected_month = $_GET['filter_month'] ?? '';
$selected_day = $_GET['filter_day'] ?? '';

// If a preset is clicked, override custom selections with the calculated preset values
if ($preset === 'daily') {
    $selected_year = date('Y');
    $selected_month = date('m');
    $selected_day = date('j');
} elseif ($preset === 'weekly') {
    $start_date = date('Y-m-d', strtotime('monday this week'));
    $end_date = date('Y-m-d', strtotime('sunday this week'));
    $selected_year = date('Y', strtotime($start_date));
    $selected_month = date('m', strtotime($start_date));
    $selected_day = 'all';
} elseif ($preset === 'monthly') {
    $selected_year = date('Y');
    $selected_month = date('m');
    $selected_day = 'all';
} else {
    // If no preset clicked, check if custom dropdown parameters are sent
    if (empty($selected_year)) {
        $selected_year = date('Y');
    }
    if (empty($selected_month)) {
        $selected_month = date('m');
    }
    if (empty($selected_day)) {
        $selected_day = 'all';
    }
}

// Keep variables within expected boundaries
if (!in_array($selected_year, ['2025', '2026', '2027', '2028'])) {
    $selected_year = date('Y');
}
$selected_month = str_pad($selected_month, 2, '0', STR_PAD_LEFT);
if ((int)$selected_month < 1 || (int)$selected_month > 12) {
    $selected_month = date('m');
}

// Calculate start_date and end_date
if ($preset !== 'weekly') {
    if ($selected_day === 'all') {
        $start_date = "{$selected_year}-{$selected_month}-01";
        $end_date = date('Y-m-t', strtotime($start_date));
    } else {
        $day_padded = str_pad($selected_day, 2, '0', STR_PAD_LEFT);
        $start_date = "{$selected_year}-{$selected_month}-{$day_padded}";
        $end_date = $start_date;
    }
}

// Months mapping for selection
$months_map = [
    '01' => 'January',
    '02' => 'February',
    '03' => 'March',
    '04' => 'April',
    '05' => 'May',
    '06' => 'June',
    '07' => 'July',
    '08' => 'August',
    '09' => 'September',
    '10' => 'October',
    '11' => 'November',
    '12' => 'December'
];

// Years mapping for selection
$years_list = ['2025', '2026', '2027', '2028'];

// Human range banner format
if ($start_date === $end_date) {
    $human_range = date('F d, Y', strtotime($start_date));
} else {
    $human_range = date('F d, Y', strtotime($start_date)) . ' — ' . date('F d, Y', strtotime($end_date));
}

// 1. Fetch Bookings & Statuses Stats
$stmt_b_stats = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT b.id) AS total_bookings,
        SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_bookings,
        SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) AS completed_bookings,
        SUM(CASE WHEN b.status = 'reserved' THEN 1 ELSE 0 END) AS reserved_bookings,
        SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings,
        COALESCE(SUM(b.pax), 0) AS total_pax
    FROM bookings b
    JOIN schedules s ON b.schedule_id = s.id
    WHERE s.session_date BETWEEN :start_date AND :end_date
");
$stmt_b_stats->execute(['start_date' => $start_date, 'end_date' => $end_date]);
$b_stats = $stmt_b_stats->fetch(PDO::FETCH_ASSOC);

// 2. Fetch Revenue & Finance Stats
$stmt_rev_stats = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN p.status = 'paid' AND p.method != 'package' THEN p.amount ELSE 0 END), 0) AS revenue_collected,
        COALESCE(SUM(CASE WHEN p.status = 'unpaid' THEN p.amount ELSE 0 END), 0) AS outstanding_balance,
        COALESCE(SUM(CASE WHEN p.status = 'overdue' THEN p.amount ELSE 0 END), 0) AS overdue_balance,
        COALESCE(SUM(CASE WHEN p.status = 'refunded' THEN p.amount ELSE 0 END), 0) AS refunded_amount,
        COALESCE(SUM(CASE WHEN p.method = 'pay_now' AND p.status = 'paid' THEN p.amount ELSE 0 END), 0) AS rev_paymongo,
        COALESCE(SUM(CASE WHEN p.method = 'pay_later' AND p.status = 'paid' THEN p.amount ELSE 0 END), 0) AS rev_cash,
        COALESCE(SUM(CASE WHEN p.method = 'package' AND p.status = 'paid' THEN p.amount ELSE 0 END), 0) AS rev_package
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN schedules s ON b.schedule_id = s.id
    WHERE s.session_date BETWEEN :start_date AND :end_date AND b.status != 'cancelled'
");
$stmt_rev_stats->execute(['start_date' => $start_date, 'end_date' => $end_date]);
$rev_stats = $stmt_rev_stats->fetch(PDO::FETCH_ASSOC);

// 3. Fetch Court Utilization Stats
$stmt_util_stats = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_slots,
        SUM(CASE WHEN status IN ('confirmed', 'reserved', 'locked') THEN 1 ELSE 0 END) AS locked_booked_slots,
        SUM(CASE WHEN status IN ('confirmed', 'reserved') THEN 1 ELSE 0 END) AS utilized_slots,
        COALESCE(SUM(duration_hours), 0) AS total_hours,
        COALESCE(SUM(CASE WHEN status IN ('confirmed', 'reserved') THEN duration_hours ELSE 0 END), 0) AS utilized_hours
    FROM schedules
    WHERE session_date BETWEEN :start_date AND :end_date
");
$stmt_util_stats->execute(['start_date' => $start_date, 'end_date' => $end_date]);
$util_stats = $stmt_util_stats->fetch(PDO::FETCH_ASSOC);

$total_hours = (float)$util_stats['total_hours'];
$utilized_hours = (float)$util_stats['utilized_hours'];
$utilization_rate = $total_hours > 0 ? ($utilized_hours / $total_hours) * 100 : 0.0;

// 4. Fetch Coaching Take-up Stats
$stmt_coaching = $pdo->prepare("
    SELECT 
        COUNT(*) AS bookings_with_coaching
    FROM bookings b
    JOIN schedules s ON b.schedule_id = s.id
    WHERE s.session_date BETWEEN :start_date AND :end_date 
      AND b.status != 'cancelled'
      AND b.coaching_fee > 0
");
$stmt_coaching->execute(['start_date' => $start_date, 'end_date' => $end_date]);
$coaching_bookings = (int)$stmt_coaching->fetchColumn();
$total_valid_bookings = (int)$b_stats['total_bookings'] - (int)$b_stats['cancelled_bookings'];
$coaching_rate = $total_valid_bookings > 0 ? ($coaching_bookings / $total_valid_bookings) * 100 : 0.0;

// 5. Fetch Attendance Rate
$stmt_attendance = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_attendance_records,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count
    FROM attendance a
    JOIN bookings b ON a.booking_id = b.id
    JOIN schedules s ON b.schedule_id = s.id
    WHERE s.session_date BETWEEN :start_date AND :end_date
      AND b.status != 'cancelled'
");
$stmt_attendance->execute(['start_date' => $start_date, 'end_date' => $end_date]);
$att_stats = $stmt_attendance->fetch(PDO::FETCH_ASSOC);

$total_attendance_records = (int)$att_stats['total_attendance_records'];
$present_count = (int)$att_stats['present_count'];
$attendance_rate = $total_attendance_records > 0 ? ($present_count / $total_attendance_records) * 100 : 0.0;

// 6. Fetch Daily Breakdown for Timeline List
$stmt_daily = $pdo->prepare("
    SELECT 
        s.session_date,
        COUNT(DISTINCT s.id) AS total_slots,
        SUM(CASE WHEN s.status IN ('confirmed', 'reserved') THEN 1 ELSE 0 END) AS utilized_slots,
        COALESCE(SUM(s.duration_hours), 0) AS total_hours,
        COALESCE(SUM(CASE WHEN s.status IN ('confirmed', 'reserved') THEN s.duration_hours ELSE 0 END), 0) AS utilized_hours,
        COUNT(DISTINCT CASE WHEN b.status != 'cancelled' THEN b.id END) AS bookings_count,
        COALESCE(SUM(CASE WHEN p.status = 'paid' AND b.status != 'cancelled' AND p.method != 'package' THEN p.amount ELSE 0 END), 0) AS daily_revenue
    FROM schedules s
    LEFT JOIN bookings b ON b.schedule_id = s.id
    LEFT JOIN payments p ON p.booking_id = b.id
    WHERE s.session_date BETWEEN :start_date AND :end_date
    GROUP BY s.session_date
    ORDER BY s.session_date ASC
");
$stmt_daily->execute(['start_date' => $start_date, 'end_date' => $end_date]);
$raw_breakdown = $stmt_daily->fetchAll(PDO::FETCH_ASSOC);

// Pre-fill daily breakdown array with all dates of the selected month to ensure chronological completeness
$daily_breakdown_map = [];
$current_time = strtotime($start_date);
$end_time = strtotime($end_date);
while ($current_time <= $end_time) {
    $date_str = date('Y-m-d', $current_time);
    $daily_breakdown_map[$date_str] = [
        'session_date'   => $date_str,
        'total_slots'    => 0,
        'utilized_slots' => 0,
        'total_hours'    => 0.0,
        'utilized_hours' => 0.0,
        'bookings_count' => 0,
        'daily_revenue'  => 0.0
    ];
    $current_time = strtotime('+1 day', $current_time);
}

// Populate database statistics onto each corresponding date
foreach ($raw_breakdown as $row) {
    $d = $row['session_date'];
    if (isset($daily_breakdown_map[$d])) {
        $daily_breakdown_map[$d] = [
            'session_date'   => $d,
            'total_slots'    => (int)$row['total_slots'],
            'utilized_slots' => (int)$row['utilized_slots'],
            'total_hours'    => (float)$row['total_hours'],
            'utilized_hours' => (float)$row['utilized_hours'],
            'bookings_count' => (int)$row['bookings_count'],
            'daily_revenue'  => (float)$row['daily_revenue']
        ];
    }
}
$daily_breakdown = array_values($daily_breakdown_map);

// 7. Fetch Detailed Booking & Sessions Log
$stmt_log = $pdo->prepare("
    SELECT 
        b.booking_code,
        u.full_name AS client_name,
        s.session_date,
        s.start_time,
        s.end_time,
        b.pax,
        b.coaching_fee,
        b.court_fee,
        b.total_fee,
        b.status AS booking_status,
        p.method AS payment_method,
        p.status AS payment_status,
        COALESCE(sess.status, 'scheduled') AS session_status
    FROM bookings b
    JOIN schedules s ON b.schedule_id = s.id
    JOIN users u ON b.user_id = u.id
    LEFT JOIN payments p ON p.booking_id = b.id
    LEFT JOIN sessions sess ON sess.booking_id = b.id
    WHERE s.session_date BETWEEN :start_date AND :end_date
    ORDER BY s.session_date DESC, s.start_time DESC
");
$stmt_log->execute(['start_date' => $start_date, 'end_date' => $end_date]);
$booking_logs = $stmt_log->fetchAll(PDO::FETCH_ASSOC);

// Format date range representation for humans
$human_range = date('M d, Y', strtotime($start_date)) . ' — ' . date('M d, Y', strtotime($end_date));
if ($start_date === $end_date) {
    $human_range = date('F d, Y', strtotime($start_date));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>RDG Tennis - Summary Reports</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700;900&family=Lexend:wght@300;400;500;600;700&family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
    .court-grid{background-image:linear-gradient(#e5e7eb 1px,transparent 1px),linear-gradient(90deg,#e5e7eb 1px,transparent 1px);background-size:64px 64px;}
    .material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
    .fill-icon{font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;}
    @keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
    .fade-in{animation:fadeIn .3s ease both}
    .metric-card{transition:all .15s ease}
    .metric-card:hover{transform:translateY(-2px);box-shadow:6px 6px 0px rgba(21,66,18,1)}

    /* PRINT STYLES */
    @media print {
        /* Hide all standard screen elements */
        header, aside, main, #filter-section, .no-print, .print-header {
            display: none !important;
        }
        
        body {
            background: white !important;
            color: #1f2937 !important;
            margin: 0 !important;
            padding: 0 !important;
            font-size: 9px !important;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
        }

        /* Show the formal print report container */
        #formal-print-report {
            display: block !important;
        }

        /* Spacing & Page layout rules */
        .print-page-break-avoid {
            page-break-inside: avoid !important;
        }
        
        .print-section {
            margin-bottom: 24px !important;
            page-break-inside: avoid !important;
        }

        /* Premium formal tables */
        .print-table {
            width: 100% !important;
            border-collapse: collapse !important;
            margin-top: 8px !important;
            font-size: 8.5px !important;
        }
        .print-table th {
            background-color: #f3f4f6 !important;
            color: #111827 !important;
            border: 1px solid #d1d5db !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            padding: 8px 10px !important;
            text-align: left;
        }
        .print-table td {
            border: 1px solid #e5e7eb !important;
            padding: 7px 10px !important;
            color: #374151 !important;
        }
        .print-table tr:nth-child(even) {
            background-color: #f9fafb !important;
        }
        
        /* Badges styling in print */
        .print-badge {
            display: inline-block !important;
            padding: 2px 6px !important;
            font-size: 7.5px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            border: 1px solid #d1d5db !important;
            background: #f3f4f6 !important;
            color: #374151 !important;
        }
        .print-badge-success {
            border-color: #a7f3d0 !important;
            background-color: #ecfdf5 !important;
            color: #065f46 !important;
        }
        .print-badge-danger {
            border-color: #fca5a5 !important;
            background-color: #fef2f2 !important;
            color: #991b1b !important;
        }
        .print-badge-info {
            border-color: #bfdbfe !important;
            background-color: #eff6ff !important;
            color: #1e40af !important;
        }
        .print-badge-warning {
            border-color: #fde68a !important;
            background-color: #fffbeb !important;
            color: #92400e !important;
        }
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
<header class="fixed top-0 left-0 right-0 z-50 bg-white flex justify-between items-center px-6 h-20 border-b-2 border-primary no-print">
    <div class="flex items-center gap-3">
        <img alt="RDG" class="h-10 w-auto" src="/RDG/RDG Logo.jpg"/>
    </div>
    <div class="flex items-center gap-4">
        <div class="hidden md:inline font-bold text-xs uppercase text-primary"><?= htmlspecialchars($admin_name) ?></div>
        <a href="/RDG/auth/logout.php" class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-accent hover:opacity-80 transition-opacity">
            <span class="material-symbols-outlined text-xl">logout</span>
        </a>
    </div>
</header>

<!-- Sidebar -->
<aside class="fixed left-0 top-20 h-[calc(100vh-80px)] w-64 bg-[#F8F9FA] border-r-2 border-primary flex flex-col py-4 z-40 no-print">
    <div class="px-6 py-4 mb-2 border-b border-zinc-200">
        <p class="font-headline font-bold uppercase text-sm text-primary">Admin Panel</p>
        <p class="text-xs text-[#42493e] font-bold uppercase">Court Management</p>
    </div>
    <nav class="flex flex-col gap-1">
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="dashboard.php"><span class="material-symbols-outlined">grid_view</span> Dashboard</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="schedule.php"><span class="material-symbols-outlined">calendar_today</span> Schedule</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="bookings.php"><span class="material-symbols-outlined">payments</span> Bookings</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="attendance.php"><span class="material-symbols-outlined">fact_check</span> Attendance</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="cancellations.php"><span class="material-symbols-outlined">cancel</span> Requests</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="months.php"><span class="material-symbols-outlined">calendar_month</span> Months</a>
        <a class="bg-[#FFE500] text-primary border-l-4 border-primary px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm" href="reports.php"><span class="material-symbols-outlined fill-icon">analytics</span> Reports</a>
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

<!-- Print-only Report Header -->
<div class="print-header w-full border-b-4 border-primary pb-6 mb-6">
    <div class="flex justify-between items-end">
        <div>
            <h1 class="font-headline font-black text-3xl text-primary uppercase tracking-tighter">RDG TENNIS CENTER</h1>
            <p class="text-xs uppercase font-bold text-zinc-500">System Performance & Financial Summary Report</p>
        </div>
        <div class="text-right">
            <p class="text-xs font-bold uppercase text-primary">Generated By: <?= htmlspecialchars($admin_name) ?></p>
            <p class="text-xs font-bold text-zinc-500">Date: <?= date('F d, Y h:i A') ?></p>
        </div>
    </div>
    <div class="mt-4 bg-primary text-white p-3 font-headline font-bold text-center uppercase tracking-widest text-sm range-banner">
        Report Range: <?= $human_range ?> (Preset: <?= strtoupper($preset) ?>)
    </div>
</div>

<!-- Main Content Area -->
<main class="md:ml-64 pt-20 min-h-screen p-4 md:p-8">
    <div class="max-w-7xl mx-auto space-y-8 fade-in">
        
        <!-- Header / Hero Section -->
        <section class="flex flex-col gap-4 border-b-4 border-primary pb-6 no-print">
            <div class="flex items-center gap-3">
                <button onclick="window.print()" class="bg-white text-primary border-2 border-primary font-headline font-black text-xs px-4 py-3 uppercase tracking-wider shadow-[3px_3px_0px_rgba(21,66,18,1)] hover:bg-[#f6f3f2] active:translate-y-[2px] active:shadow-none transition-all">
                    Print Report
                </button>
                <button onclick="exportReportToExcel()" class="bg-accent text-primary border-2 border-primary font-headline font-black text-xs px-4 py-3 uppercase tracking-wider shadow-[3px_3px_0px_rgba(21,66,18,1)] hover:bg-[#ffe500] active:translate-y-[2px] active:shadow-none transition-all">
                    Export Excel
                </button>
            </div>
            <div class="space-y-1">
                <h1 class="font-headline font-black text-4xl md:text-5xl text-primary uppercase tracking-tighter">Summary Reports</h1>
                <p class="text-[#42493e] text-sm">Analyze bookings activity, revenue streams, attendance statistics, and court utilization rates.</p>
            </div>
        </section>



        <!-- Quick Report Preset Buttons -->
        <div class="flex flex-wrap gap-3 no-print">
            <a href="?preset=daily" class="px-5 py-3 border-2 border-primary font-headline font-black text-xs uppercase tracking-wider shadow-[3px_3px_0px_rgba(21,66,18,1)] hover:bg-[#f6f3f2] active:translate-y-[1px] active:shadow-none transition-all <?= $preset === 'daily' ? 'bg-[#FFE500] text-primary' : 'bg-white text-zinc-600' ?>">
                Daily Report
            </a>
            <a href="?preset=weekly" class="px-5 py-3 border-2 border-primary font-headline font-black text-xs uppercase tracking-wider shadow-[3px_3px_0px_rgba(21,66,18,1)] hover:bg-[#f6f3f2] active:translate-y-[1px] active:shadow-none transition-all <?= $preset === 'weekly' ? 'bg-[#FFE500] text-primary' : 'bg-white text-zinc-600' ?>">
                Weekly Report
            </a>
            <a href="?preset=monthly" class="px-5 py-3 border-2 border-primary font-headline font-black text-xs uppercase tracking-wider shadow-[3px_3px_0px_rgba(21,66,18,1)] hover:bg-[#f6f3f2] active:translate-y-[1px] active:shadow-none transition-all <?= $preset === 'monthly' ? 'bg-[#FFE500] text-primary' : 'bg-white text-zinc-600' ?>">
                Monthly Report
            </a>
        </div>

        <!-- Range Status Tag for PDF Print / Screen Display -->
        <div class="bg-[#FFE500] text-primary font-headline font-black text-center py-3 uppercase tracking-wider text-xs border-2 border-primary shadow-[4px_4px_0px_rgba(21,66,18,1)]">
            Reporting Month: <span class="text-primary font-black"><?= $human_range ?></span>
        </div>

        <!-- Metric KPI Cards -->
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6">
            <!-- Bookings Count -->
            <div class="metric-card bg-white p-6 border-2 border-primary relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-blue-500"></div>
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Bookings Count</p>
                <span class="block font-headline font-black text-4xl text-primary mt-2"><?= $total_valid_bookings ?></span>
                <div class="mt-4 flex flex-col gap-1 text-[10px] text-zinc-500 font-bold uppercase">
                    <div class="flex justify-between"><span>Completed</span> <span class="text-primary font-black"><?= (int)$b_stats['completed_bookings'] ?></span></div>
                    <div class="flex justify-between"><span>Confirmed</span> <span class="text-primary font-black"><?= (int)$b_stats['confirmed_bookings'] ?></span></div>
                    <div class="flex justify-between"><span>Reserved</span> <span class="text-primary font-black"><?= (int)$b_stats['reserved_bookings'] ?></span></div>
                    <div class="flex justify-between"><span>Cancelled</span> <span class="text-red-600 font-black"><?= (int)$b_stats['cancelled_bookings'] ?></span></div>
                </div>
            </div>

            <!-- Revenue Collected -->
            <div class="metric-card bg-white p-6 border-2 border-primary relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-green-500"></div>
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Collected Revenue</p>
                <span class="block font-headline font-black text-3xl text-primary mt-2">₱<?= number_format((float)$rev_stats['revenue_collected'], 2) ?></span>
                <div class="mt-4 flex flex-col gap-1 text-[10px] text-zinc-500 font-bold uppercase">
                    <div class="flex justify-between"><span>PayMongo</span> <span class="text-primary font-black">₱<?= number_format((float)$rev_stats['rev_paymongo'], 2) ?></span></div>
                    <div class="flex justify-between"><span>Cash (Pay Later)</span> <span class="text-primary font-black">₱<?= number_format((float)$rev_stats['rev_cash'], 2) ?></span></div>
                </div>
            </div>

            <!-- Outstanding Balances -->
            <div class="metric-card bg-white p-6 border-2 border-primary relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-red-500"></div>
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Outstanding Debts</p>
                <span class="block font-headline font-black text-3xl text-red-600 mt-2">₱<?= number_format((float)$rev_stats['outstanding_balance'] + (float)$rev_stats['overdue_balance'], 2) ?></span>
                <div class="mt-4 flex flex-col gap-1 text-[10px] text-zinc-500 font-bold uppercase">
                    <div class="flex justify-between"><span>Unpaid / Pending</span> <span class="text-primary font-black">₱<?= number_format((float)$rev_stats['outstanding_balance'], 2) ?></span></div>
                    <div class="flex justify-between"><span>Overdue Limits</span> <span class="text-red-600 font-black">₱<?= number_format((float)$rev_stats['overdue_balance'], 2) ?></span></div>
                    <div class="flex justify-between"><span>Refunded</span> <span class="text-zinc-500">₱<?= number_format((float)$rev_stats['refunded_amount'], 2) ?></span></div>
                </div>
            </div>

            <!-- Court Utilization -->
            <div class="metric-card bg-white p-6 border-2 border-primary relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-accent"></div>
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Court Utilization</p>
                <span class="block font-headline font-black text-4xl text-primary mt-2"><?= number_format($utilization_rate, 1) ?>%</span>
                <div class="mt-4 flex flex-col gap-1 text-[10px] text-zinc-500 font-bold uppercase">
                    <div class="flex justify-between"><span>Allocated Slots</span> <span class="text-primary font-black"><?= (int)$util_stats['total_slots'] ?></span></div>
                    <div class="flex justify-between"><span>Booked Slots</span> <span class="text-primary font-black"><?= (int)$util_stats['utilized_slots'] ?></span></div>
                    <div class="flex justify-between"><span>Total Hours</span> <span class="text-primary"><?= number_format($total_hours, 1) ?></span></div>
                    <div class="flex justify-between"><span>Booked Hours</span> <span class="text-primary font-black"><?= number_format($utilized_hours, 1) ?></span></div>
                </div>
            </div>

            <!-- Coaching Take-up & Attendance -->
            <div class="metric-card bg-white p-6 border-2 border-primary relative shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-zinc-700"></div>
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Coaching & Attendance</p>
                <div class="mt-2 space-y-2">
                    <div>
                        <span class="text-zinc-400 text-[9px] uppercase font-bold">Coaching Add-on</span>
                        <span class="block font-headline font-black text-xl text-primary"><?= number_format($coaching_rate, 1) ?>%</span>
                    </div>
                    <div>
                        <span class="text-zinc-400 text-[9px] uppercase font-bold">Attendance Rate</span>
                        <span class="block font-headline font-black text-xl text-primary"><?= number_format($attendance_rate, 1) ?>%</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Filters Section -->
        <section id="filter-section" class="bg-white border-2 border-primary p-6 shadow-[4px_4px_0px_rgba(21,66,18,1)] no-print">
            <form id="filter-form" method="GET" class="grid grid-cols-1 sm:grid-cols-4 gap-6 items-end">
                <!-- Month select dropdown -->
                <div class="space-y-2">
                    <label for="filter_month" class="block text-xs font-black uppercase text-primary tracking-wider">MONTH</label>
                    <select id="filter_month" name="filter_month" onchange="this.form.submit()" class="w-full border-2 border-primary bg-white px-4 py-3 font-headline font-black uppercase text-sm focus:ring-primary focus:border-primary shadow-[2px_2px_0px_rgba(21,66,18,1)]">
                        <?php foreach ($months_map as $m_val => $m_name): ?>
                            <option value="<?= htmlspecialchars($m_val) ?>" <?= $m_val === $selected_month ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m_name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date select dropdown -->
                <div class="space-y-2">
                    <label for="filter_day" class="block text-xs font-black uppercase text-primary tracking-wider">DATE</label>
                    <select id="filter_day" name="filter_day" onchange="this.form.submit()" class="w-full border-2 border-primary bg-white px-4 py-3 font-headline font-black uppercase text-sm focus:ring-primary focus:border-primary shadow-[2px_2px_0px_rgba(21,66,18,1)]">
                        <option value="all" <?= $selected_day === 'all' ? 'selected' : '' ?>>All Days</option>
                        <?php for ($d = 1; $d <= 31; $d++): ?>
                            <option value="<?= $d ?>" <?= (string)$selected_day === (string)$d ? 'selected' : '' ?>>
                                <?= $d ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <!-- Year select dropdown -->
                <div class="space-y-2">
                    <label for="filter_year" class="block text-xs font-black uppercase text-primary tracking-wider">YEAR</label>
                    <select id="filter_year" name="filter_year" onchange="this.form.submit()" class="w-full border-2 border-primary bg-white px-4 py-3 font-headline font-black uppercase text-sm focus:ring-primary focus:border-primary shadow-[2px_2px_0px_rgba(21,66,18,1)]">
                        <?php foreach ($years_list as $y_val): ?>
                            <option value="<?= htmlspecialchars($y_val) ?>" <?= $y_val === $selected_year ? 'selected' : '' ?>>
                                <?= htmlspecialchars($y_val) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <button type="submit" class="w-full bg-primary text-white font-headline font-black text-xs px-6 py-3.5 uppercase tracking-widest border-2 border-primary shadow-[3px_3px_0px_rgba(0,0,0,0.15)] hover:bg-opacity-95 hover:translate-y-[-1px] active:translate-y-[1px] active:shadow-none transition-all">
                        Generate Report
                    </button>
                </div>
            </form>
        </section>

        <!-- Daily Summary Breakdown -->
        <section class="bg-white border-2 border-primary p-6 shadow-[4px_4px_0px_rgba(21,66,18,1)] shadow-neobrutal">
            <div class="flex justify-between items-center mb-6 border-b-2 border-primary pb-4">
                <h3 class="font-headline font-bold text-xl text-primary uppercase">Daily Utilization & Financial Timeline</h3>
                <span class="text-xs text-zinc-400 font-bold uppercase">Chronological Records</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse border-2 border-primary text-left text-xs">
                    <thead>
                        <tr class="bg-[#FFE500] text-primary border-b-2 border-primary">
                            <th class="p-3 border-r-2 border-primary font-headline font-black uppercase">Date</th>
                            <th class="p-3 border-r-2 border-primary font-headline font-black uppercase text-center">Allocated Slots</th>
                            <th class="p-3 border-r-2 border-primary font-headline font-black uppercase text-center">Booked Slots</th>
                            <th class="p-3 border-r-2 border-primary font-headline font-black uppercase text-center">Allocated Hours</th>
                            <th class="p-3 border-r-2 border-primary font-headline font-black uppercase text-center">Booked Hours</th>
                            <th class="p-3 border-r-2 border-primary font-headline font-black uppercase text-center">Utilization Rate</th>
                            <th class="p-3 border-r-2 border-primary font-headline font-black uppercase text-center">Bookings Count</th>
                            <th class="p-3 font-headline font-black uppercase text-right">Revenue Paid</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y-2 divide-primary">
                        <?php if (empty($daily_breakdown)): ?>
                            <tr>
                                <td colspan="8" class="p-6 text-center text-zinc-400 italic">No timeline entries found in this date range.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($daily_breakdown as $row):
                                $dh_tot = (float)$row['total_hours'];
                                $dh_util = (float)$row['utilized_hours'];
                                $day_util_rate = $dh_tot > 0 ? ($dh_util / $dh_tot) * 100 : 0.0;
                            ?>
                                <tr class="hover:bg-[#fcf9f8] transition-colors">
                                    <td class="p-3 border-r-2 border-primary font-bold uppercase"><?= date('D, M d, Y', strtotime($row['session_date'])) ?></td>
                                    <td class="p-3 border-r-2 border-primary text-center font-bold"><?= (int)$row['total_slots'] ?></td>
                                    <td class="p-3 border-r-2 border-primary text-center font-bold"><?= (int)$row['utilized_slots'] ?></td>
                                    <td class="p-3 border-r-2 border-primary text-center"><?= number_format($dh_tot, 1) ?>h</td>
                                    <td class="p-3 border-r-2 border-primary text-center font-bold"><?= number_format($dh_util, 1) ?>h</td>
                                    <td class="p-3 border-r-2 border-primary text-center font-black <?= $day_util_rate > 50 ? 'text-[#4a8f3c]' : 'text-zinc-600' ?>">
                                        <?= number_format($day_util_rate, 1) ?>%
                                    </td>
                                    <td class="p-3 border-r-2 border-primary text-center font-bold"><?= (int)$row['bookings_count'] ?></td>
                                    <td class="p-3 font-black text-right text-primary">₱<?= number_format((float)$row['daily_revenue'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Detailed Booking and Session Logs -->
        <section class="bg-white border-2 border-primary p-6 shadow-[4px_4px_0px_rgba(21,66,18,1)] shadow-neobrutal">
            <div class="flex justify-between items-center mb-6 border-b-2 border-primary pb-4">
                <h3 class="font-headline font-bold text-xl text-primary uppercase">Itemized Booking & Sessions Registry</h3>
                <span class="text-xs text-zinc-400 font-bold uppercase">Granular audit list</span>
            </div>
            
            <!-- Registry Instant Search & Date Filter Bar -->
            <div class="flex flex-wrap gap-4 items-center bg-[#fcf9f8] p-4 border-2 border-primary mb-6 no-print">
                <div class="flex-1 min-w-[200px] relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-zinc-400 pointer-events-none">
                        <span class="material-symbols-outlined text-sm">search</span>
                    </span>
                    <input type="text" id="registry-search-name" placeholder="Search by player name..." class="w-full pl-9 pr-4 py-2 border-2 border-primary bg-white text-xs font-bold uppercase placeholder-zinc-400 focus:outline-none focus:ring-0 focus:border-primary shadow-[2px_2px_0px_rgba(21,66,18,1)]" />
                </div>
                <div class="w-full sm:w-auto flex items-center gap-2">
                    <label for="registry-filter-date" class="text-[10px] font-black uppercase text-primary tracking-wider">Session Date:</label>
                    <input type="date" id="registry-filter-date" class="px-3 py-2 border-2 border-primary bg-white text-xs font-bold uppercase focus:outline-none focus:ring-0 focus:border-primary shadow-[2px_2px_0px_rgba(21,66,18,1)]" />
                </div>
                <button id="registry-clear-btn" onclick="clearRegistryFilters()" class="px-4 py-2 bg-white text-primary border-2 border-primary font-headline font-black text-xs uppercase tracking-wider shadow-[2px_2px_0px_rgba(21,66,18,1)] hover:bg-zinc-100 active:translate-y-[1px] active:shadow-none transition-all">
                    Clear Filters
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse border-2 border-primary text-left text-xs">
                    <thead>
                        <tr class="bg-primary text-white border-b-2 border-primary">
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase">Code</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase">Client</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase">Play Schedule</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase text-center">Pax</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase text-right">Coaching</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase text-right">Court Fee</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase text-right">Total Fee</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase text-center">Booking Status</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase text-center">Payment Method</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase text-center">Payment Status</th>
                            <th class="p-3 font-headline font-bold uppercase text-center">Attendance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        <?php if (empty($booking_logs)): ?>
                            <tr>
                                <td colspan="11" class="p-6 text-center text-zinc-400 italic">No bookings recorded during this period.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($booking_logs as $log): 
                                $b_badge_cls = "bg-zinc-100 text-zinc-700";
                                if ($log['booking_status'] === 'confirmed') $b_badge_cls = "bg-green-100 text-[#4a8f3c]";
                                elseif ($log['booking_status'] === 'completed') $b_badge_cls = "bg-blue-100 text-blue-700";
                                elseif ($log['booking_status'] === 'cancelled') $b_badge_cls = "bg-red-100 text-red-600";
                                elseif ($log['booking_status'] === 'reserved') $b_badge_cls = "bg-amber-100 text-amber-700";

                                $p_badge_cls = "bg-zinc-100 text-zinc-700";
                                if ($log['payment_status'] === 'paid') $p_badge_cls = "bg-green-100 text-[#4a8f3c]";
                                elseif ($log['payment_status'] === 'unpaid') $p_badge_cls = "bg-amber-100 text-amber-700";
                                elseif ($log['payment_status'] === 'overdue') $p_badge_cls = "bg-red-100 text-red-600";
                                elseif ($log['payment_status'] === 'refunded') $p_badge_cls = "bg-zinc-100 text-zinc-500";

                                $att_badge_cls = "bg-zinc-100 text-zinc-600";
                                if ($log['session_status'] === 'completed') $att_badge_cls = "bg-green-100 text-[#4a8f3c] font-black";
                                elseif ($log['session_status'] === 'no_show') $att_badge_cls = "bg-red-100 text-red-600 font-bold";
                            ?>
                                <tr class="registry-row hover:bg-zinc-50 transition-colors" data-name="<?= htmlspecialchars(strtolower($log['client_name'] ?? '')) ?>" data-date="<?= htmlspecialchars($log['session_date']) ?>">
                                    <td class="p-3 border-r border-zinc-200 font-headline font-black text-primary uppercase"><?= htmlspecialchars($log['booking_code']) ?></td>
                                    <td class="p-3 border-r border-zinc-200 font-bold"><?= htmlspecialchars($log['client_name']) ?></td>
                                    <td class="p-3 border-r border-zinc-200 font-medium">
                                        <?= date('M d, Y', strtotime($log['session_date'])) ?><br/>
                                        <span class="text-[10px] text-zinc-500 font-bold"><?= date('h:i A', strtotime($log['start_time'])) ?> - <?= date('h:i A', strtotime($log['end_time'])) ?></span>
                                    </td>
                                    <td class="p-3 border-r border-zinc-200 text-center font-bold"><?= (int)$log['pax'] ?></td>
                                    <td class="p-3 border-r border-zinc-200 text-right">₱<?= number_format((float)$log['coaching_fee'], 2) ?></td>
                                    <td class="p-3 border-r border-zinc-200 text-right">₱<?= number_format((float)$log['court_fee'], 2) ?></td>
                                    <td class="p-3 border-r border-zinc-200 text-right font-bold text-primary">₱<?= number_format((float)$log['total_fee'], 2) ?></td>
                                    <td class="p-3 border-r border-zinc-200 text-center">
                                        <span class="px-2 py-0.5 text-[9px] font-black uppercase <?= $b_badge_cls ?>"><?= $log['booking_status'] ?></span>
                                    </td>
                                    <td class="p-3 border-r border-zinc-200 text-center font-semibold text-zinc-600 uppercase text-[10px]">
                                        <?= str_replace('_', ' ', htmlspecialchars($log['payment_method'] ?? '')) ?>
                                    </td>
                                    <td class="p-3 border-r border-zinc-200 text-center">
                                        <span class="px-2 py-0.5 text-[9px] font-black uppercase <?= $p_badge_cls ?>"><?= $log['payment_status'] ?? 'unpaid' ?></span>
                                    </td>
                                    <td class="p-3 text-center">
                                        <span class="px-2 py-0.5 text-[9px] font-bold uppercase <?= $att_badge_cls ?>"><?= $log['session_status'] ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </div>
</main>

<script>
// Pass PHP data safely to JS using JSON encoding to prevent quote escaping errors
const dailyBreakdown = <?= json_encode($daily_breakdown) ?>;
const bookingLogs = <?= json_encode($booking_logs) ?>;
const totalValidBookings = <?= json_encode($total_valid_bookings) ?>;
const completedBookings = <?= json_encode((int)$b_stats['completed_bookings']) ?>;
const confirmedBookings = <?= json_encode((int)$b_stats['confirmed_bookings']) ?>;
const reservedBookings = <?= json_encode((int)$b_stats['reserved_bookings']) ?>;
const cancelledBookings = <?= json_encode((int)$b_stats['cancelled_bookings']) ?>;
const revenueCollected = <?= json_encode((float)$rev_stats['revenue_collected']) ?>;
const revPaymongo = <?= json_encode((float)$rev_stats['rev_paymongo']) ?>;
const revCash = <?= json_encode((float)$rev_stats['rev_cash']) ?>;
const outstandingBalance = <?= json_encode((float)$rev_stats['outstanding_balance'] + (float)$rev_stats['overdue_balance']) ?>;
const utilizationRate = <?= json_encode($utilization_rate) ?>;
const coachingRate = <?= json_encode($coaching_rate) ?>;
const attendanceRate = <?= json_encode($attendance_rate) ?>;

const selectedMonth = <?= json_encode($selected_month) ?>;
const selectedYear = <?= json_encode($selected_year) ?>;
const selectedDay = <?= json_encode($selected_day) ?>;
const sDate = <?= json_encode($start_date) ?>;
const eDate = <?= json_encode($end_date) ?>;

// Convert variables & logs array into a premium, styled multi-sheet Excel workbook (.xls)
function exportReportToExcel() {
    function escapeXml(unsafe) {
        if (unsafe === null || unsafe === undefined) return '';
        return String(unsafe).replace(/[<>&'"]/g, function (c) {
            switch (c) {
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '&': return '&amp;';
                case '\'': return '&apos;';
                case '"': return '&quot;';
                default: return c;
            }
        });
    }

    let xml = `<?xml version="1.0"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Author>RDG ADMIN</Author>
  <Created>${new Date().toISOString()}</Created>
 </DocumentProperties>
 <Styles>
  <!-- Normal / Default Cell Style -->
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Center"/>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937"/>
  </Style>
  
  <!-- Banner / Header Titles -->
  <Style ss:ID="sTitle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Segoe UI" ss:Size="16" ss:Color="#FFFFFF" ss:Bold="1"/>
   <Interior ss:Color="#154212" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sSubtitle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#4B5563" ss:Bold="1"/>
   <Interior ss:Color="#F3F4F6" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sSection">
   <Font ss:FontName="Segoe UI" ss:Size="12" ss:Color="#154212" ss:Bold="1"/>
  </Style>
  
  <!-- Table Header Styles -->
  <Style ss:ID="sTableHeader">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#9CA3AF"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#9CA3AF"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#9CA3AF"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#9CA3AF"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#FFFFFF" ss:Bold="1"/>
   <Interior ss:Color="#154212" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sTableHeaderSec">
   <Alignment ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#9CA3AF"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#9CA3AF"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#9CA3AF"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#9CA3AF"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
   <Interior ss:Color="#F3F4F6" ss:Pattern="Solid"/>
  </Style>

  <!-- Default Styled Cells -->
  <Style ss:ID="sCell">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
  </Style>
  <Style ss:ID="sCellZebra">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sCellBold">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
  </Style>
  <Style ss:ID="sCellZebraBold">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
  </Style>

  <!-- Left Aligned Cells (e.g. for Text and Names) -->
  <Style ss:ID="sCellLeft">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
  </Style>
  <Style ss:ID="sCellLeftZebra">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sCellLeftBold">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
  </Style>
  <Style ss:ID="sCellLeftZebraBold">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
  </Style>

  <!-- Centered Cells -->
  <Style ss:ID="sCellCenter">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
  </Style>
  <Style ss:ID="sCellCenterZebra">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sCellCenterBold">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
  </Style>
  <Style ss:ID="sCellCenterZebraBold">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
  </Style>

  <!-- Numeric Whole Cell Styles -->
  <Style ss:ID="sCellNumber">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <NumberFormat ss:Format="#,##0"/>
  </Style>
  <Style ss:ID="sCellNumberZebra">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="#,##0"/>
  </Style>
  <Style ss:ID="sCellNumberBold">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
   <NumberFormat ss:Format="#,##0"/>
  </Style>
  <Style ss:ID="sCellNumberZebraBold">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="#,##0"/>
  </Style>

  <!-- Hours Custom Cell Styles -->
  <Style ss:ID="sCellHours">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <NumberFormat ss:Format="0.0&quot;h&quot;"/>
  </Style>
  <Style ss:ID="sCellHoursZebra">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="0.0&quot;h&quot;"/>
  </Style>

  <!-- Percentage Cell Styles -->
  <Style ss:ID="sCellPercent">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <NumberFormat ss:Format="0.0%"/>
  </Style>
  <Style ss:ID="sCellPercentZebra">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="0.0%"/>
  </Style>
  <Style ss:ID="sCellPercentBold">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
   <NumberFormat ss:Format="0.0%"/>
  </Style>
  <Style ss:ID="sCellPercentZebraBold">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="0.0%"/>
  </Style>

  <!-- Currency Cell Styles -->
  <Style ss:ID="sCellCurrency">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <NumberFormat ss:Format="&quot;₱&quot;#,##0.00"/>
  </Style>
  <Style ss:ID="sCellCurrencyZebra">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="&quot;₱&quot;#,##0.00"/>
  </Style>
  <Style ss:ID="sCellCurrencyBold">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
   <NumberFormat ss:Format="&quot;₱&quot;#,##0.00"/>
  </Style>
  <Style ss:ID="sCellCurrencyZebraBold">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#1F2937" ss:Bold="1"/>
   <Interior ss:Color="#F9FAF8" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="&quot;₱&quot;#,##0.00"/>
  </Style>

  <!-- Badges inside cells (Maintains clean borders and alignment) -->
  <Style ss:ID="sBadgeSuccess">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#A7F3D0"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#A7F3D0"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#A7F3D0"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#A7F3D0"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="9.5" ss:Color="#065F46" ss:Bold="1"/>
   <Interior ss:Color="#ECFDF5" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sBadgeDanger">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FCA5A5"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FCA5A5"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FCA5A5"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FCA5A5"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="9.5" ss:Color="#991B1B" ss:Bold="1"/>
   <Interior ss:Color="#FEF2F2" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sBadgeWarning">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FDE68A"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FDE68A"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FDE68A"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FDE68A"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="9.5" ss:Color="#92400E" ss:Bold="1"/>
   <Interior ss:Color="#FFFBEB" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="sBadgeInfo">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
   </Borders>
   <Font ss:FontName="Segoe UI" ss:Size="9.5" ss:Color="#1E40AF" ss:Bold="1"/>
   <Interior ss:Color="#EFF6FF" ss:Pattern="Solid"/>
  </Style>

  <!-- Metadata labels -->
  <Style ss:ID="sMetaLabel">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Font ss:FontName="Segoe UI" ss:Size="10" ss:Color="#4B5563" ss:Bold="1"/>
   <Interior ss:Color="#F3F4F6" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
  </Style>
  <Style ss:ID="sMetaVal">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
   </Borders>
  </Style>
 </Styles>
`;

    // TAB 1: EXECUTIVE SUMMARY
    xml += `
 <Worksheet ss:Name="Executive KPI Summary">
  <Table>
   <Column ss:Width="250"/>
   <Column ss:Width="200"/>
   
   <!-- Title Banner -->
   <Row ss:Height="40">
    <Cell ss:MergeAcross="1" ss:StyleID="sTitle"><Data ss:Type="String">RDG TENNIS CENTER</Data></Cell>
   </Row>
   <Row ss:Height="25">
    <Cell ss:MergeAcross="1" ss:StyleID="sSubtitle"><Data ss:Type="String">OFFICIAL SYSTEM PERFORMANCE &amp; SUMMARY REPORT</Data></Cell>
   </Row>
   <Row ss:Height="15"></Row>

   <!-- Report Metadata -->
   <Row ss:Height="22">
    <Cell ss:StyleID="sMetaLabel"><Data ss:Type="String">Report Period</Data></Cell>
    <Cell ss:StyleID="sMetaVal"><Data ss:Type="String">${escapeXml(sDate)} to ${escapeXml(eDate)}</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:StyleID="sMetaLabel"><Data ss:Type="String">Generated By</Data></Cell>
    <Cell ss:StyleID="sMetaVal"><Data ss:Type="String">RDG ADMIN</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:StyleID="sMetaLabel"><Data ss:Type="String">Execution Time</Data></Cell>
    <Cell ss:StyleID="sMetaVal"><Data ss:Type="String">${escapeXml(new Date().toLocaleString())}</Data></Cell>
   </Row>
   <Row ss:Height="15"></Row>

   <!-- Section header -->
   <Row ss:Height="25">
    <Cell ss:MergeAcross="1" ss:StyleID="sSection"><Data ss:Type="String">1. Executive KPI Summary</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:StyleID="sTableHeaderSec"><Data ss:Type="String">Metric Description</Data></Cell>
    <Cell ss:StyleID="sTableHeaderSec" style="text-align: right;"><Data ss:Type="String">Value</Data></Cell>
   </Row>
   
   <!-- Data rows -->
   <Row ss:Height="20">
    <Cell ss:StyleID="sCell"><Data ss:Type="String">Total Valid Bookings</Data></Cell>
    <Cell ss:StyleID="sCellNumberBold"><Data ss:Type="Number">${parseInt(totalValidBookings) || 0}</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:StyleID="sCellZebra"><Data ss:Type="String">  • Confirmed Bookings</Data></Cell>
    <Cell ss:StyleID="sCellNumberZebra"><Data ss:Type="Number">${parseInt(confirmedBookings) || 0}</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:StyleID="sCell"><Data ss:Type="String">  • Completed Bookings</Data></Cell>
    <Cell ss:StyleID="sCellNumber"><Data ss:Type="Number">${parseInt(completedBookings) || 0}</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:StyleID="sCellZebra"><Data ss:Type="String">  • Reserved Bookings</Data></Cell>
    <Cell ss:StyleID="sCellNumberZebra"><Data ss:Type="Number">${parseInt(reservedBookings) || 0}</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:StyleID="sCell"><Data ss:Type="String">  • Cancelled Bookings</Data></Cell>
    <Cell ss:StyleID="sCellNumber"><Data ss:Type="Number">${parseInt(cancelledBookings) || 0}</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:StyleID="sCellZebra"><Data ss:Type="String">Total Collected Revenue</Data></Cell>
    <Cell ss:StyleID="sCellCurrencyZebraBold"><Data ss:Type="Number">${parseFloat(revenueCollected) || 0}</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:StyleID="sCell"><Data ss:Type="String">  • PayMongo Gateway (Card/E-Wallet)</Data></Cell>
    <Cell ss:StyleID="sCellCurrency"><Data ss:Type="Number">${parseFloat(revPaymongo) || 0}</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:StyleID="sCellZebra"><Data ss:Type="String">  • Cash / Pay Later Receipts</Data></Cell>
    <Cell ss:StyleID="sCellCurrencyZebra"><Data ss:Type="Number">${parseFloat(revCash) || 0}</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:StyleID="sCell"><Data ss:Type="String">Outstanding Accounts Receivable (Debt)</Data></Cell>
    <Cell ss:StyleID="sCellCurrencyBold"><Data ss:Type="Number">${parseFloat(outstandingBalance) || 0}</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:StyleID="sCellZebra"><Data ss:Type="String">Court Utilization Rate (Hours)</Data></Cell>
    <Cell ss:StyleID="sCellPercentZebraBold"><Data ss:Type="Number">${(parseFloat(utilizationRate) || 0) / 100}</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:StyleID="sCell"><Data ss:Type="String">Coaching Add-on Take-up Rate</Data></Cell>
    <Cell ss:StyleID="sCellPercentBold"><Data ss:Type="Number">${(parseFloat(coachingRate) || 0) / 100}</Data></Cell>
   </Row>
   <Row ss:Height="20">
    <Cell ss:StyleID="sCellZebra"><Data ss:Type="String">Check-in Attendance Accuracy Rate</Data></Cell>
    <Cell ss:StyleID="sCellPercentZebraBold"><Data ss:Type="Number">${(parseFloat(attendanceRate) || 0) / 100}</Data></Cell>
   </Row>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <Selected/>
   <DisplayGridlines/>
  </WorksheetOptions>
 </Worksheet>
`;

    // TAB 2: DAILY TIMELINE
    xml += `
 <Worksheet ss:Name="Daily Utilization Timeline">
  <Table>
   <Column ss:Width="160"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="110"/>
   <Column ss:Width="110"/>
   <Column ss:Width="120"/>
   <Column ss:Width="110"/>
   <Column ss:Width="140"/>
   
   <Row ss:Height="30">
    <Cell ss:MergeAcross="7" ss:StyleID="sTitle"><Data ss:Type="String">DAILY UTILIZATION &amp; FINANCIAL TIMELINE</Data></Cell>
   </Row>
   <Row ss:Height="15"></Row>
   
   <Row ss:Height="25">
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Session Date</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Allocated Slots</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Booked Slots</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Allocated Hours</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Booked Hours</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Utilization Rate</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Bookings Count</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Revenue Paid</Data></Cell>
   </Row>
`;

    dailyBreakdown.forEach((row, index) => {
        const dh_tot = parseFloat(row.total_hours) || 0;
        const dh_util = parseFloat(row.utilized_hours) || 0;
        const day_util_rate = dh_tot > 0 ? (dh_util / dh_tot) : 0.0;
        const zebraSuffix = index % 2 === 1 ? 'Zebra' : '';
        const cellCls = 'sCell' + zebraSuffix;

        xml += `
   <Row ss:Height="22">
    <Cell ss:StyleID="${cellCls}"><Data ss:Type="String">${escapeXml(row.session_date)}</Data></Cell>
    <Cell ss:StyleID="sCellNumber${zebraSuffix}"><Data ss:Type="Number">${parseInt(row.total_slots) || 0}</Data></Cell>
    <Cell ss:StyleID="sCellNumber${zebraSuffix}"><Data ss:Type="Number">${parseInt(row.utilized_slots) || 0}</Data></Cell>
    <Cell ss:StyleID="sCellHours${zebraSuffix}"><Data ss:Type="Number">${dh_tot}</Data></Cell>
    <Cell ss:StyleID="sCellHours${zebraSuffix}"><Data ss:Type="Number">${dh_util}</Data></Cell>
    <Cell ss:StyleID="sCellPercent${zebraSuffix}"><Data ss:Type="Number">${day_util_rate}</Data></Cell>
    <Cell ss:StyleID="sCellNumber${zebraSuffix}"><Data ss:Type="Number">${parseInt(row.bookings_count) || 0}</Data></Cell>
    <Cell ss:StyleID="sCellCurrency${zebraSuffix}Bold"><Data ss:Type="Number">${parseFloat(row.daily_revenue) || 0}</Data></Cell>
   </Row>
`;
    });

    xml += `
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <DisplayGridlines/>
  </WorksheetOptions>
 </Worksheet>
`;

    // TAB 3: ITEMIZED LOGS
    xml += `
 <Worksheet ss:Name="Itemized Sessions Registry">
  <Table>
   <Column ss:Width="90"/>
   <Column ss:Width="160"/>
   <Column ss:Width="110"/>
   <Column ss:Width="60"/>
   <Column ss:Width="90"/>
   <Column ss:Width="90"/>
   <Column ss:Width="100"/>
   <Column ss:Width="100"/>
   <Column ss:Width="110"/>
   <Column ss:Width="100"/>
   
   <Row ss:Height="30">
    <Cell ss:MergeAcross="9" ss:StyleID="sTitle"><Data ss:Type="String">ITEMIZED TRANSACTION &amp; SESSIONS REGISTRY</Data></Cell>
   </Row>
   <Row ss:Height="15"></Row>
   
   <Row ss:Height="25">
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Code</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Client Name</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Play Date</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Pax</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Coaching</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Court Fee</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Total Fee</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Booking Status</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Payment Status</Data></Cell>
    <Cell ss:StyleID="sTableHeader"><Data ss:Type="String">Attendance</Data></Cell>
   </Row>
`;

    bookingLogs.forEach((log, index) => {
        const zebraSuffix = index % 2 === 1 ? 'Zebra' : '';
        const cellCls = 'sCell' + zebraSuffix;
        
        let bBadgeStyle = 'sCellCenter' + zebraSuffix;
        if (log.booking_status === 'confirmed') bBadgeStyle = 'sBadgeSuccess';
        else if (log.booking_status === 'completed') bBadgeStyle = 'sBadgeInfo';
        else if (log.booking_status === 'cancelled') bBadgeStyle = 'sBadgeDanger';
        else if (log.booking_status === 'reserved') bBadgeStyle = 'sBadgeWarning';

        let pBadgeStyle = 'sCellCenter' + zebraSuffix;
        if (log.payment_status === 'paid') pBadgeStyle = 'sBadgeSuccess';
        else if (log.payment_status === 'unpaid') pBadgeStyle = 'sBadgeWarning';
        else if (log.payment_status === 'overdue') pBadgeStyle = 'sBadgeDanger';

        let attText = 'Scheduled';
        let attBadgeStyle = 'sCellCenter' + zebraSuffix;
        if (log.session_status === 'completed') {
            attText = 'Present';
            attBadgeStyle = 'sBadgeSuccess';
        } else if (log.session_status === 'no_show') {
            attText = 'Absent';
            attBadgeStyle = 'sBadgeDanger';
        }

        // Format dates cleanly with play times
        const playDateStr = `${log.session_date} ${log.start_time.substring(0, 5)}-${log.end_time.substring(0, 5)}`;

        xml += `
   <Row ss:Height="22">
    <Cell ss:StyleID="${cellCls}Bold"><Data ss:Type="String">${escapeXml(log.booking_code)}</Data></Cell>
    <Cell ss:StyleID="sCellLeft${zebraSuffix}Bold"><Data ss:Type="String">${escapeXml(log.client_name)}</Data></Cell>
    <Cell ss:StyleID="sCellCenter${zebraSuffix}"><Data ss:Type="String">${escapeXml(playDateStr)}</Data></Cell>
    <Cell ss:StyleID="sCellNumber${zebraSuffix}"><Data ss:Type="Number">${parseInt(log.pax) || 0}</Data></Cell>
    <Cell ss:StyleID="sCellCurrency${zebraSuffix}"><Data ss:Type="Number">${parseFloat(log.coaching_fee) || 0}</Data></Cell>
    <Cell ss:StyleID="sCellCurrency${zebraSuffix}"><Data ss:Type="Number">${parseFloat(log.court_fee) || 0}</Data></Cell>
    <Cell ss:StyleID="sCellCurrency${zebraSuffix}Bold"><Data ss:Type="Number">${parseFloat(log.total_fee) || 0}</Data></Cell>
    <Cell ss:StyleID="${bBadgeStyle}"><Data ss:Type="String">${escapeXml(log.booking_status)}</Data></Cell>
    <Cell ss:StyleID="${pBadgeStyle}"><Data ss:Type="String">${escapeXml(log.payment_status || 'unpaid')}</Data></Cell>
    <Cell ss:StyleID="${attBadgeStyle}"><Data ss:Type="String">${escapeXml(attText)}</Data></Cell>
   </Row>
`;
    });

    xml += `
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <DisplayGridlines/>
  </WorksheetOptions>
 </Worksheet>
</Workbook>
`;

    // Download the Excel HTML content as an .xls file
    const blob = new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.setAttribute("href", url);
    link.setAttribute("download", `RDG_Tennis_Performance_Report_${sDate}_to_${eDate}.xls`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

// Registry Instant Search & Date Filtering Logic
document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.getElementById("registry-search-name");
    const dateInput = document.getElementById("registry-filter-date");

    if (searchInput && dateInput) {
        searchInput.addEventListener("input", filterRegistryTable);
        dateInput.addEventListener("change", filterRegistryTable);
    }

    // Automatically trigger print dialog if 'autoprint=1' query parameter is present
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('autoprint') === '1') {
        setTimeout(() => {
            window.print();
        }, 600);
    }
});

function filterRegistryTable() {
    const nameQuery = document.getElementById("registry-search-name").value.trim().toLowerCase();
    const dateQuery = document.getElementById("registry-filter-date").value;
    const rows = document.querySelectorAll(".registry-row");
    
    let visibleCount = 0;
    rows.forEach(row => {
        const rowName = row.getAttribute("data-name") || "";
        const rowDate = row.getAttribute("data-date") || "";
        
        const matchesName = !nameQuery || rowName.includes(nameQuery);
        const matchesDate = !dateQuery || rowDate === dateQuery;
        
        if (matchesName && matchesDate) {
            row.style.display = "";
            visibleCount++;
        } else {
            row.style.display = "none";
        }
    });

    // Handle no matching records state by appending a visual fallback row
    let noRecordsRow = document.getElementById("registry-no-records-row");
    if (visibleCount === 0 && rows.length > 0) {
        if (!noRecordsRow) {
            const firstRow = document.querySelector(".registry-row");
            const tbody = firstRow.parentNode;
            noRecordsRow = document.createElement("tr");
            noRecordsRow.id = "registry-no-records-row";
            noRecordsRow.innerHTML = `<td colspan="11" class="p-6 text-center text-zinc-400 italic font-medium">No matching players or session dates found in this report.</td>`;
            tbody.appendChild(noRecordsRow);
        } else {
            noRecordsRow.style.display = "";
        }
    } else if (noRecordsRow) {
        noRecordsRow.style.display = "none";
    }
}

function clearRegistryFilters() {
    const searchInput = document.getElementById("registry-search-name");
    const dateInput = document.getElementById("registry-filter-date");
    if (searchInput) searchInput.value = "";
    if (dateInput) dateInput.value = "";
    filterRegistryTable();
}
</script>

<!-- DEDICATED FORMAL PRINTABLE REPORT TEMPLATE (PRINT ONLY) -->
<div id="formal-print-report" class="hidden">
    <!-- Corporate Letterhead Header -->
    <div class="border-b-2 border-zinc-800 pb-4 mb-6">
        <div class="flex justify-between items-start" style="display: flex; flex-direction: row; justify-content: space-between; align-items: flex-end; width: 100%;">
            <div class="flex items-center gap-4" style="display: flex; flex-direction: row; align-items: center; gap: 16px;">
                <img alt="RDG Logo" src="/RDG/RDG Logo.jpg" style="height: 56px; width: auto; border: 1px solid #111827;"/>
                <div>
                    <h1 class="font-serif font-bold text-2xl tracking-tight text-zinc-900" style="font-family: Georgia, serif; font-size: 24px; font-weight: bold; margin: 0; color: #111827;">RDG TENNIS CENTER</h1>
                    <p class="text-[9px] text-zinc-500 uppercase tracking-widest font-sans font-bold" style="font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; letter-spacing: 0.1em; margin-top: 2px;">Official Administration &amp; Summary Report Portal</p>
                </div>
            </div>
            <div class="text-right font-sans text-xs" style="text-align: right;">
                <span class="px-2.5 py-1 bg-zinc-100 border border-zinc-300 font-bold uppercase text-[9px] tracking-wide text-zinc-600" style="padding: 4px 10px; background-color: #f3f4f6; border: 1px solid #d1d5db; font-size: 8px; font-weight: bold; text-transform: uppercase; color: #4b5563;">CONFIDENTIAL</span>
                <p class="text-[10px] text-zinc-500 mt-2" style="font-size: 8px; color: #6b7280; margin-top: 8px;">Executed: <?= date('F d, Y h:i A') ?></p>
            </div>
        </div>
    </div>

    <!-- Title and Metadata Box -->
    <div class="mb-6 bg-zinc-50 border border-zinc-200 p-4" style="margin-bottom: 24px; background-color: #f9fafb; border: 1px solid #e5e7eb; padding: 16px;">
        <h2 class="font-serif text-lg font-bold text-zinc-800 uppercase tracking-wide" style="font-family: Georgia, serif; font-size: 14px; font-weight: bold; text-transform: uppercase; color: #1f2937; margin: 0; letter-spacing: 0.05em;">System Performance &amp; Financial Summary</h2>
        <div class="grid grid-cols-3 gap-4 mt-3 text-xs font-sans" style="display: flex; flex-direction: row; gap: 16px; margin-top: 12px; font-size: 10px;">
            <div style="flex: 1;">
                <span class="block text-[9px] text-zinc-400 font-bold uppercase" style="display: block; font-size: 8px; color: #9ca3af; font-weight: bold; text-transform: uppercase;">Reporting Scope</span>
                <span class="font-bold text-zinc-700 uppercase" style="font-weight: bold; color: #374151; text-transform: uppercase;"><?= htmlspecialchars($preset ?: 'Custom Period') ?> Summary</span>
            </div>
            <div style="flex: 1;">
                <span class="block text-[9px] text-zinc-400 font-bold uppercase" style="display: block; font-size: 8px; color: #9ca3af; font-weight: bold; text-transform: uppercase;">Date Range</span>
                <span class="font-bold text-zinc-700" style="font-weight: bold; color: #374151;"><?= $human_range ?></span>
            </div>
            <div style="flex: 1;">
                <span class="block text-[9px] text-zinc-400 font-bold uppercase" style="display: block; font-size: 8px; color: #9ca3af; font-weight: bold; text-transform: uppercase;">Generated By</span>
                <span class="font-bold text-zinc-700 uppercase" style="font-weight: bold; color: #374151; text-transform: uppercase;"><?= htmlspecialchars($admin_name) ?> (Admin)</span>
            </div>
        </div>
    </div>

    <!-- 1. Executive Summary & KPIs -->
    <div class="print-section">
        <h3 class="font-sans font-bold text-xs uppercase tracking-wider text-zinc-800 border-b border-zinc-300 pb-1 mb-3" style="font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: #111827; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; margin-bottom: 12px;">1. Executive KPI Summary</h3>
        <div class="grid grid-cols-4 gap-4" style="display: flex; flex-direction: row; gap: 12px; width: 100%;">
            <!-- Bookings KPI -->
            <div class="border border-zinc-200 bg-white p-3 font-sans" style="flex: 1; border: 1px solid #e5e7eb; padding: 12px; background-color: white;">
                <span class="block text-[8px] text-zinc-400 font-bold uppercase tracking-wider" style="display: block; font-size: 7.5px; color: #9ca3af; font-weight: bold; text-transform: uppercase;">Total Bookings</span>
                <span class="block font-bold text-xl text-zinc-800 mt-1" style="display: block; font-size: 20px; font-weight: bold; color: #111827; margin-top: 4px;"><?= $total_valid_bookings ?></span>
                <div class="mt-2 text-[8px] text-zinc-500 space-y-0.5" style="margin-top: 8px; font-size: 7.5px; color: #6b7280;">
                    <div class="flex justify-between" style="display: flex; justify-content: space-between;"><span>Confirmed</span> <span class="font-bold" style="font-weight: bold;"><?= (int)$b_stats['confirmed_bookings'] ?></span></div>
                    <div class="flex justify-between" style="display: flex; justify-content: space-between;"><span>Completed</span> <span class="font-bold" style="font-weight: bold;"><?= (int)$b_stats['completed_bookings'] ?></span></div>
                    <div class="flex justify-between" style="display: flex; justify-content: space-between;"><span>Reserved</span> <span class="font-bold" style="font-weight: bold;"><?= (int)$b_stats['reserved_bookings'] ?></span></div>
                    <div class="flex justify-between" style="display: flex; justify-content: space-between;"><span>Cancelled</span> <span class="font-bold text-red-700" style="font-weight: bold; color: #b91c1c;"><?= (int)$b_stats['cancelled_bookings'] ?></span></div>
                </div>
            </div>

            <!-- Revenue KPI -->
            <div class="border border-zinc-200 bg-white p-3 font-sans" style="flex: 1; border: 1px solid #e5e7eb; padding: 12px; background-color: white;">
                <span class="block text-[8px] text-zinc-400 font-bold uppercase tracking-wider" style="display: block; font-size: 7.5px; color: #9ca3af; font-weight: bold; text-transform: uppercase;">Collected Revenue</span>
                <span class="block font-bold text-xl text-zinc-800 mt-1" style="display: block; font-size: 20px; font-weight: bold; color: #111827; margin-top: 4px;">₱<?= number_format((float)$rev_stats['revenue_collected'], 2) ?></span>
                <div class="mt-2 text-[8px] text-zinc-500 space-y-0.5" style="margin-top: 8px; font-size: 7.5px; color: #6b7280;">
                    <div class="flex justify-between" style="display: flex; justify-content: space-between;"><span>PayMongo Gateway</span> <span class="font-bold" style="font-weight: bold;">₱<?= number_format((float)$rev_stats['rev_paymongo'], 2) ?></span></div>
                    <div class="flex justify-between" style="display: flex; justify-content: space-between;"><span>Cash (Pay Later)</span> <span class="font-bold" style="font-weight: bold;">₱<?= number_format((float)$rev_stats['rev_cash'], 2) ?></span></div>
                </div>
            </div>

            <!-- Debts KPI -->
            <div class="border border-zinc-200 bg-white p-3 font-sans" style="flex: 1; border: 1px solid #e5e7eb; padding: 12px; background-color: white;">
                <span class="block text-[8px] text-zinc-400 font-bold uppercase tracking-wider" style="display: block; font-size: 7.5px; color: #9ca3af; font-weight: bold; text-transform: uppercase;">Outstanding Debt</span>
                <span class="block font-bold text-xl text-red-850 mt-1" style="display: block; font-size: 20px; font-weight: bold; color: #b91c1c; margin-top: 4px;">₱<?= number_format((float)$rev_stats['outstanding_balance'] + (float)$rev_stats['overdue_balance'], 2) ?></span>
                <div class="mt-2 text-[8px] text-zinc-500 space-y-0.5" style="margin-top: 8px; font-size: 7.5px; color: #6b7280;">
                    <div class="flex justify-between" style="display: flex; justify-content: space-between;"><span>Unpaid / Reserved</span> <span class="font-bold" style="font-weight: bold;">₱<?= number_format((float)$rev_stats['outstanding_balance'], 2) ?></span></div>
                    <div class="flex justify-between" style="display: flex; justify-content: space-between;"><span>Overdue Limits</span> <span class="font-bold text-red-700" style="font-weight: bold; color: #b91c1c;">₱<?= number_format((float)$rev_stats['overdue_balance'], 2) ?></span></div>
                </div>
            </div>

            <!-- Utilization KPI -->
            <div class="border border-zinc-200 bg-white p-3 font-sans" style="flex: 1; border: 1px solid #e5e7eb; padding: 12px; background-color: white;">
                <span class="block text-[8px] text-zinc-400 font-bold uppercase tracking-wider" style="display: block; font-size: 7.5px; color: #9ca3af; font-weight: bold; text-transform: uppercase;">Court Utilization</span>
                <span class="block font-bold text-xl text-zinc-800 mt-1" style="display: block; font-size: 20px; font-weight: bold; color: #111827; margin-top: 4px;"><?= number_format($utilization_rate, 1) ?>%</span>
                <div class="mt-2 text-[8px] text-zinc-500 space-y-0.5" style="margin-top: 8px; font-size: 7.5px; color: #6b7280;">
                    <div class="flex justify-between" style="display: flex; justify-content: space-between;"><span>Allocated Hours</span> <span class="font-bold" style="font-weight: bold;"><?= number_format($total_hours, 1) ?>h</span></div>
                    <div class="flex justify-between" style="display: flex; justify-content: space-between;"><span>Booked Hours</span> <span class="font-bold" style="font-weight: bold;"><?= number_format($utilized_hours, 1) ?>h</span></div>
                    <div class="flex justify-between" style="display: flex; justify-content: space-between;"><span>Attendance Rate</span> <span class="font-bold" style="font-weight: bold;"><?= number_format($attendance_rate, 1) ?>%</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Daily Performance Timeline -->
    <div class="print-section">
        <h3 class="font-sans font-bold text-xs uppercase tracking-wider text-zinc-800 border-b border-zinc-300 pb-1 mb-3" style="font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: #111827; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; margin-bottom: 12px;">2. Daily Performance &amp; Utilization Timeline</h3>
        <table class="print-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th style="text-align: center;">Allocated Slots</th>
                    <th style="text-align: center;">Booked Slots</th>
                    <th style="text-align: center;">Allocated Hours</th>
                    <th style="text-align: center;">Booked Hours</th>
                    <th style="text-align: center;">Utilization Rate</th>
                    <th style="text-align: center;">Bookings Count</th>
                    <th style="text-align: right;">Revenue Paid</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($daily_breakdown)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; font-style: italic; color: #9ca3af;">No timeline entries found in this period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($daily_breakdown as $row):
                        $dh_tot = (float)$row['total_hours'];
                        $dh_util = (float)$row['utilized_hours'];
                        $day_util_rate = $dh_tot > 0 ? ($dh_util / $dh_tot) * 100 : 0.0;
                    ?>
                        <tr>
                            <td style="font-weight: bold;"><?= date('D, M d, Y', strtotime($row['session_date'])) ?></td>
                            <td style="text-align: center;"><?= (int)$row['total_slots'] ?></td>
                            <td style="text-align: center;"><?= (int)$row['utilized_slots'] ?></td>
                            <td style="text-align: center;"><?= number_format($dh_tot, 1) ?>h</td>
                            <td style="text-align: center; font-weight: bold;"><?= number_format($dh_util, 1) ?>h</td>
                            <td style="text-align: center; font-weight: bold;"><?= number_format($day_util_rate, 1) ?>%</td>
                            <td style="text-align: center;"><?= (int)$row['bookings_count'] ?></td>
                            <td style="text-align: right; font-weight: bold; color: #065f46;">₱<?= number_format((float)$row['daily_revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Page Break before granular log if long -->
    <div style="page-break-before: always;"></div>

    <!-- 3. Itemized Booking Registry -->
    <div class="print-section">
        <h3 class="font-sans font-bold text-xs uppercase tracking-wider text-zinc-800 border-b border-zinc-300 pb-1 mb-3" style="font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: #111827; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; margin-bottom: 12px;">3. Itemized Transaction &amp; Session Registry</h3>
        <table class="print-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Client Name</th>
                    <th>Play Schedule</th>
                    <th style="text-align: center;">Pax</th>
                    <th style="text-align: right;">Coaching</th>
                    <th style="text-align: right;">Court Fee</th>
                    <th style="text-align: right;">Total Fee</th>
                    <th style="text-align: center;">Booking</th>
                    <th style="text-align: center;">Payment</th>
                    <th style="text-align: center;">Attendance</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($booking_logs)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; font-style: italic; color: #9ca3af;">No bookings recorded during this period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($booking_logs as $log): 
                        $b_badge_cls = "";
                        if ($log['booking_status'] === 'confirmed') $b_badge_cls = "print-badge-success";
                        elseif ($log['booking_status'] === 'completed') $b_badge_cls = "print-badge-info";
                        elseif ($log['booking_status'] === 'cancelled') $b_badge_cls = "print-badge-danger";
                        elseif ($log['booking_status'] === 'reserved') $b_badge_cls = "print-badge-warning";

                        $p_badge_cls = "";
                        if ($log['payment_status'] === 'paid') $p_badge_cls = "print-badge-success";
                        elseif ($log['payment_status'] === 'unpaid') $p_badge_cls = "print-badge-warning";
                        elseif ($log['payment_status'] === 'overdue') $p_badge_cls = "print-badge-danger";

                        $att_status_text = 'Scheduled';
                        $att_badge_cls = '';
                        if ($log['session_status'] === 'completed') {
                            $att_status_text = 'Present';
                            $att_badge_cls = 'print-badge-success';
                        } elseif ($log['session_status'] === 'no_show') {
                            $att_status_text = 'Absent';
                            $att_badge_cls = 'print-badge-danger';
                        }
                    ?>
                        <tr>
                            <td style="font-weight: bold;"><?= htmlspecialchars($log['booking_code']) ?></td>
                            <td style="font-weight: bold;"><?= htmlspecialchars($log['client_name']) ?></td>
                            <td>
                                <?= date('M d, Y', strtotime($log['session_date'])) ?><br/>
                                <span style="font-size: 7px; color: #6b7280; font-weight: bold;"><?= date('h:i A', strtotime($log['start_time'])) ?> - <?= date('h:i A', strtotime($log['end_time'])) ?></span>
                            </td>
                            <td style="text-align: center; font-weight: bold;"><?= (int)$log['pax'] ?></td>
                            <td style="text-align: right;">₱<?= number_format((float)$log['coaching_fee'], 2) ?></td>
                            <td style="text-align: right;">₱<?= number_format((float)$log['court_fee'], 2) ?></td>
                            <td style="text-align: right; font-weight: bold; color: #154212;">₱<?= number_format((float)$log['total_fee'], 2) ?></td>
                            <td style="text-align: center;">
                                <span class="print-badge <?= $b_badge_cls ?>"><?= $log['booking_status'] ?></span>
                            </td>
                            <td style="text-align: center;">
                                <span class="print-badge <?= $p_badge_cls ?>"><?= $log['payment_status'] ?? 'unpaid' ?></span>
                            </td>
                            <td style="text-align: center;">
                                <span class="print-badge <?= $att_badge_cls ?>"><?= $att_status_text ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 4. Signature Sign-off Section -->
    <div class="print-page-break-avoid" style="margin-top: 50px; border-top: 1px solid #d1d5db; pt-6; padding-top: 24px; page-break-inside: avoid;">
        <div class="flex justify-between items-start" style="display: flex; flex-direction: row; justify-content: space-between; width: 100%;">
            <div style="flex: 1;">
                <p style="font-size: 9px; font-weight: bold; color: #4b5563; margin: 0;">Prepared By:</p>
                <div style="margin-top: 35px; border-bottom: 1px solid #9ca3af; width: 180px;"></div>
                <p style="font-size: 9px; font-weight: bold; color: #111827; margin-top: 4px; text-transform: uppercase;"><?= htmlspecialchars($admin_name) ?></p>
                <p style="font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; margin: 0;">System Administrator</p>
            </div>
            <div style="flex: 1; text-align: right; display: flex; flex-direction: column; align-items: flex-end;">
                <p style="font-size: 9px; font-weight: bold; color: #4b5563; margin: 0;">Approved By:</p>
                <div style="margin-top: 35px; border-bottom: 1px solid #9ca3af; width: 180px;"></div>
                <p style="font-size: 9px; font-weight: bold; color: #111827; margin-top: 4px; text-transform: uppercase;">AUTHORIZED SIGNATURE</p>
                <p style="font-size: 8px; color: #6b7280; text-transform: uppercase; font-weight: bold; margin: 0;">RDG Executive Board</p>
            </div>
        </div>
    </div>
</div>
</body>
</html>
