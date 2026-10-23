<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();

function clean_date(?string $value): string {
    $value = trim((string) $value);
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value ? $value : '';
}

$filters = [
    'from' => clean_date($_GET['from'] ?? ($_GET['date'] ?? '')),
    'to' => clean_date($_GET['to'] ?? ($_GET['date'] ?? '')),
    'reason' => (int) ($_GET['reason'] ?? 0),
    'q' => trim((string) ($_GET['q'] ?? '')),
];

$where = ['1 = 1'];
$types = '';
$params = [];
if ($filters['from'] !== '') {
    $where[] = 'wl.waste_date >= ?';
    $types .= 's';
    $params[] = $filters['from'];
}
if ($filters['to'] !== '') {
    $where[] = 'wl.waste_date <= ?';
    $types .= 's';
    $params[] = $filters['to'];
}
if ($filters['reason'] > 0) {
    $where[] = 'wl.waste_reason_id = ?';
    $types .= 'i';
    $params[] = $filters['reason'];
}
if ($filters['q'] !== '') {
    $where[] = '(fi.name LIKE ? OR fi.barcode LIKE ? OR wl.notes LIKE ? OR u.username LIKE ?)';
    $like = '%' . $filters['q'] . '%';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

$fromSql = "FROM waste_logs wl
            JOIN food_items fi ON fi.id = wl.food_item_id
            JOIN food_categories fc ON fc.id = fi.category_id
            JOIN waste_reasons wr ON wr.id = wl.waste_reason_id
            JOIN users u ON u.id = wl.user_id
            WHERE " . implode(' AND ', $where);
$selectSql = "SELECT wl.id, wl.quantity_wasted, wl.waste_date, wl.estimated_value, wl.notes, wl.logged_at,
                     fi.name AS item_name, fi.unit, fi.barcode, fc.name AS category_name, wr.reason, u.username
              $fromSql
              ORDER BY wl.waste_date DESC, wl.logged_at DESC, wl.id DESC";

if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_all($selectSql, $types, $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="waste-history-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Item', 'Barcode', 'Category', 'Quantity', 'Unit', 'Reason', 'Value Lost', 'Logged By', 'Logged At', 'Notes']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['waste_date'], $r['item_name'], $r['barcode'], $r['category_name'], $r['quantity_wasted'], $r['unit'], $r['reason'], $r['estimated_value'], $r['username'], $r['logged_at'], $r['notes']]);
    }
    fclose($out);
    exit();
}

$totals = db_one(
    "SELECT COUNT(*) AS entries, COALESCE(SUM(wl.quantity_wasted), 0) AS units, COALESCE(SUM(wl.estimated_value), 0) AS value,
            COUNT(DISTINCT wl.food_item_id) AS items
     $fromSql",
    $types,
    $params
);

$perPage = 30;
$totalRows = (int) $totals['entries'];
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$logs = db_all("$selectSql LIMIT ? OFFSET ?", $types . 'ii', array_merge($params, [$perPage, ($page - 1) * $perPage]));
$reasons = db_all('SELECT id, reason FROM waste_reasons ORDER BY reason');

function history_url(array $filters, array $overrides = []): string {
    $query = array_filter(array_merge($filters, $overrides), fn ($v) => $v !== '' && $v !== 0 && $v !== null);
    return 'waste_history.php' . ($query ? '?' . http_build_query($query) : '');
}

$reasonTones = ['Expired' => 'red', 'Damaged/Spoiled' => 'amber', 'Overstock' => 'purple', 'Recall' => 'red', 'Quality Control' => 'blue', 'Spillage' => 'teal'];
$hasFilters = $filters !== ['from' => '', 'to' => '', 'reason' => 0, 'q' => ''];

render_shell_start($user, 'Waste History', 'history');
page_header(
    'Waste History',
    'Every waste entry, including stock the system moved automatically after it expired.',
    '<a class="btn" href="' . e(history_url($filters, ['export' => 'csv'])) . '"><i class="fa-solid fa-download"></i> Export</a>'
        . (can_manage($user) ? '<a class="btn btn-purple" href="log_waste.php"><i class="fa-solid fa-plus"></i> Log Waste</a>' : '')
);
?>

