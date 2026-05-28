<?php
// actions/admin_dashboard.php
// Returns itemized detail lists for each dashboard summary metric.
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!is_admin_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$metric = $_GET['metric'] ?? '';

try {
    switch ($metric) {

        // ── 1. SLOTS TODAY ──────────────────────────────────────────
        case 'slots_today':
            $stmt = $pdo->query("
                SELECT s.id, s.start_time, s.end_time, s.duration_hours, s.status, s.is_game_night, s.notes,
                       b.booking_code, b.status AS booking_status, u.full_name AS client_name, b.pax
                  FROM schedules s
                  LEFT JOIN bookings b ON b.schedule_id = s.id AND b.status != 'cancelled'
                  LEFT JOIN users u ON u.id = b.user_id
                 WHERE s.session_date = CURDATE() AND s.start_time != '00:00:00'
                 ORDER BY s.start_time ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rows as $r) {
                $label = 'Available';
                $badge = 'available';
                if ($r['status'] === 'locked') { $label = 'Locked / Maintenance'; $badge = 'locked'; }
                elseif ($r['booking_code']) {
                    $label = htmlspecialchars($r['client_name']) . ' — ' . $r['booking_code'];
                    $badge = $r['booking_status'] ?? $r['status'];
                } elseif ($r['status'] === 'reserved') { $label = 'Reserved (Pending)'; $badge = 'reserved'; }

                $items[] = [
                    'time'  => date('h:i A', strtotime($r['start_time'])) . ' – ' . date('h:i A', strtotime($r['end_time'])),
                    'label' => $label,
                    'badge' => $badge,
                    'sub'   => ($r['pax'] ? $r['pax'] . ' Pax' : '') . ($r['is_game_night'] ? ' • Game Night' : ''),
                ];
            }
            echo json_encode(['success' => true, 'title' => 'All Schedule Slots Today', 'icon' => 'sports_tennis', 'items' => $items]);
            break;

        // ── 2. BOOKED SLOTS ─────────────────────────────────────────
        case 'booked_today':
            $stmt = $pdo->query("
                SELECT s.start_time, s.end_time, b.booking_code, b.pax, b.total_fee,
                       (b.coaching_fee > 0) AS add_coaching, u.full_name AS client_name
                  FROM schedules s
                  JOIN bookings b ON b.schedule_id = s.id
                  JOIN users u ON u.id = b.user_id
                 WHERE s.session_date = CURDATE() AND s.status = 'confirmed' AND s.start_time != '00:00:00'
                 ORDER BY s.start_time ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rows as $r) {
                $items[] = [
                    'time'  => date('h:i A', strtotime($r['start_time'])) . ' – ' . date('h:i A', strtotime($r['end_time'])),
                    'label' => htmlspecialchars($r['client_name']) . ' — ' . $r['booking_code'],
                    'badge' => 'confirmed',
                    'sub'   => $r['pax'] . ' Pax • ₱' . number_format($r['total_fee'], 2) . ($r['add_coaching'] ? ' • Coaching' : ''),
                    'coaching' => (bool)$r['add_coaching']
                ];
            }
            echo json_encode(['success' => true, 'title' => 'Confirmed Booked Slots Today', 'icon' => 'check_circle', 'items' => $items]);
            break;

        // ── 3. RESERVED SLOTS ───────────────────────────────────────
        case 'reserved_today':
            $stmt = $pdo->query("
                SELECT s.start_time, s.end_time, s.reserved_until,
                       b.booking_code, b.pax, b.total_fee, u.full_name AS client_name,
                       p.status AS pay_status, p.due_date
                  FROM schedules s
                  JOIN bookings b ON b.schedule_id = s.id AND b.status = 'reserved'
                  JOIN users u ON u.id = b.user_id
                  LEFT JOIN payments p ON p.booking_id = b.id
                 WHERE s.session_date = CURDATE() AND s.status = 'reserved' AND s.start_time != '00:00:00'
                 ORDER BY s.start_time ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rows as $r) {
                $due = $r['due_date'] ? date('M d, h:i A', strtotime($r['due_date'])) : '—';
                $items[] = [
                    'time'  => date('h:i A', strtotime($r['start_time'])) . ' – ' . date('h:i A', strtotime($r['end_time'])),
                    'label' => htmlspecialchars($r['client_name']) . ' — ' . $r['booking_code'],
                    'badge' => 'reserved',
                    'sub'   => $r['pax'] . ' Pax • ₱' . number_format($r['total_fee'], 2) . ' • Due: ' . $due,
                ];
            }
            echo json_encode(['success' => true, 'title' => 'Reserved Slots Today (Pending Payment)', 'icon' => 'pending', 'items' => $items]);
            break;

        // ── 4. REVENUE THIS MONTH ───────────────────────────────────
        case 'revenue_month':
            $stmt = $pdo->query("
                SELECT p.amount, p.method, p.paid_at,
                       b.booking_code, u.full_name AS client_name
                  FROM payments p
                  JOIN bookings b ON b.id = p.booking_id
                  JOIN users u ON u.id = b.user_id
                 WHERE p.status = 'paid' AND DATE_FORMAT(p.paid_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') AND p.method != 'package'
                 ORDER BY p.paid_at DESC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rows as $r) {
                $items[] = [
                    'time'  => date('M d, h:i A', strtotime($r['paid_at'])),
                    'label' => htmlspecialchars($r['client_name']) . ' — ' . $r['booking_code'],
                    'badge' => 'paid',
                    'sub'   => '₱' . number_format($r['amount'], 2) . ' • ' . ucfirst(str_replace('_', ' ', $r['method'])),
                ];
            }
            echo json_encode(['success' => true, 'title' => 'Revenue Collected for this Month', 'icon' => 'payments', 'items' => $items]);
            break;

        // ── 5. REGISTERED ATHLETES ──────────────────────────────────
        case 'registered_athletes':
            $stmt = $pdo->query("
                SELECT u.id, u.full_name, u.email, u.phone, u.email_verified, u.created_at,
                       (SELECT COUNT(*) FROM bookings b WHERE b.user_id = u.id AND b.status != 'cancelled') AS total_bookings
                  FROM users u
                 WHERE u.role = 'client'
                 ORDER BY u.created_at DESC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rows as $r) {
                $items[] = [
                    'time'  => date('M d, Y', strtotime($r['created_at'])),
                    'label' => htmlspecialchars($r['full_name']),
                    'badge' => $r['email_verified'] ? 'verified' : 'unverified',
                    'sub'   => htmlspecialchars($r['email']) . ' • ' . $r['total_bookings'] . ' bookings' . ($r['phone'] ? ' • ' . $r['phone'] : ''),
                    'verified' => (bool)$r['email_verified']
                ];
            }
            echo json_encode(['success' => true, 'title' => 'Registered Athletes Directory', 'icon' => 'groups', 'items' => $items]);
            break;

        // ── 6. TOTAL BOOKINGS PLACED ────────────────────────────────
        case 'total_bookings':
            $stmt = $pdo->query("
                SELECT b.booking_code, b.status, b.total_fee, b.pax, b.booked_at,
                       (b.coaching_fee > 0) AS add_coaching,
                       u.full_name AS client_name,
                       s.session_date, s.start_time
                  FROM bookings b
                  JOIN users u ON u.id = b.user_id
                  JOIN schedules s ON s.id = b.schedule_id
                 WHERE b.status != 'cancelled'
                 ORDER BY b.booked_at DESC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rows as $r) {
                $items[] = [
                    'time'  => date('M d', strtotime($r['session_date'])) . ' • ' . date('h:i A', strtotime($r['start_time'])),
                    'label' => htmlspecialchars($r['client_name']) . ' — ' . $r['booking_code'],
                    'badge' => $r['status'],
                    'sub'   => $r['pax'] . ' Pax • ₱' . number_format($r['total_fee'], 2) . ($r['add_coaching'] ? ' • Coaching' : ''),
                    'status' => $r['status']
                ];
            }
            echo json_encode(['success' => true, 'title' => 'All Bookings Placed', 'icon' => 'book_online', 'items' => $items]);
            break;

        // ── 7. UNPAID BOOKINGS (ACTION NEEDED) ──────────────────────
        case 'unpaid_bookings':
            $stmt = $pdo->query("
                SELECT b.booking_code, b.total_fee, b.pax, b.status AS booking_status,
                       u.full_name AS client_name, u.email,
                       s.session_date, s.start_time,
                       p.status AS pay_status, p.method, p.due_date
                  FROM payments p
                  JOIN bookings b ON b.id = p.booking_id
                  JOIN users u ON u.id = b.user_id
                  JOIN schedules s ON s.id = b.schedule_id
                 WHERE p.status = 'unpaid' AND b.status != 'cancelled'
                 ORDER BY p.due_date ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rows as $r) {
                $due = $r['due_date'] ? date('M d, h:i A', strtotime($r['due_date'])) : '—';
                $overdue = ($r['due_date'] && strtotime($r['due_date']) < time()) ? ' ⚠ OVERDUE' : '';
                $items[] = [
                    'time'  => date('M d', strtotime($r['session_date'])) . ' • ' . date('h:i A', strtotime($r['start_time'])),
                    'label' => htmlspecialchars($r['client_name']) . ' — ' . $r['booking_code'],
                    'badge' => 'unpaid',
                    'sub'   => '₱' . number_format($r['total_fee'], 2) . ' • ' . ucfirst(str_replace('_', ' ', $r['method'])) . ' • Due: ' . $due . $overdue,
                ];
            }
            echo json_encode(['success' => true, 'title' => 'Unpaid Bookings (Action Needed)', 'icon' => 'pending_actions', 'items' => $items]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown metric type.']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
