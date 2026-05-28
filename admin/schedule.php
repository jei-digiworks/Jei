<?php
// admin/schedule.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_admin();
run_cron_simulator($pdo);

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'RDG ADMIN';

// Get selected week's Monday
$selected_date = $_GET['date'] ?? date('Y-m-d');
$monday_ts = strtotime('monday this week', strtotime($selected_date));
$monday_date = date('Y-m-d', $monday_ts);

// Calculate prev/next weeks
$prev_week_date = date('Y-m-d', strtotime('-1 week', $monday_ts));
$next_week_date = date('Y-m-d', strtotime('+1 week', $monday_ts));

// Current month key for the filter dropdown
$current_month_key = date('Y-m', $monday_ts);

// Fetch all bookable months for the filter dropdown
$stmt_months = $pdo->prepare("SELECT month_year FROM bookable_months ORDER BY month_year ASC");
$stmt_months->execute();
$all_months = $stmt_months->fetchAll(PDO::FETCH_COLUMN);

// Generate label for all 7 days
$days_of_week = [];
for ($i = 0; $i < 7; $i++) {
    $ts = strtotime("+$i day", $monday_ts);
    $days_of_week[$i] = [
        'day_name' => strtoupper(date('D', $ts)),
        'date_lbl' => date('M d', $ts),
        'sql_date' => date('Y-m-d', $ts),
    ];
}

// Fetch all schedules for this week
$sql_start = $days_of_week[0]['sql_date'];
$sql_end = $days_of_week[6]['sql_date'];

