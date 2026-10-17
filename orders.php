<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/store.php';

$user = require_login();
$canManage = can_manage($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $code = (string) db_value('SELECT code FROM customer_orders WHERE id = ?', 'i', [$orderId]);

    if ($code === '') {
        flash('error', 'Order not found.');
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'ready') {
        $done = db_exec("UPDATE customer_orders SET status = 'ready', handled_by = ? WHERE id = ? AND status = 'pending'", 'ii', [$user['id'], $orderId]);
        flash($done ? 'success' : 'error', $done ? "$code is ready for pickup." : "$code can't be marked ready from its current status.");
    }

    if ($action === 'complete') {
        try {
            db_transaction(function () use ($orderId, $user, $code) {
                $order = db_one('SELECT status FROM customer_orders WHERE id = ? FOR UPDATE', 'i', [$orderId]);
                if (!in_array($order['status'] ?? '', ['pending', 'ready'], true)) {
                    throw new RuntimeException("$code is already " . ($order['status'] ?? 'closed') . '.');
                }
                $lines = db_all('SELECT food_item_id, item_name, unit, quantity FROM customer_order_items WHERE order_id = ?', 'i', [$orderId]);
                foreach ($lines as $l) {
                    // Stock is only deducted now, when the customer actually takes the items.
                    $ok = db_exec(
                        'UPDATE food_items SET quantity = quantity - ? WHERE id = ? AND quantity >= ?',
                        'iii',
                        [$l['quantity'], $l['food_item_id'], $l['quantity']]
                    );
                    if ($ok !== 1) {
                        $have = (int) db_value('SELECT quantity FROM food_items WHERE id = ?', 'i', [$l['food_item_id']]);
                        throw new RuntimeException("Not enough \"{$l['item_name']}\" in stock (have $have, order needs {$l['quantity']}). Update the stock or cancel the order.");
                    }
                }
                db_exec(
                    "UPDATE customer_orders SET status = 'completed', completed_at = NOW(), handled_by = ? WHERE id = ?",
                    'ii',
                    [$user['id'], $orderId]
                );
            });
            flash('success', "$code picked up. Stock has been deducted.");
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
    }

    if ($action === 'cancel') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === 'Other') {
            $reason = trim((string) ($_POST['reason_other'] ?? '')) ?: 'Other';
        }
        $reason = mb_substr($reason !== '' ? $reason : 'Cancelled by store', 0, 255);
        $done = db_exec(
            "UPDATE customer_orders SET status = 'cancelled', cancel_reason = ?, handled_by = ? WHERE id = ? AND status IN ('pending', 'ready')",
            'sii',
            [$reason, $user['id'], $orderId]
        );
        flash($done ? 'success' : 'error', $done ? "$code cancelled. Its items are available again." : "$code can't be cancelled from its current status.");
    }

    redirect($_SERVER['REQUEST_URI']);
}

$tabs = [
    'open' => 'To do',
    'pending' => 'New',
    'ready' => 'Ready',
    'completed' => 'Picked up',
    'cancelled' => 'Cancelled',
    'all' => 'All',
];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'open';
$q = trim((string) ($_GET['q'] ?? ''));

$where = [];
$types = '';
$params = [];
$where[] = match ($tab) {
    'open' => "co.status IN ('pending', 'ready')",
    'all' => '1 = 1',
    default => 'co.status = ?',
};
if (!in_array($tab, ['open', 'all'], true)) {
    $types .= 's';
    $params[] = $tab;
}
if ($q !== '') {
    $digits = preg_replace('/\D+/', '', $q);
    $where[] = '(co.code LIKE ? OR co.customer_name LIKE ?' . ($digits !== '' ? ' OR co.phone LIKE ?' : '') . ')';
    $like = '%' . $q . '%';
    $types .= 'ss';
    array_push($params, $like, $like);
    if ($digits !== '') {
        $types .= 's';
        $params[] = '%' . $digits . '%';
    }
}
$orderBy = match ($tab) {
    'open', 'pending', 'ready' => 'co.pickup_date ASC, co.pickup_time ASC, co.id ASC',
    'completed' => 'co.completed_at DESC',
    default => 'co.created_at DESC',
};

