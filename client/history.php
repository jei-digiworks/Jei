<?php
// client/history.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_client();
run_cron_simulator($pdo);

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// Notifications
$stmt_notif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$stmt_notif->execute([$user_id]);
$unread_count = $stmt_notif->fetchColumn();

// Pagination
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 8;
$offset   = ($page - 1) * $per_page;

// Filter
$filter_status = $_GET['status'] ?? '';
$filter_cond   = $filter_status ? "AND b.status = ?" : "";
$filter_params = $filter_status ? [$user_id, $filter_status] : [$user_id];

// Total count
$stmt_count = $pdo->prepare("
    SELECT COUNT(*) FROM bookings b
    WHERE b.user_id = ?
      AND b.status IN ('completed','cancelled','confirmed','reserved') $filter_cond
");
$stmt_count->execute($filter_params);
$total_rows  = $stmt_count->fetchColumn();
$total_pages = max(1, ceil($total_rows / $per_page));

// History rows
$params_paged = array_merge($filter_params, [$per_page, $offset]);
$stmt_hist = $pdo->prepare("
    SELECT
        b.id, b.booking_code, b.status AS booking_status,
        b.pax, (b.coaching_fee > 0) AS add_coaching, b.total_fee, b.booked_at,
        s.session_date AS slot_date, s.start_time, s.end_time,
        p.status AS pay_status, p.method AS pay_method,
        att.status AS attendance_status
    FROM bookings b
    JOIN schedules s ON b.schedule_id = s.id
    LEFT JOIN payments p ON p.booking_id = b.id
    LEFT JOIN attendance att ON att.booking_id = b.id AND att.user_id = ?
    WHERE b.user_id = ?
      AND b.status IN ('completed','cancelled','confirmed','reserved') $filter_cond
    GROUP BY b.id
    ORDER BY s.session_date DESC, s.start_time DESC
    LIMIT ? OFFSET ?
");
$hist_exec_params = array_merge([$user_id], $filter_params, [$per_page, $offset]);
$stmt_hist->execute($hist_exec_params);
$history = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stmt_stats = $pdo->prepare("
    SELECT
        COUNT(*)                              AS total_sessions,
        SUM(s.duration_hours)                AS total_hours,
        SUM(CASE WHEN b.status='completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN b.status='cancelled' THEN 1 ELSE 0 END) AS cancelled,
        SUM(CASE WHEN b.coaching_fee > 0 THEN 1 ELSE 0 END)   AS coached_sessions
    FROM bookings b JOIN schedules s ON b.schedule_id=s.id
    WHERE b.user_id=?
");
$stmt_stats->execute([$user_id]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>RDG Tennis - Session History</title>
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
        <button id="notif-btn" onclick="toggleNotif()" class="relative p-2 hover:bg-[#f6f3f2] transition-colors">
            <span class="material-symbols-outlined text-2xl">notifications</span>
            <?php if($unread_count>0): ?><span class="absolute top-2 right-2 w-2.5 h-2.5 bg-accent rounded-full border border-white"></span><?php endif; ?>
        </button>
        <div id="notif-dd" class="hidden absolute top-16 right-24 w-80 bg-white border-2 border-primary shadow-[4px_4px_0px_rgba(21,66,18,1)] z-50">
            <div class="px-4 py-2 border-b-2 border-primary flex justify-between bg-zinc-50">
                <span class="font-headline font-bold text-xs uppercase text-primary">Notifications</span>
                <button onclick="markAllRead()" class="text-[10px] font-bold text-primary hover:underline uppercase">Mark all read</button>
            </div>
            <div id="notif-items" class="divide-y max-h-60 overflow-y-auto"><div class="p-4 text-xs text-center text-zinc-400">Loading...</div></div>
        </div>
        <div class="h-8 w-px bg-[#c2c9bb]"></div>
        <span class="hidden md:inline font-bold text-xs uppercase text-primary"><?= htmlspecialchars($user_name) ?></span>
        <a href="/RDG/auth/logout.php" class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-accent hover:opacity-80 transition-opacity">
            <span class="material-symbols-outlined text-xl">logout</span>
        </a>
    </div>
</header>

<!-- Sidebar -->
<aside class="fixed left-0 top-20 h-[calc(100vh-80px)] w-64 bg-[#F8F9FA] border-r-2 border-primary flex flex-col py-4 z-40">
    <div class="px-6 py-4 mb-2 border-b border-zinc-200">
        <p class="font-headline font-bold uppercase text-sm text-primary">Player Portal</p>
        <p class="text-xs text-[#42493e] font-bold uppercase">RDG Athlete</p>
    </div>
    <nav class="flex flex-col gap-1">
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="book_slot.php"><span class="material-symbols-outlined">sports_tennis</span> Book Slot</a>
        <a class="bg-[#FFE500] text-primary border-l-4 border-primary px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm" href="history.php"><span class="material-symbols-outlined fill-icon">history</span> History</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="my_bookings.php"><span class="material-symbols-outlined">confirmation_number</span> My Bookings</a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="policies.php"><span class="material-symbols-outlined">policy</span> Policies</a>
    </nav>
</aside>

<!-- Main -->
<main class="ml-64 pt-20 min-h-screen p-8">
<div class="max-w-6xl mx-auto space-y-8">

    <!-- Header + Stats -->
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-6 border-b-4 border-primary pb-6">
        <div>
            <h1 class="font-headline font-black text-5xl text-primary uppercase tracking-tighter">Session History</h1>
            <p class="text-[#42493e] mt-1">Your complete court usage record &amp; invoice archive.</p>
        </div>
        <div class="flex gap-6 border-l-4 border-accent pl-6 py-2 bg-white px-6 shadow-[2px_2px_0px_rgba(21,66,18,0.3)]">
            <div class="text-center">
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Total Sessions</p>
                <p class="font-headline font-black text-2xl text-primary"><?= (int)($stats['total_sessions']??0) ?></p>
            </div>
            <div class="text-center">
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Court Hours</p>
                <p class="font-headline font-black text-2xl text-primary"><?= number_format((float)($stats['total_hours']??0),1) ?></p>
            </div>
            <div class="text-center">
                <p class="text-[10px] font-bold text-zinc-400 uppercase">Coached</p>
                <p class="font-headline font-black text-2xl text-secondary"><?= (int)($stats['coached_sessions']??0) ?></p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="flex flex-wrap gap-3 items-center">
        <div class="flex gap-1 border-2 border-primary overflow-hidden">
            <?php foreach([''=>'All','completed'=>'Completed','cancelled'=>'Cancelled','confirmed'=>'Confirmed'] as $val=>$label):
                $active = ($filter_status === $val); ?>
            <button type="submit" name="status" value="<?= $val ?>"
                class="px-4 py-2 font-headline font-bold text-xs uppercase transition-colors <?= $active ? 'bg-primary text-white' : 'bg-white text-primary hover:bg-zinc-100' ?>">
                <?= $label ?>
            </button>
            <?php endforeach; ?>
        </div>
    </form>

    <!-- Table -->
    <div class="bg-white border-2 border-primary overflow-hidden shadow-[4px_4px_0px_rgba(21,66,18,1)]">
        <div class="bg-primary px-6 py-4 flex justify-between items-center">
            <h2 class="font-headline font-bold text-white uppercase flex items-center gap-2">
                <span class="material-symbols-outlined">list_alt</span> Past Sessions
            </h2>
            <span class="text-xs text-primary-container font-bold uppercase">Showing <?= count($history) ?> of <?= $total_rows ?> records</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-[#f6f3f2] border-b-2 border-primary">
                        <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider">Date</th>
                        <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider">Time</th>
                        <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider">Service</th>
                        <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider">Pax</th>
                        <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider text-right">Fee</th>
                        <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider text-center">Attendance</th>
                        <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider text-center">Status</th>
                        <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider text-right">Invoice</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($history)): ?>
                <tr><td colspan="8" class="px-6 py-12 text-center text-zinc-400 text-sm">No booking history found.</td></tr>
                <?php endif; ?>
                <?php foreach($history as $i => $h):
                    $d = new DateTime($h['slot_date']);
                    $status_map = [
                        'completed' => ['bg-primary text-white','Completed'],
                        'cancelled' => ['bg-red-100 text-red-700','Cancelled'],
                        'confirmed' => ['bg-green-100 text-green-700','Confirmed'],
                        'reserved'  => ['bg-amber-100 text-amber-700','Reserved'],
                    ];
                    [$status_cls, $status_label] = $status_map[$h['booking_status']] ?? ['bg-zinc-100 text-zinc-500','Unknown'];
                    $att_map = ['present'=>'✓ Present','absent'=>'Absent','late'=>'Late','no_show'=>'No Show'];
                    $att_label = $att_map[$h['attendance_status'] ?? ''] ?? '—';
                    $att_cls   = $h['attendance_status']==='present' ? 'text-green-600 font-bold' : 'text-zinc-500';
                ?>
                <tr class="border-b border-[#c2c9bb] hover:bg-[#f6f3f2] transition-colors fade-in <?= $i%2===1?'bg-[#fcf9f8]':'' ?>">
                    <td class="px-5 py-4 font-bold text-primary"><?= $d->format('M d, Y') ?></td>
                    <td class="px-5 py-4 text-sm"><?= date('h:i A',strtotime($h['start_time'])) ?> – <?= date('h:i A',strtotime($h['end_time'])) ?></td>
                    <td class="px-5 py-4 text-sm"><?= $h['add_coaching'] ? 'Player Coaching' : 'Court Hire' ?></td>
                    <td class="px-5 py-4 text-sm"><?= $h['pax'] ?> pax</td>
                    <td class="px-5 py-4 text-right font-bold">&#8369;<?= number_format($h['total_fee'],2) ?></td>
                    <td class="px-5 py-4 text-center text-xs <?= $att_cls ?>"><?= $att_label ?></td>
                    <td class="px-5 py-4 text-center"><span class="px-2.5 py-1 text-[10px] font-bold uppercase <?= $status_cls ?>"><?= $status_label ?></span></td>
                    <td class="px-5 py-4 text-right">
                        <?php if($h['booking_status']==='completed' || $h['pay_status']==='paid'): ?>
                        <button onclick="downloadInvoice('<?= htmlspecialchars($h['booking_code']) ?>', '<?= $d->format('M d, Y') ?>', '<?= number_format($h['total_fee'],2) ?>')"
                            class="inline-flex items-center gap-1 bg-secondary text-white px-3 py-1.5 text-[10px] font-bold uppercase hover:bg-primary transition-colors">
                            <span class="material-symbols-outlined text-[14px]">download</span>Invoice
                        </button>
                        <?php else: ?>
                        <span class="text-zinc-300 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Pagination -->
        <div class="px-6 py-4 border-t border-primary flex justify-between items-center bg-white">
            <span class="text-[10px] font-bold text-zinc-400 uppercase">Page <?= $page ?> of <?= $total_pages ?></span>
            <div class="flex gap-1">
                <?php if($page > 1): ?>
                <a href="?page=<?= $page-1 ?>&status=<?= urlencode($filter_status) ?>"
                    class="w-8 h-8 border border-primary flex items-center justify-center hover:bg-primary hover:text-white transition-colors">
                    <span class="material-symbols-outlined text-sm">chevron_left</span></a>
                <?php endif; ?>
                <?php for($p=max(1,$page-2);$p<=min($total_pages,$page+2);$p++): ?>
                <a href="?page=<?= $p ?>&status=<?= urlencode($filter_status) ?>"
                    class="w-8 h-8 flex items-center justify-center font-bold text-sm border transition-colors
                    <?= $p===$page ? 'bg-primary text-white border-primary' : 'border-primary hover:bg-primary hover:text-white' ?>">
                    <?= $p ?>
                </a>
                <?php endfor; ?>
                <?php if($page < $total_pages): ?>
                <a href="?page=<?= $page+1 ?>&status=<?= urlencode($filter_status) ?>"
                    class="w-8 h-8 border border-primary flex items-center justify-center hover:bg-primary hover:text-white transition-colors">
                    <span class="material-symbols-outlined text-sm">chevron_right</span></a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Achievement cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <?php
        $achievements = [];
        if(($stats['total_sessions']??0) >= 10) $achievements[] = ['stars','Consistency Pro','10+ sessions completed — you&apos;re on a roll!'];
        if(($stats['coached_sessions']??0) >= 5)  $achievements[] = ['sports','Coaching Devotee','5+ coached sessions — dedicated to improvement!'];
        if(($stats['total_hours']??0) >= 20)       $achievements[] = ['timer','Court Champion','20+ court hours logged — elite dedication!'];
        foreach($achievements as $ach):
        ?>
        <div class="bg-white border-2 border-primary p-6 relative overflow-hidden shadow-[2px_2px_0px_rgba(21,66,18,0.4)]">
            <div class="absolute -right-3 -top-3 opacity-10"><span class="material-symbols-outlined text-8xl"><?= $ach[0] ?></span></div>
            <span class="material-symbols-outlined text-accent text-3xl fill-icon"><?= $ach[0] ?></span>
            <p class="font-headline font-black text-primary mt-2"><?= $ach[1] ?></p>
            <p class="text-xs text-[#42493e] mt-1"><?= $ach[2] ?></p>
        </div>
        <?php endforeach; ?>
    </div>

</div>
</main>

<!-- Invoice Mock Print Modal -->
<div id="invoice-modal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border-4 border-primary max-w-sm w-full shadow-[8px_8px_0px_rgba(21,66,18,1)]">
        <div class="bg-primary px-6 py-4 flex justify-between items-center">
            <div>
                <h3 class="font-headline font-black text-white uppercase text-sm">Official Receipt</h3>
                <p class="text-[10px] text-primary-container uppercase">RDG Tennis Court Booking</p>
            </div>
            <button onclick="closeInvoice()" class="text-white hover:text-accent"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="p-6 space-y-4">
            <div class="border border-primary p-4 space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-zinc-500 font-bold uppercase text-[10px]">Booking Ref</span><span id="inv-code" class="font-headline font-black text-primary"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500 font-bold uppercase text-[10px]">Date</span><span id="inv-date" class="font-bold"></span></div>
                <hr class="border-dashed border-zinc-300"/>
                <div class="flex justify-between items-end">
                    <span class="font-bold uppercase text-[10px] text-primary">Total Paid</span>
                    <span id="inv-amount" class="font-headline font-black text-2xl text-primary"></span>
                </div>
            </div>
            <p class="text-[10px] text-center text-zinc-400 italic">This is an official payment receipt issued by RDG Tennis Facility.</p>
            <button onclick="window.print()" class="w-full bg-primary text-white py-3 font-headline font-black uppercase text-sm hover:bg-accent hover:text-primary transition-colors flex items-center justify-center gap-2">
                <span class="material-symbols-outlined">print</span> Print Receipt
            </button>
        </div>
    </div>
</div>

<script>
function toggleNotif(){
    const dd=document.getElementById('notif-dd');dd.classList.toggle('hidden');
    if(!dd.classList.contains('hidden'))loadNotif();
}
function loadNotif(){
    const c=document.getElementById('notif-items');
    c.innerHTML='<div class="p-4 text-xs text-center text-zinc-400">Loading...</div>';
    fetch('../actions/get_notifications.php?action=get_unread').then(r=>r.json()).then(d=>{
        if(!d.success||!d.notifications.length){c.innerHTML='<div class="p-4 text-xs text-center text-zinc-400">No unread notifications.</div>';return;}
        c.innerHTML=d.notifications.map(n=>`<div class="p-4 hover:bg-zinc-50 flex justify-between gap-2">
            <div><p class="text-xs font-bold text-primary">${n.message}</p><span class="text-[9px] text-zinc-400 font-bold">${n.time_ago}</span></div>
            <button onclick="markRead(${n.id})" class="text-zinc-300 hover:text-primary"><span class="material-symbols-outlined text-sm">close</span></button>
        </div>`).join('');
    });
}
function markRead(id){const fd=new FormData();fd.append('notification_id',id);fetch('../actions/get_notifications.php?action=mark_read',{method:'POST',body:fd}).then(()=>loadNotif());}
function markAllRead(){const fd=new FormData();fd.append('notification_id','all');fetch('../actions/get_notifications.php?action=mark_read',{method:'POST',body:fd}).then(()=>loadNotif());}
document.addEventListener('click',e=>{
    if(!document.getElementById('notif-btn').contains(e.target)&&!document.getElementById('notif-dd').contains(e.target))
        document.getElementById('notif-dd').classList.add('hidden');
});

function downloadInvoice(code, date, amount){
    document.getElementById('inv-code').textContent=code;
    document.getElementById('inv-date').textContent=date;
    document.getElementById('inv-amount').innerHTML='&#8369;'+amount;
    document.getElementById('invoice-modal').classList.remove('hidden');
}
function closeInvoice(){document.getElementById('invoice-modal').classList.add('hidden');}
</script>
</body>
</html>
