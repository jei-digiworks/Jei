<?php
// client/pay.php
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../config/db.php';

// Enforce player login. require_client() will save current URL in session and redirect to login, then redirect back here.
require_client();

$booking_code = trim($_GET['code'] ?? $_GET['booking_code'] ?? '');

if (empty($booking_code)) {
    render_error("Booking reference code is required.");
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    // Verify booking belongs to user, is reserved, and is unpaid
    $stmt = $pdo->prepare("
        SELECT b.*, p.id AS payment_id, p.status AS payment_status
          FROM bookings b
          JOIN payments p ON p.booking_id = b.id
         WHERE b.booking_code = ? AND b.user_id = ? AND b.status IN ('reserved','pending_payment')
    ");
    $stmt->execute([$booking_code, $user_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        // Double-check if the booking is already confirmed/paid
        $stmt_check = $pdo->prepare("SELECT status FROM bookings WHERE booking_code = ? AND user_id = ? LIMIT 1");
        $stmt_check->execute([$booking_code, $user_id]);
        $existing_status = $stmt_check->fetchColumn();
        
        if ($existing_status === 'confirmed' || $existing_status === 'completed') {
            // Settle successfully, redirect back to My Bookings with success message
            header("Location: /RDG/client/my_bookings.php?payment=success");
            exit;
        }
        
        render_error("This court reservation is not active, has expired, or has already been paid for.");
        exit;
    }

    // Fetch user details for PayMongo
    $stmt_user = $pdo->prepare("SELECT email, full_name, phone FROM users WHERE id = ?");
    $stmt_user->execute([$user_id]);
    $user_details = $stmt_user->fetch();
    $email = $user_details['email'] ?? '';
    $name = $user_details['full_name'] ?? $_SESSION['user_name'];
    $phone = $user_details['phone'] ?? '';

    // Create secure Checkout Session via PayMongo API
    $checkout_res = create_paymongo_checkout($booking['booking_code'], $booking['total_fee'], $email, $name, $phone);
    if (!$checkout_res['success']) {
        throw new Exception($checkout_res['message']);
    }
    $session_id = $checkout_res['session_id'];
    $checkout_url = $checkout_res['checkout_url'];

    $pdo->beginTransaction();

    // Update payment with the new session ID
    $pdo->prepare("UPDATE payments SET paymongo_intent_id = ? WHERE id = ?")
        ->execute([$session_id, $booking['payment_id']]);

    // Log checkout transaction initiation
    $stmt_tx = $pdo->prepare("
        INSERT INTO payment_transactions 
          (payment_id, paymongo_ref, event_type, payload) 
        VALUES 
          (?, ?, 'checkout', ?)
    ");
    $payload = json_encode(['amount' => $booking['total_fee'] * 100, 'currency' => 'PHP', 'status' => 'checkout_initiated', 'session_id' => $session_id]);
    $stmt_tx->execute([$booking['payment_id'], $session_id, $payload]);

    $pdo->commit();

    // Forward player directly to PayMongo checkout screen
    header("Location: " . $checkout_url);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    render_error("Payment Session Initiation Failed: " . $e->getMessage());
}

/**
 * Renders a beautiful Neobrutalist error block to match theme
 */
function render_error($message) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8"/>
        <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
        <title>RDG Tennis - Payment Gateway Error</title>
        <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@900&family=Lexend:wght@400;700&display=swap" rel="stylesheet"/>
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
        <style>
            body {
                background-image: linear-gradient(#e5e7eb 1px, transparent 1px), linear-gradient(90deg, #e5e7eb 1px, transparent 1px);
                background-size: 64px 64px;
                font-family: 'Lexend', sans-serif;
            }
        </style>
    </head>
    <body class="bg-[#fcf9f8] min-h-screen flex items-center justify-center p-6 text-[#1c1b1b]">
        <div class="bg-white border-4 border-[#154212] p-8 max-w-md w-full shadow-[8px_8px_0px_rgba(21,66,18,1)] text-center space-y-6">
            <span class="material-symbols-outlined text-6xl text-red-600 font-black animate-pulse">warning</span>
            <div>
                <h2 class="font-['Space_Grotesk'] font-black text-2xl uppercase text-[#154212] tracking-tight">Payment Session Error</h2>
                <p class="text-sm text-zinc-500 mt-3 leading-relaxed"><?= htmlspecialchars($message) ?></p>
            </div>
            <div class="pt-2">
                <a href="/RDG/client/my_bookings.php" class="inline-block bg-[#154212] text-white border-2 border-[#154212] px-6 py-3 font-['Space_Grotesk'] font-black uppercase text-xs hover:bg-[#FFB800] hover:text-[#154212] transition-colors shadow-[4px_4px_0px_rgba(21,66,18,0.2)]">
                    &larr; Back to My Bookings
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
