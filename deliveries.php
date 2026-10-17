<?php
// Supplier transactions: schedule deliveries, track them on the way, and confirm when they arrive.
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$canManage = can_manage($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['delivery_id'] ?? 0);
    $back = $_SERVER['REQUEST_URI'] ?? 'deliveries.php';

    if (!$canManage) {
        flash('error', 'Only admins and managers can schedule or update deliveries.');
        redirect($back);
    }

    if ($action === 'save') {
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $supplier = db_one('SELECT id, name FROM suppliers WHERE id = ?', 'i', [$supplierId]);
        $items = trim((string) ($_POST['items'] ?? ''));
        $date = trim((string) ($_POST['expected_date'] ?? ''));
        $time = trim((string) ($_POST['expected_time'] ?? ''));
        $amountRaw = trim((string) ($_POST['amount'] ?? ''));
        $amount = $amountRaw !== '' ? round((float) $amountRaw, 2) : null;
        $notes = trim((string) ($_POST['notes'] ?? ''));

        $error = match (true) {
            !$supplier => 'Please choose a supplier.',
            $items === '' || mb_strlen($items) > 2000 => 'Describe what will be delivered (up to 2000 characters).',
            !valid_date($date) => 'Please enter a valid delivery date.',
            $time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) => 'Please enter a valid delivery time.',
            $amountRaw !== '' && (!is_numeric($amountRaw) || $amount < 0) => 'Amount must be a positive number.',
            mb_strlen($notes) > 500 => 'Notes can be at most 500 characters.',
            default => null,
        };
        if ($error) {
            flash('error', $error);
            redirect($back);
        }
        $time = $time !== '' ? $time : null;
        $notes = $notes !== '' ? $notes : null;

        if ($id > 0) {
            $old = db_one('SELECT * FROM supplier_deliveries WHERE id = ?', 'i', [$id]);
            if (!$old || !in_array($old['status'], ['scheduled', 'on_the_way'], true)) {
                flash('error', 'Only deliveries that have not arrived yet can be edited.');
                redirect($back);
            }
            db_exec(
                'UPDATE supplier_deliveries SET supplier_id = ?, supplier_name = ?, items = ?, amount = ?, expected_date = ?, expected_time = ?, notes = ? WHERE id = ?',
                'issdsssi',
                [$supplier['id'], $supplier['name'], $items, $amount, $date, $time, $notes, $id]
            );
            $changes = [];
            if ($old['expected_date'] !== $date || (string) $old['expected_time'] !== (string) $time) {
                $changes[] = 'Expected ' . delivery_when($old['expected_date'], $old['expected_time'], false) . ' → ' . delivery_when($date, $time, false);
            }
            if ((int) $old['supplier_id'] !== (int) $supplier['id']) {
                $changes[] = 'Supplier ' . $old['supplier_name'] . ' → ' . $supplier['name'];
            }
            if ((string) $old['amount'] !== (string) ($amount === null ? null : number_format($amount, 2, '.', ''))) {
                $changes[] = 'Amount ' . ($old['amount'] !== null ? peso($old['amount']) : 'none') . ' → ' . ($amount !== null ? peso($amount) : 'none');
            }
            if ($old['items'] !== $items) {
                $changes[] = 'Items updated';
            }
            log_delivery_event($id, 'rescheduled', $changes ? implode('; ', $changes) : 'Notes updated', $user['id']);
            flash('success', delivery_ref($id) . ' updated.');
        } else {
            db_exec(
                'INSERT INTO supplier_deliveries (supplier_id, supplier_name, items, amount, expected_date, expected_time, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                'issdsssi',
                [$supplier['id'], $supplier['name'], $items, $amount, $date, $time, $notes, $user['id']]
            );
            $id = db_last_id();
            log_delivery_event($id, 'scheduled', 'Expected ' . delivery_when($date, $time, false), $user['id']);
            flash('success', delivery_ref($id) . ' scheduled: ' . $supplier['name'] . ', ' . delivery_when($date, $time) . '.');
        }
        redirect($back);
    }

    $delivery = db_one('SELECT * FROM supplier_deliveries WHERE id = ?', 'i', [$id]);
    if (!$delivery) {
        flash('error', 'Delivery not found.');
        redirect($back);
    }
    $ref = delivery_ref($id);

    if ($action === 'on_the_way') {
        $done = db_exec("UPDATE supplier_deliveries SET status = 'on_the_way', shipped_at = NOW() WHERE id = ? AND status = 'scheduled'", 'i', [$id]);
        if ($done) {
            log_delivery_event($id, 'on_the_way', trim((string) ($_POST['note'] ?? '')), $user['id']);
        }
        flash($done ? 'success' : 'error', $done ? "$ref is on the way." : "$ref can't be marked on the way from its current status.");
    }

    if ($action === 'delivered') {
        $done = db_exec(
            "UPDATE supplier_deliveries SET status = 'delivered', delivered_at = NOW(), shipped_at = COALESCE(shipped_at, NOW())
             WHERE id = ? AND status IN ('scheduled', 'on_the_way')",
            'i',
            [$id]
        );
        if ($done) {
            log_delivery_event($id, 'delivered', trim((string) ($_POST['note'] ?? '')), $user['id']);
            flash('success', "$ref delivered by {$delivery['supplier_name']}. Remember to receive the stock into inventory.", [
                'label' => 'Receive stock',
                'href' => 'food_items.php?q=' . urlencode($delivery['supplier_name']),
            ]);
        } else {
            flash('error', "$ref can't be marked delivered from its current status.");
        }
    }

    if ($action === 'cancel') {
        $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
        $done = db_exec("UPDATE supplier_deliveries SET status = 'cancelled' WHERE id = ? AND status IN ('scheduled', 'on_the_way')", 'i', [$id]);
        if ($done) {
            log_delivery_event($id, 'cancelled', $reason !== '' ? $reason : null, $user['id']);
        }
        flash($done ? 'success' : 'error', $done ? "$ref cancelled." : "$ref can't be cancelled from its current status.");
    }

    if ($action === 'paid' || $action === 'unpaid') {
        $paid = $action === 'paid';
        $done = db_exec(
            'UPDATE supplier_deliveries SET payment_status = ?, paid_at = ' . ($paid ? 'NOW()' : 'NULL') . " WHERE id = ? AND status <> 'cancelled'",
            'si',
            [$action, $id]
        );
        if ($done) {
            log_delivery_event($id, $action, null, $user['id']);
        }
        flash($done ? 'success' : 'error', $done ? "$ref marked as " . ($paid ? 'paid.' : 'unpaid.') : "$ref can't be changed.");
    }

    redirect($back);
}

