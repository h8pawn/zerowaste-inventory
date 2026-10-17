<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$canManage = can_manage($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        flash('error', 'Only admins and managers can restore or delete archived items.');
        redirect($_SERVER['REQUEST_URI']);
    }

    $action = $_POST['action'] ?? '';
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $item = db_one('SELECT id, name, purchase_price FROM food_items WHERE id = ? AND archived_at IS NOT NULL', 'i', [$itemId]);

    if (!$item) {
        flash('error', 'That item is not in the archive.');
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'restore') {
        $addQty = (int) ($_POST['quantity'] ?? 0);
        $expiry = trim((string) ($_POST['expiration_date'] ?? ''));
        $d = DateTime::createFromFormat('Y-m-d', $expiry);

        if ($addQty < 0) {
            flash('error', 'Quantity cannot be negative.');
        } elseif (!$d || $d->format('Y-m-d') !== $expiry || $expiry < date('Y-m-d')) {
            flash('error', 'Choose an expiration date from today onward, or the item would expire again right away.');
        } else {
            db_transaction(function () use ($addQty, $expiry, $itemId, $item, $user) {
                db_exec(
                    'UPDATE food_items
                     SET quantity = quantity + ?, expiration_date = ?, archived_at = NULL, archive_reason = NULL,
                         added_date = IF(? > 0, NOW(), added_date)
                     WHERE id = ?',
                    'isii',
                    [$addQty, $expiry, $addQty, $itemId]
                );
                record_receipt($itemId, $addQty, $item['purchase_price'], 'restore', $user['id']);
            });
            flash('success', '"' . $item['name'] . '" restored to inventory' . ($addQty > 0 ? ' with ' . $addQty . ' new units.' : '.'));
        }
    }

    if ($action === 'purge') {
        if (item_has_history($itemId)) {
            flash('error', '"' . $item['name'] . '" has waste or return history, so it stays in the archive to keep reports accurate.');
        } else {
            db_exec('DELETE FROM food_items WHERE id = ?', 'i', [$itemId]);
            flash('success', '"' . $item['name'] . '" permanently deleted.');
        }
    }

    redirect($_SERVER['REQUEST_URI']);
}

$reasonOptions = ['' => 'All reasons', 'expired' => 'Expired', 'manual' => 'Archived by staff', 'removed' => 'Removed (has history)'];
$q = trim((string) ($_GET['q'] ?? ''));
$reason = (string) ($_GET['reason'] ?? '');
$reason = array_key_exists($reason, $reasonOptions) ? $reason : '';

