<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/shelf_life.php';
require_once __DIR__ . '/includes/barcode.php';

$user = require_login();
$canManage = can_manage($user);

function first_category_id(): int {
    return (int) (db_value('SELECT id FROM food_categories ORDER BY id ASC LIMIT 1') ?? 0);
}

// Reads and validates the add/edit item form. Returns [data, error].
function read_item_form(?int $ignoreId = null): array {
    $data = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'barcode' => preg_replace('/\s+/', '', (string) ($_POST['barcode'] ?? '')),
        'category_id' => (int) ($_POST['category_id'] ?? 0),
        'quantity' => (int) ($_POST['quantity'] ?? 0),
        'unit' => trim((string) ($_POST['unit'] ?? '')),
        'purchase_price' => ($_POST['purchase_price'] ?? '') !== '' ? (float) $_POST['purchase_price'] : null,
        'expiration_date' => trim((string) ($_POST['expiration_date'] ?? '')),
        'storage_location' => trim((string) ($_POST['storage_location'] ?? '')),
        'supplier_id' => ($_POST['supplier_id'] ?? '') !== '' ? (int) $_POST['supplier_id'] : null,
        'selling_price' => ($_POST['selling_price'] ?? '') !== '' ? round((float) $_POST['selling_price'], 2) : null,
    ];
    $data['barcode'] = $data['barcode'] !== '' ? $data['barcode'] : null;

    if ($data['name'] === '' || $data['unit'] === '') {
        return [$data, 'Item name and unit are required.'];
    }
    if (!db_value('SELECT COUNT(*) FROM food_categories WHERE id = ?', 'i', [$data['category_id']])) {
        return [$data, 'Please choose a category.'];
    }
    if ($data['quantity'] < 0) {
        return [$data, 'Quantity cannot be negative.'];
    }
    if ($data['purchase_price'] !== null && $data['purchase_price'] < 0) {
        return [$data, 'Purchase price cannot be negative.'];
    }
    if ($data['selling_price'] !== null && $data['selling_price'] < 0) {
        return [$data, 'Selling price cannot be negative.'];
    }
    if (!valid_date($data['expiration_date'])) {
        return [$data, 'Please enter a valid expiration date.'];
    }
    if ($data['supplier_id'] !== null && !db_value('SELECT COUNT(*) FROM suppliers WHERE id = ?', 'i', [$data['supplier_id']])) {
        $data['supplier_id'] = null;
    }
    if ($data['barcode'] !== null && !preg_match('/^[\x21-\x7E]{1,48}$/', $data['barcode'])) {
        return [$data, 'Barcodes can only use letters, numbers and symbols (up to 48 characters).'];
    }
    if ($data['barcode'] !== null) {
        $dupe = db_one('SELECT id, name FROM food_items WHERE barcode = ? AND id <> ?', 'si', [$data['barcode'], $ignoreId ?? 0]);
        if ($dupe) {
            return [$data, 'Barcode ' . $data['barcode'] . ' is already used by "' . $dupe['name'] . '".'];
        }
    }
    return [$data, null];
}