/* ---------- Listing ---------- */

$tabs = [
    'upcoming' => 'Upcoming',
    'scheduled' => 'Scheduled',
    'on_the_way' => 'On the way',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
    'all' => 'All',
];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'upcoming';
$q = trim((string) ($_GET['q'] ?? ''));
$supplierFilter = (int) ($_GET['supplier'] ?? 0);
$viewId = (int) ($_GET['view'] ?? 0);

$where = [match ($tab) {
    'upcoming' => "d.status IN ('scheduled', 'on_the_way')",
    'all' => '1 = 1',
    default => 'd.status = ?',
}];
$types = '';
$params = [];
if (!in_array($tab, ['upcoming', 'all'], true)) {
    $types .= 's';
    $params[] = $tab;
}
if ($supplierFilter > 0) {
    $where[] = 'd.supplier_id = ?';
    $types .= 'i';
    $params[] = $supplierFilter;
}
if ($q !== '') {
    $refId = preg_match('/^(?:DLV-?)?0*(\d+)$/i', $q, $m) ? (int) $m[1] : 0;
    $where[] = '(d.supplier_name LIKE ? OR d.items LIKE ? OR d.notes LIKE ? OR d.id = ?)';
    $like = '%' . $q . '%';
    $types .= 'sssi';
    array_push($params, $like, $like, $like, $refId);
}
$orderBy = match ($tab) {
    'delivered' => 'd.delivered_at DESC',
    'cancelled', 'all' => 'd.expected_date DESC, d.id DESC',
    default => "d.status = 'on_the_way' DESC, d.expected_date ASC, d.expected_time IS NULL, d.expected_time ASC",
};

