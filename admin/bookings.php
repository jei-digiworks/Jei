<?php
// admin/bookings.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_admin();
run_cron_simulator($pdo);

// Reconcile all pending PayMongo payments globally dynamically on load
try {
    $stmt_pending = $pdo->query("
        SELECT b.id 
          FROM bookings b
          JOIN payments p ON p.booking_id = b.id
         WHERE b.status = 'reserved' 
           AND p.status = 'unpaid' 
           AND p.paymongo_intent_id IS NOT NULL 
           AND p.paymongo_intent_id != ''
    ");
    $pending_reconciliations = $stmt_pending->fetchAll(PDO::FETCH_COLUMN);

    foreach ($pending_reconciliations as $bid) {
        reconcile_paymongo_payment($pdo, $bid);
    }
} catch (Exception $e) {
    error_log("Global admin dynamic reconciliation failed: " . $e->getMessage());
}

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'RDG ADMIN';

// Fetch stats for widgets
$stmt_overdue = $pdo->query("
    SELECT COALESCE(SUM(p.amount), 0) 
      FROM payments p
      JOIN bookings b ON b.id = p.booking_id
     WHERE b.status != 'cancelled'
       AND (p.status = 'overdue' OR (p.status = 'unpaid' AND p.due_date < NOW()))
");
$total_overdue = (float)$stmt_overdue->fetchColumn();

$stmt_reserves = $pdo->query("SELECT COUNT(*) FROM schedules WHERE status = 'reserved'");
$pending_reserves = (int) $stmt_reserves->fetchColumn();

$stmt_rev_mtd = $pdo->query("
    SELECT COALESCE(SUM(amount), 0) 
      FROM payments 
     WHERE status = 'paid' 
       AND MONTH(paid_at) = MONTH(CURRENT_DATE()) 
       AND YEAR(paid_at) = YEAR(CURRENT_DATE())
");
$revenue_mtd = (float) $stmt_rev_mtd->fetchColumn();

// Filters and search logic
$search = trim($_GET['search'] ?? '');
$method = trim($_GET['method'] ?? '');
$date = trim($_GET['date'] ?? '');

$where_clauses = [];
$params = [];

if ($search !== '') {
    $where_clauses[] = "(v.booking_code LIKE ? OR v.client_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($method !== '' && $method !== 'All Methods') {
    // Map human readable values back to database enum
    $method_map = ['Pay Now' => 'pay_now', 'Pay Later' => 'pay_later', 'Package' => 'package'];
    $mapped_val = $method_map[$method] ?? 'pay_later';
    $where_clauses[] = "v.method = ?";
    $params[] = $mapped_val;
}
if ($date !== '') {
    $where_clauses[] = "v.session_date = ?";
    $params[] = $date;
}

$where_str = '';
if (!empty($where_clauses)) {
    $where_str = "WHERE " . implode(" AND ", $where_clauses);
}

// Count total matching bookings
$stmt_count = $pdo->prepare("
    SELECT COUNT(*) 
      FROM v_payment_monitoring v
     $where_str
");
$stmt_count->execute($params);
$total_rows = $stmt_count->fetchColumn();

// Pagination setup
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 8;
$offset = ($page - 1) * $per_page;
$total_pages = max(1, ceil($total_rows / $per_page));

// Fetch bookings matching search constraints
$stmt_bookings = $pdo->prepare("
    SELECT v.*, b.id AS booking_id
      FROM v_payment_monitoring v
      JOIN bookings b ON b.booking_code = v.booking_code
     $where_str
     ORDER BY v.session_date DESC, v.start_time DESC
     LIMIT $per_page OFFSET $offset
");
$stmt_bookings->execute($params);
$bookings = $stmt_bookings->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>RDG Tennis - Admin Bookings &amp; Payments</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700;900&family=Lexend:wght@300;400;500;600;700&family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap"
        rel="stylesheet" />
    <style>
        .court-grid {
            background-image: linear-gradient(#e5e7eb 1px, transparent 1px), linear-gradient(90deg, #e5e7eb 1px, transparent 1px);
            background-size: 64px 64px;
        }

        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }

        .fill-icon {
            font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(6px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .fade-in {
            animation: fadeIn .3s ease both
        }
    </style>
    <script>
        tailwind.config = {
            darkMode: "class", theme: {
                extend: {
                    colors: { "primary": "#154212", "primary-container": "#2d5a27", "accent": "#FFB800", "on-surface": "#1c1b1b", "surface-container-low": "#f6f3f2", "outline-variant": "#c2c9bb", "secondary": "#7d5700", "error": "#ba1a1a" },
                    borderRadius: { DEFAULT: "0px", full: "9999px" },
                    fontFamily: { headline: ["Space Grotesk"], body: ["Lexend"] }
                }
            }
        }
    </script>
</head>

<body class="bg-[#fcf9f8] text-[#1c1b1b] font-body court-grid min-h-screen">

    <!-- TopBar -->
    <header
        class="fixed top-0 left-0 right-0 z-50 bg-white flex justify-between items-center px-6 h-20 border-b-2 border-primary">
        <div class="flex items-center gap-3">
            <img alt="RDG" class="h-10 w-auto" src="/RDG/RDG Logo.jpg" />
        </div>
        <div class="flex items-center gap-4">
            <button class="relative p-2 hover:bg-[#f6f3f2] transition-colors">
                <span class="material-symbols-outlined text-2xl">notifications</span>
            </button>
            <div class="h-8 w-px bg-[#c2c9bb]"></div>
            <span
                class="hidden md:inline font-bold text-xs uppercase text-primary"><?= htmlspecialchars($admin_name) ?></span>
            <a href="/RDG/auth/logout.php"
                class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-accent hover:opacity-80 transition-opacity">
                <span class="material-symbols-outlined text-xl">logout</span>
            </a>
        </div>
    </header>

    <!-- Sidebar -->
    <aside
        class="fixed left-0 top-20 h-[calc(100vh-80px)] w-64 bg-[#F8F9FA] border-r-2 border-primary flex flex-col py-4 z-40">
        <div class="px-6 py-4 mb-2 border-b border-zinc-200">
            <p class="font-headline font-bold uppercase text-sm text-primary">Admin Panel</p>
            <p class="text-xs text-[#42493e] font-bold uppercase">Court Management</p>
        </div>
        <nav class="flex flex-col gap-1">
            <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all"
                href="dashboard.php"><span class="material-symbols-outlined">grid_view</span> Dashboard</a>
            <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all"
                href="schedule.php"><span class="material-symbols-outlined">calendar_today</span> Schedule</a>
            <a class="bg-[#FFE500] text-primary border-l-4 border-primary px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm"
                href="bookings.php"><span class="material-symbols-outlined fill-icon">payments</span> Bookings</a>
            <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all"
                href="attendance.php"><span class="material-symbols-outlined">fact_check</span> Attendance</a>
            <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all"
                href="cancellations.php"><span class="material-symbols-outlined">cancel</span> Requests</a>
            <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all"
                href="months.php"><span class="material-symbols-outlined">calendar_month</span> Months</a>
            <a class="text-primary hover:bg-zinc-200 px-6 py-3 flex items-center gap-3 font-headline font-bold uppercase text-sm transition-all"
                href="reports.php"><span class="material-symbols-outlined">analytics</span> Reports</a>
        </nav>
    </aside>

    <!-- Main -->
    <main class="ml-64 pt-20 min-h-screen p-8">
        <div class="max-w-7xl mx-auto space-y-8 fade-in">
            <!-- Page Header -->
            <div
                class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 border-b-4 border-primary pb-6">
                <div>
                    <h1 class="font-headline font-black text-5xl text-primary uppercase tracking-tighter">Payment
                        Monitor</h1>
                    <p class="text-[#42493e]">Track transactions, confirm manual entries, and identify overdue balances.
                    </p>
                </div>
                <button onclick="alert('Exporting record sheet...')"
                    class="bg-primary text-white font-headline font-black uppercase px-6 py-3 hover:bg-accent hover:text-primary transition-all shadow-[2px_2px_0px_rgba(0,0,0,0.15)] flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">download</span> Export CSV
                </button>
            </div>

            <!-- Filters Block -->
            <form method="GET" class="bg-white border-2 border-primary p-6 shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-bold text-primary uppercase mb-2">Search Booking or
                            Client</label>
                        <input name="search" value="<?= htmlspecialchars($search) ?>"
                            placeholder="e.g. BK-2026-0001 or Juan Cruz"
                            class="w-full border-t-0 border-x-0 border-b-2 border-primary focus:ring-0 font-body-md py-2 px-0 bg-transparent placeholder-zinc-300">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-primary uppercase mb-2">Payment Method</label>
                        <select name="method" onchange="this.form.submit()"
                            class="w-full border-t-0 border-x-0 border-b-2 border-primary focus:ring-0 font-body-md py-2 px-0 bg-transparent">
                            <option value="">All Methods</option>
                            <?php foreach (['Pay Now', 'Pay Later', 'Package'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= $method === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-primary uppercase mb-2">Session Date</label>
                        <input type="date" name="date" value="<?= htmlspecialchars($date) ?>"
                            onchange="this.form.submit()"
                            class="w-full border-t-0 border-x-0 border-b-2 border-primary focus:ring-0 font-body-md py-2 px-0 bg-transparent">
                    </div>
                </div>
            </form>

            <!-- Bookings Data Table -->
            <div class="bg-white border-2 border-primary shadow-[6px_6px_0px_rgba(21,66,18,1)] overflow-hidden">
                <div class="bg-primary px-6 py-4 flex justify-between items-center border-b-2 border-primary">
                    <h2 class="font-headline font-bold text-white uppercase flex items-center gap-2">
                        <span class="material-symbols-outlined">payments</span> Transactions Record
                    </h2>
                    <span class="text-xs text-zinc-300 font-bold uppercase">Showing <?= count($bookings) ?> of
                        <?= $total_rows ?> entries</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-[#f6f3f2] border-b-2 border-primary">
                                <th
                                    class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider border-r border-zinc-200">
                                    Booking Ref</th>
                                <th
                                    class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider border-r border-zinc-200">
                                    Client Name</th>
                                <th
                                    class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider border-r border-zinc-200">
                                    Session Schedule</th>
                                <th
                                    class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider border-r border-zinc-200 text-center">
                                    Status</th>
                                <th
                                    class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider border-r border-zinc-200">
                                    Method</th>
                                <th
                                    class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider text-right">
                                    Total Fee</th>
                                <th
                                    class="px-5 py-3 font-bold text-primary uppercase text-[11px] tracking-wider text-center">
                                    Verification Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookings)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center text-zinc-400 text-sm">No transaction
                                        records found.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($bookings as $i => $b):
                                $sch_d = new DateTime($b['session_date']);
                                $eff_status = strtoupper($b['effective_status']);

                                $status_cls = 'bg-zinc-400 text-white';
                                if ($eff_status === 'PAID') {
                                    $status_cls = 'bg-primary text-white';
                                } elseif ($eff_status === 'OVERDUE') {
                                    $status_cls = 'bg-red-100 text-red-700 border-2 border-red-500 font-black';
                                } elseif ($eff_status === 'RESERVED') {
                                    $status_cls = 'bg-accent text-primary font-bold';
                                } elseif ($eff_status === 'CANCELLED') {
                                    $status_cls = 'bg-red-50 text-red-500 border border-red-200 font-bold';
                                }
                                ?>
                                <tr
                                    class="border-b border-[#c2c9bb] hover:bg-[#f6f3f2] transition-colors fade-in <?= $i % 2 === 1 ? 'bg-[#fcf9f8]' : '' ?>">
                                    <td class="px-5 py-4 font-black text-primary border-r border-zinc-200">
                                        #<?= htmlspecialchars($b['booking_code']) ?></td>
                                    <td class="px-5 py-4 text-xs font-bold border-r border-zinc-200">
                                        <?= htmlspecialchars($b['client_name']) ?></td>
                                    <td class="px-5 py-4 text-xs border-r border-zinc-200"><?= $sch_d->format('M d, Y') ?>
                                        &bull; <?= date('h:i A', strtotime($b['start_time'])) ?></td>
                                    <td class="px-5 py-4 border-r border-zinc-200 text-center">
                                        <span
                                            class="px-2.5 py-1 text-[9px] uppercase tracking-wider font-bold <?= $status_cls ?>"><?= $eff_status ?></span>
                                    </td>
                                    <td
                                        class="px-5 py-4 border-r border-zinc-200 text-xs font-bold text-zinc-500 uppercase">
                                        <?= str_replace('_', ' ', $b['method']) ?></td>
                                    <td class="px-5 py-4 text-right font-black text-primary">
                                        &#8369;<?= number_format($b['amount'], 2) ?></td>
                                    <td class="px-5 py-4 text-center">
                                        <?php if ($eff_status === 'UNPAID' || $eff_status === 'RESERVED' || $eff_status === 'OVERDUE'): ?>
                                            <button
                                                onclick="confirmCashPayment(<?= $b['booking_id'] ?>, '<?= htmlspecialchars($b['booking_code']) ?>')"
                                                class="bg-accent text-primary px-3 py-1.5 text-[9px] font-black uppercase hover:bg-primary hover:text-white transition-colors border border-primary shadow-[1px_1px_0px_rgba(0,0,0,0.15)]">
                                                Confirm Cash
                                            </button>
                                        <?php else: ?>
                                            <span class="text-zinc-300 text-xs font-bold">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination Controls -->
                <div class="px-6 py-4 border-t border-primary flex justify-between items-center bg-white">
                    <span class="text-[10px] font-bold text-zinc-400 uppercase">Page <?= $page ?> of
                        <?= $total_pages ?></span>
                    <div class="flex gap-1">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&method=<?= urlencode($method) ?>&date=<?= urlencode($date) ?>"
                                class="w-8 h-8 border border-primary flex items-center justify-center hover:bg-primary hover:text-white transition-colors">
                                <span class="material-symbols-outlined text-sm">chevron_left</span></a>
                        <?php endif; ?>
                        <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                            <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&method=<?= urlencode($method) ?>&date=<?= urlencode($date) ?>"
                                class="w-8 h-8 flex items-center justify-center font-bold text-sm border transition-colors
                           <?= $p === $page ? 'bg-primary text-white border-primary' : 'border-primary hover:bg-primary hover:text-white' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&method=<?= urlencode($method) ?>&date=<?= urlencode($date) ?>"
                                class="w-8 h-8 border border-primary flex items-center justify-center hover:bg-primary hover:text-white transition-colors">
                                <span class="material-symbols-outlined text-sm">chevron_right</span></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Metric Summary Widgets -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div
                    class="bg-white border-2 border-primary border-l-4 border-l-red-500 p-6 shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                    <p class="text-[10px] font-bold text-zinc-400 uppercase">Total Overdue Balances</p>
                    <div class="flex items-end justify-between mt-2">
                        <span
                            class="font-headline font-black text-3xl text-error">&#8369;<?= number_format($total_overdue, 2) ?></span>
                        <span
                            class="text-[9px] bg-red-100 text-red-700 px-2 py-0.5 border border-red-500 font-bold uppercase">Attention
                            Required</span>
                    </div>
                </div>
                <div
                    class="bg-white border-2 border-primary border-l-4 border-l-accent p-6 shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                    <p class="text-[10px] font-bold text-zinc-400 uppercase">Active Court Reserves</p>
                    <div class="flex items-end justify-between mt-2">
                        <span class="font-headline font-black text-3xl text-primary"><?= $pending_reserves ?>
                            Units</span>
                        <span class="material-symbols-outlined text-accent text-2xl">hourglass_empty</span>
                    </div>
                </div>
                <div
                    class="bg-white border-2 border-primary border-l-4 border-l-primary p-6 shadow-[4px_4px_0px_rgba(21,66,18,1)]">
                    <p class="text-[10px] font-bold text-zinc-400 uppercase">Total Revenue (MTD)</p>
                    <div class="flex items-end justify-between mt-2">
                        <span
                            class="font-headline font-black text-3xl text-primary">&#8369;<?= number_format($revenue_mtd, 2) ?></span>
                        <span
                            class="text-[9px] bg-green-100 text-green-700 px-2 py-0.5 border border-primary font-bold uppercase">Current
                            Month</span>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script>
        function confirmCashPayment(bookingId, code) {
            if (!confirm(`Are you sure you want to verify and confirm cash payment for booking ${code}?`)) return;

            const fd = new FormData();
            fd.append('action', 'confirm_payment');
            fd.append('booking_id', bookingId);

            fetch('../actions/admin_booking_status.php', {
                method: 'POST',
                body: fd
            })
                .then(r => r.json())
                .then(d => {
                    alert(d.message);
                    if (d.success) location.reload();
                })
                .catch(() => alert('Failed to confirm payment.'));
        }
    </script>
</body>

</html>