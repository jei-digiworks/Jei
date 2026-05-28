<?php
// client/my_bookings.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

require_client();
run_cron_simulator($pdo);

$user_id  = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];

// Reconcile any pending PayMongo payments for the logged-in user dynamically on load
try {
    $stmt_pending = $pdo->prepare("
        SELECT b.id 
          FROM bookings b
          JOIN payments p ON p.booking_id = b.id
         WHERE b.user_id = ? 
           AND b.status = 'reserved' 
           AND p.status = 'unpaid' 
           AND p.paymongo_intent_id IS NOT NULL 
           AND p.paymongo_intent_id != ''
    ");
    $stmt_pending->execute([$user_id]);
    $pending_reconciliations = $stmt_pending->fetchAll(PDO::FETCH_COLUMN);
    
    $reconciled_any = false;
    foreach ($pending_reconciliations as $bid) {
        if (reconcile_paymongo_payment($pdo, $bid)) {
            $reconciled_any = true;
        }
    }
    
    if ($reconciled_any) {
        header("Location: my_bookings.php?payment=success");
        exit;
    }
} catch (Exception $e) {
    error_log("Dynamic reconciliation failed: " . $e->getMessage());
}

// Fetch unread notification count
$stmt_notif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt_notif->execute([$user_id]);
$unread_count = $stmt_notif->fetchColumn();

// Fetch active / upcoming bookings for this user
$stmt_bookings = $pdo->prepare("
    SELECT
        b.id,
        b.booking_code,
        b.status        AS booking_status,
        b.pax,
        (b.coaching_fee > 0) AS add_coaching,
        b.total_fee,
        b.booked_at,
        b.cancelled_reason,
        s.session_date  AS slot_date,
        s.start_time,
        s.end_time,
        s.status        AS slot_status,
        p.status        AS payment_status,
        p.method        AS payment_method,
        p.due_date      AS payment_due,
        p.id            AS payment_id
    FROM bookings b
    JOIN schedules s ON b.schedule_id = s.id
    LEFT JOIN payments p ON p.booking_id = b.id
    WHERE b.user_id = ?
      AND b.status IN ('reserved','confirmed')
    ORDER BY s.session_date ASC, s.start_time ASC
");
$stmt_bookings->execute([$user_id]);
$bookings = $stmt_bookings->fetchAll(PDO::FETCH_ASSOC);

// Cancellation eligibility: must be > 48h before slot
foreach ($bookings as &$bk) {
    $slot_datetime = strtotime($bk['slot_date'] . ' ' . $bk['start_time']);
    $bk['can_cancel'] = ($slot_datetime - time()) > (48 * 3600);
    $bk['hours_to_slot'] = round(($slot_datetime - time()) / 3600, 1);
}
unset($bk);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>RDG Tennis - My Bookings</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700;900&family=Lexend:wght@300;400;500;600;700&family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<style>
    .court-grid { background-image: linear-gradient(#e5e7eb 1px, transparent 1px), linear-gradient(90deg, #e5e7eb 1px, transparent 1px); background-size: 64px 64px; }
    .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
    .btn-fill { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    .animate-in { animation: fadeIn 0.3s ease forwards; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .animate-spin { animation: spin 0.8s linear infinite; }
</style>
<script>
tailwind.config = {
    darkMode: "class",
    theme: { extend: {
        colors: { "primary":"#154212","primary-container":"#2d5a27","accent":"#FFB800","on-surface":"#1c1b1b","on-surface-variant":"#42493e","surface-container-low":"#f6f3f2","surface-container":"#f0eded","outline-variant":"#c2c9bb","secondary":"#7d5700","error":"#ba1a1a" },
        borderRadius: { DEFAULT:"0px", full:"9999px" },
        fontFamily: { headline:["Space Grotesk"], body:["Lexend"] },
        spacing: { md:"24px", sm:"16px", lg:"48px", gutter:"24px", xs:"8px" }
    }}
}
</script>
</head>
<body class="bg-[#fcf9f8] text-[#1c1b1b] font-body court-grid min-h-screen">

<!-- ─── TOP APP BAR ─── -->
<header class="fixed top-0 left-0 right-0 z-50 bg-white flex justify-between items-center px-6 h-20 border-b-2 border-primary">
    <div class="flex items-center gap-3">
        <img alt="RDG Logo" class="h-10 w-auto" src="/RDG/RDG Logo.jpg"/>
    </div>
    <div class="flex items-center gap-4">
        <!-- Notification Bell -->
        <button id="notif-btn" onclick="toggleNotifDropdown()" class="relative p-2 hover:bg-[#f6f3f2] transition-colors">
            <span class="material-symbols-outlined text-2xl text-[#1c1b1b]">notifications</span>
            <?php if ($unread_count > 0): ?>
            <span id="notif-badge" class="absolute top-2 right-2 w-2.5 h-2.5 bg-accent rounded-full border border-white"></span>
            <?php endif; ?>
        </button>
        <!-- Notifications Dropdown -->
        <div id="notif-dropdown" class="hidden absolute top-16 right-24 w-80 bg-white border-2 border-primary shadow-[4px_4px_0px_rgba(21,66,18,1)] z-50">
            <div class="px-4 py-2 border-b-2 border-primary flex justify-between items-center bg-zinc-50">
                <span class="font-headline font-bold text-xs uppercase text-primary">Notifications</span>
                <button onclick="markAllNotificationsRead()" class="text-[10px] font-bold text-primary hover:underline uppercase">Mark all read</button>
            </div>
            <div id="notif-items" class="divide-y divide-zinc-100 max-h-60 overflow-y-auto">
                <div class="p-4 text-center text-xs text-zinc-400">Loading...</div>
            </div>
        </div>
        <div class="h-8 w-px bg-[#c2c9bb] mx-1"></div>
        <span class="hidden md:inline font-bold text-xs uppercase tracking-wider text-primary"><?= htmlspecialchars($user_name) ?></span>
        <a href="/RDG/auth/logout.php" class="flex items-center justify-center w-10 h-10 rounded-full bg-primary text-accent hover:opacity-80 transition-opacity" title="Logout">
            <span class="material-symbols-outlined text-xl">logout</span>
        </a>
    </div>
</header>

<!-- ─── SIDE NAV ─── -->
<aside class="fixed left-0 top-20 h-[calc(100vh-80px)] w-64 bg-[#F8F9FA] border-r-2 border-primary flex flex-col py-4 z-40">
    <div class="px-6 py-4 mb-2 border-b border-zinc-200">
        <p class="font-headline font-bold uppercase text-sm text-primary">Player Portal</p>
        <p class="text-xs text-[#42493e] font-bold uppercase mt-0.5">RDG Athlete</p>
    </div>
    <nav class="flex flex-col gap-1">
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="book_slot.php">
            <span class="material-symbols-outlined">sports_tennis</span> Book Slot
        </a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="history.php">
            <span class="material-symbols-outlined">history</span> History
        </a>
        <a class="bg-[#FFE500] text-primary border-l-4 border-primary px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm" href="my_bookings.php">
            <span class="material-symbols-outlined btn-fill">confirmation_number</span> My Bookings
        </a>
        <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all" href="policies.php">
            <span class="material-symbols-outlined">policy</span> Policies
        </a>
    </nav>
</aside>

<!-- ─── MAIN ─── -->
<main class="ml-64 pt-20 min-h-screen p-gutter">
    <div class="max-w-5xl mx-auto space-y-8">

        <!-- Page Header -->
        <div class="flex items-end justify-between border-b-4 border-primary pb-4 bg-white/80 backdrop-blur-sm p-6 sticky top-20 z-30">
            <div>
                <h1 class="font-headline text-5xl font-black uppercase text-primary tracking-tighter">My Bookings</h1>
                <p class="text-[#42493e] mt-1">Manage your upcoming reservations, settle payments, or cancel sessions.</p>
            </div>
            <a href="book_slot.php" class="hidden md:flex items-center gap-2 bg-accent text-primary border-2 border-primary px-5 py-2.5 font-headline font-black uppercase text-sm hover:bg-primary hover:text-white transition-all">
                <span class="material-symbols-outlined text-sm">add</span> New Booking
            </a>
        </div>

        <?php if (empty($bookings)): ?>
        <!-- Empty State -->
        <div class="bg-white border-2 border-primary p-16 text-center shadow-[4px_4px_0px_rgba(21,66,18,1)]">
            <span class="material-symbols-outlined text-6xl text-zinc-300" style="font-variation-settings:'FILL' 1;">sports_tennis</span>
            <h2 class="font-headline font-black text-2xl text-primary uppercase mt-4">No Active Bookings</h2>
            <p class="text-[#42493e] mt-2 max-w-md mx-auto">You have no upcoming court reservations. Reserve your next training session now!</p>
            <a href="book_slot.php" class="inline-block mt-6 bg-primary text-white px-8 py-3 font-headline font-black uppercase text-sm hover:bg-accent hover:text-primary transition-colors">
                Book a Court &rarr;
            </a>
        </div>
        <?php else: ?>

        <!-- Summary pills -->
        <div class="flex flex-wrap gap-3">
            <span class="inline-flex items-center gap-1.5 bg-primary text-white px-4 py-1.5 text-xs font-bold uppercase">
                <span class="material-symbols-outlined text-sm">event</span>
                <?= count($bookings) ?> Active Booking<?= count($bookings) !== 1 ? 's' : '' ?>
            </span>
            <?php
            $pending_pay = array_filter($bookings, fn($b) => $b['payment_status'] === 'unpaid');
            if (count($pending_pay) > 0):
            ?>
            <span class="inline-flex items-center gap-1.5 bg-accent text-primary px-4 py-1.5 text-xs font-bold uppercase">
                <span class="material-symbols-outlined text-sm">warning</span>
                <?= count($pending_pay) ?> Payment<?= count($pending_pay) !== 1 ? 's' : '' ?> Due
            </span>
            <?php endif; ?>
        </div>

        <!-- Booking Cards -->
        <div class="space-y-4" id="bookings-container">
        <?php foreach ($bookings as $bk):
            $is_paid      = ($bk['payment_status'] === 'paid' || $bk['booking_status'] === 'confirmed');
            $is_pending   = ($bk['payment_status'] === 'unpaid');
            $is_cancellation_pending = ($bk['cancelled_reason'] === 'Pending Admin Approval');
            
            $slot_date_obj = new DateTime($bk['slot_date']);
            $slot_day      = $slot_date_obj->format('M d');
            $slot_weekday  = strtoupper($slot_date_obj->format('D'));
            $start_fmt     = date('h:i A', strtotime($bk['start_time']));
            $end_fmt       = date('h:i A', strtotime($bk['end_time']));
            
            if ($is_cancellation_pending) {
                $bar_color     = 'bg-amber-500';
                $status_label  = 'Cancellation Pending';
                $status_class  = 'bg-amber-500 text-white';
            } else {
                $bar_color     = $is_paid ? 'bg-primary' : 'bg-accent';
                $status_label  = $is_paid ? 'Confirmed' : 'Pending Payment';
                $status_class  = $is_paid ? 'bg-primary text-white' : 'bg-accent text-primary';
            }
            $hours_label   = $bk['hours_to_slot'] < 48 ? '<span class="text-red-600">⚠ ' . $bk['hours_to_slot'] . 'h away</span>' : $bk['hours_to_slot'] . 'h away';
        ?>
        <div id="card-<?= $bk['id'] ?>" class="bg-white border-2 border-primary flex group hover:border-accent transition-all duration-200 animate-in shadow-[2px_2px_0px_rgba(21,66,18,0.5)]">
            <!-- Status bar -->
            <div class="w-1.5 <?= $bar_color ?> flex-shrink-0"></div>
            <!-- Date column -->
            <div class="flex-shrink-0 flex flex-col justify-center items-center w-24 border-r border-zinc-200 p-4 bg-[#f6f3f2]">
                <span class="font-headline font-black text-primary text-lg leading-none"><?= $slot_day ?></span>
                <span class="font-bold text-[10px] text-zinc-500 uppercase mt-1"><?= $slot_weekday ?></span>
                <div class="mt-2 flex items-center gap-1 text-primary">
                    <span class="material-symbols-outlined text-xs">schedule</span>
                    <span class="font-bold text-[10px]"><?= $start_fmt ?></span>
                </div>
            </div>
            <!-- Details -->
            <div class="flex-1 p-5 flex flex-col md:flex-row md:items-center gap-4">
                <div class="flex-1 grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                        <p class="text-[10px] font-bold text-zinc-400 uppercase">Booking Code</p>
                        <p class="font-headline font-black text-primary text-sm"><?= htmlspecialchars($bk['booking_code']) ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-zinc-400 uppercase">Time</p>
                        <p class="font-bold text-primary text-sm"><?= $start_fmt ?> – <?= $end_fmt ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-zinc-400 uppercase">Players</p>
                        <p class="font-bold text-primary text-sm"><?= $bk['pax'] ?> Pax<?= $bk['add_coaching'] ? ' + Coach' : '' ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-zinc-400 uppercase">Total Fee</p>
                        <p class="font-headline font-black text-primary text-sm">&#8369;<?= number_format($bk['total_fee'], 2) ?></p>
                    </div>
                </div>
                <!-- Status + Actions -->
                <div class="flex-shrink-0 flex flex-col items-start md:items-end gap-3">
                    <span class="<?= $status_class ?> px-3 py-1 text-[10px] font-bold uppercase"><?= $status_label ?></span>
                    <?php if ($is_pending && !$is_cancellation_pending): ?>
                    <p class="text-[10px] text-zinc-500"><?= $hours_label ?></p>
                    <?php if ($bk['payment_due']): ?>
                    <p class="text-[10px] text-red-600 font-bold">Pay by: <?= date('M d, h:i A', strtotime($bk['payment_due'])) ?></p>
                    <?php endif; ?>
                    <?php endif; ?>
                    <div class="flex gap-2 flex-wrap">
                        <?php if ($is_cancellation_pending): ?>
                        <span class="text-[11px] font-bold text-amber-600 uppercase flex items-center gap-1 bg-amber-50 px-2.5 py-1 border border-amber-200">
                            <span class="material-symbols-outlined text-sm animate-pulse">pending</span> Pending Review
                        </span>
                        <?php else: ?>
                            <?php if ($is_pending): ?>
                            <button onclick="openPayModal(<?= $bk['id'] ?>, '<?= htmlspecialchars($bk['booking_code']) ?>', <?= $bk['total_fee'] ?>)"
                                class="flex items-center gap-1.5 bg-accent text-primary border-2 border-primary px-3 py-1.5 font-headline font-black uppercase text-[11px] hover:bg-primary hover:text-white transition-all">
                                <span class="material-symbols-outlined text-sm">payments</span> Pay Now
                            </button>
                            <?php endif; ?>
                            <?php if ($bk['can_cancel']): ?>
                            <button onclick="confirmCancel(<?= $bk['id'] ?>, '<?= htmlspecialchars($bk['booking_code']) ?>')"
                                class="flex items-center gap-1.5 bg-white text-primary border-2 border-primary px-3 py-1.5 font-headline font-bold uppercase text-[11px] hover:bg-red-50 hover:border-red-500 hover:text-red-600 transition-all">
                                <span class="material-symbols-outlined text-sm">cancel</span> Cancel
                            </button>
                            <?php else: ?>
                            <span class="flex items-center gap-1 text-[10px] text-zinc-400 font-bold uppercase">
                                <span class="material-symbols-outlined text-xs">lock</span> Cancel window closed
                            </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</main>

<!-- ─── PAY NOW MODAL (PayMongo Mock) ─── -->
<div id="pay-modal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border-4 border-primary max-w-sm w-full p-6 shadow-[8px_8px_0px_rgba(21,66,18,1)] flex flex-col space-y-5">
        <div class="flex items-center gap-3 border-b-2 border-primary pb-3">
            <span class="material-symbols-outlined text-primary text-3xl">credit_card</span>
            <div>
                <h3 class="font-headline font-bold text-sm uppercase text-primary leading-none">PayMongo Checkout</h3>
                <span class="text-[9px] text-zinc-500 font-bold uppercase">Booking: <span id="modal-booking-code">—</span></span>
            </div>
        </div>
        <div class="bg-zinc-50 p-3 border border-primary text-center">
            <span class="text-[10px] font-bold text-zinc-500 uppercase">Amount Due</span>
            <p id="modal-amount" class="font-headline font-black text-2xl text-primary">&#8369;0.00</p>
        </div>
        <div class="space-y-3">
            <div>
                <label class="block font-bold uppercase text-[9px] text-primary mb-1">Card Number</label>
                <input id="pay-card-num" type="text" placeholder="4111 •••• •••• 1111" maxlength="19"
                    class="w-full border-2 border-primary p-2 text-sm tracking-widest focus:outline-none focus:border-accent"/>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold uppercase text-[9px] text-primary mb-1">Expiry</label>
                    <input id="pay-expiry" type="text" placeholder="MM/YY" maxlength="5"
                        class="w-full border-2 border-primary p-2 text-sm text-center focus:outline-none focus:border-accent"/>
                </div>
                <div>
                    <label class="block font-bold uppercase text-[9px] text-primary mb-1">CVV</label>
                    <input id="pay-cvv" type="password" placeholder="•••" maxlength="3"
                        class="w-full border-2 border-primary p-2 text-sm text-center focus:outline-none focus:border-accent"/>
                </div>
            </div>
        </div>
        <div id="pay-error" class="hidden text-[10px] text-red-600 font-bold uppercase"></div>
        <div class="grid grid-cols-2 gap-3">
            <button onclick="closePayModal()" class="py-2.5 border-2 border-primary font-bold text-xs uppercase hover:bg-zinc-50">Cancel</button>
            <button id="pay-btn" onclick="processPayment()"
                class="py-2.5 bg-primary text-white border-2 border-primary font-bold text-xs uppercase flex items-center justify-center gap-2 hover:bg-accent hover:text-primary transition-all">
                Pay Now <span class="material-symbols-outlined text-sm">lock</span>
            </button>
        </div>
    </div>
</div>

<!-- ─── CANCEL CONFIRM MODAL ─── -->
<div id="cancel-modal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white border-4 border-primary max-w-sm w-full p-6 shadow-[8px_8px_0px_rgba(21,66,18,1)] text-center space-y-5">
        <span class="material-symbols-outlined text-5xl text-red-600" style="font-variation-settings:'FILL' 1;">warning</span>
        <div>
            <h3 class="font-headline font-black text-xl uppercase text-primary">Cancel Booking?</h3>
            <p class="text-sm text-zinc-600 mt-2">Are you sure you want to cancel <strong id="cancel-code">—</strong>?<br>This action cannot be undone.</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <button onclick="closeCancelModal()" class="py-2.5 border-2 border-primary font-bold text-xs uppercase hover:bg-zinc-50">Keep It</button>
            <button id="cancel-confirm-btn" onclick="submitCancel()"
                class="py-2.5 bg-red-600 text-white border-2 border-red-600 font-bold text-xs uppercase flex items-center justify-center gap-2 hover:bg-red-700 transition-all">
                Yes, Cancel
            </button>
        </div>
    </div>
</div>

<!-- ─── SUCCESS TOAST ─── -->
<div id="toast" class="hidden fixed bottom-6 right-6 z-50 bg-primary text-white px-6 py-4 border-2 border-accent shadow-[4px_4px_0px_rgba(255,184,0,1)] flex items-center gap-3">
    <span class="material-symbols-outlined" style="font-variation-settings:'FILL' 1;">check_circle</span>
    <span id="toast-msg" class="font-bold text-sm uppercase"></span>
</div>

<script>
let activeBookingId = null;

// ── Notification helpers ──────────────────────────────────────────
function toggleNotifDropdown() {
    const dd = document.getElementById('notif-dropdown');
    dd.classList.toggle('hidden');
    if (!dd.classList.contains('hidden')) loadNotifications();
}
function loadNotifications() {
    const c = document.getElementById('notif-items');
    c.innerHTML = '<div class="p-4 text-center text-xs text-zinc-400">Loading...</div>';
    fetch('../actions/get_notifications.php?action=get_unread')
        .then(r => r.json()).then(data => {
            if (!data.success || !data.notifications.length) {
                c.innerHTML = '<div class="p-4 text-center text-xs text-zinc-400">No unread notifications.</div>';
                return;
            }
            c.innerHTML = data.notifications.map(n => `
                <div class="p-4 hover:bg-zinc-50 flex justify-between items-start gap-2">
                    <div>
                        <p class="text-xs font-bold text-primary">${n.message}</p>
                        <span class="text-[9px] text-zinc-400 font-bold uppercase">${n.time_ago}</span>
                    </div>
                    <button onclick="markNotificationRead(${n.id})" class="text-zinc-300 hover:text-primary">
                        <span class="material-symbols-outlined text-sm">close</span>
                    </button>
                </div>`).join('');
        });
}
function markNotificationRead(id) {
    const fd = new FormData(); fd.append('notification_id', id);
    fetch('../actions/get_notifications.php?action=mark_read', { method:'POST', body:fd }).then(() => loadNotifications());
}
function markAllNotificationsRead() {
    const fd = new FormData(); fd.append('notification_id', 'all');
    fetch('../actions/get_notifications.php?action=mark_read', { method:'POST', body:fd }).then(() => loadNotifications());
}
document.addEventListener('click', e => {
    if (!document.getElementById('notif-btn').contains(e.target) &&
        !document.getElementById('notif-dropdown').contains(e.target)) {
        document.getElementById('notif-dropdown').classList.add('hidden');
    }
});

// ── Pay Now Modal ─────────────────────────────────────────────────
// ── Pay Now Modal ─────────────────────────────────────────────────
function openPayModal(bookingId, code, amount) {
    activeBookingId = bookingId;
    submitPayNow();
}
function closePayModal() {}
function processPayment() {}
function submitPayNow() {
    const payBtn = document.querySelector(`button[onclick^="openPayModal(${activeBookingId}"]`);
    let originalHtml = '';
    if (payBtn) {
        originalHtml = payBtn.innerHTML;
        payBtn.disabled = true;
        payBtn.innerHTML = 'Connecting... <span class="material-symbols-outlined text-xs animate-spin">progress_activity</span>';
    }

    const fd = new FormData();
    fd.append('action', 'pay_now');
    fd.append('booking_id', activeBookingId);
    fetch('../actions/client_booking.php', { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            if (payBtn) {
                payBtn.disabled = false;
                payBtn.innerHTML = originalHtml;
            }
            if (data.success && data.redirect_url) {
                // Redirect directly to PayMongo Secure Checkout page!
                window.location.href = data.redirect_url;
            } else {
                alert('Redirect failed: ' + data.message);
            }
        }).catch(() => {
            if (payBtn) {
                payBtn.disabled = false;
                payBtn.innerHTML = originalHtml;
            }
            alert('Network error. Please retry.');
        });
}

// ── Cancel Modal ──────────────────────────────────────────────────
function confirmCancel(bookingId, code) {
    activeBookingId = bookingId;
    document.getElementById('cancel-code').textContent = code;
    document.getElementById('cancel-modal').classList.remove('hidden');
}
function closeCancelModal() {
    document.getElementById('cancel-modal').classList.add('hidden');
    activeBookingId = null;
}
function submitCancel() {
    const btn = document.getElementById('cancel-confirm-btn');
    btn.disabled = true;
    btn.innerHTML = 'Submitting Request… <span class="material-symbols-outlined text-sm animate-spin">progress_activity</span>';
    const fd = new FormData();
    fd.append('action', 'cancel_booking');
    fd.append('booking_id', activeBookingId);
    fetch('../actions/client_booking.php', { method:'POST', body:fd })
        .then(r => r.json()).then(data => {
            closeCancelModal();
            btn.disabled = false;
            btn.innerHTML = 'Yes, Cancel';
            if (data.success) {
                if (data.pending) {
                    showToast('Cancellation request submitted! Waiting for admin approval.');
                } else {
                    showToast('Booking cancelled successfully.');
                }
                setTimeout(() => location.reload(), 1500);
            } else {
                alert('Error: ' + data.message);
            }
        }).catch(() => alert('Network error. Please retry.'));
}

// ── Toast ─────────────────────────────────────────────────────────
function showToast(msg) {
    const t = document.getElementById('toast');
    document.getElementById('toast-msg').textContent = msg;
    t.classList.remove('hidden');
    setTimeout(() => t.classList.add('hidden'), 5000);
}

// ── Card format input ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // Parse callback payment result status from query params
    const urlParams = new URLSearchParams(window.location.search);
    const paymentStatus = urlParams.get('payment');
    if (paymentStatus === 'success') {
        showToast('Payment Successful! Your court is locked and confirmed.');
    } else if (paymentStatus === 'cancel') {
        showToast('Payment Cancelled or Failed. Please try paying again before the 24h deadline.');
    }

    const cardInput = document.getElementById('pay-card-num');
    if (cardInput) {
        cardInput.addEventListener('input', function() {
            let v = this.value.replace(/\D/g,'').substring(0,16);
            this.value = v.replace(/(.{4})/g,'$1 ').trim();
        });
    }
    const expInput = document.getElementById('pay-expiry');
    if (expInput) {
        expInput.addEventListener('input', function() {
            let v = this.value.replace(/\D/g,'').substring(0,4);
            if (v.length > 2) v = v.substring(0,2) + '/' + v.substring(2);
            this.value = v;
        });
    }
    loadNotifications();
});
</script>
</body>
</html>
