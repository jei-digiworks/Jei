<?php
// actions/get_notifications.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$is_admin = is_admin_logged_in();
$is_client = is_client_logged_in();

if (!$is_admin && !$is_client) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($action === 'get_unread') {
    try {
        if ($is_admin) {
            $stmt = $pdo->prepare("SELECT id, message, type, sent_at FROM notifications WHERE admin_id = 1 AND is_read = 0 ORDER BY sent_at DESC LIMIT 5");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("SELECT id, message, type, sent_at FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY sent_at DESC LIMIT 5");
            $stmt->execute([$_SESSION['user_id']]);
        }
        $notifs = $stmt->fetchAll();

        $formatted = [];
        foreach ($notifs as $notif) {
            $formatted[] = [
                'id' => $notif['id'],
                'message' => $notif['message'],
                'type' => $notif['type'],
                'time_ago' => date('h:i A', strtotime($notif['sent_at']))
            ];
        }

        echo json_encode(['success' => true, 'notifications' => $formatted]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'mark_read') {
    $id = $_POST['notification_id'] ?? '';

    try {
        if ($id === 'all') {
            if ($is_admin) {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE admin_id = 1");
                $stmt->execute();
            } else {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
            }
        } else {
            $id = (int)$id;
            if ($is_admin) {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND admin_id = 1");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $_SESSION['user_id']]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Notifications updated successfully.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action request.']);