<div class="stats" style="margin-bottom:18px">
    <div class="stat">
        <span class="stat-icon tone-teal"><i class="fa-solid fa-list"></i></span>
        <span><div class="stat-label">Entries</div><div class="stat-value"><?= number_format($totalRows) ?></div><div class="stat-sub"><?= number_format((int) $totals['items']) ?> distinct items</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-amber"><i class="fa-solid fa-cubes"></i></span>
        <span><div class="stat-label">Units wasted</div><div class="stat-value"><?= number_format((int) $totals['units']) ?></div><div class="stat-sub"><?= $hasFilters ? 'Matching filters' : 'All time' ?></div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-red"><i class="fa-solid fa-peso-sign"></i></span>
        <span><div class="stat-label">Value lost</div><div class="stat-value"><?= peso($totals['value']) ?></div><div class="stat-sub"><?= $hasFilters ? 'Matching filters' : 'All time' ?></div></span>
    </div>
</div>

<form class="toolbar" method="GET" action="waste_history.php">
    <div class="search-field">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Item, barcode, note, user…" aria-label="Search">
    </div>
    <input class="input" type="date" name="from" value="<?= e($filters['from']) ?>" aria-label="From date" title="From">
    <span class="muted">to</span>
    <input class="input" type="date" name="to" value="<?= e($filters['to']) ?>" aria-label="To date" title="To">
    <select class="select" name="reason" aria-label="Reason">
        <option value="">All Reasons</option>
        <?php foreach ($reasons as $r): ?>
            <option value="<?= (int) $r['id'] ?>" <?= $filters['reason'] === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['reason']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
    <?php if ($hasFilters): ?><a href="waste_history.php" class="btn btn-ghost"><i class="fa-solid fa-xmark"></i> Clear</a><?php endif; ?>
</form>

<div class="table-card">
    <?php if (!$logs): ?>
        <div class="empty"><i class="fa-solid fa-inbox"></i><strong>No waste entries found</strong><?= $hasFilters ? 'Try widening the filters.' : 'Entries appear here once waste is logged.' ?></div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                <tr><th>Date</th><th>Item</th><th>Category</th><th class="center">Qty</th><th>Reason</th><th class="text-right">Value Lost</th><th>Logged By</th><th>Notes</th></tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="nowrap"><?= date('M j, Y', strtotime($log['waste_date'])) ?></td>
                        <td>
                            <div class="cell-title"><?= e($log['item_name']) ?></div>
                            <?php if ($log['barcode']): ?><div class="cell-sub sku"><?= e($log['barcode']) ?></div><?php endif; ?>
                        </td>
                        <td><span class="chip chip-green"><?= e($log['category_name']) ?></span></td>
                        <td class="center nowrap"><span class="qty-big"><?= (int) $log['quantity_wasted'] ?></span> <span class="muted"><?= e($log['unit']) ?></span></td>
                        <td><span class="chip chip-<?= $reasonTones[$log['reason']] ?? 'gray' ?>"><?= e($log['reason']) ?></span></td>
                        <td class="text-right strong num"><?= peso($log['estimated_value']) ?></td>
                        <td><?= e($log['username']) ?></td>
                        <td class="muted" style="max-width:260px" title="<?= e($log['notes']) ?>"><?= e(mb_strimwidth((string) $log['notes'], 0, 70, '…')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-foot">
            <span>Showing <?= ($page - 1) * $perPage + 1 ?>–<?= min($page * $perPage, $totalRows) ?> of <?= number_format($totalRows) ?></span>
            <div class="pager">
                <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(history_url($filters, ['page' => $page - 1])) ?>"><i class="fa-solid fa-chevron-left"></i> Prev</a><?php endif; ?>
                <span class="btn btn-sm btn-ghost">Page <?= $page ?> / <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?><a class="btn btn-sm" href="<?= e(history_url($filters, ['page' => $page + 1])) ?>">Next <i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php render_shell_end(); ?>
