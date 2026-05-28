<?php
// admin/cancellations.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_admin();
run_cron_simulator($pdo);

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'RDG ADMIN';

// Fetch pending cancellations
$stmt_pending = $pdo->prepare("
    SELECT b.id AS booking_id, b.booking_code, b.total_fee, b.pax, b.booked_at,
           u.full_name AS client_name, u.email AS client_email,
           s.session_date, s.start_time, s.end_time,
           p.method AS payment_method, p.status AS payment_status
      FROM bookings b
      JOIN users u ON b.user_id = u.id
      JOIN schedules s ON b.schedule_id = s.id
      LEFT JOIN payments p ON p.booking_id = b.id
     WHERE b.cancelled_reason = 'Pending Admin Approval'
     ORDER BY s.session_date ASC, s.start_time ASC
");
$stmt_pending->execute();
$pending_list = $stmt_pending->fetchAll(PDO::FETCH_ASSOC);

// Fetch processed cancellations
$stmt_processed = $pdo->prepare("
    SELECT b.id AS booking_id, b.booking_code, b.total_fee, b.pax, b.cancelled_reason, b.cancelled_at,
           u.full_name AS client_name, u.email AS client_email,
           s.session_date, s.start_time, s.end_time,
           p.method AS payment_method, p.status AS payment_status
      FROM bookings b
      JOIN users u ON b.user_id = u.id
      JOIN schedules s ON b.schedule_id = s.id
      LEFT JOIN payments p ON p.booking_id = b.id
     WHERE b.status = 'cancelled'
     ORDER BY b.cancelled_at DESC
     LIMIT 30
");
$stmt_processed->execute();
$processed_list = $stmt_processed->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>RDG Tennis - Cancellation Requests</title>
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
        <a class="bg-[#FFE500] text-primary border-l-4 border-primary px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm" href="cancellations.php"><span class="material-symbols-outlined fill-icon">cancel</span> Requests</a>
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
                <h1 class="font-headline font-black text-5xl text-primary uppercase tracking-tighter">Cancellation Review</h1>
                <p class="text-[#42493e]">Process player cancellation requests, approve waivers or issue session refunds.</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs font-black bg-white p-3 border-2 border-primary uppercase text-primary shadow-[2px_2px_0px_rgba(21,66,18,0.2)]">
                Pending Requests: <?= count($pending_list) ?>
            </div>
        </div>

        <!-- Search and Filter Controls Box -->
        <div class="bg-white border-2 border-primary shadow-[4px_4px_0px_rgba(21,66,18,1)] p-6 space-y-4">
            <div class="flex items-center gap-2 border-b border-zinc-200 pb-3 mb-4">
                <span class="material-symbols-outlined text-primary text-2xl fill-icon">tune</span>
                <h2 class="font-headline font-black text-lg text-primary uppercase">Filter & Search Requests</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <!-- Search Input -->
                <div class="md:col-span-2 space-y-1.5">
                    <label for="search-input" class="block text-[10px] font-black text-primary uppercase tracking-wider">Search Query</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-2.5 text-zinc-400 text-lg">search</span>
                        <input type="text" id="search-input" placeholder="Search by Ref Code (#BK-...), athlete name, or email..." 
                               class="w-full border-2 border-primary pl-9 pr-4 py-2 text-xs font-bold focus:ring-0 focus:border-accent bg-[#fcf9f8] hover:bg-white transition-colors duration-200">
                    </div>
                </div>
                <!-- Policy Filter -->
                <div class="space-y-1.5">
                    <label for="policy-filter" class="block text-[10px] font-black text-primary uppercase tracking-wider">Cancellation Policy</label>
                    <select id="policy-filter" class="w-full border-2 border-primary px-3 py-2.5 text-xs font-bold focus:ring-0 focus:border-accent bg-[#fcf9f8] hover:bg-white transition-colors duration-200">
                        <option value="all">ALL COMPLIANCE TYPES</option>
                        <option value="violation">⚠ POLICY VIOLATIONS (<48H)</option>
                        <option value="valid">✓ VALID REQUESTS (>=48H)</option>
                    </select>
                </div>
                <!-- Status Filter -->
                <div class="space-y-1.5">
                    <label for="status-filter" class="block text-[10px] font-black text-primary uppercase tracking-wider">Request Status</label>
                    <select id="status-filter" class="w-full border-2 border-primary px-3 py-2.5 text-xs font-bold focus:ring-0 focus:border-accent bg-[#fcf9f8] hover:bg-white transition-colors duration-200">
                        <option value="all">ALL STATUSES</option>
                        <option value="pending">PENDING REVIEW</option>
                        <option value="approved">APPROVED & SETTLED</option>
                        <option value="rejected">REJECTED / USER CANCELLED</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Pending Cancellations List -->
        <div class="bg-white border-2 border-primary shadow-[6px_6px_0px_rgba(21,66,18,1)] overflow-hidden">
            <div class="bg-primary px-6 py-4 flex justify-between items-center border-b-2 border-primary text-white">
                <h2 class="font-headline font-bold uppercase flex items-center gap-2">
                    <span class="material-symbols-outlined">pending_actions</span> Pending Cancellation Requests
                </h2>
                <span class="text-xs text-zinc-300 font-bold uppercase"><?= count($pending_list) ?> Requests Pending Approval</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-[#f6f3f2] border-b-2 border-primary">
                            <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider border-r border-zinc-200">Ref Code</th>
                            <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider border-r border-zinc-200">Athlete Details</th>
                            <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider border-r border-zinc-200">Session details</th>
                            <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider border-r border-zinc-200 text-center">Status / Paid</th>
                            <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider text-right border-r border-zinc-200">Total Fee</th>
                            <th class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider text-center">Operations</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#c2c9bb]">
                        <?php if (empty($pending_list)): ?>
                            <tr><td colspan="6" class="px-6 py-12 text-center text-zinc-400 text-sm">No pending cancellation requests found.</td></tr>
                        <?php endif; ?>
                        <tr id="pending-no-results" class="hidden"><td colspan="6" class="px-6 py-12 text-center text-zinc-400 text-sm">No matching pending requests found.</td></tr>
                        <?php foreach ($pending_list as $i => $b):
                            $sch_d = new DateTime($b['session_date']);
                            $paid_cls = ($b['payment_status'] === 'paid') ? 'bg-primary text-white' : 'bg-red-100 text-red-700 border border-red-400';
                            
                            $slot_ts = strtotime($b['session_date'] . ' ' . $b['start_time']);
                            $hours_to_go = round(($slot_ts - time()) / 3600, 1);
                            $policy_violation = ($hours_to_go < 48);
                        ?>
                            <tr class="cancellation-row hover:bg-[#f6f3f2] transition-colors fade-in <?= $i%2===1?'bg-[#fcf9f8]':'' ?>"
                                data-search="<?= htmlspecialchars(strtolower($b['booking_code'] . ' ' . $b['client_name'] . ' ' . $b['client_email'])) ?>"
                                data-policy="<?= $policy_violation ? 'violation' : 'valid' ?>"
                                data-status="pending">
                                <td class="px-5 py-4 font-black text-primary border-r border-zinc-200">#<?= htmlspecialchars($b['booking_code']) ?></td>
                                <td class="px-5 py-4 text-xs border-r border-zinc-200">
                                    <p class="font-bold text-primary"><?= htmlspecialchars($b['client_name']) ?></p>
                                    <p class="text-[10px] text-zinc-400 font-bold"><?= htmlspecialchars($b['client_email']) ?></p>
                                </td>
                                <td class="px-5 py-4 text-xs border-r border-zinc-200">
                                    <p class="font-bold"><?= $sch_d->format('M d, Y') ?> &bull; <?= date('h:i A', strtotime($b['start_time'])) ?></p>
                                    <p class="text-[10px] uppercase font-bold mt-1">
                                        <?php if ($policy_violation): ?>
                                            <span class="text-error font-black">⚠ Policy Violation (<?= $hours_to_go ?>h to slot)</span>
                                        <?php else: ?>
                                            <span class="text-primary font-black">✓ Valid Request (<?= $hours_to_go ?>h to slot)</span>
                                        <?php endif; ?>
                                    </p>
                                </td>
                                <td class="px-5 py-4 border-r border-zinc-200 text-center">
                                    <span class="px-2 py-0.5 text-[9px] uppercase tracking-wider font-bold <?= $paid_cls ?>"><?= strtoupper($b['payment_status']) ?></span>
                                    <p class="text-[8px] text-zinc-400 font-bold mt-1 uppercase"><?= str_replace('_', ' ', $b['payment_method']) ?></p>
                                </td>
                                <td class="px-5 py-4 text-right font-black text-primary border-r border-zinc-200">&#8369;<?= number_format($b['total_fee'], 2) ?></td>
                                <td class="px-5 py-4 text-center flex justify-center gap-2">
                                    <button onclick="openReviewModal(<?= $b['booking_id'] ?>, '<?= htmlspecialchars($b['booking_code']) ?>', 'approve', <?= $policy_violation ? 'true' : 'false' ?>)"
                                            class="bg-[#4a8f3c] text-white px-3 py-1.5 text-[9px] font-black uppercase hover:opacity-90 border border-[#4a8f3c] shadow-[1px_1px_0px_rgba(0,0,0,0.15)] flex items-center gap-1">
                                        <span class="material-symbols-outlined text-xs">done</span> Approve
                                    </button>
                                    <button onclick="openReviewModal(<?= $b['booking_id'] ?>, '<?= htmlspecialchars($b['booking_code']) ?>', 'reject', <?= $policy_violation ? 'true' : 'false' ?>)"
                                            class="bg-red-400 text-white px-3 py-1.5 text-[9px] font-black uppercase hover:opacity-90 border border-red-500 shadow-[1px_1px_0px_rgba(0,0,0,0.15)] flex items-center gap-1">
                                        <span class="material-symbols-outlined text-xs">close</span> Reject
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Historical Processed List -->
        <div class="bg-white border-2 border-primary shadow-[4px_4px_0px_rgba(21,66,18,1)] overflow-hidden">
            <div class="bg-[#f6f3f2] px-6 py-4 flex justify-between items-center border-b-2 border-primary text-primary">
                <h3 class="font-headline font-bold uppercase flex items-center gap-2">
                    <span class="material-symbols-outlined">history</span> Cancellation Log Archive
                </h3>
                <span class="text-xs font-bold uppercase">Last 30 records</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-zinc-50 border-b border-zinc-200">
                            <th class="px-5 py-3 font-bold text-zinc-500 uppercase text-[10px] tracking-wider border-r border-zinc-200">Ref Code</th>
                            <th class="px-5 py-3 font-bold text-zinc-500 uppercase text-[10px] tracking-wider border-r border-zinc-200">Athlete</th>
                            <th class="px-5 py-3 font-bold text-zinc-500 uppercase text-[10px] tracking-wider border-r border-zinc-200">Slot Scheduled</th>
                            <th class="px-5 py-3 font-bold text-zinc-500 uppercase text-[10px] tracking-wider border-r border-zinc-200">Decision Outcome / Reason</th>
                            <th class="px-5 py-3 font-bold text-zinc-500 uppercase text-[10px] tracking-wider text-right border-r border-zinc-200">Refunded Fee</th>
                            <th class="px-5 py-3 font-bold text-zinc-500 uppercase text-[10px] tracking-wider text-center">Settled At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 text-xs">
                        <?php if (empty($processed_list)): ?>
                            <tr><td colspan="6" class="px-6 py-8 text-center text-zinc-400 italic">No historical cancellations logged.</td></tr>
                        <?php endif; ?>
                        <tr id="archive-no-results" class="hidden"><td colspan="6" class="px-6 py-12 text-center text-zinc-400 text-sm">No matching archived requests found.</td></tr>
                        <?php foreach ($processed_list as $i => $b):
                            $sch_d = new DateTime($b['session_date']);
                            $settled_d = new DateTime($b['cancelled_at']);
                            $is_approved = stripos($b['cancelled_reason'] ?? '', 'Approved') !== false;
                            $outcome_cls = $is_approved ? 'bg-green-100 text-green-700 border-green-500' : 'bg-red-100 text-red-700 border-red-500';
                            
                            $slot_ts = strtotime($b['session_date'] . ' ' . $b['start_time']);
                            $cancelled_ts = strtotime($b['cancelled_at']);
                            $hours_to_go = round(($slot_ts - $cancelled_ts) / 3600, 1);
                            $policy_violation = ($hours_to_go < 48);
                        ?>
                            <tr class="cancellation-row hover:bg-zinc-50 transition-colors <?= $i%2===1?'bg-[#fcf9f8]':'' ?>"
                                data-search="<?= htmlspecialchars(strtolower($b['booking_code'] . ' ' . $b['client_name'] . ' ' . $b['client_email'])) ?>"
                                data-policy="<?= $policy_violation ? 'violation' : 'valid' ?>"
                                data-status="<?= $is_approved ? 'approved' : 'rejected' ?>">
                                <td class="px-5 py-3.5 font-bold text-zinc-600 border-r border-zinc-200">#<?= htmlspecialchars($b['booking_code']) ?></td>
                                <td class="px-5 py-3.5 border-r border-zinc-200"><?= htmlspecialchars($b['client_name']) ?></td>
                                <td class="px-5 py-3.5 border-r border-zinc-200">
                                    <p class="font-bold"><?= $sch_d->format('M d, Y') ?> &bull; <?= date('h:i A', strtotime($b['start_time'])) ?></p>
                                    <p class="text-[9px] uppercase font-bold mt-1">
                                        <?php if ($policy_violation): ?>
                                            <span class="text-error font-black">⚠ Policy Violation (<?= $hours_to_go ?>h to slot)</span>
                                        <?php else: ?>
                                            <span class="text-primary font-black">✓ Valid Request (<?= $hours_to_go ?>h to slot)</span>
                                        <?php endif; ?>
                                    </p>
                                </td>
                                <td class="px-5 py-3.5 border-r border-zinc-200 space-y-1">
                                    <span class="px-2 py-0.5 text-[9px] uppercase tracking-wider font-bold border <?= $outcome_cls ?>"><?= $is_approved ? 'Approved' : 'Rejected / Cancelled' ?></span>
                                    <p class="text-[10px] text-zinc-500 font-medium italic mt-1"><?= htmlspecialchars($b['cancelled_reason'] ?? 'Staff action') ?></p>
                                </td>
                                <td class="px-5 py-3.5 text-right font-bold text-zinc-500 border-r border-zinc-200">&#8369;<?= number_format($b['total_fee'], 2) ?></td>
                                <td class="px-5 py-3.5 text-center text-zinc-400 font-bold"><?= $settled_d->format('M d, h:i A') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Review Modal -->
<div id="review-modal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border-4 border-primary max-w-sm w-full shadow-[8px_8px_0px_rgba(21,66,18,1)] fade-in">
        <div class="bg-primary px-6 py-4 flex justify-between items-center">
            <div>
                <h3 class="font-headline font-black text-white uppercase text-sm" id="modal-title">Review Cancellation Request</h3>
                <p class="text-[10px] text-zinc-300 uppercase font-bold" id="modal-slot-lbl"></p>
            </div>
            <button onclick="closeReviewModal()" class="text-white hover:text-accent"><span class="material-symbols-outlined">close</span></button>
        </div>
        <form onsubmit="submitReview(event)" class="p-6 space-y-4">
            <input type="hidden" id="modal-booking-id">
            <input type="hidden" id="modal-action">
            
            <div id="policy-alert" class="hidden p-3 bg-red-100 text-red-700 border-2 border-red-500 text-[10px] font-bold uppercase leading-relaxed">
                ⚠ WARNING: This request violates the 48-Hour Cancellation Rule. Approving this will waive the fee and refund the athlete.
            </div>

            <div class="space-y-1">
                <label class="block text-[10px] font-bold text-primary uppercase">Decision Notes / Reason</label>
                <input type="text" id="decision-notes" required placeholder="Reason for approval or rejection..."
                       class="w-full border-2 border-primary p-2 text-xs font-bold focus:ring-0 focus:border-accent">
            </div>

            <div class="grid grid-cols-2 gap-4 pt-2">
                <button type="button" onclick="closeReviewModal()" class="w-full border-2 border-primary text-primary font-headline font-black uppercase text-xs py-3 hover:bg-zinc-100 transition-colors">
                    Go Back
                </button>
                <button type="submit" id="modal-submit-btn" class="w-full bg-primary text-white border-2 border-primary font-headline font-black uppercase text-xs py-3 hover:bg-accent hover:text-primary transition-colors">
                    Confirm Action
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('search-input');
    const policyFilter = document.getElementById('policy-filter');
    const statusFilter = document.getElementById('status-filter');
    
    const rows = document.querySelectorAll('.cancellation-row');
    const pendingNoResults = document.getElementById('pending-no-results');
    const archiveNoResults = document.getElementById('archive-no-results');
    
    function filterCancellations() {
        const query = searchInput.value.toLowerCase().trim();
        const policy = policyFilter.value;
        const status = statusFilter.value;
        
        let visiblePendingCount = 0;
        let visibleArchiveCount = 0;
        
        rows.forEach(row => {
            const rowSearch = row.getAttribute('data-search') || '';
            const rowPolicy = row.getAttribute('data-policy') || '';
            const rowStatus = row.getAttribute('data-status') || '';
            
            const matchesSearch = !query || rowSearch.includes(query);
            const matchesPolicy = policy === 'all' || rowPolicy === policy;
            const matchesStatus = status === 'all' || rowStatus === status;
            
            if (matchesSearch && matchesPolicy && matchesStatus) {
                row.classList.remove('hidden');
                if (rowStatus === 'pending') {
                    visiblePendingCount++;
                } else {
                    visibleArchiveCount++;
                }
            } else {
                row.classList.add('hidden');
            }
        });
        
        if (pendingNoResults) {
            const totalPendingRows = document.querySelectorAll('.cancellation-row[data-status="pending"]').length;
            if (totalPendingRows > 0) {
                if (visiblePendingCount === 0) {
                    pendingNoResults.classList.remove('hidden');
                } else {
                    pendingNoResults.classList.add('hidden');
                }
            }
        }
        
        if (archiveNoResults) {
            const totalArchiveRows = document.querySelectorAll('.cancellation-row[data-status="approved"], .cancellation-row[data-status="rejected"]').length;
            if (totalArchiveRows > 0) {
                if (visibleArchiveCount === 0) {
                    archiveNoResults.classList.remove('hidden');
                } else {
                    archiveNoResults.classList.add('hidden');
                }
            }
        }
    }
    
    searchInput.addEventListener('input', filterCancellations);
    policyFilter.addEventListener('change', filterCancellations);
    statusFilter.addEventListener('change', filterCancellations);
});

function openReviewModal(bookingId, code, action, isViolation) {
    document.getElementById('modal-booking-id').value = bookingId;
    document.getElementById('modal-action').value = action;
    document.getElementById('modal-slot-lbl').textContent = `Booking: #${code}`;
    document.getElementById('decision-notes').value = '';
    
    const alertBox = document.getElementById('policy-alert');
    const submitBtn = document.getElementById('modal-submit-btn');
    
    if (action === 'approve') {
        document.getElementById('modal-title').textContent = 'Approve Cancellation';
        submitBtn.className = 'w-full bg-[#4a8f3c] text-white border-2 border-[#4a8f3c] font-headline font-black uppercase text-xs py-3 hover:bg-primary transition-colors';
        submitBtn.textContent = 'Approve Cancellation';
        if (isViolation) {
            alertBox.classList.remove('hidden');
        } else {
            alertBox.classList.add('hidden');
        }
    } else {
        document.getElementById('modal-title').textContent = 'Reject Cancellation';
        submitBtn.className = 'w-full bg-red-400 text-white border-2 border-red-500 font-headline font-black uppercase text-xs py-3 hover:bg-red-500 transition-colors';
        submitBtn.textContent = 'Reject Cancellation';
        alertBox.classList.add('hidden');
    }
    
    document.getElementById('review-modal').classList.remove('hidden');
}

function closeReviewModal() {
    document.getElementById('review-modal').classList.add('hidden');
}

function submitReview(e) {
    e.preventDefault();
    const btn = document.getElementById('modal-submit-btn');
    btn.disabled = true;
    btn.textContent = 'PROCESSING...';
    
    const bookingId = document.getElementById('modal-booking-id').value;
    const reviewAction = document.getElementById('modal-action').value;
    const reason = document.getElementById('decision-notes').value;
    
    const actionEndpoint = reviewAction === 'approve' ? 'approve_cancellation' : 'reject_cancellation';
    
    const fd = new FormData();
    fd.append('action', actionEndpoint);
    fd.append('booking_id', bookingId);
    fd.append('reason', reason);
    
    fetch('../actions/admin_booking_status.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        alert(d.message);
        if(d.success) {
            location.reload();
        } else {
            btn.disabled = false;
            btn.textContent = reviewAction === 'approve' ? 'Approve Cancellation' : 'Reject Cancellation';
        }
    })
    .catch(() => {
        alert('An error occurred during submission.');
        btn.disabled = false;
        btn.textContent = reviewAction === 'approve' ? 'Approve Cancellation' : 'Reject Cancellation';
    });
}
</script>
</body>
</html>
