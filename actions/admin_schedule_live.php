<?php
// actions/admin_schedule_live.php
// Returns live schedule data for admin schedule.php polling
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
require_admin();

$action = $_GET['action'] ?? '';

// ── Get live week schedule data ───────────────────────────────────
if ($action === 'get_week') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $monday_ts  = strtotime('monday this week', strtotime($date));
    $sql_start  = date('Y-m-d', $monday_ts);
    $sql_end    = date('Y-m-d', strtotime('+6 days', $monday_ts));

    try {
        $stmt_schedules = $pdo->prepare("
            SELECT id, session_date, start_time, end_time, status, is_game_night, notes
              FROM schedules
             WHERE session_date BETWEEN ? AND ?
             ORDER BY session_date ASC, start_time ASC
        ");
        $stmt_schedules->execute([$sql_start, $sql_end]);
        $rows = $stmt_schedules->fetchAll(PDO::FETCH_ASSOC);

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

        // Build a map keyed as "YYYY-MM-DD|HH:MM"
        $slot_map = [];
        foreach ($rows as $r) {
            $time_key = date('H:i', strtotime($r['start_time']));
            $slot_key = $r['session_date'] . '|' . $time_key;

            $r['booking_code'] = null;
            $r['booking_status'] = null;
            $r['pax'] = null;
            $r['client_name'] = null;
            $r['booking_id'] = null;

            if (isset($occupied_slots[$slot_key])) {
                $b = $occupied_slots[$slot_key];
                $r['booking_code']   = $b['booking_code'];
                $r['booking_status'] = $b['booking_status'];
                $r['pax']            = $b['pax'];
                $r['client_name']    = $b['client_name'];
                $r['booking_id']     = $b['booking_id'];
            }

            $slot_map[$slot_key] = $r;
        }

        echo json_encode(['success' => true, 'slots' => $slot_map, 'generated_at' => date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
