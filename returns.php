<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = ($_POST['return_type'] ?? '') === 'supplier' ? 'supplier' : 'customer';
    $outcome = $type === 'supplier' ? 'sent_back' : (($_POST['outcome'] ?? '') === 'disposed' ? 'disposed' : 'restocked');
    $itemId = (int) ($_POST['food_item_id'] ?? 0);
    $qty = (int) ($_POST['quantity'] ?? 0);
    $date = trim((string) ($_POST['return_date'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $amountInput = trim((string) ($_POST['amount'] ?? ''));
    $amount = $amountInput === '' ? 0.0 : round((float) $amountInput, 2);

    $item = db_one('SELECT id, name, unit, quantity, purchase_price FROM food_items WHERE id = ? AND archived_at IS NULL', 'i', [$itemId]);
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    $error = null;

    if (!$item) {
        $error = 'Please choose an item.';
    } elseif ($qty < 1) {
        $error = 'Quantity must be at least 1.';
    } elseif ($type === 'supplier' && $qty > (int) $item['quantity']) {
        $error = 'You only have ' . (int) $item['quantity'] . ' ' . $item['unit'] . ' in stock to send back.';
    } elseif (!$dateObj || $dateObj->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
        $error = 'Return date must be a valid date that is not in the future.';
    } elseif ($amount < 0) {
        $error = 'Amount cannot be negative.';
    }

    if ($error) {
        flash('error', $error);
        $_SESSION['return_old'] = $_POST;
        redirect('returns.php' . ($itemId ? '?item=' . $itemId : ''));
    }

    $unitValue = (float) ($item['purchase_price'] ?? 0);
    $value = round($qty * $unitValue, 2);

    try {
        db_transaction(function () use ($type, $outcome, $itemId, $qty, $date, $reason, $amount, $unitValue, $value, $user) {
            $wasteLogId = null;

            if ($outcome === 'restocked') {
                db_exec('UPDATE food_items SET quantity = quantity + ? WHERE id = ?', 'ii', [$qty, $itemId]);
            } elseif ($outcome === 'disposed') {
                // The returned units never re-entered stock, so only a waste entry is needed.
                $reasonId = (int) db_value("SELECT id FROM waste_reasons WHERE reason = 'Customer Return'");
                db_exec(
                    'INSERT INTO waste_logs (food_item_id, quantity_wasted, waste_reason_id, waste_date, user_id, notes, estimated_value)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    'iiisisd',
                    [$itemId, $qty, $reasonId, $date, $user['id'], 'Customer return' . ($reason !== '' ? ': ' . $reason : ''), $value]
                );
                $wasteLogId = db_last_id();
            } else {
                $updated = db_exec('UPDATE food_items SET quantity = quantity - ? WHERE id = ? AND quantity >= ?', 'iii', [$qty, $itemId, $qty]);
                if ($updated !== 1) {
                    throw new RuntimeException('Stock changed while you were recording. Please check the quantity and try again.');
                }
            }

            db_exec(
                'INSERT INTO item_returns (food_item_id, return_type, outcome, quantity, unit_value, amount, reason, return_date, user_id, waste_log_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'issiddssii',
                [$itemId, $type, $outcome, $qty, $unitValue, $amount, $reason, $date, $user['id'], $wasteLogId]
            );
        });
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('returns.php?item=' . $itemId);
    }

    $messages = [
        'restocked' => $qty . ' ' . $item['unit'] . ' of "' . $item['name'] . '" returned by a customer and put back in stock.',
        'disposed' => $qty . ' ' . $item['unit'] . ' of "' . $item['name'] . '" returned by a customer and logged as waste (' . peso($value) . ').',
        'sent_back' => $qty . ' ' . $item['unit'] . ' of "' . $item['name'] . '" sent back to the supplier' . ($amount > 0 ? ' for ' . peso($amount) . ' credit.' : '.'),
    ];
    flash('success', $messages[$outcome]);
    redirect('returns.php');
}

$old = $_SESSION['return_old'] ?? [];
unset($_SESSION['return_old']);
$selectedId = (int) ($old['food_item_id'] ?? ($_GET['item'] ?? 0));
$oldType = ($old['return_type'] ?? '') === 'supplier' ? 'supplier' : 'customer';

$items = db_all(
    'SELECT fi.id, fi.name, fi.quantity, fi.unit, fi.purchase_price, s.name AS supplier, fc.name AS category
     FROM food_items fi
     JOIN food_categories fc ON fc.id = fi.category_id
     LEFT JOIN suppliers s ON s.id = fi.supplier_id
     WHERE fi.archived_at IS NULL
     ORDER BY fc.name, fi.name'
);
$grouped = [];
foreach ($items as $it) {
    $grouped[$it['category']][] = $it;
}

$typeFilter = in_array($_GET['type'] ?? '', ['customer', 'supplier'], true) ? $_GET['type'] : '';
$returns = db_all(
    'SELECT r.*, fi.name AS item_name, fi.unit, fi.barcode, u.username, s.name AS supplier
     FROM item_returns r
     JOIN food_items fi ON fi.id = r.food_item_id
     JOIN users u ON u.id = r.user_id
     LEFT JOIN suppliers s ON s.id = fi.supplier_id
     WHERE ? = \'\' OR r.return_type = ?
     ORDER BY r.return_date DESC, r.id DESC
     LIMIT 100',
    'ss',
    [$typeFilter, $typeFilter]
);

$month = db_one(
    "SELECT
        COALESCE(SUM(return_type = 'customer'), 0) AS customer_count,
        COALESCE(SUM(CASE WHEN outcome = 'restocked' THEN quantity END), 0) AS restocked_units,
        COALESCE(SUM(CASE WHEN outcome = 'disposed' THEN quantity * unit_value END), 0) AS disposed_value,
        COALESCE(SUM(CASE WHEN return_type = 'customer' THEN amount END), 0) AS refunds,
        COALESCE(SUM(return_type = 'supplier'), 0) AS supplier_count,
        COALESCE(SUM(CASE WHEN return_type = 'supplier' THEN quantity * unit_value END), 0) AS supplier_value,
        COALESCE(SUM(CASE WHEN return_type = 'supplier' THEN amount END), 0) AS credits
     FROM item_returns
     WHERE return_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
);

$outcomeLabels = [
    'restocked' => ['Restocked', 'green'],
    'disposed' => ['Disposed', 'red'],
    'sent_back' => ['Sent to supplier', 'blue'],
];

render_shell_start($user, 'Returns', 'returns');
page_header('Returns', 'Customer returns and stock sent back to suppliers. Supplier credits count as money recovered.');
?>

<div class="stats" style="margin-bottom:18px">
    <div class="stat">
        <span class="stat-icon tone-teal"><i class="fa-solid fa-user"></i></span>
        <span><div class="stat-label">Customer returns</div><div class="stat-value"><?= (int) $month['customer_count'] ?></div><div class="stat-sub">This month · <?= (int) $month['restocked_units'] ?> units restocked</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-red"><i class="fa-solid fa-trash-can"></i></span>
        <span><div class="stat-label">Disposed returns</div><div class="stat-value"><?= peso($month['disposed_value'], 0) ?></div><div class="stat-sub">Logged as waste · <?= peso($month['refunds'], 0) ?> refunded</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-blue"><i class="fa-solid fa-truck-ramp-box"></i></span>
        <span><div class="stat-label">Sent to suppliers</div><div class="stat-value"><?= peso($month['supplier_value'], 0) ?></div><div class="stat-sub"><?= (int) $month['supplier_count'] ?> return<?= (int) $month['supplier_count'] === 1 ? '' : 's' ?> this month</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-green"><i class="fa-solid fa-hand-holding-dollar"></i></span>
        <span><div class="stat-label">Credit recovered</div><div class="stat-value"><?= peso($month['credits'], 0) ?></div><div class="stat-sub"><?= peso(max(0, $month['supplier_value'] - $month['credits']), 0) ?> not recovered</div></span>
    </div>
</div>

<?php if (!can_manage($user)): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-eye"></i>
        <span>Your account is <strong>view and print only</strong>. Returns are recorded by a manager or admin; you can still see the history below.</span>
    </div>
<?php else: ?>
<div class="grid-3-2" style="margin-top:0">
    <form class="card" method="POST" id="returnForm">
        <?= csrf_field() ?>
        <div class="card-head">
            <div class="card-title"><i class="fa-solid fa-rotate-left"></i> Record a return</div>
        </div>
        <div class="card-body">
            <div class="segmented" role="radiogroup" aria-label="Return type">
                <label><input type="radio" name="return_type" value="customer" <?= $oldType === 'customer' ? 'checked' : '' ?>><span><i class="fa-solid fa-user"></i> From a customer</span></label>
                <label><input type="radio" name="return_type" value="supplier" <?= $oldType === 'supplier' ? 'checked' : '' ?>><span><i class="fa-solid fa-truck"></i> Back to supplier</span></label>
            </div>

            <div class="field">
                <label for="retItem">Item <span class="req">*</span></label>
                <select class="select" id="retItem" name="food_item_id" required>
                    <option value="">Select an item…</option>
                    <?php foreach ($grouped as $category => $list): ?>
                        <optgroup label="<?= e($category) ?>">
                            <?php foreach ($list as $it): ?>
                                <option value="<?= (int) $it['id'] ?>" data-qty="<?= (int) $it['quantity'] ?>" data-unit="<?= e($it['unit']) ?>"
                                        data-price="<?= e($it['purchase_price'] ?? '0') ?>" data-supplier="<?= e($it['supplier'] ?? '') ?>"
                                    <?= $selectedId === (int) $it['id'] ? 'selected' : '' ?>>
                                    <?= e($it['name']) ?> — <?= (int) $it['quantity'] ?> <?= e($it['unit']) ?> in stock
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="alert alert-info hidden" id="retInfo"><i class="fa-solid fa-circle-info"></i><span id="retInfoText"></span></div>

            <div class="field" id="outcomeField">
                <span class="field-label">What happens to the returned item? <span class="req">*</span></span>
                <div class="segmented">
                    <label><input type="radio" name="outcome" value="restocked" <?= ($old['outcome'] ?? 'restocked') === 'restocked' ? 'checked' : '' ?>><span><i class="fa-solid fa-box"></i> Back on the shelf</span></label>
                    <label><input type="radio" name="outcome" value="disposed" <?= ($old['outcome'] ?? '') === 'disposed' ? 'checked' : '' ?>><span><i class="fa-solid fa-trash-can"></i> Dispose (waste)</span></label>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="retQty">Quantity <span class="req">*</span></label>
                    <input class="input" type="number" id="retQty" name="quantity" min="1" step="1" required value="<?= e($old['quantity'] ?? '') ?>">
                    <span class="hint" id="retQtyHint"></span>
                </div>
                <div class="field">
                    <label for="retDate">Return date <span class="req">*</span></label>
                    <input class="input" type="date" id="retDate" name="return_date" required max="<?= date('Y-m-d') ?>" value="<?= e($old['return_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="field">
                    <label for="retAmount" id="retAmountLabel">Refund given (₱)</label>
                    <input class="input" type="number" id="retAmount" name="amount" min="0" step="0.01" placeholder="0.00" value="<?= e($old['amount'] ?? '') ?>">
                    <span class="hint" id="retAmountHint"></span>
                </div>
                <div class="field">
                    <label for="retReason">Reason</label>
                    <input class="input" type="text" id="retReason" name="reason" maxlength="255" placeholder="e.g. Damaged seal, wrong item" value="<?= e($old['reason'] ?? '') ?>">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Record return</button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-head"><div class="card-title"><i class="fa-solid fa-circle-question"></i> How returns affect the numbers</div></div>
        <div class="card-body">
            <ul class="list">
                <li><div><div class="cell-title">Customer → back on the shelf</div><div class="cell-sub">Stock goes up. No waste, no loss.</div></div><span class="chip chip-green">Restocked</span></li>
                <li><div><div class="cell-title">Customer → dispose</div><div class="cell-sub">Logged as “Customer Return” waste at purchase price. Counts toward waste and loss.</div></div><span class="chip chip-red">Waste</span></li>
                <li><div><div class="cell-title">Back to supplier</div><div class="cell-sub">Stock goes down. Any credit is money recovered; the rest counts as loss.</div></div><span class="chip chip-blue">Loss − credit</span></li>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="toolbar" style="margin-top:22px">
    <h2 class="card-title" style="font-size:16px"><i class="fa-solid fa-list"></i> Return history</h2>
    <span class="spacer"></span>
    <a class="btn btn-sm <?= $typeFilter === '' ? 'btn-primary' : '' ?>" href="returns.php">All</a>
    <a class="btn btn-sm <?= $typeFilter === 'customer' ? 'btn-primary' : '' ?>" href="returns.php?type=customer">Customer</a>
    <a class="btn btn-sm <?= $typeFilter === 'supplier' ? 'btn-primary' : '' ?>" href="returns.php?type=supplier">Supplier</a>
</div>
<div class="table-card">
    <?php if (!$returns): ?>
        <div class="empty"><i class="fa-solid fa-rotate-left"></i><strong>No returns recorded</strong>Returns you record appear here.</div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Date</th><th>Type</th><th class="col-item">Item</th><th class="center">Qty</th><th>Outcome</th><th class="text-right">Stock value</th><th class="text-right">Refund / Credit</th><th>Reason</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ($returns as $r): [$outLabel, $outTone] = $outcomeLabels[$r['outcome']]; ?>
                    <tr>
                        <td class="nowrap"><?= date('M j, Y', strtotime($r['return_date'])) ?></td>
                        <td><span class="chip chip-<?= $r['return_type'] === 'customer' ? 'teal' : 'purple' ?>"><?= $r['return_type'] === 'customer' ? 'Customer' : 'Supplier' ?></span></td>
                        <td class="col-item">
                            <div class="cell-title"><?= e($r['item_name']) ?></div>
                            <div class="cell-sub"><?= $r['return_type'] === 'supplier' ? e($r['supplier'] ?? 'No supplier') : ($r['barcode'] ? '<span class="sku">' . e($r['barcode']) . '</span>' : '') ?></div>
                        </td>
                        <td class="center nowrap"><span class="qty-big"><?= (int) $r['quantity'] ?></span> <span class="muted"><?= e($r['unit']) ?></span></td>
                        <td><span class="chip chip-<?= $outTone ?>"><?= e($outLabel) ?></span></td>
                        <td class="text-right num"><?= peso($r['quantity'] * $r['unit_value']) ?></td>
                        <td class="text-right num strong"><?= (float) $r['amount'] > 0 ? peso($r['amount']) : '<span class="muted">—</span>' ?></td>
                        <td class="muted" style="max-width:220px"><?= e(mb_strimwidth((string) $r['reason'], 0, 60, '…')) ?></td>
                        <td><?= e($r['username']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php ob_start(); ?>
<script>
(function () {
    const form = document.getElementById('returnForm');
    if (!form) return; // view-only accounts don't get the form
    const item = document.getElementById('retItem');
    const qty = document.getElementById('retQty');
    const info = document.getElementById('retInfo');
    const infoText = document.getElementById('retInfoText');
    const qtyHint = document.getElementById('retQtyHint');
    const amountLabel = document.getElementById('retAmountLabel');
    const amountHint = document.getElementById('retAmountHint');
    const outcomeField = document.getElementById('outcomeField');

    function type() { return form.querySelector('input[name=return_type]:checked').value; }

    function refresh() {
        const isSupplier = type() === 'supplier';
        const opt = item.options[item.selectedIndex];
        outcomeField.classList.toggle('hidden', isSupplier);
        amountLabel.textContent = isSupplier ? 'Credit from supplier (₱)' : 'Refund given (₱)';
        amountHint.textContent = isSupplier ? 'Money the supplier gives back. Reduces your loss.' : 'Optional, for your records.';

        if (!opt || !opt.value) { info.classList.add('hidden'); qtyHint.textContent = ''; qty.removeAttribute('max'); return; }
        const stock = Number(opt.dataset.qty);
        const price = Number(opt.dataset.price) || 0;
        const n = Number(qty.value) || 0;
        if (isSupplier) { qty.max = stock; qtyHint.textContent = `Up to ${stock} ${opt.dataset.unit} in stock`; }
        else { qty.removeAttribute('max'); qtyHint.textContent = ''; }
        infoText.innerHTML = `₱${price.toFixed(2)} per ${escapeHtml(opt.dataset.unit)} · ${stock} in stock`
            + (opt.dataset.supplier ? ` · Supplier: <strong>${escapeHtml(opt.dataset.supplier)}</strong>` : '')
            + (n > 0 ? ` · Value of this return: <strong>₱${(n * price).toFixed(2)}</strong>` : '');
        info.classList.remove('hidden');
    }

    form.addEventListener('change', refresh);
    qty.addEventListener('input', refresh);
    refresh();
})();
</script>
<?php render_shell_end(ob_get_clean()); ?>
