<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();

if (!can_manage($user)) {
    flash('info', 'Your account is view and print only, so waste is logged by a manager or admin. Here is the waste history.');
    redirect('waste_history.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemId = (int) ($_POST['food_item_id'] ?? 0);
    $qty = (int) ($_POST['quantity_wasted'] ?? 0);
    $reasonId = (int) ($_POST['waste_reason_id'] ?? 0);
    $date = trim((string) ($_POST['waste_date'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $valueInput = trim((string) ($_POST['estimated_value'] ?? ''));

    $item = db_one('SELECT id, name, quantity, unit, purchase_price FROM food_items WHERE id = ?', 'i', [$itemId]);
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    $error = null;

    if (!$item) {
        $error = 'Please choose an item.';
    } elseif ($qty < 1) {
        $error = 'Quantity wasted must be at least 1.';
    } elseif ($qty > (int) $item['quantity']) {
        $error = 'Cannot waste more than the available quantity (' . (int) $item['quantity'] . ' ' . $item['unit'] . ').';
    } elseif (!db_value('SELECT COUNT(*) FROM waste_reasons WHERE id = ?', 'i', [$reasonId])) {
        $error = 'Please choose a waste reason.';
    } elseif (!$dateObj || $dateObj->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
        $error = 'Waste date must be a valid date that is not in the future.';
    } elseif ($valueInput !== '' && (float) $valueInput < 0) {
        $error = 'Estimated value cannot be negative.';
    }

    if ($error) {
        flash('error', $error);
        $_SESSION['waste_old'] = $_POST;
        redirect('log_waste.php' . ($itemId ? '?item=' . $itemId : ''));
    }

    $value = $valueInput !== '' ? round((float) $valueInput, 2) : round($qty * (float) $item['purchase_price'], 2);

    try {
        db_transaction(function () use ($qty, $itemId, $reasonId, $date, $user, $notes, $value) {
            // The quantity guard stops two people from wasting the same stock at once.
            $updated = db_exec('UPDATE food_items SET quantity = quantity - ? WHERE id = ? AND quantity >= ?', 'iii', [$qty, $itemId, $qty]);
            if ($updated !== 1) {
                throw new RuntimeException('Stock changed while you were logging. Please check the quantity and try again.');
            }
            db_exec(
                'INSERT INTO waste_logs (food_item_id, quantity_wasted, waste_reason_id, waste_date, user_id, notes, estimated_value)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                'iiisisd',
                [$itemId, $qty, $reasonId, $date, $user['id'], $notes, $value]
            );
        });
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('log_waste.php?item=' . $itemId);
    }

    $remaining = (int) $item['quantity'] - $qty;
    flash('success', 'Logged ' . $qty . ' ' . $item['unit'] . ' of "' . $item['name'] . '" (' . peso($value) . '). ' . $remaining . ' left in stock.');
    redirect('log_waste.php');
}

$old = $_SESSION['waste_old'] ?? [];
unset($_SESSION['waste_old']);
$selectedId = (int) ($old['food_item_id'] ?? ($_GET['item'] ?? 0));

$items = db_all(
    "SELECT fi.id, fi.name, fi.quantity, fi.unit, fi.purchase_price, fi.barcode, fi.expiration_date,
            DATEDIFF(fi.expiration_date, CURDATE()) AS days_left, fc.name AS category
     FROM food_items fi
     JOIN food_categories fc ON fc.id = fi.category_id
     WHERE fi.quantity > 0 AND fi.archived_at IS NULL
     ORDER BY fc.name, fi.name"
);
$reasons = db_all('SELECT id, reason, description FROM waste_reasons ORDER BY reason');
$recent = db_all(
    "SELECT wl.quantity_wasted, wl.estimated_value, wl.waste_date, fi.name, fi.unit, wr.reason
     FROM waste_logs wl
     JOIN food_items fi ON fi.id = wl.food_item_id
     JOIN waste_reasons wr ON wr.id = wl.waste_reason_id
     WHERE wl.user_id = ?
     ORDER BY wl.logged_at DESC, wl.id DESC
     LIMIT 6",
    'i',
    [$user['id']]
);

$grouped = [];
foreach ($items as $it) {
    $grouped[$it['category']][] = $it;
}

render_shell_start($user, 'Log Waste', 'log_waste');
page_header('Log Waste', 'Record discarded stock. The quantity is deducted from inventory automatically.');
?>

<div class="grid-3-2" style="margin-top:0">
    <form class="card" method="POST" id="wasteForm">
        <?= csrf_field() ?>
        <div class="card-head">
            <div class="card-title"><i class="fa-solid fa-trash-can"></i> New waste entry</div>
        </div>
        <div class="card-body">
            <?php if (!$items): ?>
                <div class="empty"><i class="fa-solid fa-box-open"></i><strong>No items with stock</strong>There is nothing in inventory to log as waste.</div>
            <?php else: ?>
                <div class="field">
                    <label for="item">Food item <span class="req">*</span></label>
                    <select class="select" id="item" name="food_item_id" required>
                        <option value="">Select an item…</option>
                        <?php foreach ($grouped as $category => $list): ?>
                            <optgroup label="<?= e($category) ?>">
                                <?php foreach ($list as $it): ?>
                                    <option value="<?= (int) $it['id'] ?>"
                                            data-qty="<?= (int) $it['quantity'] ?>"
                                            data-unit="<?= e($it['unit']) ?>"
                                            data-price="<?= e($it['purchase_price'] ?? '0') ?>"
                                            data-days="<?= (int) $it['days_left'] ?>"
                                            data-expiry="<?= e(date('M j, Y', strtotime($it['expiration_date']))) ?>"
                                            data-barcode="<?= e($it['barcode'] ?? '') ?>"
                                        <?= $selectedId === (int) $it['id'] ? 'selected' : '' ?>>
                                        <?= e($it['name']) ?> — <?= (int) $it['quantity'] ?> <?= e($it['unit']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="alert alert-info hidden" id="itemInfo">
                    <i class="fa-solid fa-circle-info"></i>
                    <span id="itemInfoText"></span>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="quantity">Quantity wasted <span class="req">*</span></label>
                        <input class="input" type="number" id="quantity" name="quantity_wasted" min="1" step="1" required value="<?= e($old['quantity_wasted'] ?? '') ?>">
                        <span class="hint" id="qtyHint"></span>
                    </div>
                    <div class="field">
                        <label for="waste_date">Waste date <span class="req">*</span></label>
                        <input class="input" type="date" id="waste_date" name="waste_date" required max="<?= date('Y-m-d') ?>" value="<?= e($old['waste_date'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="field">
                        <label for="reason">Reason <span class="req">*</span></label>
                        <select class="select" id="reason" name="waste_reason_id" required>
                            <option value="">Select a reason…</option>
                            <?php foreach ($reasons as $r): ?>
                                <option value="<?= (int) $r['id'] ?>" title="<?= e($r['description']) ?>" <?= (int) ($old['waste_reason_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['reason']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="estimated_value">Value lost (₱)</label>
                        <input class="input" type="number" id="estimated_value" name="estimated_value" step="0.01" min="0" placeholder="Auto from purchase price" value="<?= e($old['estimated_value'] ?? '') ?>">
                    </div>
                    <div class="field span-2">
                        <label for="notes">Notes</label>
                        <textarea class="input" id="notes" name="notes" maxlength="1000" placeholder="What happened? e.g. packaging torn during delivery"><?= e($old['notes'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <a href="home_page.php" class="btn">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Log waste entry</button>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Your recent entries</div>
                <div class="card-sub">The last things you logged</div>
            </div>
            <a href="waste_history.php" class="btn btn-sm btn-ghost">History <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="card-body">
            <?php if ($recent): ?>
                <ul class="list">
                    <?php foreach ($recent as $r): ?>
                        <li>
                            <div>
                                <div class="cell-title"><?= e($r['name']) ?></div>
                                <div class="cell-sub"><?= (int) $r['quantity_wasted'] ?> <?= e($r['unit']) ?> · <?= e($r['reason']) ?> · <?= date('M j', strtotime($r['waste_date'])) ?></div>
                            </div>
                            <span class="strong num"><?= peso($r['estimated_value']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="empty"><i class="fa-solid fa-leaf"></i><strong>No entries yet</strong></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php ob_start(); ?>
<script>
(function () {
    const item = document.getElementById('item');
    if (!item) return;
    const qty = document.getElementById('quantity');
    const value = document.getElementById('estimated_value');
    const info = document.getElementById('itemInfo');
    const infoText = document.getElementById('itemInfoText');
    const qtyHint = document.getElementById('qtyHint');
    // Only auto-fill the value until the user types their own.
    let valueTouched = value.value !== '';

    function selected() {
        return item.options[item.selectedIndex];
    }

    function refresh() {
        const opt = selected();
        if (!opt || !opt.value) {
            info.classList.add('hidden');
            qtyHint.textContent = '';
            qty.removeAttribute('max');
            return;
        }
        const max = Number(opt.dataset.qty);
        const price = Number(opt.dataset.price) || 0;
        const days = Number(opt.dataset.days);
        qty.max = max;
        qtyHint.textContent = `Up to ${max} ${opt.dataset.unit}`;
        const when = days < 0 ? 'expired' : days === 0 ? 'expires today' : `expires in ${days} day${days === 1 ? '' : 's'}`;
        infoText.innerHTML = `<strong>${max} ${escapeHtml(opt.dataset.unit)}</strong> in stock · ₱${price.toFixed(2)} each · ${escapeHtml(opt.dataset.expiry)} (${when})`
            + (opt.dataset.barcode ? ` · <span class="sku">${escapeHtml(opt.dataset.barcode)}</span>` : '');
        info.classList.remove('hidden');
        recalc();
    }

    function recalc() {
        if (valueTouched) return;
        const opt = selected();
        const price = Number(opt?.dataset.price) || 0;
        const n = Number(qty.value) || 0;
        value.value = n > 0 && price > 0 ? (n * price).toFixed(2) : '';
    }

    item.addEventListener('change', () => { valueTouched = false; refresh(); });
    qty.addEventListener('input', recalc);
    value.addEventListener('input', () => { valueTouched = value.value !== ''; if (!valueTouched) recalc(); });
    refresh();
})();
</script>
<?php render_shell_end(ob_get_clean()); ?>