$deliveries = db_all(
    "SELECT d.*, u.username AS created_by_name
     FROM supplier_deliveries d
     LEFT JOIN users u ON u.id = d.created_by
     WHERE " . implode(' AND ', $where) . "
     ORDER BY $orderBy
     LIMIT 200",
    $types,
    $params
);
// The delivery opened from a notification may be outside the current tab.
if ($viewId > 0 && !in_array($viewId, array_map('intval', array_column($deliveries, 'id')), true)) {
    $extra = db_one('SELECT d.*, u.username AS created_by_name FROM supplier_deliveries d LEFT JOIN users u ON u.id = d.created_by WHERE d.id = ?', 'i', [$viewId]);
    $viewOnly = $extra ? [$extra] : [];
} else {
    $viewOnly = [];
}

$eventsByDelivery = [];
$allIds = array_map('intval', array_column(array_merge($deliveries, $viewOnly), 'id'));
if ($allIds) {
    $placeholders = implode(',', array_fill(0, count($allIds), '?'));
    foreach (db_all(
        "SELECT e.delivery_id, e.event, e.note, e.created_at, u.username
         FROM supplier_delivery_events e LEFT JOIN users u ON u.id = e.user_id
         WHERE e.delivery_id IN ($placeholders) ORDER BY e.created_at, e.id",
        str_repeat('i', count($allIds)),
        $allIds
    ) as $ev) {
        $eventsByDelivery[(int) $ev['delivery_id']][] = $ev;
    }
}

function delivery_detail(array $d, array $events): array {
    [$statusLabel, $statusTone] = delivery_status_meta($d['status']);
    return [
        'id' => (int) $d['id'],
        'ref' => delivery_ref((int) $d['id']),
        'supplier_id' => (int) $d['supplier_id'],
        'supplier' => $d['supplier_name'],
        'items' => $d['items'],
        'amount' => $d['amount'],
        'amount_label' => $d['amount'] !== null ? peso($d['amount']) : null,
        'expected_date' => $d['expected_date'],
        'expected_time' => $d['expected_time'],
        'expected' => delivery_when($d['expected_date'], $d['expected_time']),
        'status' => $d['status'],
        'status_label' => $statusLabel,
        'status_tone' => $statusTone,
        'payment' => $d['payment_status'] === 'paid' ? 'Paid' . ($d['paid_at'] ? ' · ' . date('M j, g:i A', strtotime($d['paid_at'])) : '') : 'Unpaid',
        'notes' => $d['notes'],
        'created_by' => $d['created_by_name'],
        'events' => array_map(fn ($ev) => [
            'label' => DELIVERY_EVENTS[$ev['event']][0] ?? ucfirst($ev['event']),
            'icon' => DELIVERY_EVENTS[$ev['event']][1] ?? 'fa-circle',
            'event' => $ev['event'],
            'note' => $ev['note'],
            'when' => date('M j, Y g:i A', strtotime($ev['created_at'])),
            'by' => $ev['username'],
        ], $events),
    ];
}

$counts = delivery_counts();
$month = db_one(
    "SELECT COALESCE(SUM(status = 'delivered' AND delivered_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) AS delivered_month,
            COALESCE(SUM(CASE WHEN status = 'delivered' AND delivered_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN amount END), 0) AS delivered_amount,
            COALESCE(SUM(CASE WHEN status = 'delivered' AND payment_status = 'unpaid' THEN amount END), 0) AS unpaid_amount,
            COALESCE(SUM(status = 'delivered' AND payment_status = 'unpaid'), 0) AS unpaid_count
     FROM supplier_deliveries"
);
$suppliers = db_all('SELECT id, name FROM suppliers ORDER BY name');
$supplierName = $supplierFilter ? (string) db_value('SELECT name FROM suppliers WHERE id = ?', 'i', [$supplierFilter]) : '';
$today = date('Y-m-d');

render_shell_start($user, 'Deliveries', 'deliveries');
page_header(
    'Deliveries',
    'Supplier transactions: schedule deliveries, track them on the way, and confirm when they arrive.',
    $canManage ? '<button type="button" class="btn btn-primary" data-delivery-new><i class="fa-solid fa-plus"></i> Schedule delivery</button>' : ''
);
?>

<?php if ($counts['overdue'] > 0): ?>
    <div class="alert alert-error">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span><?= $counts['overdue'] ?> deliver<?= $counts['overdue'] === 1 ? 'y is' : 'ies are' ?> overdue. Call the supplier, then mark it on the way, delivered or cancelled.</span>
    </div>
<?php endif; ?>