$orders = db_all(
    "SELECT co.*, u.username AS handler, SUM(coi.quantity) AS units,
            GROUP_CONCAT(CONCAT(coi.quantity, '× ', coi.item_name) ORDER BY coi.id SEPARATOR ', ') AS summary
     FROM customer_orders co
     JOIN customer_order_items coi ON coi.order_id = co.id
     LEFT JOIN users u ON u.id = co.handled_by
     WHERE " . implode(' AND ', $where) . "
     GROUP BY co.id
     ORDER BY $orderBy
     LIMIT 150",
    $types,
    $params
);

// Line items (with current stock) for the detail view.
$linesByOrder = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_all(
        "SELECT coi.*, fi.quantity AS stock, fi.archived_at
         FROM customer_order_items coi JOIN food_items fi ON fi.id = coi.food_item_id
         WHERE coi.order_id IN ($placeholders) ORDER BY coi.id",
        str_repeat('i', count($ids)),
        $ids
    ) as $l) {
        $linesByOrder[$l['order_id']][] = $l;
    }
}

$counts = db_one(
    "SELECT COALESCE(SUM(status = 'pending'), 0) AS pending,
            COALESCE(SUM(status = 'ready'), 0) AS ready,
            COALESCE(SUM(status IN ('pending', 'ready') AND pickup_date = CURDATE()), 0) AS due_today,
            COALESCE(SUM(status IN ('pending', 'ready') AND TIMESTAMP(pickup_date, pickup_time) < NOW()), 0) AS late,
            COALESCE(SUM(status = 'completed' AND completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS completed_month,
            COALESCE(SUM(CASE WHEN status = 'completed' AND completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN total END), 0) AS sales_month
     FROM customer_orders"
);
// Near-expiry stock that was sold through deals instead of becoming waste (valued at cost).
$rescued = db_one(
    "SELECT COALESCE(SUM(coi.quantity), 0) AS units, COALESCE(SUM(coi.quantity * COALESCE(fi.purchase_price, 0)), 0) AS cost_value
     FROM customer_order_items coi
     JOIN customer_orders co ON co.id = coi.order_id
     JOIN food_items fi ON fi.id = coi.food_item_id
     WHERE co.status = 'completed' AND coi.discount_percent > 0 AND co.completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
);

// Sales this month by mode of payment, e.g. "Cash ₱1,200 · Online payment ₱450".
$payMix = db_all(
    "SELECT payment_method, COUNT(*) AS n, SUM(total) AS amount
     FROM customer_orders
     WHERE status = 'completed' AND completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
     GROUP BY payment_method ORDER BY amount DESC"
);

$now = date('Y-m-d H:i');

render_shell_start($user, 'Online Orders', 'orders');
page_header(
    'Online Orders',
    'Pickup reservations from the store website. Reserved items are held; stock is deducted when the order is picked up.',
    '<a class="btn" href="front_page.php" target="_blank" rel="noopener"><i class="fa-solid fa-store"></i> View storefront</a>'
);
?>

<div class="stats" style="margin-bottom:18px">
    <a class="stat" href="orders.php?tab=pending">
        <span class="stat-icon tone-amber"><i class="fa-solid fa-inbox"></i></span>
        <span><div class="stat-label">New</div><div class="stat-value"><?= (int) $counts['pending'] ?></div><div class="stat-sub">Waiting to be packed</div></span>
    </a>
    <a class="stat" href="orders.php?tab=ready">
        <span class="stat-icon tone-blue"><i class="fa-solid fa-bag-shopping"></i></span>
        <span><div class="stat-label">Ready</div><div class="stat-value"><?= (int) $counts['ready'] ?></div><div class="stat-sub"><?= (int) $counts['due_today'] ?> due today<?= (int) $counts['late'] ? ' · ' . (int) $counts['late'] . ' late' : '' ?></div></span>
    </a>
    <a class="stat" href="orders.php?tab=completed">
        <span class="stat-icon tone-green"><i class="fa-solid fa-peso-sign"></i></span>
        <span><div class="stat-label">Picked up this month</div><div class="stat-value"><?= peso($counts['sales_month'], 0) ?></div><div class="stat-sub"><?= (int) $counts['completed_month'] ?> order<?= (int) $counts['completed_month'] === 1 ? '' : 's' ?></div></span>
    </a>
    <div class="stat">
        <span class="stat-icon tone-blue"><i class="fa-solid fa-wallet"></i></span>
        <span>
            <div class="stat-label">Paid by (this month)</div>
            <?php if ($payMix): ?>
                <?php foreach (array_slice($payMix, 0, 3) as $pm): ?>
                    <div class="stat-sub"><i class="fa-solid <?= e(payment_icon($pm['payment_method'])) ?>"></i> <?= e(payment_label($pm['payment_method'])) ?>: <strong><?= peso($pm['amount'], 0) ?></strong> (<?= (int) $pm['n'] ?>)</div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="stat-sub">No picked-up orders yet</div>
            <?php endif; ?>
        </span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-teal"><i class="fa-solid fa-leaf"></i></span>
        <span><div class="stat-label">Rescued from waste</div><div class="stat-value"><?= peso($rescued['cost_value'], 0) ?></div><div class="stat-sub"><?= (int) $rescued['units'] ?> near-expiry units sold this month</div></span>
    </div>
</div>

<form class="toolbar" method="GET">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="search-field">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Code, name or phone…" aria-label="Search orders">
    </div>
    <?php foreach ($tabs as $key => $label):
        $n = match ($key) { 'open' => (int) $counts['pending'] + (int) $counts['ready'], 'pending' => (int) $counts['pending'], 'ready' => (int) $counts['ready'], default => null };
        ?>
        <a class="btn btn-sm <?= $tab === $key ? 'btn-primary' : '' ?>" href="orders.php?<?= e(http_build_query(array_filter(['tab' => $key, 'q' => $q]))) ?>"><?= e($label) ?><?= $n ? " ($n)" : '' ?></a>
    <?php endforeach; ?>
</form>

<div class="table-card">
    <?php if (!$orders): ?>
        <div class="empty">
            <i class="fa-solid fa-basket-shopping"></i>
            <strong><?= $tab === 'open' && $q === '' ? 'No reservations waiting' : 'No orders here' ?></strong>
            New pickup reservations from the storefront will appear here.
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Customer</th><th>Pickup</th><th class="col-item">Items</th><th class="text-right">Total</th><th>Payment</th><th>Status</th><th class="center">Action</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $o):
                    [$statusLabel, $statusTone] = order_status_meta($o['status']);
                    $isOpen = in_array($o['status'], ['pending', 'ready'], true);
                    $pickupAt = $o['pickup_date'] . ' ' . $o['pickup_time'];
                    $late = $isOpen && $pickupAt < $now;
                    $lines = $linesByOrder[$o['id']] ?? [];
                    $shortage = $isOpen && array_filter($lines, fn ($l) => (int) $l['stock'] < (int) $l['quantity'] || $l['archived_at'] !== null);
                    $detail = [
                        'code' => $o['code'],
                        'status' => $statusLabel,
                        'name' => $o['customer_name'],
                        'phone' => format_phone($o['phone']),
                        'email' => $o['email'],
                        'pickup' => pickup_label($o['pickup_date'], $o['pickup_time']),
                        'notes' => $o['notes'],
                        'payment' => payment_label($o['payment_method']),
                        'total' => peso($o['total']),
                        'savings' => (float) $o['savings'] > 0 ? peso($o['savings']) : null,
                        'created' => date('M j, Y g:i A', strtotime($o['created_at'])),
                        'handler' => $o['handler'],
                        'cancel_reason' => $o['cancel_reason'],
                        'lines' => array_map(fn ($l) => [
                            'name' => $l['item_name'],
                            'qty' => (int) $l['quantity'] . ' ' . $l['unit'],
                            'price' => peso($l['unit_price']),
                            'discount' => (int) $l['discount_percent'],
                            'total' => peso($l['line_total']),
                            'warning' => !$isOpen ? null : ($l['archived_at'] !== null
                                ? 'Item is archived (expired or removed)'
                                : ((int) $l['stock'] < (int) $l['quantity'] ? 'Only ' . (int) $l['stock'] . ' in stock' : null)),
                        ], $lines),
                    ];
                    ?>
                    <tr>
                        <td class="nowrap"><span class="sku"><?= e($o['code']) ?></span><div class="cell-sub">Placed <?= date('M j, g:i A', strtotime($o['created_at'])) ?></div></td>
                        <td>
                            <div class="cell-title"><?= e($o['customer_name']) ?></div>
                            <div class="cell-sub"><a href="tel:<?= e($o['phone']) ?>"><?= e(format_phone($o['phone'])) ?></a></div>
                        </td>
                        <td class="nowrap">
                            <div class="cell-title"><?= e(pickup_label($o['pickup_date'], $o['pickup_time'])) ?></div>
                            <?php if ($late): ?><span class="chip chip-red">Late</span><?php elseif ($isOpen && $o['pickup_date'] === date('Y-m-d')): ?><span class="chip chip-amber">Today</span><?php endif; ?>
                        </td>
                        <td class="col-item">
                            <div style="max-width:320px"><?= e(mb_strimwidth((string) $o['summary'], 0, 90, '…')) ?></div>
                            <?php if ($shortage): ?><div class="cell-sub" style="color:var(--red-fg)"><i class="fa-solid fa-triangle-exclamation"></i> Not enough stock for some items</div><?php endif; ?>
                        </td>
                        <td class="text-right num">
                            <span class="strong"><?= peso($o['total']) ?></span>
                            <?php if ((float) $o['savings'] > 0): ?><div class="cell-sub">saved <?= peso($o['savings']) ?></div><?php endif; ?>
                        </td>
                        <td class="nowrap"><span class="chip chip-gray"><i class="fa-solid <?= e(payment_icon($o['payment_method'])) ?>"></i> <?= e(payment_label($o['payment_method'])) ?></span></td>
                        <td><span class="chip chip-<?= $statusTone ?>"><?= e($statusLabel) ?></span></td>
                        <td class="center nowrap">
                            <?php if ($canManage && $o['status'] === 'pending'): ?>
                                <form method="POST" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="ready">
                                    <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-box"></i> Mark ready</button>
                                </form>
                            <?php elseif ($canManage && $o['status'] === 'ready'): ?>
                                <form method="POST" style="display:inline" data-confirm="Mark <?= e($o['code']) ?> as picked up and paid (<?= e(payment_label($o['payment_method'])) ?>)? Stock will be deducted.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="complete">
                                    <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-check"></i> Picked up</button>
                                </form>
                            <?php endif; ?>
                            <div class="menu-wrap">
                                <button type="button" class="kebab" data-menu aria-label="More actions for <?= e($o['code']) ?>"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                <div class="menu">
                                    <button type="button" class="menu-item" data-order='<?= e(json_encode($detail)) ?>'><i class="fa-solid fa-eye"></i> View details</button>
                                    <?php if ($canManage && $o['status'] === 'pending'): ?>
                                        <form method="POST" data-confirm="Mark <?= e($o['code']) ?> as picked up and paid? Stock will be deducted.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="complete">
                                            <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                                            <button type="submit" class="menu-item"><i class="fa-solid fa-check"></i> Picked up now</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canManage && $isOpen): ?>
                                        <div class="menu-sep"></div>
                                        <button type="button" class="menu-item danger" data-cancel-order="<?= (int) $o['id'] ?>" data-code="<?= e($o['code']) ?>"><i class="fa-solid fa-ban"></i> Cancel order</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal" id="orderModal" role="dialog" aria-modal="true" aria-labelledby="orderTitle">
    <div class="modal-card">
        <div class="modal-head">
            <h2 id="orderTitle">Order</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="orderBody"></div>
        <div class="modal-foot"><button type="button" class="btn" data-close-modal>Close</button></div>
    </div>