/* =========================================================
   AJAX endpoints
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'scan') {
        $barcode = preg_replace('/[^\x21-\x7E]/', '', (string) ($_POST['barcode'] ?? ''));
        if ($barcode === '') {
            json_response(['success' => false, 'message' => 'Barcode is empty.']);
        }

        $item = db_one(
            'SELECT id, name, quantity, unit, purchase_price, storage_location, archived_at, expiration_date < CURDATE() AS expired
             FROM food_items WHERE barcode = ? LIMIT 1',
            's',
            [$barcode]
        );

        if ($item) {
            $itemId = (int) $item['id'];
            $previous = (int) $item['quantity'];
            $note = $item['archived_at'] ? 'Restored from archive' : '';

            if ($item['expired']) {
                // The old batch expired (its leftovers were already logged as waste), so this scan starts a fresh batch.
                $template = db_one('SELECT default_shelf_life_days FROM products WHERE barcode = ?', 's', [$barcode]);
                $days = (int) ($template['default_shelf_life_days'] ?? 0) ?: estimateShelfLifeDays($item['name'], (string) $item['storage_location'])['days'];
                $newExpiry = dateAfterDays($days);
                db_exec(
                    'UPDATE food_items SET quantity = 1, expiration_date = ?, added_date = CURRENT_TIMESTAMP, archived_at = NULL, archive_reason = NULL WHERE id = ?',
                    'si',
                    [$newExpiry, $itemId]
                );
                $previous = 0;
                $note = 'New batch, expires ' . date('M j', strtotime($newExpiry));
            } else {
                db_exec('UPDATE food_items SET quantity = quantity + 1, archived_at = NULL, archive_reason = NULL WHERE id = ?', 'i', [$itemId]);
            }
            record_receipt($itemId, 1, $item['purchase_price'], 'scan', $user['id']);

            json_response([
                'success' => true,
                'action' => 'increment',
                'item' => [
                    'id' => $itemId,
                    'name' => $item['name'],
                    'barcode' => $barcode,
                    'previous_quantity' => $previous,
                    'new_quantity' => (int) db_value('SELECT quantity FROM food_items WHERE id = ?', 'i', [$itemId]),
                    'unit' => $item['unit'] ?: 'unit',
                    'note' => $note,
                ],
            ]);
        }

        $product = db_one('SELECT name, category_id, unit, default_price, default_shelf_life_days FROM products WHERE barcode = ? LIMIT 1', 's', [$barcode]);

        if ($product) {
            $categoryId = (int) ($product['category_id'] ?: first_category_id());
            if ($categoryId > 0) {
                $days = (int) ($product['default_shelf_life_days'] ?? 0) ?: estimateShelfLifeDays($product['name'])['days'];
                $unit = trim((string) $product['unit']) ?: 'unit';
                db_exec(
                    'INSERT INTO food_items (name, barcode, category_id, quantity, unit, purchase_price, expiration_date, storage_location, supplier_id)
                     VALUES (?, ?, ?, 1, ?, ?, ?, ?, NULL)',
                    'ssisdss',
                    [$product['name'], $barcode, $categoryId, $unit, $product['default_price'] !== null ? (float) $product['default_price'] : null, dateAfterDays($days), '']
                );
                $newId = db_last_id();
                record_receipt($newId, 1, $product['default_price'], 'scan', $user['id']);
                json_response([
                    'success' => true,
                    'action' => 'created_from_product',
                    'item' => [
                        'id' => $newId,
                        'name' => $product['name'],
                        'barcode' => $barcode,
                        'previous_quantity' => 0,
                        'new_quantity' => 1,
                        'unit' => $unit,
                        'note' => 'Added from product template',
                    ],
                ]);
            }
        }

        json_response([
            'success' => false,
            'not_found' => true,
            'can_add' => $canManage,
            'barcode' => $barcode,
            'message' => 'Barcode not found in inventory or product templates.',
        ]);
    }

    if ($action === 'item_details') {
        $item = db_one(
            'SELECT id, name, barcode, category_id, quantity, unit, purchase_price, selling_price, expiration_date, storage_location, supplier_id
             FROM food_items WHERE id = ?',
            'i',
            [(int) ($_POST['item_id'] ?? 0)]
        );
        if (!$item) {
            json_response(['found' => false, 'message' => 'Item not found.']);
        }
        json_response(['found' => true, 'item' => $item]);
    }

    if ($action === 'generate_barcode') {
        if (!$canManage) {
            json_response(['success' => false, 'message' => 'Only admins and managers can create barcodes.'], 403);
        }
        $code = generate_store_barcode();
        json_response(['success' => true, 'code' => $code, 'svg' => barcode_svg($code)]);
    }

    if ($action === 'barcode_preview') {
        $code = preg_replace('/\s+/', '', (string) ($_POST['code'] ?? ''));
        json_response([
            'success' => true,
            'svg' => barcode_svg($code),
            'type' => $code === '' ? '' : (is_valid_ean13($code) ? 'EAN-13' : (is_printable_barcode($code) ? 'Code 128' : 'invalid')),
        ]);
    }

    if ($action === 'predict') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $storage = trim((string) ($_POST['storage_location'] ?? ''));
        $manual = ($_POST['shelf_life_days'] ?? '') !== '' ? (int) $_POST['shelf_life_days'] : null;

        $prediction = estimateShelfLifeDays($name, $storage, $manual);
        $chilled = estimateBaselineShelfLifeDays($name, 'refrigerated')['days'];
        $frozen = estimateBaselineShelfLifeDays($name, 'freezer')['days'];

        json_response([
            'success' => true,
            'days' => $prediction['days'],
            'date' => dateAfterDays($prediction['days']),
            'source' => $prediction['source'],
            'samples' => $prediction['sample_count'],
            'chilled_days' => $chilled,
            'frozen_days' => $frozen,
        ]);
    }

    json_response(['success' => false, 'message' => 'Unknown action.'], 400);
}

/* =========================================================
   Form actions (Post/Redirect/Get)
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $manageActions = ['add_item', 'update_item', 'delete_item', 'bulk_delete', 'archive_item', 'generate_barcodes', 'receive'];

    if (in_array($action, $manageActions, true) && !$canManage) {
        flash('error', 'Only admins and managers can change inventory items.');
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'add_item') {
        [$d, $error] = read_item_form();
        if ($error) {
            flash('error', $error);
        } else {
            // No manufacturer barcode: give the item its own store barcode so it can be labelled and scanned.
            $generated = false;
            if ($d['barcode'] === null && isset($_POST['auto_barcode'])) {
                $d['barcode'] = generate_store_barcode();
                $generated = true;
            }
            db_exec(
                'INSERT INTO food_items (name, barcode, category_id, quantity, unit, purchase_price, selling_price, expiration_date, storage_location, supplier_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'ssiisddssi',
                [$d['name'], $d['barcode'], $d['category_id'], $d['quantity'], $d['unit'], $d['purchase_price'], $d['selling_price'], $d['expiration_date'], $d['storage_location'], $d['supplier_id']]
            );
            $newItemId = db_last_id();
            record_receipt($newItemId, $d['quantity'], $d['purchase_price'], 'initial', $user['id']);

            if ($d['barcode'] !== null && isset($_POST['save_as_product'])) {
                $shelfLife = (int) ($_POST['shelf_life_days'] ?? 0);
                if ($shelfLife <= 0 && $d['expiration_date'] > date('Y-m-d')) {
                    // No shelf life typed: suggest it from today to this batch's expiry date.
                    $shelfLife = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($d['expiration_date']))->days;
                }
                $shelfLife = $shelfLife > 0 ? $shelfLife : null;
                db_exec(
                    'INSERT INTO products (barcode, name, category_id, unit, default_price, default_shelf_life_days, created_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE name = VALUES(name), category_id = VALUES(category_id), unit = VALUES(unit),
                        default_price = VALUES(default_price), default_shelf_life_days = VALUES(default_shelf_life_days)',
                    'ssisdii',
                    [$d['barcode'], $d['name'], $d['category_id'], $d['unit'], $d['purchase_price'], $shelfLife, $user['id']]
                );
            }
            $labelLink = $d['barcode'] !== null
                ? ['label' => 'Print barcode label', 'href' => 'barcode_labels.php?ids=' . $newItemId . '&copies=' . max(1, min(50, $d['quantity'])), 'new_tab' => true]
                : null;
            flash(
                'success',
                '"' . $d['name'] . '" added to inventory.' . ($generated ? ' New store barcode: ' . $d['barcode'] . '.' : ''),
                $labelLink
            );
        }
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'update_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        [$d, $error] = read_item_form($itemId);
        $previousQty = db_value('SELECT quantity FROM food_items WHERE id = ?', 'i', [$itemId]);
        if (!$error && $previousQty === null) {
            $error = 'That item no longer exists.';
        }
        if ($error) {
            flash('error', $error);
        } else {
            db_exec(
                'UPDATE food_items SET name = ?, barcode = ?, category_id = ?, quantity = ?, unit = ?, purchase_price = ?,
                        selling_price = ?, expiration_date = ?, storage_location = ?, supplier_id = ?
                 WHERE id = ?',
                'ssiisddssii',
                [$d['name'], $d['barcode'], $d['category_id'], $d['quantity'], $d['unit'], $d['purchase_price'], $d['selling_price'], $d['expiration_date'], $d['storage_location'], $d['supplier_id'], $itemId]
            );
            // Stock added by hand still cost money, so upward corrections count as received stock.
            record_receipt($itemId, $d['quantity'] - (int) $previousQty, $d['purchase_price'], 'adjust', $user['id']);
            flash('success', '"' . $d['name'] . '" updated.');
        }
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'delete_item' || $action === 'bulk_delete') {
        $ids = $action === 'delete_item' ? [(int) ($_POST['item_id'] ?? 0)] : array_map('intval', (array) ($_POST['ids'] ?? []));
        $ids = array_values(array_filter(array_unique($ids), fn ($id) => $id > 0));
        $deleted = 0;
        $archived = 0;

        foreach ($ids as $id) {
            // Deleting would wipe waste and return history, so those items are archived instead.
            if (item_has_history($id)) {
                archive_item($id, 'removed');
                $archived++;
            } else {
                $deleted += db_exec('DELETE FROM food_items WHERE id = ?', 'i', [$id]);
            }
        }

        $parts = [];
        if ($deleted) $parts[] = $deleted . ' item' . ($deleted === 1 ? '' : 's') . ' deleted';
        if ($archived) $parts[] = $archived . ' moved to the archive (history kept)';
        flash($parts ? 'success' : 'error', $parts ? ucfirst(implode(', ', $parts)) . '.' : 'Nothing was selected.');
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'generate_barcodes') {
        $ids = isset($_POST['item_id']) ? [(int) $_POST['item_id']] : array_map('intval', (array) ($_POST['ids'] ?? []));
        $ids = array_values(array_filter(array_unique($ids), fn ($id) => $id > 0));
        $created = [];
        foreach ($ids as $id) {
            // Only items without a barcode; existing (manufacturer) codes are never replaced.
            $code = generate_store_barcode();
            if (db_exec("UPDATE food_items SET barcode = ? WHERE id = ? AND (barcode IS NULL OR barcode = '')", 'si', [$code, $id]) === 1) {
                $created[] = $id;
            }
        }
        if ($created) {
            flash(
                'success',
                count($created) === 1 ? 'Barcode created.' : count($created) . ' barcodes created.',
                ['label' => 'Print labels', 'href' => 'barcode_labels.php?ids=' . implode(',', $created), 'new_tab' => true]
            );
        } else {
            flash('error', $ids ? 'The selected items already have barcodes.' : 'Nothing was selected.');
        }
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'archive_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $name = db_value('SELECT name FROM food_items WHERE id = ?', 'i', [$itemId]);
        if ($name !== null) {
            archive_item($itemId, 'manual');
            flash('success', '"' . $name . '" moved to the archive. You can restore it from there.');
        }
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($action === 'receive') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $qty = (int) ($_POST['quantity'] ?? 0);
        $newExpiry = trim((string) ($_POST['expiration_date'] ?? ''));
        $item = db_one('SELECT name, purchase_price FROM food_items WHERE id = ?', 'i', [$itemId]);

        if (!$item || $qty < 1) {
            flash('error', 'Enter a quantity of at least 1.');
        } elseif ($newExpiry !== '' && !valid_date($newExpiry)) {
            flash('error', 'Please enter a valid expiration date.');
        } else {
            if ($newExpiry !== '') {
                db_exec('UPDATE food_items SET quantity = quantity + ?, expiration_date = ? WHERE id = ?', 'isi', [$qty, $newExpiry, $itemId]);
            } else {
                db_exec('UPDATE food_items SET quantity = quantity + ? WHERE id = ?', 'ii', [$qty, $itemId]);
            }
            record_receipt($itemId, $qty, $item['purchase_price'], 'receive', $user['id']);
            flash('success', 'Received ' . $qty . ' × "' . $item['name'] . '".');
        }
        redirect($_SERVER['REQUEST_URI']);
    }

    redirect($_SERVER['REQUEST_URI']);
}

/* =========================================================
   Listing: filters, sorting, pagination, CSV export
   ========================================================= */