<div class="stats" style="margin-bottom:18px">
    <a class="stat" href="deliveries.php?tab=scheduled">
        <span class="stat-icon tone-amber"><i class="fa-solid fa-calendar-day"></i></span>
        <span><div class="stat-label">Due today</div><div class="stat-value"><?= $counts['due_today'] ?></div><div class="stat-sub"><?= $counts['due_tomorrow'] ?> tomorrow<?= $counts['overdue'] ? ' · ' . $counts['overdue'] . ' overdue' : '' ?></div></span>
    </a>
    <a class="stat" href="deliveries.php?tab=on_the_way">
        <span class="stat-icon tone-blue"><i class="fa-solid fa-truck-fast"></i></span>
        <span><div class="stat-label">On the way</div><div class="stat-value"><?= $counts['on_the_way'] ?></div><div class="stat-sub"><?= $counts['scheduled'] ?> scheduled in total</div></span>
    </a>
    <a class="stat" href="deliveries.php?tab=delivered">
        <span class="stat-icon tone-green"><i class="fa-solid fa-circle-check"></i></span>
        <span><div class="stat-label">Delivered this month</div><div class="stat-value"><?= (int) $month['delivered_month'] ?></div><div class="stat-sub"><?= peso($month['delivered_amount'], 0) ?> in supplier transactions</div></span>
    </a>
    <div class="stat">
        <span class="stat-icon tone-red"><i class="fa-solid fa-file-invoice-dollar"></i></span>
        <span><div class="stat-label">Unpaid to suppliers</div><div class="stat-value"><?= peso($month['unpaid_amount'], 0) ?></div><div class="stat-sub"><?= (int) $month['unpaid_count'] ?> delivered, not yet paid</div></span>
    </div>
</div>

