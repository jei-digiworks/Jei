<?php
// admin/months.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_admin();
run_cron_simulator($pdo);

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'RDG ADMIN';

// Fetch all bookable months
$stmt = $pdo->prepare("SELECT * FROM bookable_months ORDER BY month_year ASC");
$stmt->execute();
$months_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Sunday Locked configuration status
$stmt_config = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'sunday_locked' LIMIT 1");
$stmt_config->execute();
$sunday_locked = $stmt_config->fetchColumn();
if ($sunday_locked === false) {
    $sunday_locked = '1'; // Default to locked
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>RDG Tennis - Months Management</title>
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
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="schedule.php"><span class="material-symbols-outlined">calendar_today</span> Schedule</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="bookings.php"><span class="material-symbols-outlined">payments</span> Bookings</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="attendance.php"><span class="material-symbols-outlined">fact_check</span> Attendance</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="cancellations.php"><span class="material-symbols-outlined">cancel</span> Requests</a>
        <a class="bg-[#FFE500] text-primary border-l-4 border-primary px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm" href="months.php"><span class="material-symbols-outlined fill-icon">calendar_month</span> Months</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="reports.php"><span class="material-symbols-outlined">analytics</span> Reports</a>
    </nav>
</aside>

<!-- Main -->
<main class="ml-64 pt-20 min-h-screen p-8">
    <div class="max-w-7xl mx-auto space-y-8 fade-in">
        <!-- Page Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 border-b-4 border-primary pb-6">
            <div>
                <h1 class="font-headline font-black text-5xl text-primary uppercase tracking-tighter">Months Management</h1>
                <p class="text-[#42493e]">Control which months are available/open for athlete bookings and batch generate monthly slot blocks.</p>
            </div>
            <button onclick="openAddMonthModal()" class="flex items-center gap-2 bg-primary text-white font-headline font-black uppercase px-6 py-3 hover:bg-accent hover:text-primary transition-all shadow-[4px_4px_0px_rgba(21,66,18,0.3)] border-2 border-primary">
                <span class="material-symbols-outlined text-sm">add_circle</span>
                Add Month
            </button>
        </div>

        <!-- Global Sunday Slots Booking Control Section -->
        <section class="bg-white border-2 border-primary p-6 shadow-[6px_6px_0px_rgba(21,66,18,1)] flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
            <div class="space-y-2">
                <h3 class="font-headline font-black text-xl text-primary uppercase tracking-tight flex items-center gap-2">
                    <span class="material-symbols-outlined text-2xl text-primary">calendar_today</span>
                    Sunday Weekly Slots Control
                </h3>
                <p class="text-xs text-zinc-500 max-w-2xl leading-relaxed">
                    By default, Sundays can be locked from training to prevent automated bookings. Locking Sundays will lock all existing available Sunday slots and ensure any newly generated slots are marked as locked. Unlocking Sundays will restore all unbooked Sunday slots back to available.
                </p>
            </div>
            <div class="flex items-center gap-4 min-w-[280px] w-full md:w-auto">
                <div class="flex-1 flex flex-col">
                    <span class="text-[10px] text-zinc-400 font-bold uppercase tracking-wider">Sunday Booking Status:</span>
                    <span class="font-headline font-black text-sm uppercase flex items-center gap-1.5 <?= $sunday_locked === '1' ? 'text-red-600' : 'text-[#4a8f3c]' ?>">
                        <span class="w-2.5 h-2.5 rounded-full <?= $sunday_locked === '1' ? 'bg-red-600' : 'bg-green-400' ?> <?= $sunday_locked === '1' ? '' : 'animate-pulse' ?>"></span>
                        <?= $sunday_locked === '1' ? 'Currently Locked' : 'Currently Unlocked / Open' ?>
                    </span>
                </div>
                <button onclick="toggleSundayLock('<?= $sunday_locked ?>')" class="px-5 py-3 font-headline font-black text-xs uppercase tracking-wider border-2 border-primary shadow-[3px_3px_0px_rgba(21,66,18,1)] active:translate-y-[1px] active:shadow-none transition-all <?= $sunday_locked === '1' ? 'bg-accent hover:bg-yellow-400 text-primary' : 'bg-red-100 hover:bg-red-200 text-red-600' ?>">
                    <?= $sunday_locked === '1' ? 'Unlock Sunday Slots' : 'Lock Sunday Slots' ?>
                </button>
            </div>
        </section>

        <!-- Months Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($months_list as $m): 
                $timestamp = strtotime($m['month_year'] . '-01');
                $month_name = date('F Y', $timestamp);
                $is_open = (bool)$m['is_open'];
                $card_border = $is_open ? 'border-primary' : 'border-zinc-300 opacity-80';
                $card_shadow = $is_open ? 'shadow-[6px_6px_0px_rgba(21,66,18,1)]' : 'shadow-[4px_4px_0px_rgba(150,150,150,0.5)]';
            ?>
                <div class="bg-white border-2 <?= $card_border ?> <?= $card_shadow ?> flex flex-col justify-between p-6 transition-all duration-200 hover:-translate-y-0.5">
                    <div class="space-y-4">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="font-headline font-black text-lg text-primary uppercase tracking-tight"><?= $month_name ?></h3>
                                <span class="text-[10px] text-zinc-400 font-bold tracking-widest"><?= $m['month_year'] ?></span>
                            </div>
                            <?php if ($is_open): ?>
                                <span class="px-2 py-0.5 text-[9px] uppercase tracking-wider font-black bg-primary text-white flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-400"></span> Open
                                </span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 text-[9px] uppercase tracking-wider font-black bg-zinc-100 text-zinc-500 border border-zinc-300">
                                    Locked
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <p class="text-xs text-zinc-500 leading-relaxed">
                            <?php if ($is_open): ?>
                                Athletes can book available time slots for any day in this month.
                            <?php else: ?>
                                Bookings are disabled for this month. Athletes cannot view or book slots.
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="mt-6 flex flex-col gap-2">
                        <!-- Toggle Button -->
                        <button onclick="toggleMonth('<?= $m['month_year'] ?>')" 
                                class="w-full py-2.5 font-headline font-black text-xs uppercase transition-all flex items-center justify-center gap-2 border-2 
                                       <?= $is_open ? 'bg-zinc-50 hover:bg-zinc-100 text-primary border-primary' : 'bg-primary hover:bg-accent hover:text-primary text-white border-primary' ?>">
                            <span class="material-symbols-outlined text-sm"><?= $is_open ? 'lock_open' : 'lock' ?></span>
                            <?= $is_open ? 'Lock booking window' : 'Open for bookings' ?>
                        </button>

                        <!-- Batch Generator Button -->
                        <button onclick="generateMonthlySlots(this, '<?= $m['month_year'] ?>')" 
                                class="w-full py-2.5 font-headline font-black text-xs uppercase bg-[#FFE500] hover:bg-accent text-primary border-2 border-primary transition-all flex items-center justify-center gap-2 shadow-[2px_2px_0px_rgba(21,66,18,0.2)]">
                            <span class="material-symbols-outlined text-sm">event_repeat</span>
                            Generate Time Slots
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<!-- Add Month Modal -->
<div id="add-month-modal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border-4 border-primary max-w-sm w-full shadow-[8px_8px_0px_rgba(21,66,18,1)] fade-in">
        <div class="bg-primary px-6 py-4 flex justify-between items-center">
            <div>
                <h3 class="font-headline font-black text-white uppercase text-sm">Add New Month</h3>
                <p class="text-[10px] text-zinc-300 uppercase font-bold">Register a booking window month</p>
            </div>
            <button onclick="closeAddMonthModal()" class="text-white hover:text-accent"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="p-6 space-y-5">
            <div class="space-y-1">
                <label class="block text-[10px] font-bold text-primary uppercase">Month &amp; Year</label>
                <input type="month" id="new-month-input" min="2026-01" max="2030-12"
                       class="w-full border-2 border-primary p-2.5 text-sm font-bold font-headline focus:ring-0 focus:border-accent text-primary"
                       placeholder="YYYY-MM">
                <p class="text-[10px] text-zinc-400">Select the month you want to make available for administration.</p>
            </div>
            <div class="space-y-1">
                <label class="block text-[10px] font-bold text-primary uppercase">Initial Status</label>
                <select id="new-month-status" class="w-full border-2 border-primary p-2.5 text-sm font-bold font-headline focus:ring-0 focus:border-accent text-primary">
                    <option value="0">Locked (Admin closes bookings)</option>
                    <option value="1">Open (Athletes can book)</option>
                </select>
            </div>
            <button onclick="submitAddMonth()" id="add-month-btn"
                    class="w-full bg-primary text-white font-headline font-black uppercase text-xs py-3 border-2 border-primary hover:bg-accent hover:text-primary transition-colors flex items-center justify-center gap-2 shadow-[3px_3px_0px_rgba(21,66,18,0.3)]">
                <span class="material-symbols-outlined text-sm">add_circle</span>
                Add Month to System
            </button>
        </div>
    </div>
</div>

<script>
function toggleMonth(monthYear) {
    const fd = new FormData();
    fd.append('action', 'toggle_month');
    fd.append('month_year', monthYear);

    fetch('../actions/admin_months.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            location.reload();
        } else {
            alert(d.message);
        }
    })
    .catch(() => alert('Failed to toggle month availability.'));
}

