<?php
// Public JSON endpoint for the storefront basket.
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}
if (!csrf_valid()) {
    json_response(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.'], 403);
}

$action = $_POST['action'] ?? '';

if ($action === 'reserve') {
    // Bots fill every field; people never see this one.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        json_response(['success' => false, 'message' => 'Could not submit your reservation.'], 400);
    }

    $result = place_order(
        is_array($_POST['items'] ?? null) ? $_POST['items'] : [],
        [
            'name' => $_POST['name'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? '',
            'pickup_date' => $_POST['pickup_date'] ?? '',
            'pickup_time' => $_POST['pickup_time'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'payment_method' => $_POST['payment_method'] ?? '',
        ],
        $_SERVER['REMOTE_ADDR'] ?? ''
    );

    if (!$result['ok']) {
        json_response([
            'success' => false,
            'message' => $result['message'],
            'item_errors' => (object) ($result['item_errors'] ?? []),
        ]);
    }

    $order = $result['order'];
    // Remember the order on this device so the status page opens without asking for the phone number.
    $_SESSION['my_orders'] = array_slice(array_values(array_unique(array_merge([$order['code']], $_SESSION['my_orders'] ?? []))), 0, 10);

    json_response(['success' => true, 'order' => [
        'code' => $order['code'],
        'total' => $order['total'],
        'savings' => $order['savings'],
        'pickup' => $order['pickup'],
        'payment' => $order['payment'],
        'payment_note' => $order['payment_note'],
        'track_url' => 'order_status.php?code=' . urlencode($order['code']),
    ]]);
}

json_response(['success' => false, 'message' => 'Unknown action.'], 400);
