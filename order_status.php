<?php
// Public reservation tracker. An order is shown only after the code + phone number match,
// or when it was placed (or looked up) earlier in this browser session. Once a mobile number
// is proven that way, every order on that number is listed in "All your reservations".
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/store.php';

$s = store_settings();
$myOrders = $_SESSION['my_orders'] ?? [];

function remember_order(string $code): void {
    $_SESSION['my_orders'] = array_slice(array_values(array_unique(array_merge([$code], $_SESSION['my_orders'] ?? []))), 0, 10);
}

// Orders this browser may see: the remembered codes plus every other order on the same mobile numbers.
function visible_orders(array $codes): array {
    if (!$codes) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    return db_all(
        "SELECT co.id, co.code, co.phone, co.status, co.pickup_date, co.pickup_time, co.total, co.savings, co.payment_method, co.created_at,
                SUM(coi.quantity) AS units,
                GROUP_CONCAT(CONCAT(coi.quantity, '× ', coi.item_name) ORDER BY coi.id SEPARATOR ', ') AS summary
         FROM customer_orders co
         JOIN customer_order_items coi ON coi.order_id = co.id
         WHERE co.code IN ($placeholders)
            OR co.phone IN (SELECT phone FROM customer_orders WHERE code IN ($placeholders))
         GROUP BY co.id
         ORDER BY co.created_at DESC
         LIMIT 200",
        str_repeat('s', count($codes) * 2),
        array_merge($codes, $codes)
    );
}

// 0917 ••• 4567
function mask_phone(string $phone): string {
    return strlen($phone) === 11 ? substr($phone, 0, 4) . ' ••• ' . substr($phone, -4) : '•••';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        flash('error', 'Your session expired. Please try again.');
        redirect('order_status.php');
    }
    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
    $action = $_POST['action'] ?? '';

    if ($action === 'lookup') {
        $order = db_one('SELECT code, phone FROM customer_orders WHERE code = ?', 's', [$code]);
        $phone = normalize_phone((string) ($_POST['phone'] ?? ''));
        if (!$order || $phone === null || !hash_equals($order['phone'], $phone)) {
            flash('error', "We couldn't find a reservation with that code and mobile number.");
            redirect('order_status.php?code=' . urlencode($code));
        }
        remember_order($order['code']);
        redirect('order_status.php?code=' . urlencode($order['code']));
    }

    if ($action === 'forget') {
        unset($_SESSION['my_orders']);
        flash('success', 'Done. This device no longer shows your reservations.');
        redirect('order_status.php');
    }

    if ($action === 'cancel' && in_array($code, array_column(visible_orders($myOrders), 'code'), true)) {
        $cancelled = db_exec(
            "UPDATE customer_orders SET status = 'cancelled', cancel_reason = 'Cancelled by customer' WHERE code = ? AND status = 'pending'",
            's',
            [$code]
        );
        flash($cancelled ? 'success' : 'error', $cancelled
            ? 'Your reservation was cancelled. The items are back on our shelves.'
            : 'This reservation can no longer be cancelled online. Please call the store.');
        redirect('order_status.php?code=' . urlencode($code));
    }

    redirect('order_status.php');
}

$history = visible_orders($myOrders);
$code = strtoupper(trim((string) ($_GET['code'] ?? '')));
$order = null;
$lines = [];
if ($code !== '' && in_array($code, array_column($history, 'code'), true)) {
    $order = db_one('SELECT * FROM customer_orders WHERE code = ?', 's', [$code]);
    if ($order) {
        $lines = db_all('SELECT item_name, unit, quantity, regular_price, discount_percent, unit_price, line_total FROM customer_order_items WHERE order_id = ? ORDER BY id', 'i', [$order['id']]);
    }
}

// Groups for the history filter, and a few totals for the summary.
$historyGroup = fn (array $o) => in_array($o['status'], ['pending', 'ready'], true) ? 'open' : $o['status'];
$groupCounts = array_count_values(array_map($historyGroup, $history));
$picked = array_filter($history, fn ($o) => $o['status'] === 'completed');
$spent = array_sum(array_column($picked, 'total'));
$saved = array_sum(array_column($picked, 'savings'));
$phones = array_values(array_unique(array_column($history, 'phone')));