<form class="toolbar" method="GET">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="search-field">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Supplier, items or DLV number…" aria-label="Search deliveries">
    </div>
    <select class="select" name="supplier" aria-label="Filter by supplier" onchange="this.form.submit()">
        <option value="">All suppliers</option>
        <?php foreach ($suppliers as $sup): ?>
            <option value="<?= (int) $sup['id'] ?>" <?= $supplierFilter === (int) $sup['id'] ? 'selected' : '' ?>><?= e($sup['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php foreach ($tabs as $key => $label):
        $n = match ($key) { 'upcoming' => $counts['scheduled'] + $counts['on_the_way'], 'scheduled' => $counts['scheduled'], 'on_the_way' => $counts['on_the_way'], default => null };
        ?>
        <a class="btn btn-sm <?= $tab === $key ? 'btn-primary' : '' ?>" href="deliveries.php?<?= e(http_build_query(array_filter(['tab' => $key, 'q' => $q, 'supplier' => $supplierFilter ?: null]))) ?>"><?= e($label) ?><?= $n ? " ($n)" : '' ?></a>
    <?php endforeach; ?>
    <?php if ($q !== '' || $supplierFilter): ?><a href="deliveries.php?tab=<?= e($tab) ?>" class="btn btn-sm btn-ghost"><i class="fa-solid fa-xmark"></i> Clear</a><?php endif; ?>
</form>

<div class="table-card">
    <?php if (!$deliveries): ?>
        <div class="empty">
            <i class="fa-solid fa-truck-field"></i>
            <strong><?= $q !== '' || $supplierFilter ? 'No deliveries match' : 'No deliveries here' ?></strong>
            <?= $canManage && $suppliers ? 'Use "Schedule delivery" to record what a supplier will bring and when.' : '' ?>
            <?= $canManage && !$suppliers ? 'Add a supplier first, then schedule its deliveries here.' : '' ?>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Ref</th><th>Supplier</th><th class="col-item">Items</th><th>Expected</th><th class="text-right">Amount</th><th>Payment</th><th>Status</th><th class="center">Action</th></tr></thead>
                <tbody>
                <?php foreach ($deliveries as $d):
                    $did = (int) $d['id'];
                    [$statusLabel, $statusTone, $statusIcon] = delivery_status_meta($d['status']);
                    $isOpen = in_array($d['status'], ['scheduled', 'on_the_way'], true);
                    $overdue = $d['status'] === 'scheduled' && $d['expected_date'] < $today;
                    $detail = delivery_detail($d, $eventsByDelivery[$did] ?? []);
                    ?>
                    <tr id="delivery-<?= $did ?>">
                        <td class="nowrap"><span class="sku"><?= e(delivery_ref($did)) ?></span><div class="cell-sub">Added <?= date('M j', strtotime($d['created_at'])) ?></div></td>
                        <td>
                            <div class="cell-title"><?= e($d['supplier_name']) ?></div>
                            <?php if ($d['notes']): ?><div class="cell-sub"><i class="fa-regular fa-note-sticky"></i> <?= e(mb_strimwidth($d['notes'], 0, 50, '…')) ?></div><?php endif; ?>
                        </td>
                        <td class="col-item"><div style="max-width:300px"><?= e(mb_strimwidth($d['items'], 0, 110, '…')) ?></div></td>
                        <td class="nowrap">
                            <div class="cell-title"><?= e(delivery_when($d['expected_date'], $d['expected_time'])) ?></div>
                            <?php if ($overdue): ?><span class="chip chip-red">Overdue</span>
                            <?php elseif ($isOpen && $d['expected_date'] === $today): ?><span class="chip chip-amber">Today</span>
                            <?php elseif ($d['status'] === 'delivered' && $d['delivered_at']): ?><div class="cell-sub">Arrived <?= date('M j, g:i A', strtotime($d['delivered_at'])) ?></div>
                            <?php elseif ($d['status'] === 'on_the_way' && $d['shipped_at']): ?><div class="cell-sub">Left <?= date('M j, g:i A', strtotime($d['shipped_at'])) ?></div><?php endif; ?>
                        </td>
                        <td class="text-right num"><?= $d['amount'] !== null ? '<span class="strong">' . peso($d['amount']) . '</span>' : '<span class="muted">—</span>' ?></td>
                        <td><?= $d['status'] === 'cancelled' ? '<span class="muted">—</span>' : ($d['payment_status'] === 'paid' ? '<span class="chip chip-green">Paid</span>' : '<span class="chip chip-gray">Unpaid</span>') ?></td>
                        <td><span class="chip chip-<?= $statusTone ?>"><i class="fa-solid <?= $statusIcon ?>"></i> <?= e($statusLabel) ?></span></td>
                        <td class="center nowrap">
                            <?php if ($canManage && $d['status'] === 'scheduled'): ?>
                                <form method="POST" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="on_the_way">
                                    <input type="hidden" name="delivery_id" value="<?= $did ?>">
                                    <button type="submit" class="btn btn-sm" title="The supplier has left and is on the way"><i class="fa-solid fa-truck-fast"></i> On the way</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canManage && $isOpen): ?>
                                <form method="POST" style="display:inline" data-confirm="Mark <?= e(delivery_ref($did)) ?> from <?= e($d['supplier_name']) ?> as delivered?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delivered">
                                    <input type="hidden" name="delivery_id" value="<?= $did ?>">
                                    <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-check"></i> Delivered</button>
                                </form>
                            <?php endif; ?>
                            <div class="menu-wrap">
                                <button type="button" class="kebab" data-menu aria-label="More actions for <?= e(delivery_ref($did)) ?>"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                <div class="menu">
                                    <button type="button" class="menu-item" data-delivery-view='<?= e(json_encode($detail)) ?>'><i class="fa-solid fa-timeline"></i> View tracking</button>
                                    <?php if ($canManage && $isOpen): ?>
                                        <button type="button" class="menu-item" data-delivery-edit='<?= e(json_encode($detail)) ?>'><i class="fa-solid fa-pen"></i> Edit / reschedule</button>
                                    <?php endif; ?>
                                    <?php if ($canManage && $d['status'] !== 'cancelled'): ?>
                                        <form method="POST">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="<?= $d['payment_status'] === 'paid' ? 'unpaid' : 'paid' ?>">
                                            <input type="hidden" name="delivery_id" value="<?= $did ?>">
                                            <button type="submit" class="menu-item"><i class="fa-solid <?= $d['payment_status'] === 'paid' ? 'fa-rotate-left' : 'fa-peso-sign' ?>"></i> <?= $d['payment_status'] === 'paid' ? 'Mark as unpaid' : 'Mark as paid' ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($d['supplier_id']): ?>
                                        <a class="menu-item" href="deliveries.php?tab=all&amp;supplier=<?= (int) $d['supplier_id'] ?>"><i class="fa-solid fa-list"></i> All from this supplier</a>
                                    <?php endif; ?>
                                    <?php if ($canManage && $isOpen): ?>
                                        <div class="menu-sep"></div>
                                        <button type="button" class="menu-item danger" data-delivery-cancel="<?= $did ?>" data-ref="<?= e(delivery_ref($did)) ?>"><i class="fa-solid fa-ban"></i> Cancel delivery</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($supplierName !== ''): ?>
            <div class="table-foot"><span>Showing deliveries from <strong><?= e($supplierName) ?></strong></span></div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
<div class="modal" id="deliveryModal" role="dialog" aria-modal="true" aria-labelledby="deliveryTitle">
    <form class="modal-card" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="delivery_id" id="dlvId">
        <div class="modal-head">
            <h2 id="deliveryTitle">Schedule delivery</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <?php if (!$suppliers): ?>
                <div class="alert alert-warning"><i class="fa-solid fa-circle-info"></i><span>Add a supplier first on the <a href="suppliers.php">Suppliers</a> page.</span></div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="field span-2">
                    <label for="dlvSupplier">Supplier <span class="req">*</span></label>
                    <select class="select" name="supplier_id" id="dlvSupplier" required>
                        <option value="">Choose a supplier</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= (int) $sup['id'] ?>"><?= e($sup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field span-2">
                    <label for="dlvItems">What will be delivered <span class="req">*</span></label>
                    <textarea class="input" name="items" id="dlvItems" rows="3" maxlength="2000" required placeholder="e.g. 20 trays eggs, 10 boxes fresh milk, 5 bundles pechay"></textarea>
                </div>
                <div class="field">
                    <label for="dlvDate">Delivery date <span class="req">*</span></label>
                    <input class="input" type="date" name="expected_date" id="dlvDate" required>
                </div>
                <div class="field">
                    <label for="dlvTime">Expected time</label>
                    <input class="input" type="time" name="expected_time" id="dlvTime">
                </div>
                <div class="field">
                    <label for="dlvAmount">Amount (₱)</label>
                    <input class="input" type="number" name="amount" id="dlvAmount" min="0" step="0.01" placeholder="Invoice total">
                </div>
                <div class="field">
                    <label for="dlvNotes">Notes</label>
                    <input class="input" type="text" name="notes" id="dlvNotes" maxlength="500" placeholder="e.g. Driver: Mang Ben, 0917…">
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary" <?= $suppliers ? '' : 'disabled' ?>><i class="fa-solid fa-floppy-disk"></i> Save delivery</button>
        </div>
    </form>
</div>

<div class="modal" id="cancelDeliveryModal" role="dialog" aria-modal="true" aria-labelledby="cancelDeliveryTitle">
    <form class="modal-card sm" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="delivery_id" id="cancelDeliveryId">
        <div class="modal-head">
            <h2 id="cancelDeliveryTitle">Cancel delivery</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="cancelDeliveryReason">Reason</label>
                <input class="input" type="text" name="reason" id="cancelDeliveryReason" maxlength="255" placeholder="e.g. Supplier out of stock">
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn" data-close-modal>Keep delivery</button>
            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-ban"></i> Cancel delivery</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="modal" id="deliveryViewModal" role="dialog" aria-modal="true" aria-labelledby="deliveryViewTitle">
    <div class="modal-card">
        <div class="modal-head">
            <h2 id="deliveryViewTitle">Delivery</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="deliveryViewBody"></div>
        <div class="modal-foot"><button type="button" class="btn" data-close-modal>Close</button></div>
    </div>
</div>

<?php
// Delivery to open right away (from a notification, or "Schedule delivery" on the Suppliers page).
$autoView = null;
if ($viewId > 0) {
    foreach (array_merge($deliveries, $viewOnly) as $d) {
        if ((int) $d['id'] === $viewId) {
            $autoView = delivery_detail($d, $eventsByDelivery[$viewId] ?? []);
        }
    }
}
$autoNew = $canManage && isset($_GET['new']) ? ['supplier_id' => $supplierFilter] : null;
ob_start();
?>
<script>
(function () {
    const $ = (id) => document.getElementById(id);
    const closeMenus = () => document.querySelectorAll('.menu.open').forEach((m) => m.classList.remove('open'));
    const row = (label, value) => value ? `<div><dt>${label}</dt><dd>${value}</dd></div>` : '';
    const steps = ['scheduled', 'on_the_way', 'delivered'];

    function openForm(d) {
        closeMenus();
        $('deliveryTitle').textContent = d.id ? `Edit ${d.ref}` : 'Schedule delivery';
        $('dlvId').value = d.id || '';
        $('dlvSupplier').value = d.supplier_id ? String(d.supplier_id) : '';
        $('dlvItems').value = d.items || '';
        $('dlvDate').value = d.expected_date || localDatePlusDays(1);
        $('dlvTime').value = d.expected_time || '';
        $('dlvAmount').value = d.amount ?? '';
        $('dlvNotes').value = d.notes || '';
        openModal('deliveryModal');
    }

    function openView(d) {
        closeMenus();
        const reached = d.status === 'cancelled' ? -1 : steps.indexOf(d.status);
        $('deliveryViewTitle').textContent = `${d.ref} · ${d.status_label}`;
        $('deliveryViewBody').innerHTML = `
            ${d.status === 'cancelled'
                ? '<div class="alert alert-error"><i class="fa-solid fa-ban"></i><span>This delivery was cancelled.</span></div>'
                : `<ol class="track-steps">${['Scheduled', 'On the way', 'Delivered'].map((label, i) =>
                    `<li class="${i <= reached ? 'done' : ''}${i === reached ? ' current' : ''}">${label}</li>`).join('')}</ol>`}
            <dl class="order-detail">
                ${row('Supplier', escapeHtml(d.supplier))}
                ${row('Expected', escapeHtml(d.expected))}
                ${row('Amount', d.amount_label ? escapeHtml(d.amount_label) : '')}
                ${row('Payment', d.status !== 'cancelled' ? escapeHtml(d.payment) : '')}
                ${row('Scheduled by', d.created_by ? escapeHtml(d.created_by) : '')}
            </dl>
            <div class="alert alert-info"><i class="fa-solid fa-boxes-stacked"></i><span style="white-space:pre-line">${escapeHtml(d.items)}</span></div>
            ${d.notes ? `<p class="muted" style="margin-top:0"><i class="fa-regular fa-note-sticky"></i> ${escapeHtml(d.notes)}</p>` : ''}
            <h3 class="field-label" style="margin:6px 0 10px">Transaction log</h3>
            <ul class="event-log">${(d.events.length ? d.events : [{ label: 'Scheduled', icon: 'fa-calendar-plus', when: '', note: '' }]).map((ev) => `
                <li class="ev-${escapeHtml(ev.event || '')}">
                    <span class="ev-icon"><i class="fa-solid ${escapeHtml(ev.icon)}"></i></span>
                    <div><strong>${escapeHtml(ev.label)}</strong>${ev.note ? ` · ${escapeHtml(ev.note)}` : ''}
                        <div class="cell-sub">${escapeHtml(ev.when)}${ev.by ? ' · ' + escapeHtml(ev.by) : ''}</div></div>
                </li>`).join('')}</ul>`;
        openModal('deliveryViewModal');
    }

    document.querySelector('[data-delivery-new]')?.addEventListener('click', () => openForm({}));
    document.addEventListener('click', (e) => {
        const view = e.target.closest('[data-delivery-view]');
        if (view) { openView(JSON.parse(view.dataset.deliveryView)); return; }
        const edit = e.target.closest('[data-delivery-edit]');
        if (edit) { openForm(JSON.parse(edit.dataset.deliveryEdit)); return; }
        const cancel = e.target.closest('[data-delivery-cancel]');
        if (cancel) {
            closeMenus();
            $('cancelDeliveryId').value = cancel.dataset.deliveryCancel;
            $('cancelDeliveryTitle').textContent = `Cancel ${cancel.dataset.ref}`;
            $('cancelDeliveryReason').value = '';
            openModal('cancelDeliveryModal');
        }
    });

    const autoView = <?= json_encode($autoView, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const autoNew = <?= json_encode($autoNew, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    if (autoView) {
        openView(autoView);
        document.getElementById('delivery-' + autoView.id)?.classList.add('row-flash');
    } else if (autoNew) {
        openForm(autoNew);
    }
})();
</script>
<?php render_shell_end(ob_get_clean()); ?>