function generateMonthlySlots(btn, monthYear) {
    if (!confirm(`Are you sure you want to batch generate/reset time slots for the entire month of ${monthYear}?`)) {
        return;
    }

    // Show loading indicator
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> Generating slots...';

    const fd = new FormData();
    fd.append('action', 'generate_monthly_slots');
    fd.append('month_year', monthYear);

    fetch('../actions/admin_months.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        alert(d.message);
        location.reload();
    })
    .catch(() => {
        alert('Failed to generate monthly slots.');
        btn.disabled = false;
        btn.innerHTML = origText;
    });
}

function toggleSundayLock(currentLocked) {
    const actionText = currentLocked === '1' ? 'unlock all Sundays' : 'lock all Sundays';
    if (!confirm(`Are you sure you want to ${actionText}? This will update the availability status of all Sunday weekly slots.`)) {
        return;
    }

    const fd = new FormData();
    fd.append('action', 'toggle_sunday_lock');
    fd.append('current_locked', currentLocked);

    fetch('../actions/admin_months.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert(d.message);
            location.reload();
        } else {
            alert(d.message);
        }
    })
    .catch(() => alert('Failed to update Sunday slots configuration.'));
}

function openAddMonthModal() {
    document.getElementById('add-month-modal').classList.remove('hidden');
    // Pre-fill with next month not in the system if possible
    const input = document.getElementById('new-month-input');
    if (!input.value) {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        input.value = `${y}-${m}`;
    }
}

function closeAddMonthModal() {
    document.getElementById('add-month-modal').classList.add('hidden');
}

function submitAddMonth() {
    const monthYear = document.getElementById('new-month-input').value;
    const isOpen = document.getElementById('new-month-status').value;

    if (!monthYear || !/^\d{4}-\d{2}$/.test(monthYear)) {
        alert('Please select a valid month and year.');
        return;
    }

    const btn = document.getElementById('add-month-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">sync</span> Adding...';

    const fd = new FormData();
    fd.append('action', 'add_month');
    fd.append('month_year', monthYear);
    fd.append('is_open', isOpen);

    fetch('../actions/admin_months.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            closeAddMonthModal();
            location.reload();
        } else {
            alert(d.message);
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined text-sm">add_circle</span> Add Month to System';
        }
    })
    .catch(() => {
        alert('Failed to add month.');
        btn.disabled = false;
        btn.innerHTML = '<span class="material-symbols-outlined text-sm">add_circle</span> Add Month to System';
    });
}

// Close modal on backdrop click
document.getElementById('add-month-modal').addEventListener('click', function(e) {
    if (e.target === this) closeAddMonthModal();
});
</script>
</body>
</html>