$statusOptions = [
    '' => 'All Status',
    'fresh' => 'Fresh',
    'expiring' => 'Expiring soon',
    'low' => 'Low stock',
    'out' => 'Out of stock',
    'overstock' => 'Overstock',
];
$status = (string) ($_GET['status'] ?? '');
$status = ['expiring_soon' => 'expiring', 'about_to_expire' => 'expiring', 'good' => 'fresh'][$status] ?? $status;
if (!array_key_exists($status, $statusOptions)) {
    $status = '';
}

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => $status,
    'category' => (int) ($_GET['category'] ?? 0),
    'sort' => (string) ($_GET['sort'] ?? 'updated'),
    'dir' => ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc',
];

$sortColumns = [
    'barcode' => 'fi.barcode',
    'name' => 'fi.name',
    'category' => 'fc.name',
    'qty' => 'fi.quantity',
    'expiry' => 'fi.expiration_date',
    'updated' => 'fi.updated_at',
];
if (!isset($sortColumns[$filters['sort']])) {
    $filters['sort'] = 'updated';
}

$where = [ACTIVE_ITEM_SQL];
$types = '';
$params = [];

switch ($filters['status']) {
    case 'fresh':
        $where[] = 'fi.expiration_date > DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_SOON_DAYS . ' DAY)';
        break;
    case 'expiring':
        $where[] = 'fi.quantity > 0 AND fi.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . EXPIRING_SOON_DAYS . ' DAY)';
        break;
    case 'low':
        $where[] = 'fi.quantity <= ' . LOW_STOCK_THRESHOLD;
        break;
    case 'out':
        $where[] = 'fi.quantity = 0';
        break;
    case 'overstock':
        $where[] = 'fi.quantity >= ' . OVERSTOCK_THRESHOLD;
        break;
}

if ($filters['category'] > 0) {
    $where[] = 'fi.category_id = ?';
    $types .= 'i';
    $params[] = $filters['category'];
}

if ($filters['q'] !== '') {
    $where[] = '(fi.name LIKE ? OR fi.barcode LIKE ? OR fc.name LIKE ? OR s.name LIKE ? OR fi.storage_location LIKE ?)';
    $like = '%' . $filters['q'] . '%';
    $types .= 'sssss';
    array_push($params, $like, $like, $like, $like, $like);
}

$fromSql = "FROM food_items fi
            JOIN food_categories fc ON fc.id = fi.category_id
            LEFT JOIN suppliers s ON s.id = fi.supplier_id
            WHERE " . implode(' AND ', $where);
$orderSql = 'ORDER BY ' . $sortColumns[$filters['sort']] . ' ' . strtoupper($filters['dir']) . ', fi.id DESC';
$selectSql = "SELECT fi.*, fc.name AS category_name, s.name AS supplier_name,
                     DATEDIFF(fi.expiration_date, CURDATE()) AS days_left
              $fromSql $orderSql";

if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_all($selectSql, $types, $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Barcode', 'Item', 'Category', 'Quantity', 'Unit', 'Unit Cost', 'Selling Price', 'Stock Value', 'Expiration Date', 'Days Left', 'Freshness', 'Stock Status', 'Storage', 'Supplier']);
    foreach ($rows as $r) {
        $days = (int) $r['days_left'];
        $retail = retail_price($r['selling_price'], $r['purchase_price']);
        fputcsv($out, [
            $r['barcode'], $r['name'], $r['category_name'], $r['quantity'], $r['unit'],
            $r['purchase_price'], $retail !== null ? number_format($retail, 2, '.', '') : '',
            number_format((int) $r['quantity'] * (float) $r['purchase_price'], 2, '.', ''),
            $r['expiration_date'], $days, freshness_status($days)[0], stock_status((int) $r['quantity'])[0],
            $r['storage_location'], $r['supplier_name'],
        ]);
    }
    fclose($out);
    exit();
}