</div>

<div class="modal" id="cancelModal" role="dialog" aria-modal="true" aria-labelledby="cancelTitle">
    <form class="modal-card sm" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="order_id" id="cancelId">
        <div class="modal-head">
            <h2 id="cancelTitle">Cancel order</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p class="muted" style="margin-top:0">The reserved items go back to the storefront right away.</p>
            <div class="field">
                <label for="cancelReason">Reason</label>
                <select class="select" name="reason" id="cancelReason">
                    <option>Customer didn't pick up</option>
                    <option>Customer asked to cancel</option>
                    <option>Item out of stock or damaged</option>
                    <option>Other</option>
                </select>
            </div>
            <div class="field hidden" id="cancelOtherField">
                <label for="cancelOther">Describe the reason</label>
                <input class="input" type="text" name="reason_other" id="cancelOther" maxlength="200">
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn" data-close-modal>Keep order</button>
            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-ban"></i> Cancel order</button>
        </div>
    </form>
</div>

<?php ob_start(); ?>
<script>
(function () {
    const closeMenus = () => document.querySelectorAll('.menu.open').forEach((m) => m.classList.remove('open'));
    const row = (label, value) => value ? `<div><dt>${label}</dt><dd>${value}</dd></div>` : '';

    document.addEventListener('click', (e) => {
        const view = e.target.closest('[data-order]');
        if (view) {
            const o = JSON.parse(view.dataset.order);
            closeMenus();
            document.getElementById('orderTitle').textContent = `${o.code} · ${o.status}`;
            document.getElementById('orderBody').innerHTML = `
                <dl class="order-detail">
                    ${row('Customer', escapeHtml(o.name))}
                    ${row('Mobile', `<a href="tel:${escapeHtml(o.phone.replace(/\s/g, ''))}">${escapeHtml(o.phone)}</a>`)}
                    ${row('Email', o.email ? `<a href="mailto:${escapeHtml(o.email)}">${escapeHtml(o.email)}</a>` : '')}
                    ${row('Pickup', escapeHtml(o.pickup))}
                    ${row('Payment', escapeHtml(o.payment))}
                    ${row('Placed', escapeHtml(o.created))}
                    ${row('Handled by', o.handler ? escapeHtml(o.handler) : '')}
                    ${row('Cancel reason', o.cancel_reason ? escapeHtml(o.cancel_reason) : '')}
                </dl>
                ${o.notes ? `<div class="alert alert-info"><i class="fa-solid fa-note-sticky"></i><span>${escapeHtml(o.notes)}</span></div>` : ''}
                <table class="data-table" style="margin-bottom:14px">
                    <thead><tr><th>Item</th><th class="center">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead>
                    <tbody>${o.lines.map((l) => `<tr>
                        <td><div class="cell-title">${escapeHtml(l.name)}</div>
                            ${l.discount ? `<span class="chip chip-purple">${l.discount}% off</span>` : ''}
                            ${l.warning ? `<div class="cell-sub" style="color:var(--red-fg)"><i class="fa-solid fa-triangle-exclamation"></i> ${escapeHtml(l.warning)}</div>` : ''}</td>
                        <td class="center">${escapeHtml(l.qty)}</td>
                        <td class="text-right num">${escapeHtml(l.price)}</td>
                        <td class="text-right num strong">${escapeHtml(l.total)}</td></tr>`).join('')}</tbody>
                </table>
                <dl class="order-detail">
                    ${row('Customer saved', o.savings ? escapeHtml(o.savings) : '')}
                    ${row('Total to collect', `<strong>${escapeHtml(o.total)}</strong>`)}
                </dl>`;
            openModal('orderModal');
            return;
        }
        const cancel = e.target.closest('[data-cancel-order]');
        if (cancel) {
            closeMenus();
            document.getElementById('cancelId').value = cancel.dataset.cancelOrder;
            document.getElementById('cancelTitle').textContent = `Cancel ${cancel.dataset.code}`;
            openModal('cancelModal');
        }
    });

    const reason = document.getElementById('cancelReason');
    reason?.addEventListener('change', () => {
        const other = reason.value === 'Other';
        document.getElementById('cancelOtherField').classList.toggle('hidden', !other);
        document.getElementById('cancelOther').required = other;
    });
})();
</script>
<?php render_shell_end(ob_get_clean()); ?>
