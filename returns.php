<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemId = (int) ($_POST['food_item_id'] ?? 0);
    $qty = (int) ($_POST['quantity'] ?? 0);
    $date = trim((string) ($_POST['return_date'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $amountInput = trim((string) ($_POST['amount'] ?? ''));
    $amount = $amountInput === '' ? 0.0 : round((float) $amountInput, 2);

    $item = db_one('SELECT id, name, unit, quantity, purchase_price FROM food_items WHERE id = ? AND archived_at IS NULL', 'i', [$itemId]);
    $error = null;

    if (!$item) {
        $error = 'Please choose an item.';
    } elseif ($qty < 1) {
        $error = 'Quantity must be at least 1.';
    } elseif ($qty > (int) $item['quantity']) {
        $error = 'You only have ' . (int) $item['quantity'] . ' ' . $item['unit'] . ' in stock to send back.';
    } elseif (!valid_date($date) || $date > date('Y-m-d')) {
        $error = 'Return date must be a valid date that is not in the future.';
    } elseif ($amount < 0) {
        $error = 'Credit cannot be negative.';
    }

    if ($error) {
        flash('error', $error);
        $_SESSION['return_old'] = $_POST;
        redirect('returns.php' . ($itemId ? '?item=' . $itemId : ''));
    }

    $unitValue = (float) ($item['purchase_price'] ?? 0);

    try {
        db_transaction(function () use ($itemId, $qty, $date, $reason, $amount, $unitValue, $user) {
            $updated = db_exec('UPDATE food_items SET quantity = quantity - ? WHERE id = ? AND quantity >= ?', 'iii', [$qty, $itemId, $qty]);
            if ($updated !== 1) {
                throw new RuntimeException('Stock changed while you were recording. Please check the quantity and try again.');
            }
            db_exec(
                'INSERT INTO item_returns (food_item_id, quantity, unit_value, amount, reason, return_date, user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                'iiddssi',
                [$itemId, $qty, $unitValue, $amount, $reason, $date, $user['id']]
            );
        });
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('returns.php?item=' . $itemId);
    }

    flash('success', $qty . ' ' . $item['unit'] . ' of "' . $item['name'] . '" sent back to the supplier' . ($amount > 0 ? ' for ' . peso($amount) . ' credit.' : '.'));
    redirect('returns.php');
}

$old = $_SESSION['return_old'] ?? [];
unset($_SESSION['return_old']);
$selectedId = (int) ($old['food_item_id'] ?? ($_GET['item'] ?? 0));

// Only stock that is on hand can be sent back.
$items = db_all(
    'SELECT fi.id, fi.name, fi.quantity, fi.unit, fi.purchase_price, s.name AS supplier, fc.name AS category
     FROM food_items fi
     JOIN food_categories fc ON fc.id = fi.category_id
     LEFT JOIN suppliers s ON s.id = fi.supplier_id
     WHERE fi.archived_at IS NULL AND fi.quantity > 0
     ORDER BY fc.name, fi.name'
);
$grouped = [];
foreach ($items as $it) {
    $grouped[$it['category']][] = $it;
}

$returns = db_all(
    'SELECT r.*, fi.name AS item_name, fi.unit, u.username, s.name AS supplier
     FROM item_returns r
     JOIN food_items fi ON fi.id = r.food_item_id
     JOIN users u ON u.id = r.user_id
     LEFT JOIN suppliers s ON s.id = fi.supplier_id
     ORDER BY r.return_date DESC, r.id DESC
     LIMIT 100'
);

$month = db_one(
    "SELECT COUNT(*) AS returns_count,
            COALESCE(SUM(quantity), 0) AS units,
            COALESCE(SUM(quantity * unit_value), 0) AS value,
            COALESCE(SUM(amount), 0) AS credits
     FROM item_returns
     WHERE return_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
);

render_shell_start($user, 'Returns', 'returns');
page_header('Returns', 'Stock sent back to suppliers. Credit from the supplier counts as money recovered.');
?>

<div class="stats" style="margin-bottom:18px">
    <div class="stat">
        <span class="stat-icon tone-purple"><i class="fa-solid fa-rotate-left"></i></span>
        <span><div class="stat-label">Returns this month</div><div class="stat-value"><?= (int) $month['returns_count'] ?></div><div class="stat-sub"><?= number_format((int) $month['units']) ?> units sent back</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-blue"><i class="fa-solid fa-truck-ramp-box"></i></span>
        <span><div class="stat-label">Sent to suppliers</div><div class="stat-value"><?= peso($month['value'], 0) ?></div><div class="stat-sub">This month, at purchase price</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-green"><i class="fa-solid fa-hand-holding-dollar"></i></span>
        <span><div class="stat-label">Credit recovered</div><div class="stat-value"><?= peso($month['credits'], 0) ?></div><div class="stat-sub"><?= peso(max(0, $month['value'] - $month['credits']), 0) ?> not recovered</div></span>
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
            <div class="card-title"><i class="fa-solid fa-truck-ramp-box"></i> Send stock back to a supplier</div>
        </div>
        <div class="card-body">
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
                    <label for="retAmount">Credit from supplier (₱)</label>
                    <input class="input" type="number" id="retAmount" name="amount" min="0" step="0.01" placeholder="0.00" value="<?= e($old['amount'] ?? '') ?>">
                    <span class="hint">Money the supplier gives back.</span>
                </div>
                <div class="field">
                    <label for="retReason">Reason</label>
                    <input class="input" type="text" id="retReason" name="reason" maxlength="255" placeholder="e.g. Damaged on arrival, wrong item" value="<?= e($old['reason'] ?? '') ?>">
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
                <li><div><div class="cell-title">Stock goes down</div><div class="cell-sub">The quantity you send back leaves inventory.</div></div><span class="chip chip-blue">Stock</span></li>
                <li><div><div class="cell-title">Credit is money recovered</div><div class="cell-sub">Whatever the supplier gives back is recorded against the return.</div></div><span class="chip chip-green">Credit</span></li>
                <li><div><div class="cell-title">Not counted as waste</div><div class="cell-sub">Sent-back stock is a return, so waste reports don't change.</div></div><span class="chip chip-gray">Waste</span></li>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="toolbar" style="margin-top:22px">
    <h2 class="card-title" style="font-size:16px"><i class="fa-solid fa-list"></i> Return history</h2>
</div>
<div class="table-card">
    <?php if (!$returns): ?>
        <div class="empty"><i class="fa-solid fa-rotate-left"></i><strong>No returns recorded</strong>Returns you record appear here.</div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Date</th><th class="col-item">Item</th><th class="center">Qty</th><th class="text-right">Stock value</th><th class="text-right">Credit</th><th>Reason</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ($returns as $r): ?>
                    <tr>
                        <td class="nowrap"><?= date('M j, Y', strtotime($r['return_date'])) ?></td>
                        <td class="col-item">
                            <div class="cell-title"><?= e($r['item_name']) ?></div>
                            <div class="cell-sub"><?= e($r['supplier'] ?? 'No supplier') ?></div>
                        </td>
                        <td class="center nowrap"><span class="qty-big"><?= (int) $r['quantity'] ?></span> <span class="muted"><?= e($r['unit']) ?></span></td>
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

    function refresh() {
        const opt = item.options[item.selectedIndex];
        if (!opt || !opt.value) { info.classList.add('hidden'); qtyHint.textContent = ''; qty.removeAttribute('max'); return; }
        const stock = Number(opt.dataset.qty);
        const price = Number(opt.dataset.price) || 0;
        const n = Number(qty.value) || 0;
        qty.max = stock;
        qtyHint.textContent = `Up to ${stock} ${opt.dataset.unit} in stock`;
        infoText.innerHTML = `₱${price.toFixed(2)} per ${escapeHtml(opt.dataset.unit)} · ${stock} in stock`
            + (opt.dataset.supplier ? ` · Supplier: <strong>${escapeHtml(opt.dataset.supplier)}</strong>` : ' · No supplier linked to this item')
            + (n > 0 ? ` · Value of this return: <strong>₱${(n * price).toFixed(2)}</strong>` : '');
        info.classList.remove('hidden');
    }

    form.addEventListener('change', refresh);
    qty.addEventListener('input', refresh);
    refresh();
})();
</script>
<?php render_shell_end(ob_get_clean()); ?>