$perPage = 25;
$totalRows = (int) db_value("SELECT COUNT(*) $fromSql", $types, $params);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$items = db_all("$selectSql LIMIT ? OFFSET ?", $types . 'ii', array_merge($params, [$perPage, ($page - 1) * $perPage]));

$categories = db_all('SELECT id, name FROM food_categories ORDER BY name');
$suppliers = db_all('SELECT id, name FROM suppliers ORDER BY name');

function list_url(array $filters, array $overrides = []): string {
    $query = array_filter(array_merge($filters, $overrides), fn ($v) => $v !== '' && $v !== 0 && $v !== null);
    if (($query['sort'] ?? '') === 'updated' && ($query['dir'] ?? '') === 'desc') {
        unset($query['sort'], $query['dir']);
    }
    return 'food_items.php' . ($query ? '?' . http_build_query($query) : '');
}

function sort_header(array $filters, string $key, string $label, string $class = ''): string {
    $active = $filters['sort'] === $key;
    $nextDir = $active && $filters['dir'] === 'asc' ? 'desc' : 'asc';
    $icon = $active ? ($filters['dir'] === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort';
    return '<th class="' . e($class) . '"' . ($active ? ' aria-sort="' . ($filters['dir'] === 'asc' ? 'ascending' : 'descending') . '"' : '') . '>'
        . '<a href="' . e(list_url($filters, ['sort' => $key, 'dir' => $nextDir, 'page' => 0])) . '">' . e($label)
        . '<i class="fa-solid ' . $icon . ' sort-ind"></i></a></th>';
}

$activeNav = $filters['status'] === 'expiring' ? 'expiring' : ($filters['status'] === 'low' ? 'lowstock' : 'items');
$pageTitle = ['expiring' => 'Expiring Soon', 'low' => 'Low Stock'][$filters['status']] ?? 'Food Items';
$hasFilters = $filters['q'] !== '' || $filters['status'] !== '' || $filters['category'] > 0;

render_shell_start($user, $pageTitle, $activeNav);
page_header(
    $pageTitle,
    'Inventory · ' . implode(' · ', array_column(array_slice($categories, 0, 6), 'name'))
);
?>

<form class="toolbar" method="GET" action="food_items.php" id="filterForm">
    <div class="search-field">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search item / barcode…" aria-label="Search items">
    </div>
    <select class="select" name="status" data-autosubmit aria-label="Filter by status">
        <?php foreach ($statusOptions as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="select" name="category" data-autosubmit aria-label="Filter by category">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
            <option value="<?= (int) $cat['id'] ?>" <?= $filters['category'] === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($filters['sort'] !== 'updated' || $filters['dir'] !== 'desc'): ?>
        <input type="hidden" name="sort" value="<?= e($filters['sort']) ?>">
        <input type="hidden" name="dir" value="<?= e($filters['dir']) ?>">
    <?php endif; ?>
    <span class="count-pill"><?= number_format($totalRows) ?> item<?= $totalRows === 1 ? '' : 's' ?></span>
    <?php if ($hasFilters): ?>
        <a class="btn btn-sm btn-ghost" href="food_items.php"><i class="fa-solid fa-xmark"></i> Clear</a>
    <?php endif; ?>
    <span class="spacer"></span>
    <a class="btn" href="<?= e(list_url($filters, ['export' => 'csv', 'page' => 0])) ?>"><i class="fa-solid fa-download"></i> Export</a>
    <?php if ($canManage): ?>
        <button type="button" class="btn btn-purple" id="scanToggle"><i class="fa-solid fa-barcode"></i> Quick Scan</button>
        <button type="button" class="btn btn-primary" data-open-modal="addItemModal"><i class="fa-solid fa-plus"></i> Add Item</button>
    <?php endif; ?>
</form>

<?php if (!$canManage): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-eye"></i>
        <span>Your account is <strong>view and print only</strong>. Tick items to print their barcode labels, or use Export. Ask a manager to add or change stock.</span>
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="card scan-panel hidden" id="scanPanel">
    <div class="card-head">
        <div>
            <div class="card-title"><i class="fa-solid fa-barcode"></i> Quick Scan — stock in</div>
            <div class="card-sub">Each scan adds 1 unit. Unknown barcodes open the Add Item form.</div>
        </div>
        <span class="scan-live" id="scanLive">Scanner idle</span>
    </div>
    <div class="card-body">
        <div class="scan-row">
            <input type="text" class="input scan-input" id="scanInput" placeholder="Scan or type a barcode, then press Enter" autocomplete="off" inputmode="numeric" aria-label="Barcode">
            <button type="button" class="btn" id="scanClear"><i class="fa-solid fa-eraser"></i> Clear</button>
            <a href="<?= e(list_url($filters)) ?>" class="btn btn-ghost" title="Reload the list to include newly added items"><i class="fa-solid fa-rotate"></i> Refresh list</a>
        </div>
        <div id="scanStatus" class="alert hidden" style="margin:12px 0 0"></div>
        <ul class="list" id="scanLog" style="margin-top:8px"></ul>
    </div>
</div>
<?php endif; ?>

<form method="POST" id="bulkForm">
    <?= csrf_field() ?>
</form>
<div class="alert alert-info hidden" id="bulkBar">
    <i class="fa-solid fa-square-check"></i>
    <span><strong id="bulkCount">0</strong> selected</span>
    <button type="button" class="btn btn-sm" id="bulkPrint"><i class="fa-solid fa-print"></i> Print labels</button>
    <?php if ($canManage): ?>
        <button type="submit" form="bulkForm" name="action" value="generate_barcodes" class="btn btn-sm"><i class="fa-solid fa-barcode"></i> Generate missing barcodes</button>
        <button type="submit" form="bulkForm" name="action" value="bulk_delete" class="btn btn-sm btn-danger"
                data-confirm="Delete the selected items? Items with waste or return history are moved to the archive instead."><i class="fa-solid fa-trash"></i> Delete selected</button>
    <?php endif; ?>
</div>

<div class="table-card">
    <?php if (!$items): ?>
        <div class="empty">
            <i class="fa-solid fa-box-open"></i>
            <?php if ($hasFilters): ?>
                <strong>No items match these filters</strong>
                <a href="food_items.php">Clear filters</a>
            <?php else: ?>
                <strong>Your inventory is empty</strong>
                <?= $canManage ? 'Add your first item or use Quick Scan to stock in by barcode.' : 'Ask a manager to add items.' ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" id="checkAll" aria-label="Select all"></th>
                    <?= sort_header($filters, 'barcode', 'Barcode') ?>
                    <?= sort_header($filters, 'name', 'Item', 'col-item') ?>
                    <?= sort_header($filters, 'category', 'Category') ?>
                    <?= sort_header($filters, 'qty', 'Stock', 'center') ?>
                    <?= sort_header($filters, 'expiry', 'Expiry') ?>
                    <th>Freshness</th>
                    <th>Status</th>
                    <th class="center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item):
                    $id = (int) $item['id'];
                    $qty = (int) $item['quantity'];
                    $days = (int) $item['days_left'];
                    [$stockLabel, $stockTone] = stock_status($qty);
                    [$freshLabel, $freshTone] = freshness_status($days);
                    $discount = $qty > 0 ? discount_percent($days) : 0;
                    $retail = retail_price($item['selling_price'], $item['purchase_price']);
                    ?>
                    <tr data-item-id="<?= $id ?>">
                        <td class="col-check"><input type="checkbox" name="ids[]" value="<?= $id ?>" form="bulkForm" class="row-check" aria-label="Select <?= e($item['name']) ?>"></td>
                        <td>
                            <?php if ($item['barcode']): ?>
                                <a class="sku sku-link" href="barcode_labels.php?ids=<?= $id ?>" target="_blank" rel="noopener" title="Print barcode label"><?= e($item['barcode']) ?></a>
                            <?php else: ?>
                                <span class="muted" title="No barcode yet<?= $canManage ? ' — use ⋮ › Generate barcode' : '' ?>">No barcode</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-item">
                            <div class="cell-title"><?= e($item['name']) ?></div>
                            <div class="cell-sub">
                                <?= e($item['supplier_name'] ?? 'No supplier') ?><?= $item['storage_location'] ? ' · ' . e($item['storage_location']) : '' ?>
                            </div>
                        </td>
                        <td><span class="chip chip-green"><?= e($item['category_name']) ?></span></td>
                        <td class="center nowrap">
                            <span class="qty-big" data-qty><?= $qty ?></span> <span class="muted"><?= e($item['unit']) ?></span>
                            <?php if ($item['purchase_price'] !== null || $retail !== null): ?>
                                <div class="cell-sub num" title="Cost / selling price<?= $item['selling_price'] === null && $retail !== null ? ' (default markup)' : '' ?>">
                                    <?= $item['purchase_price'] !== null ? peso($item['purchase_price']) : '—' ?> → <?= $retail !== null ? peso($retail) : '—' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap"><?= date('M j, Y', strtotime($item['expiration_date'])) ?></td>
                        <td>
                            <div class="chip-stack">
                                <span class="chip chip-<?= $freshTone ?>"><?= e($freshLabel) ?></span>
                                <?php if ($discount): ?><span class="chip chip-purple" title="Suggested markdown"><?= $discount ?>% off</span><?php endif; ?>
                            </div>
                        </td>
                        <td><span class="chip chip-<?= $stockTone ?>" data-stock-chip><?= e($stockLabel) ?></span></td>
                        <td class="center">
                            <div class="menu-wrap">
                                <button type="button" class="kebab" data-menu aria-label="Actions for <?= e($item['name']) ?>"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                <div class="menu">
                                    <?php if ($canManage): ?>
                                        <button type="button" class="menu-item" data-edit="<?= $id ?>"><i class="fa-solid fa-pen"></i> Edit item</button>
                                        <button type="button" class="menu-item" data-receive="<?= $id ?>" data-name="<?= e($item['name']) ?>" data-unit="<?= e($item['unit']) ?>"><i class="fa-solid fa-dolly"></i> Receive stock</button>
                                        <?php if ($qty > 0): ?>
                                            <a class="menu-item" href="log_waste.php?item=<?= $id ?>"><i class="fa-solid fa-trash-can"></i> Log waste</a>
                                            <a class="menu-item" href="returns.php?item=<?= $id ?>"><i class="fa-solid fa-rotate-left"></i> Return to supplier</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($item['barcode']): ?>
                                        <a class="menu-item" href="barcode_labels.php?ids=<?= $id ?>" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i> Print barcode label</a>
                                    <?php elseif ($canManage): ?>
                                        <form method="POST">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="generate_barcodes">
                                            <input type="hidden" name="item_id" value="<?= $id ?>">
                                            <button type="submit" class="menu-item"><i class="fa-solid fa-barcode"></i> Generate barcode</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canManage): ?>
                                        <div class="menu-sep"></div>
                                        <form method="POST" data-confirm="Move &quot;<?= e($item['name']) ?>&quot; to the archive? It will be hidden from inventory until restored.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="archive_item">
                                            <input type="hidden" name="item_id" value="<?= $id ?>">
                                            <button type="submit" class="menu-item"><i class="fa-solid fa-box-archive"></i> Archive</button>
                                        </form>
                                        <form method="POST" data-confirm="Delete &quot;<?= e($item['name']) ?>&quot;? Items with waste or return history are archived instead.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="item_id" value="<?= $id ?>">
                                            <button type="submit" class="menu-item danger"><i class="fa-solid fa-trash"></i> Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-foot">
            <span>Showing <?= ($page - 1) * $perPage + 1 ?>–<?= min($page * $perPage, $totalRows) ?> of <?= number_format($totalRows) ?></span>
            <div class="pager">
                <?php if ($page > 1): ?>
                    <a class="btn btn-sm" href="<?= e(list_url($filters, ['page' => $page - 1])) ?>"><i class="fa-solid fa-chevron-left"></i> Prev</a>
                <?php endif; ?>
                <span class="btn btn-sm btn-ghost" aria-current="page">Page <?= $page ?> / <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="btn btn-sm" href="<?= e(list_url($filters, ['page' => $page + 1])) ?>">Next <i class="fa-solid fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
function item_form_fields(string $prefix, array $categories, array $suppliers, bool $isAdd): void {
    ?>
    <div class="form-grid">
        <div class="field span-2">
            <label for="<?= $prefix ?>Name">Item name <span class="req">*</span></label>
            <input class="input" type="text" name="name" id="<?= $prefix ?>Name" required maxlength="255">
        </div>
        <div class="field span-2">
            <label for="<?= $prefix ?>Barcode">Barcode</label>
            <div class="barcode-field">
                <div class="barcode-input">
                    <input class="input mono" type="text" name="barcode" id="<?= $prefix ?>Barcode" data-barcode-preview="<?= $prefix ?>BarcodePreview"
                           placeholder="<?= $isAdd ? 'Scan the product barcode, or leave blank' : 'e.g. 4800016644290' ?>" maxlength="48" autocomplete="off">
                    <button type="button" class="btn" data-generate-barcode="<?= $prefix ?>Barcode" title="Create a new store barcode"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
                </div>
                <div class="barcode-preview" id="<?= $prefix ?>BarcodePreview" aria-live="polite"></div>
            </div>
            <?php if ($isAdd): ?>
                <label class="check" style="margin-top:4px"><input type="checkbox" name="auto_barcode" value="1" checked> Create a store barcode automatically if left blank</label>
            <?php else: ?>
                <span class="hint">Clear the field to remove the barcode.</span>
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>Category">Category <span class="req">*</span></label>
            <select class="select" name="category_id" id="<?= $prefix ?>Category" required>
                <option value="">Select category</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>"><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>Quantity">Quantity <span class="req">*</span></label>
            <input class="input" type="number" name="quantity" id="<?= $prefix ?>Quantity" min="0" step="1" required>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>Unit">Unit <span class="req">*</span></label>
            <select class="select" name="unit" id="<?= $prefix ?>Unit" required>
                <option value="">Select unit</option>
                <option value="unit">Unit</option>
                <option value="tray">Tray</option>
                <option value="bundle">Bundle</option>
                <option value="box">Box</option>
                <option value="kg">Kilo (kg)</option>
            </select>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>Price">Purchase price (₱ per unit)</label>
            <input class="input" type="number" step="0.01" min="0" name="purchase_price" id="<?= $prefix ?>Price" data-cost-for="<?= $prefix ?>SellHint">
        </div>
        <div class="field">
            <label for="<?= $prefix ?>Sell">Selling price (₱ per unit)</label>
            <input class="input" type="number" step="0.01" min="0" name="selling_price" id="<?= $prefix ?>Sell" placeholder="Auto" data-sell-for="<?= $prefix ?>SellHint">
            <span class="hint" id="<?= $prefix ?>SellHint" data-markup="<?= DEFAULT_MARKUP_PERCENT ?>">Blank = cost + <?= DEFAULT_MARKUP_PERCENT ?>% markup</span>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>Expiry">Expiration date <span class="req">*</span></label>
            <input class="input" type="date" name="expiration_date" id="<?= $prefix ?>Expiry" required>
            <?php if ($isAdd): ?><span class="hint" id="predictHint"></span><span class="hint" id="shelfLifeHint"></span><?php endif; ?>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>Storage">Storage location</label>
            <select class="select" name="storage_location" id="<?= $prefix ?>Storage">
                <option value="">Select storage</option>
                <option value="Shelf">Shelf</option>
                <option value="Fridge">Fridge</option>
                <option value="Frozen">Frozen</option>
            </select>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>Supplier">Supplier</label>
            <select class="select" name="supplier_id" id="<?= $prefix ?>Supplier">
                <option value="">No supplier</option>
                <?php foreach ($suppliers as $sup): ?>
                    <option value="<?= (int) $sup['id'] ?>"><?= e($sup['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php
}
?>

<?php if ($canManage): ?>
<div class="modal" id="addItemModal" role="dialog" aria-modal="true" aria-labelledby="addItemTitle">
    <form class="modal-card" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_item">
        <div class="modal-head">
            <h2 id="addItemTitle">Add food item</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <?php item_form_fields('add', $categories, $suppliers, true); ?>
            <div class="field" style="flex-direction:row;align-items:center;gap:16px;flex-wrap:wrap">
                <label class="check"><input type="checkbox" name="save_as_product" checked> Save as product template (for future scans)</label>
                <label class="check" style="gap:6px">Shelf life
                    <input class="input" type="number" name="shelf_life_days" id="addShelfLife" min="1" style="width:84px;height:32px" placeholder="days">
                </label>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add item</button>
        </div>
    </form>
</div>

<div class="modal" id="editItemModal" role="dialog" aria-modal="true" aria-labelledby="editItemTitle">
    <form class="modal-card" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_item">
        <input type="hidden" name="item_id" id="editId">
        <div class="modal-head">
            <h2 id="editItemTitle">Edit food item</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <?php item_form_fields('edit', $categories, $suppliers, false); ?>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>
        </div>
    </form>
</div>

<div class="modal" id="receiveModal" role="dialog" aria-modal="true" aria-labelledby="receiveTitle">
    <form class="modal-card sm" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="receive">
        <input type="hidden" name="item_id" id="receiveId">
        <div class="modal-head">
            <h2 id="receiveTitle">Receive stock</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p class="muted" style="margin-top:0">Adding stock to <strong id="receiveName"></strong></p>
            <div class="field">
                <label for="receiveQty">Quantity received <span class="req">*</span></label>
                <input class="input" type="number" name="quantity" id="receiveQty" min="1" step="1" required autofocus>
            </div>
            <div class="field">
                <label for="receiveExpiry">New expiration date</label>
                <input class="input" type="date" name="expiration_date" id="receiveExpiry" min="<?= date('Y-m-d') ?>">
                <span class="hint">Leave blank to keep the current date.</span>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-dolly"></i> Receive</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php ob_start(); ?>
<script>
(function () {
    const canManage = <?= $canManage ? 'true' : 'false' ?>;
    const $ = (id) => document.getElementById(id);

    /* ---------- Filters auto-submit ---------- */
    document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => $('filterForm').submit()));

    /* ---------- Bulk selection ---------- */
    const checks = Array.from(document.querySelectorAll('.row-check'));
    function syncBulk() {
        const n = checks.filter((c) => c.checked).length;
        if ($('bulkBar')) {
            $('bulkBar').classList.toggle('hidden', n === 0);
            $('bulkCount').textContent = n;
        }
        if ($('checkAll')) {
            $('checkAll').checked = n > 0 && n === checks.length;
            $('checkAll').indeterminate = n > 0 && n < checks.length;
        }
    }
    checks.forEach((c) => c.addEventListener('change', syncBulk));
    $('checkAll')?.addEventListener('change', (e) => { checks.forEach((c) => { c.checked = e.target.checked; }); syncBulk(); });

    /* ---------- Row actions ---------- */
    document.addEventListener('click', (e) => {
        const editBtn = e.target.closest('[data-edit]');
        if (editBtn) { loadEdit(editBtn.dataset.edit); return; }
        const recvBtn = e.target.closest('[data-receive]');
        if (recvBtn) {
            $('receiveId').value = recvBtn.dataset.receive;
            $('receiveName').textContent = recvBtn.dataset.name;
            $('receiveQty').value = '';
            $('receiveExpiry').value = '';
            document.querySelectorAll('.menu.open').forEach((m) => m.classList.remove('open'));
            openModal('receiveModal');
        }
    });

    function setSelect(el, value) {
        const v = value === null || value === undefined ? '' : String(value);
        el.value = Array.from(el.options).some((o) => o.value === v) ? v : '';
    }

    // Like setSelect, but a saved value that is no longer a listed choice is kept as an extra option.
    function setSelectKeep(el, value) {
        el.querySelector('option[data-legacy]')?.remove();
        const v = value === null || value === undefined ? '' : String(value).trim();
        const match = Array.from(el.options).find((o) => o.value.toLowerCase() === v.toLowerCase());
        if (!match && v !== '') {
            const opt = new Option(v, v);
            opt.dataset.legacy = '1';
            el.add(opt);
        }
        el.value = match ? match.value : v;
    }

    function loadEdit(id) {
        document.querySelectorAll('.menu.open').forEach((m) => m.classList.remove('open'));
        postForm('food_items.php', { action: 'item_details', item_id: id }).then((data) => {
            if (!data.found) { alert(data.message || 'Could not load item.'); return; }
            const it = data.item;
            $('editId').value = it.id;
            $('editName').value = it.name || '';
            $('editBarcode').value = it.barcode || '';
            previewBarcode($('editBarcode'));
            setSelect($('editCategory'), it.category_id);
            $('editQuantity').value = it.quantity ?? 0;
            setSelectKeep($('editUnit'), it.unit);
            $('editPrice').value = it.purchase_price ?? '';
            $('editExpiry').value = it.expiration_date || '';
            setSelectKeep($('editStorage'), it.storage_location);
            setSelect($('editSupplier'), it.supplier_id);
            $('editSell').value = it.selling_price ?? '';
            updateSellHint($('editSellHint'));
            openModal('editItemModal');
        }).catch(() => alert('Error loading item details.'));
    }

    /* ---------- Expiration prediction (add form) ---------- */
    function predict() {
        const name = $('addName')?.value.trim();
        // A shelf life we filled in from the expiry date is a suggestion, not the user's own input.
        const shelfInput = $('addShelfLife');
        const shelf = shelfInput && shelfInput.dataset.auto !== '1' ? shelfInput.value.trim() : '';
        if (!name && !shelf) return;
        postForm('food_items.php', {
            action: 'predict', name, shelf_life_days: shelf, storage_location: $('addStorage')?.value.trim() || '',
        }).then((d) => {
            if (!d.success) return;
            if (!$('addExpiry').value || $('addExpiry').dataset.auto === '1') {
                $('addExpiry').value = d.date;
                $('addExpiry').dataset.auto = '1';
                suggestShelfLife();
            }
            const src = d.source === 'historical_blended' ? `based on ${d.samples} past batches` : (d.source === 'manual_input' ? 'from shelf life' : 'estimate');
            $('predictHint').textContent = `Suggested: ${d.days} day(s) (${src}). Chilled ${d.chilled_days}d · Frozen ${d.frozen_days}d.`;
        }).catch(() => {});
    }
    ['addName', 'addShelfLife'].forEach((id) => $(id)?.addEventListener('blur', predict));
    $('addStorage')?.addEventListener('change', predict);

    /* ---------- Barcodes: generate + live preview ---------- */
    const previewTimers = {};
    function previewBarcode(input) {
        const box = $(input.dataset.barcodePreview);
        if (!box) return;
        clearTimeout(previewTimers[input.id]);
        const code = input.value.replace(/\s+/g, '');
        if (!code) { box.innerHTML = ''; return; }
        previewTimers[input.id] = setTimeout(() => {
            postForm('food_items.php', { action: 'barcode_preview', code }).then((d) => {
                if (input.value.replace(/\s+/g, '') !== code) return; // user kept typing
                box.innerHTML = d.svg
                    ? `${d.svg}<span class="bc-type">${escapeHtml(d.type)}</span>`
                    : '<span class="bc-type">Letters, numbers and symbols only</span>';
            }).catch(() => {});
        }, 250);
    }
    document.querySelectorAll('[data-barcode-preview]').forEach((input) => {
        input.addEventListener('input', () => previewBarcode(input));
    });
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-generate-barcode]');
        if (!btn) return;
        const input = $(btn.dataset.generateBarcode);
        if (input.value.trim() && !confirm('Replace the current barcode with a new store barcode?')) return;
        btn.disabled = true;
        postForm('food_items.php', { action: 'generate_barcode' }).then((d) => {
            if (!d.success) { alert(d.message || 'Could not generate a barcode.'); return; }
            input.value = d.code;
            $(input.dataset.barcodePreview).innerHTML = `${d.svg}<span class="bc-type">New store barcode (EAN-13)</span>`;
        }).catch(() => alert('Could not generate a barcode.')).finally(() => { btn.disabled = false; });
    });
    $('bulkPrint')?.addEventListener('click', () => {
        const ids = checks.filter((c) => c.checked).map((c) => c.value);
        if (ids.length) window.open('barcode_labels.php?ids=' + ids.join(','), '_blank', 'noopener');
    });

    /* ---------- Selling price hint (default markup preview + margin) ---------- */
    function updateSellHint(hint) {
        if (!hint) return;
        const cost = parseFloat(document.querySelector(`[data-cost-for="${hint.id}"]`)?.value);
        const sell = parseFloat(document.querySelector(`[data-sell-for="${hint.id}"]`)?.value);
        const markup = parseFloat(hint.dataset.markup) || 0;
        if (!isNaN(sell)) {
            hint.textContent = !isNaN(cost) && cost > 0
                ? `Margin ₱${(sell - cost).toFixed(2)} (${(((sell - cost) / cost) * 100).toFixed(1)}% over cost)`
                : 'Custom selling price';
        } else {
            hint.textContent = !isNaN(cost)
                ? `Blank = cost + ${markup}% → ₱${(cost * (1 + markup / 100)).toFixed(2)}`
                : `Blank = cost + ${markup}% markup`;
        }
    }
    document.querySelectorAll('[data-cost-for], [data-sell-for]').forEach((input) => {
        input.addEventListener('input', () => updateSellHint($(input.dataset.costFor || input.dataset.sellFor)));
    });
    $('addExpiry')?.addEventListener('input', (e) => { e.target.dataset.auto = '0'; suggestShelfLife(); });

    /* ---------- Shelf life suggestion (from the expiry date) ---------- */
    function daysUntil(dateValue) {
        const [y, m, d] = dateValue.split('-').map(Number);
        if (!y || !m || !d) return null;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        return Math.round((new Date(y, m - 1, d) - today) / 86400000);
    }
    function suggestShelfLife() {
        const expiry = $('addExpiry');
        const shelf = $('addShelfLife');
        const hint = $('shelfLifeHint');
        if (!expiry || !hint) return;
        const days = expiry.value ? daysUntil(expiry.value) : null;
        if (days === null || days < 1) {
            if (shelf && shelf.dataset.auto === '1') { shelf.value = ''; delete shelf.dataset.auto; }
            hint.textContent = days !== null ? 'Expiry date is today or already past, so there is no shelf life to suggest.' : '';
            return;
        }
        if (shelf && (shelf.value === '' || shelf.dataset.auto === '1')) {
            shelf.value = days;
            shelf.dataset.auto = '1';
        }
        const text = `Suggested shelf life: ${days} day${days === 1 ? '' : 's'} (from today to the expiry date).`;
        hint.textContent = shelf && shelf.value !== '' && shelf.value !== String(days) ? `${text} You entered ${shelf.value}.` : text;
    }
    $('addShelfLife')?.addEventListener('input', (e) => { delete e.target.dataset.auto; suggestShelfLife(); });

    /* ---------- Quick scan (admins and managers only) ---------- */
    const panel = $('scanPanel');
    if (!panel) return;
    const input = $('scanInput');
    const live = $('scanLive');
    const statusBox = $('scanStatus');
    const log = $('scanLog');
    let queue = Promise.resolve();
    let firstKeyAt = 0;
    let autoTimer = null;

    function panelOpen() { return !panel.classList.contains('hidden'); }
    function setPanel(open) {
        panel.classList.toggle('hidden', !open);
        $('scanToggle').classList.toggle('btn-purple', !open);
        $('scanToggle').innerHTML = open ? '<i class="fa-solid fa-xmark"></i> Close Scan' : '<i class="fa-solid fa-barcode"></i> Quick Scan';
        try { localStorage.setItem('scanPanel', open ? '1' : '0'); } catch (e) {}
        if (open) focusScanner();
        updateLive();
    }
    function focusScanner() {
        if (panelOpen() && !anyModalOpen()) input.focus({ preventScroll: true });
    }
    function updateLive() {
        const on = panelOpen() && document.activeElement === input;
        live.classList.toggle('on', on);
        live.textContent = on ? 'Ready to scan' : 'Click the box to scan';
    }
    function showStatus(type, html) {
        statusBox.className = 'alert alert-' + type;
        statusBox.innerHTML = html;
    }

    function addLog(item) {
        const li = document.createElement('li');
        li.innerHTML = `<div><div class="cell-title">${escapeHtml(item.name)}</div>
            <div class="cell-sub"><span class="sku">${escapeHtml(item.barcode)}</span> · ${new Date().toLocaleTimeString()}${item.note ? ' · ' + escapeHtml(item.note) : ''}</div></div>
            <span class="chip chip-green">${escapeHtml(item.previous_quantity)} → ${escapeHtml(item.new_quantity)} ${escapeHtml(item.unit)}</span>`;
        log.prepend(li);
        while (log.children.length > 6) log.lastChild.remove();
    }

    function updateRow(item) {
        const row = document.querySelector(`tr[data-item-id="${item.id}"]`);
        if (!row) return;
        row.querySelector('[data-qty]').textContent = item.new_quantity;
        row.classList.remove('row-flash');
        void row.offsetWidth;
        row.classList.add('row-flash');
    }

    function process(barcode) {
        showStatus('info', `<i class="fa-solid fa-spinner fa-spin"></i><span>Looking up <span class="sku">${escapeHtml(barcode)}</span>…</span>`);
        return postForm('food_items.php', { action: 'scan', barcode }).then((data) => {
            if (data.success) {
                addLog(data.item);
                updateRow(data.item);
                showStatus('success', `<i class="fa-solid fa-circle-check"></i><span><strong>${escapeHtml(data.item.name)}</strong> now ${escapeHtml(data.item.new_quantity)} ${escapeHtml(data.item.unit)}${data.item.note ? ' · ' + escapeHtml(data.item.note) : ''}</span>`);
                return;
            }
            if (data.not_found && data.can_add) {
                showStatus('warning', `<i class="fa-solid fa-circle-question"></i><span>New barcode <span class="sku">${escapeHtml(barcode)}</span> — fill in the details to add it.</span>`);
                $('addBarcode').value = barcode;
                previewBarcode($('addBarcode'));
                $('addQuantity').value = 1;
                $('addExpiry').value = '';
                $('addExpiry').dataset.auto = '1';
                suggestShelfLife();
                openModal('addItemModal');
                setTimeout(() => $('addName').focus(), 80);
                return;
            }
            showStatus('error', `<i class="fa-solid fa-circle-exclamation"></i><span>${escapeHtml(data.message || 'Scan failed.')}${data.not_found ? ' Ask a manager to add it.' : ''}</span>`);
        }).catch(() => showStatus('error', '<i class="fa-solid fa-circle-exclamation"></i><span>Network error while scanning. Try again.</span>'));
    }

    function submitScan() {
        clearTimeout(autoTimer);
        const code = input.value.replace(/\s+/g, '');
        input.value = '';
        firstKeyAt = 0;
        if (!code) return;
        queue = queue.then(() => process(code));
    }

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); submitScan(); }
    });
    // Scanners configured without an Enter suffix: submit once a fast burst of characters stops.
    input.addEventListener('input', () => {
        if (!firstKeyAt) firstKeyAt = Date.now();
        clearTimeout(autoTimer);
        autoTimer = setTimeout(() => {
            const len = input.value.trim().length;
            const msPerChar = (Date.now() - firstKeyAt - 150) / Math.max(1, len);
            if (len >= 6 && msPerChar < 35) submitScan(); else firstKeyAt = 0;
        }, 150);
    });
    input.addEventListener('focus', updateLive);
    input.addEventListener('blur', updateLive);
    $('scanClear').addEventListener('click', () => { input.value = ''; log.innerHTML = ''; statusBox.className = 'alert hidden'; focusScanner(); });
    $('scanToggle').addEventListener('click', () => setPanel(!panelOpen()));

    // While the panel is open, keystrokes typed outside any field go to the scanner.
    document.addEventListener('keydown', (e) => {
        if (!panelOpen() || anyModalOpen() || e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
        const tag = document.activeElement?.tagName;
        if (['INPUT', 'SELECT', 'TEXTAREA'].includes(tag) || document.activeElement?.isContentEditable) return;
        input.focus({ preventScroll: true });
    });
    // Return to the scanner after a modal closes.
    const modalWatcher = new MutationObserver(() => { if (!anyModalOpen()) focusScanner(); });
    document.querySelectorAll('.modal').forEach((m) => modalWatcher.observe(m, { attributes: true, attributeFilter: ['class'] }));

    let startOpen = new URLSearchParams(location.search).get('scan') === '1';
    try { startOpen = startOpen || localStorage.getItem('scanPanel') === '1'; } catch (e) {}
    if (startOpen) setPanel(true);
})();
</script>
<?php render_shell_end(ob_get_clean()); ?>