$where = ['fi.archived_at IS NOT NULL'];
$types = '';
$params = [];
if ($q !== '') {
    $where[] = '(fi.name LIKE ? OR fi.barcode LIKE ? OR fc.name LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
if ($reason !== '') {
    $where[] = 'fi.archive_reason = ?';
    $types .= 's';
    $params[] = $reason;
}

$items = db_all(
    'SELECT fi.*, fc.name AS category_name, s.name AS supplier_name,
            COALESCE(w.units, 0) AS wasted_units, COALESCE(w.value, 0) AS wasted_value,
            (COALESCE(w.entries, 0) + COALESCE(r.entries, 0)) AS history_count
     FROM food_items fi
     JOIN food_categories fc ON fc.id = fi.category_id
     LEFT JOIN suppliers s ON s.id = fi.supplier_id
     LEFT JOIN (SELECT food_item_id, COUNT(*) AS entries, SUM(quantity_wasted) AS units, SUM(estimated_value) AS value
                FROM waste_logs GROUP BY food_item_id) w ON w.food_item_id = fi.id
     LEFT JOIN (SELECT food_item_id, COUNT(*) AS entries FROM item_returns GROUP BY food_item_id) r ON r.food_item_id = fi.id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY fi.archived_at DESC, fi.name',
    $types,
    $params
);

$totals = db_one(
    "SELECT COUNT(*) AS items,
            SUM(archive_reason = 'expired') AS expired,
            COALESCE(SUM(quantity * COALESCE(purchase_price, 0)), 0) AS held_value
     FROM food_items WHERE archived_at IS NOT NULL"
);
$archivedWaste = (float) db_value(
    'SELECT COALESCE(SUM(wl.estimated_value), 0) FROM waste_logs wl JOIN food_items fi ON fi.id = wl.food_item_id WHERE fi.archived_at IS NOT NULL'
);

render_shell_start($user, 'Archive', 'archive');
page_header('Archive', 'Expired batches and discontinued items. They are hidden from inventory but keep their full history.');
?>

<div class="stats" style="margin-bottom:18px">
    <div class="stat">
        <span class="stat-icon tone-gray"><i class="fa-solid fa-box-archive"></i></span>
        <span><div class="stat-label">Archived items</div><div class="stat-value"><?= (int) $totals['items'] ?></div><div class="stat-sub"><?= (int) $totals['expired'] ?> expired batches</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-red"><i class="fa-solid fa-peso-sign"></i></span>
        <span><div class="stat-label">Lifetime waste</div><div class="stat-value"><?= peso($archivedWaste, 0) ?></div><div class="stat-sub">From archived items</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-amber"><i class="fa-solid fa-cubes"></i></span>
        <span><div class="stat-label">Stock on hold</div><div class="stat-value"><?= peso($totals['held_value'], 0) ?></div><div class="stat-sub">Units still counted on archived items</div></span>
    </div>
</div>

<form class="toolbar" method="GET">
    <div class="search-field">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search archived items…" aria-label="Search archive">
    </div>
    <select class="select" name="reason" onchange="this.form.submit()" aria-label="Archive reason">
        <?php foreach ($reasonOptions as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $reason === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <span class="count-pill"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span>
    <?php if ($q !== '' || $reason !== ''): ?><a href="archive.php" class="btn btn-sm btn-ghost"><i class="fa-solid fa-xmark"></i> Clear</a><?php endif; ?>
</form>

<div class="table-card">
    <?php if (!$items): ?>
        <div class="empty">
            <i class="fa-solid fa-box-archive"></i>
            <strong><?= $q !== '' || $reason !== '' ? 'No archived items match' : 'The archive is empty' ?></strong>
            Expired batches land here automatically. You can also archive items from the Food Items menu.
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                <tr><th>Barcode</th><th class="col-item">Item</th><th>Category</th><th>Reason</th><th>Archived</th><th>Last expiry</th><th class="center">Stock</th><th class="text-right">Lifetime waste</th><th class="center">Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): [$reasonLabel, $reasonTone] = archive_reason_label($item['archive_reason']); ?>
                    <tr>
                        <td><?= $item['barcode'] ? '<span class="sku">' . e($item['barcode']) . '</span>' : '<span class="muted">—</span>' ?></td>
                        <td class="col-item">
                            <div class="cell-title"><?= e($item['name']) ?></div>
                            <div class="cell-sub"><?= e($item['supplier_name'] ?? 'No supplier') ?></div>
                        </td>
                        <td><span class="chip chip-green"><?= e($item['category_name']) ?></span></td>
                        <td><span class="chip chip-<?= $reasonTone ?>"><?= e($reasonLabel) ?></span></td>
                        <td class="nowrap"><?= date('M j, Y', strtotime($item['archived_at'])) ?></td>
                        <td class="nowrap"><?= date('M j, Y', strtotime($item['expiration_date'])) ?></td>
                        <td class="center"><span class="qty-big"><?= (int) $item['quantity'] ?></span> <span class="muted"><?= e($item['unit']) ?></span></td>
                        <td class="text-right num">
                            <span class="strong"><?= peso($item['wasted_value']) ?></span>
                            <div class="cell-sub"><?= (int) $item['wasted_units'] ?> <?= e($item['unit']) ?> wasted</div>
                        </td>
                        <td class="center">
                            <?php if ($canManage): ?>
                                <div class="menu-wrap">
                                    <button type="button" class="kebab" data-menu aria-label="Actions for <?= e($item['name']) ?>"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                    <div class="menu">
                                        <button type="button" class="menu-item" data-restore="<?= (int) $item['id'] ?>" data-name="<?= e($item['name']) ?>" data-unit="<?= e($item['unit']) ?>"
                                                data-expiry="<?= $item['expiration_date'] >= date('Y-m-d') ? e($item['expiration_date']) : '' ?>">
                                            <i class="fa-solid fa-box-open"></i> Restore to inventory
                                        </button>
                                        <a class="menu-item" href="waste_history.php?q=<?= urlencode($item['name']) ?>"><i class="fa-solid fa-clock-rotate-left"></i> View waste history</a>
                                        <?php if (!(int) $item['history_count']): ?>
                                            <div class="menu-sep"></div>
                                            <form method="POST" data-confirm="Permanently delete &quot;<?= e($item['name']) ?>&quot;? This cannot be undone.">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="purge">
                                                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                <button type="submit" class="menu-item danger"><i class="fa-solid fa-trash"></i> Delete permanently</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
<div class="modal" id="restoreModal" role="dialog" aria-modal="true" aria-labelledby="restoreTitle">
    <form class="modal-card sm" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="item_id" id="restoreId">
        <div class="modal-head">
            <h2 id="restoreTitle">Restore to inventory</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p class="muted" style="margin-top:0">Bring <strong id="restoreName"></strong> back into active inventory.</p>
            <div class="field">
                <label for="restoreQty">New stock received</label>
                <input class="input" type="number" name="quantity" id="restoreQty" min="0" step="1" value="0" autofocus>
                <span class="hint">Counted as received stock (cost) at the item's purchase price.</span>
            </div>
            <div class="field">
                <label for="restoreExpiry">Expiration date <span class="req">*</span></label>
                <input class="input" type="date" name="expiration_date" id="restoreExpiry" min="<?= date('Y-m-d') ?>" required>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-box-open"></i> Restore</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php ob_start(); ?>
<script>
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-restore]');
    if (!btn) return;
    document.getElementById('restoreId').value = btn.dataset.restore;
    document.getElementById('restoreName').textContent = btn.dataset.name;
    document.getElementById('restoreQty').value = 0;
    document.getElementById('restoreExpiry').value = btn.dataset.expiry || '';
    document.querySelectorAll('.menu.open').forEach((m) => m.classList.remove('open'));
    openModal('restoreModal');
});
</script>
<?php render_shell_end(ob_get_clean()); ?>