$flashes = pull_flashes();
render_store_head('Track your reservation — ' . $s['store_name'], 'page-quiet');
render_store_header(false);
?>

<main class="status-wrap">
    <?php foreach ($flashes as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($order):
        [$statusLabel, $statusTone, $statusIcon] = order_status_meta($order['status']);
        $steps = ['pending' => 1, 'ready' => 2, 'completed' => 3][$order['status']] ?? 0;
        ?>
        <section class="status-card">
            <div class="order-top">
                <div>
                    <p class="eyebrow" style="margin:0">Reservation</p>
                    <div class="order-code"><?= e($order['code']) ?></div>
                </div>
                <span class="status-chip <?= $statusTone ?>"><i class="fa-solid <?= $statusIcon ?>"></i> <?= e($statusLabel) ?></span>
            </div>

            <?php if ($order['status'] === 'cancelled'): ?>
                <p class="flash flash-error" style="margin-top:16px">This reservation was cancelled<?= $order['cancel_reason'] ? ': ' . e($order['cancel_reason']) : '' ?>.</p>
            <?php else: ?>
                <ol class="timeline" aria-label="Order progress">
                    <li class="<?= $steps >= 1 ? 'done' : '' ?>">Received</li>
                    <li class="<?= $steps >= 2 ? 'done' : '' ?>">Ready for pickup</li>
                    <li class="<?= $steps >= 3 ? 'done' : '' ?>">Picked up</li>
                </ol>
                <?php if ($order['status'] === 'ready'): ?>
                    <p class="flash flash-success">Your items are packed and waiting at the counter. Show your code when you arrive.</p>
                <?php endif; ?>
            <?php endif; ?>

            <dl class="order-facts">
                <div><dt>Pickup</dt><dd><?= e(pickup_label($order['pickup_date'], $order['pickup_time'])) ?></dd></div>
                <div><dt>Name</dt><dd><?= e($order['customer_name']) ?></dd></div>
                <div><dt>Payment</dt><dd><i class="fa-solid <?= e(payment_icon($order['payment_method'])) ?>"></i> <?= e(payment_label($order['payment_method'])) ?></dd></div>
                <div><dt>Total to pay</dt><dd><?= peso($order['total']) ?></dd></div>
            </dl>
            <?php if (in_array($order['status'], ['pending', 'ready'], true)): ?>
                <p class="pay-note"><?= e(payment_instructions($order['payment_method'])) ?></p>
            <?php endif; ?>

            <table class="order-lines">
                <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Total</th></tr></thead>
                <tbody>
                <?php foreach ($lines as $l): ?>
                    <tr>
                        <td><?= e($l['item_name']) ?><?php if ((int) $l['discount_percent']): ?> <span class="deal"><?= (int) $l['discount_percent'] ?>% off</span><?php endif; ?></td>
                        <td class="num"><?= (int) $l['quantity'] ?> <?= e($l['unit']) ?></td>
                        <td class="num"><?= peso($l['unit_price']) ?><?php if ((int) $l['discount_percent']): ?><br><s style="color:var(--muted);font-size:12px"><?= peso($l['regular_price']) ?></s><?php endif; ?></td>
                        <td class="num"><strong><?= peso($l['line_total']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ((float) $order['savings'] > 0): ?>
                <p style="margin:12px 0 0;color:var(--deal);font-weight:800">You saved <?= peso($order['savings']) ?> by rescuing food close to its best-before date.</p>
            <?php endif; ?>

            <?php if ($order['status'] === 'pending'): ?>
                <form method="POST" style="margin-top:18px" onsubmit="return confirm('Cancel this reservation?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="code" value="<?= e($order['code']) ?>">
                    <button type="submit" class="btn btn-light"><i class="fa-solid fa-xmark"></i> Cancel reservation</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="status-card">
        <h1><?= $order ? 'Track another reservation' : 'Track your reservation' ?></h1>
        <p>Enter the code you received and the mobile number you used.</p>
        <form method="POST" class="lookup">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="lookup">
            <label>Reservation code<input type="text" name="code" value="<?= e($order ? '' : $code) ?>" placeholder="LG-XXXXXX" required autocomplete="off" style="text-transform:uppercase"></label>
            <label>Mobile number<input type="tel" name="phone" placeholder="0917 123 4567" required autocomplete="tel"></label>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Find</button>
        </form>
    </section>

    <section class="status-card history-card" id="history" aria-labelledby="historyTitle">
        <?php if ($history): ?>
            <div class="history-head">
                <div>
                    <p class="eyebrow" style="margin:0">Your orders</p>
                    <h2 id="historyTitle">All your reservations</h2>
                    <p class="history-sub">Every order for <?= e(implode(', ', array_map('mask_phone', $phones))) ?>, newest first.</p>
                </div>
                <dl class="history-stats">
                    <div><dt>Orders</dt><dd><?= count($history) ?></dd></div>
                    <div><dt>Picked up</dt><dd><?= peso($spent, 0) ?></dd></div>
                    <?php if ($saved > 0): ?><div class="saved"><dt>You saved</dt><dd><?= peso($saved, 0) ?></dd></div><?php endif; ?>
                </dl>
            </div>

            <?php if (count($groupCounts) > 1): ?>
                <div class="chips history-filter" role="toolbar" aria-label="Filter your orders">
                    <button type="button" class="chip is-active" data-history-filter="">All <b><?= count($history) ?></b></button>
                    <?php foreach (['open' => 'Open', 'completed' => 'Picked up', 'cancelled' => 'Cancelled'] as $group => $label): ?>
                        <?php if (!empty($groupCounts[$group])): ?>
                            <button type="button" class="chip" data-history-filter="<?= $group ?>"><?= $label ?> <b><?= $groupCounts[$group] ?></b></button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <ul class="history-list">
                <?php foreach ($history as $h): [$label, $tone, $icon] = order_status_meta($h['status']); ?>
                    <li data-history-status="<?= e($historyGroup($h)) ?>" class="<?= $order && $order['code'] === $h['code'] ? 'is-current' : '' ?>">
                        <a href="order_status.php?code=<?= urlencode($h['code']) ?>">
                            <div class="h-main">
                                <div class="h-top">
                                    <span class="h-code"><?= e($h['code']) ?></span>
                                    <?php if ($order && $order['code'] === $h['code']): ?><span class="h-viewing">Viewing</span><?php endif; ?>
                                </div>
                                <div class="h-items"><?= e(mb_strimwidth((string) $h['summary'], 0, 120, '…')) ?></div>
                                <div class="h-meta">
                                    <span><i class="fa-regular fa-calendar"></i> Placed <?= date('M j, Y', strtotime($h['created_at'])) ?></span>
                                    <span><i class="fa-solid fa-bag-shopping"></i> Pickup <?= e(pickup_label($h['pickup_date'], $h['pickup_time'])) ?></span>
                                    <span><i class="fa-solid <?= e(payment_icon($h['payment_method'])) ?>"></i> <?= e(payment_label($h['payment_method'])) ?></span>
                                </div>
                            </div>
                            <div class="h-side">
                                <strong class="h-total"><?= peso($h['total']) ?></strong>
                                <span class="status-chip <?= $tone ?>"><i class="fa-solid <?= $icon ?>"></i> <?= e($label) ?></span>
                                <?php if ((float) $h['savings'] > 0): ?><span class="h-saved">Saved <?= peso($h['savings']) ?></span><?php endif; ?>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form method="POST" class="history-forget" onsubmit="return confirm('Hide your reservations on this device? You can find them again with a code and your mobile number.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="forget">
                <span>Using a shared phone or computer?</span>
                <button type="submit"><i class="fa-solid fa-user-lock"></i> Forget my orders on this device</button>
            </form>
        <?php else: ?>
            <div class="history-empty">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <h2 id="historyTitle">Your orders will show up here</h2>
                <p>Reserve something, or find a reservation above with its code and mobile number. Every order you've made with that number will be listed here, open and past.</p>
                <a class="btn btn-primary" href="front_page.php#shop"><i class="fa-solid fa-basket-shopping"></i> Start shopping</a>
            </div>
        <?php endif; ?>
    </section>

    <p class="back-link"><a href="front_page.php#shop"><i class="fa-solid fa-arrow-left"></i> Back to the store</a></p>
</main>

<?php render_store_footer(); ?>
<script src="<?= asset('store.js') ?>"></script>
</body>
</html>
