<?php
// admin/attendance.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_admin();
run_cron_simulator($pdo);

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'RDG ADMIN';

// Selected Date
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Load active confirmed/completed booking sessions for this date
$stmt_sessions = $pdo->prepare("
    SELECT s.id AS schedule_id, s.start_time, s.end_time, s.notes,
           b.booking_code, b.status AS booking_status, u.full_name AS client_name, (b.coaching_fee > 0) AS add_coaching, b.pax
      FROM schedules s
      JOIN bookings b ON b.schedule_id = s.id
      JOIN users u ON u.id = b.user_id
     WHERE s.session_date = ? AND b.status IN ('confirmed', 'completed')
     ORDER BY s.start_time ASC
");
$stmt_sessions->execute([$selected_date]);
$active_sessions = $stmt_sessions->fetchAll(PDO::FETCH_ASSOC);

// Selected Session ID (default to first active session)
$selected_schedule_id = (int)($_GET['schedule_id'] ?? ($active_sessions[0]['schedule_id'] ?? 0));

// Compute Daily attendance metrics
$stmt_tot = $pdo->prepare("
    SELECT COUNT(*), SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END)
      FROM attendance a
      JOIN sessions s ON s.id = a.session_id
      JOIN bookings b ON b.id = s.booking_id
      JOIN schedules sch ON sch.id = b.schedule_id
     WHERE sch.session_date = ?
");
$stmt_tot->execute([$selected_date]);
$att_stats = $stmt_tot->fetch(PDO::FETCH_NUM);
$total_capacity = (int)($att_stats[0] ?? 0);
$total_present = (int)($att_stats[1] ?? 0);
$attendance_percent = $total_capacity > 0 ? round(($total_present / $total_capacity) * 100) : 0;

// Cancelled sessions today
$stmt_cancel = $pdo->prepare("
    SELECT COUNT(*) 
      FROM bookings b 
      JOIN schedules s ON b.schedule_id = s.id 
     WHERE s.session_date = ? AND b.status = 'cancelled'
");
$stmt_cancel->execute([$selected_date]);
$cancelled_count = (int)$stmt_cancel->fetchColumn();

// Fetch currently selected session details
$current_session = null;
foreach ($active_sessions as $sess) {
    if ((int)$sess['schedule_id'] === $selected_schedule_id) {
        $current_session = $sess;
        break;
    }
}
if (!$current_session && !empty($active_sessions)) {
    $current_session = $active_sessions[0];
    $selected_schedule_id = (int)$current_session['schedule_id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>RDG Tennis - Admin Attendance Management</title>
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
        <a class="bg-[#FFE500] text-primary border-l-4 border-primary px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm" href="attendance.php"><span class="material-symbols-outlined fill-icon">fact_check</span> Attendance</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="cancellations.php"><span class="material-symbols-outlined">cancel</span> Requests</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="months.php"><span class="material-symbols-outlined">calendar_month</span> Months</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="reports.php"><span class="material-symbols-outlined">analytics</span> Reports</a>
    </nav>
</aside>

<!-- Main -->
<main class="ml-64 pt-20 min-h-screen p-8">
    <div class="max-w-7xl mx-auto space-y-8 fade-in">
        <!-- Page Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 border-b-4 border-primary pb-6">
            <div>
                <h1 class="font-headline font-black text-5xl text-primary uppercase tracking-tighter">Athlete Check-in</h1>
                <p class="text-[#42493e]">Process session attendance logs and track athlete statistics.</p>
            </div>
            
            <!-- Date Filter form -->
            <form method="GET" class="flex flex-wrap gap-2 items-center bg-white p-3 border-2 border-primary shadow-[2px_2px_0px_rgba(21,66,18,0.2)]">
                <span class="text-xs font-bold text-primary uppercase">Select Date:</span>
                <input type="date" name="date" value="<?= htmlspecialchars($selected_date) ?>" onchange="this.form.submit()"
                       class="border-2 border-primary text-xs font-bold p-1 focus:ring-0 focus:border-accent">
                <?php if($selected_schedule_id): ?>
                    <input type="hidden" name="schedule_id" value="<?= $selected_schedule_id ?>">
                <?php endif; ?>
            </form>
        </div>

        <!-- Master Sessions Table -->
        <section class="bg-white border-2 border-primary p-6 shadow-[4px_4px_0px_rgba(21,66,18,1)]">
            <div class="flex justify-between items-center mb-6 border-b-2 border-primary pb-4">
                <h3 class="font-headline font-bold text-xl text-primary uppercase">Training Sessions & Court Registry</h3>
                <span class="text-xs text-zinc-400 font-bold uppercase">Schedule Overview for <?= date('F d, Y', strtotime($selected_date)) ?></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse border-2 border-primary text-left text-xs">
                    <thead>
                        <tr class="bg-primary text-white border-b-2 border-primary">
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase">Time Slot</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase">Client Name</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase text-center">Reference</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase text-center">Session Type</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase text-center">Pax</th>
                            <th class="p-3 border-r border-white/20 font-headline font-bold uppercase text-center">Status</th>
                            <th class="p-3 font-headline font-bold uppercase text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white">
                        <?php if (empty($active_sessions)): ?>
                            <tr>
                                <td colspan="7" class="p-6 text-center text-zinc-400 italic">No training sessions or court bookings scheduled on this date.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($active_sessions as $sess):
                                $is_active_selection = ((int)$sess['schedule_id'] === $selected_schedule_id);
                                $is_done = ($sess['booking_status'] === 'completed');
                                $row_highlight = $is_active_selection ? 'bg-[#FFE500]/10 hover:bg-[#FFE500]/15' : 'hover:bg-zinc-50';
                            ?>
                                <tr class="transition-colors <?= $row_highlight ?>">
                                    <td class="p-3 border-r border-zinc-200 font-headline font-black text-xs text-primary uppercase">
                                        <?= date('h:i A', strtotime($sess['start_time'])) ?> - <?= date('h:i A', strtotime($sess['end_time'])) ?>
                                    </td>
                                    <td class="p-3 border-r border-zinc-200 font-bold text-primary">
                                        <?= htmlspecialchars($sess['client_name']) ?>
                                    </td>
                                    <td class="p-3 border-r border-zinc-200 font-mono text-zinc-500 font-bold uppercase">
                                        <?= htmlspecialchars($sess['booking_code']) ?>
                                    </td>
                                    <td class="p-3 border-r border-zinc-200 text-center">
                                        <span class="px-2 py-0.5 text-[9px] font-black uppercase <?= $sess['add_coaching'] ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700' ?>">
                                            <?= $sess['add_coaching'] ? 'Coaching Training' : 'Court Hire' ?>
                                        </span>
                                    </td>
                                    <td class="p-3 border-r border-zinc-200 text-center font-bold">
                                        <?= (int)$sess['pax'] ?> Pax
                                    </td>
                                    <td class="p-3 border-r border-zinc-200 text-center">
                                        <?php if ($is_done): ?>
                                            <span class="px-2 py-0.5 text-[9px] font-black uppercase bg-green-100 text-[#4a8f3c]">Completed</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 text-[9px] font-black uppercase bg-accent text-primary">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3 text-center flex items-center justify-center gap-2">
                                        <!-- Take Attendance/Select Row -->
                                        <a href="?date=<?= urlencode($selected_date) ?>&schedule_id=<?= $sess['schedule_id'] ?>" 
                                           class="px-3 py-1.5 font-headline font-black text-[10px] uppercase tracking-wider border-2 border-primary shadow-[2px_2px_0px_rgba(21,66,18,1)] hover:bg-[#FFE500] active:translate-y-[1px] active:shadow-none transition-all <?= $is_active_selection ? 'bg-[#FFE500] text-primary' : 'bg-white text-zinc-700' ?>">
                                            <?= $is_active_selection ? '✓ Selected Checklist' : 'Take Attendance' ?>
                                        </a>
                                        
                                        <!-- Complete Session -->
                                        <?php if (!$is_done): ?>
                                            <button onclick="markSessionCompleted(<?= $sess['schedule_id'] ?>)" 
                                                    class="px-3 py-1.5 bg-primary text-white font-headline font-black text-[10px] uppercase tracking-wider border-2 border-primary shadow-[2px_2px_0px_rgba(0,0,0,0.15)] hover:bg-accent hover:text-primary active:translate-y-[1px] active:shadow-none transition-all">
                                                Complete Session
                                            </button>
                                        <?php else: ?>
                                            <span class="px-3 py-1.5 text-[10px] font-bold text-[#4a8f3c] flex items-center justify-center gap-1 font-headline font-black uppercase">
                                                <span class="material-symbols-outlined text-sm font-bold">check_circle</span> Done
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Attendance Checklist details -->
        <section class="bg-white border-2 border-primary shadow-[6px_6px_0px_rgba(21,66,18,1)] overflow-hidden">
            <div class="px-6 py-4 border-b-2 border-primary flex justify-between items-center bg-[#f6f3f2]">
                <h3 class="font-headline font-black text-primary uppercase text-sm">
                    Athlete Attendance Checklist: <?= $current_session ? htmlspecialchars($current_session['client_name']) : '—' ?>
                </h3>
                <span class="text-xs text-zinc-400 font-bold uppercase" id="athlete-count">Loading list...</span>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-primary text-white font-headline font-bold text-xs uppercase">
                            <th class="px-5 py-3 border-r border-[#2d5a27]">Athlete Name</th>
                            <th class="px-5 py-3 border-r border-[#2d5a27] text-center">Membership Status</th>
                            <th class="px-5 py-3 border-r border-[#2d5a27] text-center">Status</th>
                            <th class="px-5 py-3 border-r border-[#2d5a27] text-center">Log Time</th>
                            <th class="px-5 py-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="attendance-rows" class="divide-y divide-zinc-200">
                        <!-- Dynamic AJAX content loaded here -->
                        <tr><td colspan="5" class="px-6 py-12 text-center text-zinc-400 text-sm">Please select a session from the list above.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    loadAthletesList();
});

function loadAthletesList() {
    const scheduleId = <?= $selected_schedule_id ?>;
    const container = document.getElementById('attendance-rows');
    const header = document.getElementById('athlete-count');
    
    if (scheduleId <= 0) {
        container.innerHTML = '<tr><td colspan="5" class="px-6 py-12 text-center text-zinc-400 text-sm">No booked session available for attendance logging.</td></tr>';
        header.textContent = '0 ATHLETES';
        return;
    }
    
    container.innerHTML = '<tr><td colspan="5" class="px-6 py-12 text-center text-zinc-400 text-sm">Loading athlete list...</td></tr>';
    
    fetch(`../actions/admin_attendance.php?action=get_session_athletes&schedule_id=${scheduleId}`)
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                header.textContent = `${d.athletes.length} ATHLETES REGISTERED`;
                if (d.athletes.length === 0) {
                    container.innerHTML = '<tr><td colspan="5" class="px-6 py-12 text-center text-zinc-400 text-sm">No athletes checked-in.</td></tr>';
                    return;
                }
                
                const isCompleted = d.booking_status === 'completed';
                const disabledAttr = isCompleted ? 'disabled' : '';
                
                container.innerHTML = d.athletes.map(a => {
                    const isMarked = a.marked_at !== null;
                    const isPresent = isMarked && a.status === 'present';
                    const isAbsent = isMarked && a.status === 'absent';
                    
                    let statusClass = 'bg-zinc-100 text-zinc-500 border-2 border-zinc-300';
                    let statusText = 'Pending';
                    
                    if (isMarked) {
                        if (a.status === 'present') {
                            statusClass = 'bg-[#eefcf2] text-[#4a8f3c] border-2 border-[#4a8f3c]';
                            statusText = 'Present';
                        } else {
                            statusClass = 'bg-red-50 text-red-500 border-2 border-red-300';
                            statusText = 'Absent';
                        }
                    }

                    const initials = a.attendee_name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();

                    const presClass = isPresent 
                        ? 'bg-[#4a8f3c] text-white border-2 border-[#4a8f3c] font-black uppercase text-[10px] px-3 py-1.5 shadow-[1px_1px_0px_rgba(0,0,0,0.1)]'
                        : `bg-[#f6f3f2] text-zinc-500 border-2 border-zinc-300 font-bold uppercase text-[10px] px-3 py-1.5 transition-all ${isCompleted ? 'opacity-40 cursor-not-allowed' : 'hover:text-white hover:bg-[#4a8f3c] hover:border-[#4a8f3c]'}`;

                    const absClass = isAbsent
                        ? 'bg-red-500 text-white border-2 border-red-500 font-black uppercase text-[10px] px-3 py-1.5 shadow-[1px_1px_0px_rgba(0,0,0,0.1)]'
                        : `bg-[#f6f3f2] text-zinc-500 border-2 border-zinc-300 font-bold uppercase text-[10px] px-3 py-1.5 transition-all ${isCompleted ? 'opacity-40 cursor-not-allowed' : 'hover:text-white hover:bg-red-500 hover:border-red-500'}`;

                    const actionBtn = `
                        <div class="flex items-center justify-center gap-2">
                            <button onclick="markStatus(${a.id}, 'present')" ${disabledAttr} class="${presClass}">Present</button>
                            <button onclick="markStatus(${a.id}, 'absent')" ${disabledAttr} class="${absClass}">Absent</button>
                        </div>
                    `;
                    
                    return `<tr class="hover:bg-[#f6f3f2] transition-colors border-b border-zinc-200 fade-in">
                        <td class="px-5 py-4 border-r border-zinc-200">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 flex items-center justify-center font-bold text-xs border-2 border-primary bg-accent text-primary uppercase">${initials}</div>
                                <span class="font-bold text-primary">${a.attendee_name}</span>
                            </div>
                        </td>
                        <td class="px-5 py-4 border-r border-zinc-200 text-center uppercase font-bold text-[10px] text-zinc-500">${a.role}</td>
                        <td class="px-5 py-4 border-r border-zinc-200 text-center">
                            <span class="px-2.5 py-1 text-[9px] uppercase tracking-wider font-black ${statusClass}">${statusText}</span>
                        </td>
                        <td class="px-5 py-4 border-r border-zinc-200 text-center text-xs text-zinc-400">${a.marked_at ? a.marked_at : '—'}</td>
                        <td class="px-5 py-4 text-center">${actionBtn}</td>
                    </tr>`;
                }).join('');
            } else {
                container.innerHTML = `<tr><td colspan="5" class="px-6 py-12 text-center text-red-500 text-sm">Failed to load athletes: ${d.message}</td></tr>`;
            }
        })
        .catch(() => {
            container.innerHTML = '<tr><td colspan="5" class="px-6 py-12 text-center text-red-500 text-sm">An error occurred while fetching checklist.</td></tr>';
        });
}

function markStatus(attendanceId, status) {
    const fd = new FormData();
    fd.append('action', 'mark_attendance');
    fd.append('attendance_id', attendanceId);
    fd.append('status', status);
    
    fetch('../actions/admin_attendance.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if(d.success) {
            loadAthletesList();
        } else {
            alert('Failed to log attendance: ' + d.message);
        }
    })
    .catch(() => alert('Failed to update attendance status.'));
}

function markSessionCompleted(scheduleId) {
    if (!confirm('Are you sure you want to mark this training session as completed? This will lock attendance checking.')) {
        return;
    }
    
    const fd = new FormData();
    fd.append('action', 'complete_session');
    fd.append('schedule_id', scheduleId);
    
    fetch('../actions/admin_attendance.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('Session marked as completed successfully!');
            window.location.reload();
        } else {
            alert('Failed to complete session: ' + d.message);
        }
    })
    .catch(() => alert('An error occurred. Failed to complete session.'));
}
</script>
</body>
</html>