$stmt_schedules = $pdo->prepare("
    SELECT id, admin_id, session_date, start_time, end_time, duration_hours, status, is_game_night, reserved_until, notes, created_at, updated_at
      FROM schedules
     WHERE session_date BETWEEN ? AND ?
     ORDER BY session_date ASC, start_time ASC
");
$stmt_schedules->execute([$sql_start, $sql_end]);
$all_schedules = $stmt_schedules->fetchAll(PDO::FETCH_ASSOC);

// Fetch all active bookings for this week
$stmt_bookings = $pdo->prepare("
    SELECT b.id AS booking_id, b.booking_code, b.status AS booking_status, b.pax, b.duration_hours,
           u.full_name AS client_name, s.session_date, s.start_time
      FROM bookings b
      JOIN schedules s ON b.schedule_id = s.id
      LEFT JOIN users u ON b.user_id = u.id
     WHERE b.status IN ('confirmed', 'reserved', 'completed')
       AND s.session_date BETWEEN ? AND ?
");
$stmt_bookings->execute([$sql_start, $sql_end]);
$week_bookings = $stmt_bookings->fetchAll(PDO::FETCH_ASSOC);

// Map bookings to slots by start_time and duration
$occupied_slots = [];
foreach ($week_bookings as $b) {
    $start_ts = strtotime($b['session_date'] . ' ' . $b['start_time']);
    $duration = (float)$b['duration_hours'];
    for ($i = 0; $i < $duration; $i++) {
        $slot_time = date('H:i', strtotime("+$i hour", $start_ts));
        $slot_key = $b['session_date'] . '|' . $slot_time;
        $occupied_slots[$slot_key] = $b;
    }
}

// Map schedules by date and start_time, overlaying booking details
$schedule_map = [];
foreach ($all_schedules as $s) {
    $time_key = date('H:i', strtotime($s['start_time']));
    $slot_key = $s['session_date'] . '|' . $time_key;

    $s['booking_code'] = null;
    $s['booking_status'] = null;
    $s['pax'] = null;
    $s['client_name'] = null;
    $s['booking_id'] = null;

    if (isset($occupied_slots[$slot_key])) {
        $b = $occupied_slots[$slot_key];
        $s['booking_code']   = $b['booking_code'];
        $s['booking_status'] = $b['booking_status'];
        $s['pax']            = $b['pax'];
        $s['client_name']    = $b['client_name'];
        $s['booking_id']     = $b['booking_id'];
    }

    $schedule_map[$s['session_date']][$time_key] = $s;
}

// Define the time blocks we want to display
$time_blocks = [
    '08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00',
    '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00', '23:00'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>RDG Tennis - Admin Schedule Management</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700;900&family=Lexend:wght@300;400;500;600;700&family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
    .court-grid{background-image:linear-gradient(#e5e7eb 1px,transparent 1px),linear-gradient(90deg,#e5e7eb 1px,transparent 1px);background-size:64px 64px;}
    .material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
    .fill-icon{font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24;}
    @keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
    .fade-in{animation:fadeIn .3s ease both}
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
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="dashboard.php"><span class="material-symbols-outlined">grid_view</span> Dashboard</a>
        <a class="bg-[#FFE500] text-primary border-l-4 border-primary px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm" href="schedule.php"><span class="material-symbols-outlined fill-icon">calendar_today</span> Schedule</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="bookings.php"><span class="material-symbols-outlined">payments</span> Bookings</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="attendance.php"><span class="material-symbols-outlined">fact_check</span> Attendance</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="cancellations.php"><span class="material-symbols-outlined">cancel</span> Requests</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="months.php"><span class="material-symbols-outlined">calendar_month</span> Months</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="reports.php"><span class="material-symbols-outlined">analytics</span> Reports</a>
    </nav>
</aside>

<!-- Main -->
<main class="ml-64 pt-20 min-h-screen p-8">
    <div class="max-w-7xl mx-auto space-y-8 fade-in">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 border-b-4 border-primary pb-6">
            <div>
                <h1 class="font-headline font-black text-5xl text-primary uppercase tracking-tighter">Schedule Matrix</h1>
                <p class="text-[#42493e]">Weekly planner for court lockouts, walk-ins, and standard matches.</p>
            </div>
            <div class="flex flex-wrap gap-2 items-center">
                <!-- Month Filter Dropdown -->
                <div class="relative flex items-center gap-2">
                    <label class="text-[10px] font-black text-primary uppercase tracking-wider whitespace-nowrap">Jump to Month:</label>
                    <select id="month-filter" onchange="jumpToMonth(this.value)"
                            class="border-2 border-primary bg-white text-primary font-headline font-bold text-xs uppercase px-3 py-2 focus:ring-0 focus:border-accent cursor-pointer hover:bg-[#f6f3f2] transition-colors shadow-[2px_2px_0px_rgba(21,66,18,0.15)]">
                        <?php foreach ($all_months as $m): ?>
                            <option value="<?= $m ?>" <?= $m === $current_month_key ? 'selected' : '' ?>>
                                <?= date('M Y', strtotime($m . '-01')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-px h-8 bg-primary opacity-30 hidden md:block"></div>
                <a href="?date=<?= $prev_week_date ?>" class="bg-white text-primary border-2 border-primary font-headline font-bold uppercase px-4 py-2 hover:bg-[#f6f3f2] transition-colors"><span class="material-symbols-outlined text-sm align-middle mr-1">chevron_left</span>Prev Week</a>
                <a href="?date=<?= $next_week_date ?>" class="bg-white text-primary border-2 border-primary font-headline font-bold uppercase px-4 py-2 hover:bg-[#f6f3f2] transition-colors">Next Week<span class="material-symbols-outlined text-sm align-middle ml-1">chevron_right</span></a>
                <button onclick="generateWeeklySchedule()" class="bg-primary text-white font-headline font-black uppercase px-6 py-3 hover:bg-accent hover:text-primary transition-all shadow-[2px_2px_0px_rgba(0,0,0,0.15)]">Generate Weekly Blocks</button>
            </div>
        </div>

        <!-- Legend / Info -->
        <div class="flex flex-wrap gap-6 bg-white p-4 border-2 border-primary shadow-[4px_4px_0px_rgba(21,66,18,1)] justify-between items-center">
            <div class="flex flex-wrap gap-4 text-xs font-bold uppercase">
                <div class="flex items-center gap-2"><div class="w-4 h-4 bg-primary border border-primary"></div><span>Available</span></div>
                <div class="flex items-center gap-2"><div class="w-4 h-4 bg-[#FFB800] border border-primary"></div><span>Reserved</span></div>
                <div class="flex items-center gap-2"><div class="w-4 h-4 bg-[#bcf0ae] border border-primary"></div><span>Confirmed</span></div>
                <div class="flex items-center gap-2"><div class="w-4 h-4 bg-[#4a8f3c] border border-primary"></div><span>Completed</span></div>
                <div class="flex items-center gap-2"><div class="w-4 h-4 bg-[#f6f3f2] border border-primary"></div><span>Empty Slot</span></div>
                <div class="flex items-center gap-2"><div class="w-4 h-4 bg-red-400 border border-primary"></div><span>Maintenance (Locked)</span></div>
                <div class="flex items-center gap-2"><div class="w-4 h-4 bg-zinc-800 border border-primary"></div><span>Game Night (Locked)</span></div>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-xs font-black text-primary uppercase">Week Starting: <?= date('F d, Y', strtotime($monday_date)) ?></span>
                <div id="live-badge" class="flex items-center gap-1.5 px-2 py-1 bg-primary border border-primary text-white text-[9px] font-black uppercase tracking-wider">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse"></span>
                    <span id="live-status">LIVE</span>
                </div>
            </div>
        </div>

        <!-- Weekly Schedule Grid -->
        <div id="schedule-grid-wrap" class="bg-white border-2 border-primary shadow-[6px_6px_0px_rgba(21,66,18,1)] overflow-hidden">
            <!-- Grid Header -->
            <div class="grid grid-cols-8 border-b-2 border-primary bg-primary text-white text-center font-headline font-bold text-xs uppercase">
                <div class="p-4 border-r-2 border-primary flex items-center justify-center bg-[#2d5a27]"><span class="tracking-widest">Time</span></div>
                <?php foreach ($days_of_week as $day): ?>
                    <div class="p-4 border-r border-[#2d5a27] last:border-0 flex flex-col justify-center">
                        <span class="font-black text-sm"><?= $day['day_name'] ?></span>
                        <span class="text-[9px] text-zinc-300 font-bold"><?= $day['date_lbl'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Grid Body -->
            <div class="divide-y divide-zinc-200">
                <?php foreach ($time_blocks as $time): ?>
                    <div class="grid grid-cols-8 items-stretch min-h-[72px]">
                        <!-- Time Column -->
                        <div class="p-4 bg-[#f6f3f2] border-r-2 border-primary flex items-center justify-center">
                            <span class="font-headline font-black text-sm text-primary"><?= date('h:i A', strtotime($time)) ?></span>
                        </div>
                        
                        <!-- Day Columns -->
                        <?php foreach ($days_of_week as $day):
                            $sql_date   = $day['sql_date'];
                            $slot       = $schedule_map[$sql_date][$time] ?? null;
                            $cell_dow            = (int)date('N', strtotime($sql_date));
                            $cell_hour           = (int)substr($time, 0, 2);
                            $is_game_night_cell  = (($cell_dow === 2 || $cell_dow === 4) && ($cell_hour >= 19 || $cell_hour === 0));
                            $cell_key = $sql_date . '|' . $time;
                        ?>
                            <div class="p-1.5 border-r border-zinc-200 last:border-0 flex"
                                 data-cell="<?= htmlspecialchars($cell_key) ?>"
                                 data-date="<?= $sql_date ?>"
                                 data-time="<?= $time ?>">
                                <?php if ($is_game_night_cell): ?>
                                    <div class="w-full bg-neutral-800 border-2 border-zinc-700 flex flex-col justify-between p-2.5 text-left select-none cursor-not-allowed"
                                         title="Game Night — Fixed Lock: Every Tuesday &amp; Thursday 7 PM onwards">
                                        <div class="flex justify-between items-start w-full">
                                            <span class="text-[9px] font-black uppercase tracking-wider text-amber-400">Game Night</span>
                                            <span class="material-symbols-outlined text-xs text-zinc-400">lock</span>
                                        </div>
                                        <p class="text-[10px] font-black uppercase tracking-tight mt-1 text-zinc-500">Tue &amp; Thu Only</p>
                                    </div>
                                <?php elseif (!$slot): ?>
                                    <div class="w-full bg-[#fcfcfc] border border-dashed border-zinc-300 flex items-center justify-center p-2 text-center select-none opacity-50">
                                        <span class="text-[8px] font-bold text-zinc-400 uppercase">No Block</span>
                                    </div>
                                <?php else:
                                    $bg_cls = 'bg-primary text-white';
                                    $label  = 'Available';
                                    $detail = 'Open for booking';

                                    if (isset($slot['booking_status'])) {
                                        if ($slot['booking_status'] === 'completed') {
                                            $bg_cls = 'bg-[#4a8f3c] text-white';
                                            $label  = 'Completed';
                                            $detail = htmlspecialchars($slot['client_name'] ?? 'Trainee');
                                        } elseif ($slot['booking_status'] === 'confirmed') {
                                            $bg_cls = 'bg-[#bcf0ae] text-primary';
                                            $label  = 'Confirmed';
                                            $detail = htmlspecialchars($slot['client_name'] ?? 'Trainee');
                                        } elseif ($slot['booking_status'] === 'reserved') {
                                            $bg_cls = 'bg-accent text-primary';
                                            $label  = 'Reserved';
                                            $detail = $slot['client_name'] ? htmlspecialchars($slot['client_name']) : 'Pay Later';
                                        }
                                    } elseif ($slot['status'] === 'locked') {
                                        $bg_cls = 'bg-red-400 text-white border-red-500';
                                        $label  = 'Locked';
                                        $detail = $slot['notes'] ? htmlspecialchars($slot['notes']) : 'Maintenance';
                                    } elseif ($slot['status'] === 'reserved') {
                                        $bg_cls = 'bg-accent text-primary';
                                        $label  = 'Reserved';
                                        $detail = $slot['client_name'] ? htmlspecialchars($slot['client_name']) : 'Pay Later';
                                    } elseif ($slot['status'] === 'confirmed') {
                                        $bg_cls = 'bg-[#bcf0ae] text-primary';
                                        $label  = 'Confirmed';
                                        $detail = htmlspecialchars($slot['client_name'] ?? 'Trainee');
                                    }
                                ?>
                                    <button onclick="cellClicked(<?= htmlspecialchars(json_encode($slot)) ?>)"
                                            data-slot-id="<?= $slot['id'] ?>"
                                            class="w-full flex flex-col justify-between p-2.5 text-left border-2 border-primary cursor-pointer hover:brightness-95 active:scale-[0.98] transition-all <?= $bg_cls ?>">
                                        <div class="flex justify-between items-start w-full">
                                            <span class="text-[9px] font-black uppercase tracking-wider opacity-90"><?= $label ?></span>
                                            <?php if ($slot['status'] === 'locked'): ?>
                                                <span class="material-symbols-outlined text-xs">lock</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[10px] font-black uppercase tracking-tight mt-1 overflow-hidden overflow-ellipsis whitespace-nowrap w-full"><?= $detail ?></p>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</main>

<!-- Cell Control Modal -->
<div id="control-modal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border-4 border-primary max-w-sm w-full shadow-[8px_8px_0px_rgba(21,66,18,1)] fade-in">
        <div class="bg-primary px-6 py-4 flex justify-between items-center">
            <div>
                <h3 class="font-headline font-black text-white uppercase text-sm">Slot Operations Panel</h3>
                <p class="text-[10px] text-zinc-300 uppercase font-bold" id="modal-slot-lbl"></p>
            </div>
            <button onclick="closeControlModal()" class="text-white hover:text-accent"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="p-6 space-y-4">
            <input type="hidden" id="modal-slot-id">
            
            <div id="op-available" class="hidden space-y-3">
                <p class="text-xs text-zinc-500 font-bold">This slot is active and open for registration.</p>
                <div class="space-y-1">
                    <label class="block text-[10px] font-bold text-primary uppercase">Lock Notes / Reason</label>
                    <input type="text" id="lock-notes" placeholder="Routine maintenance check..."
                           class="w-full border-2 border-primary p-2 text-xs font-bold focus:ring-0 focus:border-accent">
                </div>
                <button onclick="toggleSlotLock()" class="w-full bg-red-400 text-white font-headline font-black uppercase text-xs py-3 border-2 border-red-500 hover:bg-red-500 transition-colors">
                    Lock / Close Court Slot
                </button>
                <button onclick="openWalkinModalFromSlot()" class="w-full bg-[#FFB800] text-primary font-headline font-black uppercase text-xs py-3 border-2 border-primary hover:bg-white transition-colors">
                    Book Walk-In Guest
                </button>
            </div>

            <div id="op-locked" class="hidden space-y-3">
                <p class="text-xs text-zinc-500 font-bold">This slot is locked for maintenance / administrative events.</p>
                <p class="text-xs text-primary font-bold">Lock Reason: <span id="lock-note-val" class="italic">None</span></p>
                <button onclick="toggleSlotLock()" class="w-full bg-primary text-white font-headline font-black uppercase text-xs py-3 border-2 border-primary hover:bg-accent hover:text-primary transition-colors">
                    Unlock / Open Court Slot
                </button>
            </div>

            <div id="op-game-night" class="hidden space-y-3">
                <div class="bg-neutral-800 border-2 border-zinc-700 p-4 flex items-center gap-3">
                    <span class="material-symbols-outlined text-amber-400 text-2xl">sports_tennis</span>
                    <div>
                        <p class="text-white font-headline font-black uppercase text-sm">Game Night — Fixed Lock</p>
                        <p class="text-[10px] text-zinc-400 font-bold uppercase">Every Tuesday & Thursday, 6:00 PM onwards</p>
                    </div>
                </div>
                <p class="text-xs text-zinc-500 font-bold">This time slot is permanently reserved for Game Night. It cannot be unlocked or used for bookings.</p>
                <div class="bg-[#f6f3f2] border-2 border-primary p-3 text-xs space-y-1">
                    <p class="flex items-center gap-1.5 font-bold text-primary"><span class="material-symbols-outlined text-sm">lock</span> Slot is protected by system policy</p>
                    <p class="flex items-center gap-1.5 font-bold text-zinc-400"><span class="material-symbols-outlined text-sm">block</span> Walk-in bookings disabled</p>
                    <p class="flex items-center gap-1.5 font-bold text-zinc-400"><span class="material-symbols-outlined text-sm">event_busy</span> Client reservations blocked</p>
                </div>
            </div>

            <div id="op-booked" class="hidden space-y-3">
                <div class="border-2 border-primary p-4 bg-[#f6f3f2] text-xs space-y-2">
                    <p class="flex justify-between"><span class="font-bold text-zinc-400 uppercase">Booking Ref</span><span id="booking-ref" class="font-headline font-black text-primary"></span></p>
                    <p class="flex justify-between"><span class="font-bold text-zinc-400 uppercase">Client Name</span><span id="booking-client" class="font-bold"></span></p>
                    <p class="flex justify-between"><span class="font-bold text-zinc-400 uppercase">Players</span><span id="booking-pax" class="font-bold"></span></p>
                    <p class="flex justify-between"><span class="font-bold text-zinc-400 uppercase">Status</span><span id="booking-status" class="font-bold"></span></p>
                </div>
                <div id="cancel-section" class="space-y-3">
                    <div class="space-y-1">
                        <label class="block text-[10px] font-bold text-primary uppercase">Cancellation Reason</label>
                        <input type="text" id="cancel-reason" placeholder="Client requested refund / rescheduling"
                               class="w-full border-2 border-primary p-2 text-xs font-bold focus:ring-0 focus:border-accent">
                    </div>
                    <button onclick="cancelBookingFromSlot()" class="w-full bg-red-400 text-white font-headline font-black uppercase text-xs py-3 border-2 border-red-500 hover:bg-red-500 transition-colors">
                        Force Cancel Booking
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Walk-in Booking Modal -->
<div id="walkin-modal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border-4 border-primary max-w-lg w-full shadow-[8px_8px_0px_rgba(21,66,18,1)] fade-in">
        <div class="bg-primary px-6 py-4 flex justify-between items-center">
            <div>
                <h3 class="font-headline font-black text-white uppercase text-sm">Walk-In Booking Registry</h3>
                <p class="text-[10px] text-zinc-300 uppercase font-bold">Direct administration checkout portal</p>
            </div>
            <button onclick="closeWalkinModal()" class="text-white hover:text-accent"><span class="material-symbols-outlined">close</span></button>
        </div>
        <form id="walkin-form" onsubmit="submitWalkin(event)" class="p-6 space-y-4">
            <input type="hidden" id="walkin-schedule-id">
            
            <div class="p-4 bg-[#f6f3f2] border-2 border-primary text-xs font-bold text-primary">
                Selected Slot: <span id="walkin-slot-desc"></span>
            </div>

            <div class="space-y-1">
                <label class="block text-[10px] font-bold text-primary uppercase mb-1">Guest Full Name</label>
                <input type="text" id="walkin-name" required placeholder="John Doe"
                       class="w-full border-2 border-primary p-2 text-xs font-bold focus:ring-0 focus:border-accent">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[10px] font-bold text-primary uppercase mb-1">Guest Email</label>
                    <input type="email" id="walkin-email" required placeholder="guest@temp.com"
                           class="w-full border-2 border-primary p-2 text-xs font-bold focus:ring-0 focus:border-accent">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-primary uppercase mb-1">Guest Phone Number</label>
                    <input type="text" id="walkin-phone" placeholder="+639171234567"
                           class="w-full border-2 border-primary p-2 text-xs font-bold focus:ring-0 focus:border-accent">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 items-center">
                <div>
                    <label class="block text-[10px] font-bold text-primary uppercase mb-1">Pax (Players Count)</label>
                    <select id="walkin-pax" required class="w-full border-2 border-primary p-2 text-xs font-bold focus:ring-0 focus:border-accent">
                        <option value="1">1 Player</option>
                        <option value="2">2 Players</option>
                        <option value="3">3 Players</option>
                        <option value="4">4 Players</option>
                    </select>
                </div>
                <div class="flex items-center gap-2 pt-4">
                    <input type="checkbox" id="walkin-coach" value="1"
                           class="border-2 border-primary text-primary focus:ring-0 focus:border-accent h-5 w-5">
                    <label for="walkin-coach" class="text-[10px] font-bold text-primary uppercase select-none cursor-pointer">Coaching Add-on</label>
                </div>
            </div>

            <hr class="border-2 border-primary my-4">
            
            <button type="submit" id="walkin-submit" class="w-full bg-primary text-white py-3.5 font-headline font-black uppercase text-xs hover:bg-accent hover:text-primary transition-colors flex items-center justify-center gap-2 shadow-[4px_4px_0px_rgba(0,0,0,0.15)]">
                Confirm Cash &amp; Register Booking
            </button>
        </form>
    </div>
</div>

<script>
// Jump to the first Monday of the selected month
function jumpToMonth(monthYear) {
    if (!monthYear) return;
    const [year, month] = monthYear.split('-').map(Number);
    // Find the first day of the month
    const firstDay = new Date(year, month - 1, 1);
    // ISO day of week: 0=Sun,1=Mon,...,6=Sat
    const dow = firstDay.getDay(); // 0=Sun
    // Calculate offset to next Monday (or same day if already Monday)
    const offsetToMonday = dow === 0 ? 1 : (dow === 1 ? 0 : (8 - dow));
    const monday = new Date(firstDay);
    monday.setDate(1 + (dow === 1 ? 0 : (dow === 0 ? 1 : 8 - dow)));
    const pad = n => String(n).padStart(2, '0');
    const dateStr = `${monday.getFullYear()}-${pad(monday.getMonth()+1)}-${pad(monday.getDate())}`;
    window.location.href = `?date=${dateStr}`;
}

// ── LIVE SCHEDULE SYNC ENGINE ────────────────────────────────────
// Polls the DB every 180 seconds and re-colours cells in-place
// so admin sees client bookings (and other admin changes) live.
const POLL_INTERVAL_MS = 180000;
const currentWeekDate  = '<?= $monday_date ?>';
let   pollTimer        = null;
let   countdown        = 180;
let   polling          = true;

const STATUS_STYLES = {
    available:  { bg: 'bg-primary',      text: 'text-white',   label: 'Available',  detail: 'Open for booking' },
    reserved:   { bg: 'bg-[#FFB800]',    text: 'text-primary', label: 'Reserved',   detail: 'Pay Later' },
    confirmed:  { bg: 'bg-[#bcf0ae]',    text: 'text-primary', label: 'Confirmed',  detail: '' },
    completed:  { bg: 'bg-[#4a8f3c]',    text: 'text-white',   label: 'Completed',  detail: '' },
    locked:     { bg: 'bg-red-400',       text: 'text-white',   label: 'Locked',     detail: 'Maintenance' },
};

function buildCellHTML(slot) {
    if (!slot) return null; // No block — leave as-is

    let style = STATUS_STYLES['available'];
    let label = 'Available';
    let detail = 'Open for booking';

    const bStatus = slot.booking_status;
    const sStatus = slot.status;

    if (bStatus === 'completed')        { style = STATUS_STYLES['completed']; label = 'Completed';  detail = slot.client_name || 'Trainee'; }
    else if (bStatus === 'confirmed')   { style = STATUS_STYLES['confirmed']; label = 'Confirmed';  detail = slot.client_name || 'Trainee'; }
    else if (bStatus === 'reserved')    { style = STATUS_STYLES['reserved'];  label = 'Reserved';   detail = slot.client_name || 'Pay Later'; }
    else if (sStatus === 'locked')      { style = STATUS_STYLES['locked'];    label = 'Locked';     detail = slot.notes || 'Maintenance'; }
    else if (sStatus === 'reserved')    { style = STATUS_STYLES['reserved'];  label = 'Reserved';   detail = slot.client_name || 'Pay Later'; }
    else if (sStatus === 'confirmed')   { style = STATUS_STYLES['confirmed']; label = 'Confirmed';  detail = slot.client_name || 'Trainee'; }

    const lockIcon = (sStatus === 'locked') ? '<span class="material-symbols-outlined text-xs">lock</span>' : '';
    const slotJson = JSON.stringify(slot).replace(/'/g, "\\'");

    return `<button onclick="cellClicked(${JSON.stringify(slot).replace(/"/g, '&quot;')})"
                    data-slot-id="${slot.id}"
                    class="w-full flex flex-col justify-between p-2.5 text-left border-2 border-primary cursor-pointer hover:brightness-95 active:scale-[0.98] transition-all ${style.bg} ${style.text}">
                <div class="flex justify-between items-start w-full">
                    <span class="text-[9px] font-black uppercase tracking-wider opacity-90">${label}</span>
                    ${lockIcon}
                </div>
                <p class="text-[10px] font-black uppercase tracking-tight mt-1 overflow-hidden overflow-ellipsis whitespace-nowrap w-full">${detail}</p>
            </button>`;
}

async function pollSchedule() {
    if (!polling) return;

    const statusEl = document.getElementById('live-status');
    const badgeEl  = document.getElementById('live-badge');
    if (statusEl) statusEl.textContent = 'Syncing...';
    if (badgeEl)  badgeEl.classList.add('opacity-60');

    try {
        const res  = await fetch(`../actions/admin_schedule_live.php?action=get_week&date=${currentWeekDate}`);
        const data = await res.json();

        if (data.success) {
            // Update each cell that has changed
            document.querySelectorAll('[data-cell]').forEach(cellDiv => {
                const key  = cellDiv.dataset.cell;
                const slot = data.slots[key];

                // Skip Game Night cells entirely (they are permanently locked static blocks with no buttons)
                if (slot && (slot.is_game_night || slot.is_game_night === '1' || slot.is_game_night === 1 || slot.is_game_night === true)) {
                    return;
                }

                // Skip empty cells (they don't change)
                const existingBtn = cellDiv.querySelector('button[data-slot-id]');
                if (!existingBtn && !slot) return; // was empty, still empty

                if (slot && existingBtn) {
                    // Cell exists — check if anything changed by comparing slot state
                    const newHTML = buildCellHTML(slot);
                    if (newHTML && existingBtn.outerHTML !== newHTML) {
                        cellDiv.innerHTML = newHTML;
                    }
                } else if (slot && !existingBtn) {
                    // Slot just appeared (was "No Block", now has data) — reload whole page
                    location.reload();
                }
            });

            if (statusEl) statusEl.textContent = 'LIVE';
            if (badgeEl)  { badgeEl.classList.remove('opacity-60'); badgeEl.classList.remove('bg-red-600'); badgeEl.classList.add('bg-primary'); }

            // Restart countdown
            countdown = 180;
        }
    } catch(e) {
        if (statusEl) statusEl.textContent = 'Offline';
        if (badgeEl)  { badgeEl.classList.remove('bg-primary'); badgeEl.classList.add('bg-red-600'); }
    }
}

// Countdown ticker
setInterval(() => {
    if (!polling) return;
    countdown--;
    const el = document.getElementById('live-status');
    if (el && countdown > 0 && el.textContent !== 'Syncing...') {
        el.textContent = `Sync in ${countdown}s`;
    }
    if (countdown <= 0) {
        countdown = 180;
        pollSchedule();
    }
}, 1000);

// Initial poll after 5 seconds (let page settle)
setTimeout(pollSchedule, 5000);

function generateWeeklySchedule() {
    if(!confirm("Are you sure you want to generate schedules for this entire week? Locked and booked blocks won't be cleared.")) return;
    
    const fd = new FormData();
    fd.append('action', 'generate_schedule');
    fd.append('monday_date', '<?= $monday_date ?>');
    
    fetch('../actions/admin_schedule.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        alert(d.message);
        if(d.success) location.reload();
    })
    .catch(() => alert('Failed to generate weekly schedule.'));
}

function cellClicked(slot) {
    document.getElementById('modal-slot-id').value = slot.id;
    document.getElementById('modal-slot-lbl').textContent = `${slot.session_date} @ ${slot.start_time}`;
    
    document.getElementById('op-available').classList.add('hidden');
    document.getElementById('op-locked').classList.add('hidden');
    document.getElementById('op-booked').classList.add('hidden');
    document.getElementById('op-game-night').classList.add('hidden');
    
    // Check if this is a game night slot (Tue/Thu 6PM+)
    const isGameNight = !!slot.is_game_night;

    if (slot.status === 'locked' && isGameNight) {
        // Fixed game night lock — no unlock allowed
        document.getElementById('op-game-night').classList.remove('hidden');
    } else if (slot.status === 'available' && !slot.booking_status) {
        document.getElementById('op-available').classList.remove('hidden');
    } else if (slot.status === 'locked') {
        document.getElementById('op-locked').classList.remove('hidden');
        document.getElementById('lock-note-val').textContent = slot.notes ? slot.notes : 'General Lock';
    } else if (slot.booking_status || slot.status === 'booked' || slot.status === 'confirmed' || slot.status === 'reserved') {
        document.getElementById('op-booked').classList.remove('hidden');
        document.getElementById('booking-ref').textContent = slot.booking_code;
        document.getElementById('booking-client').textContent = slot.client_name ? slot.client_name : 'N/A';
        document.getElementById('booking-pax').textContent = `${slot.pax} players`;
        document.getElementById('booking-status').textContent = (slot.booking_status ? slot.booking_status : slot.status).toUpperCase();
        
        // Save current active booking id for cancelling
        document.getElementById('op-booked').dataset.bookingId = slot.booking_id;

        // Hide cancel section if completed
        if (slot.booking_status === 'completed') {
            document.getElementById('cancel-section').classList.add('hidden');
        } else {
            document.getElementById('cancel-section').classList.remove('hidden');
        }
    }
    
    document.getElementById('control-modal').classList.remove('hidden');
}

function closeControlModal() {
    document.getElementById('control-modal').classList.add('hidden');
}

function toggleSlotLock() {
    const id = document.getElementById('modal-slot-id').value;
    const notes = document.getElementById('lock-notes').value;
    
    const fd = new FormData();
    fd.append('action', 'toggle_lock');
    fd.append('slot_id', id);
    fd.append('notes', notes);
    
    fetch('../actions/admin_schedule.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        alert(d.message);
        if(d.success) location.reload();
    })
    .catch(() => alert('Failed to toggle slot lock.'));
}

function openWalkinModalFromSlot() {
    const id = document.getElementById('modal-slot-id').value;
    const desc = document.getElementById('modal-slot-lbl').textContent;
    
    closeControlModal();
    
    document.getElementById('walkin-schedule-id').value = id;
    document.getElementById('walkin-slot-desc').textContent = desc;
    document.getElementById('walkin-modal').classList.remove('hidden');
}

function closeWalkinModal() {
    document.getElementById('walkin-modal').classList.add('hidden');
    document.getElementById('walkin-form').reset();
}

function submitWalkin(e) {
    e.preventDefault();
    const btn = document.getElementById('walkin-submit');
    btn.disabled = true;
    btn.textContent = 'PLACING BOOKING...';
    
    const fd = new FormData();
    fd.append('action', 'manual_booking');
    fd.append('schedule_id', document.getElementById('walkin-schedule-id').value);
    fd.append('full_name', document.getElementById('walkin-name').value);
    fd.append('email', document.getElementById('walkin-email').value);
    fd.append('phone', document.getElementById('walkin-phone').value);
    fd.append('pax', document.getElementById('walkin-pax').value);
    fd.append('add_coaching', document.getElementById('walkin-coach').checked);
    
    fetch('../actions/admin_schedule.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert(`Booking ${d.booking_code} registered successfully!`);
            closeWalkinModal();
            location.reload();
        } else {
            alert('Error placing booking: ' + d.message);
            btn.disabled = false;
            btn.textContent = 'Confirm Cash & Register Booking';
        }
    })
    .catch(e => {
        alert('An error occurred. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Confirm Cash & Register Booking';
    });
}

function cancelBookingFromSlot() {
    const bookingId = document.getElementById('op-booked').dataset.bookingId;
    const reason = document.getElementById('cancel-reason').value;
    
    if(!confirm("Are you sure you want to cancel this booking and release the slot? This cannot be undone.")) return;
    
    const fd = new FormData();
    fd.append('action', 'cancel_booking');
    fd.append('booking_id', bookingId);
    fd.append('reason', reason);
    
    fetch('../actions/admin_booking_status.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        alert(d.message);
        if (d.success) location.reload();
    })
    .catch(() => alert('Failed to cancel booking.'));
}
</script>
</body>
</html>
