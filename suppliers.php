<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$canManage = can_manage($user);

// Stock that came in from a supplier: everything received except upward corrections made by hand.
const SUPPLIER_RECEIPT_SQL = "sr.source <> 'adjust'";

// "today", "yesterday", "5 days ago", "3 weeks ago", "2 months ago".
function time_ago(string $datetime): string {
    $days = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable(substr($datetime, 0, 10)))->days;
    return match (true) {
        $days === 0 => 'today',
        $days === 1 => 'yesterday',
        $days < 14 => $days . ' days ago',
        $days < 60 => intdiv($days, 7) . ' weeks ago',
        $days < 730 => intdiv($days, 30) . ' months ago',
        default => intdiv($days, 365) . ' years ago',
    };
}

// The most recent of the last stock received from the supplier and their last completed delivery.
function last_business(?string $received, ?string $delivered): ?string {
    $dates = array_filter([$received, $delivered]);
    return $dates ? max($dates) : null;
}

function category_list(): array {
    return array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name']], db_all('SELECT id, name FROM food_categories ORDER BY name'));
}

/* ---------- Supplier details (opened from a card; read-only, so staff can see it too) ---------- */

if (isset($_GET['details'])) {
    $id = (int) $_GET['details'];
    $s = db_one('SELECT id, name, contact_person, email, phone, address FROM suppliers WHERE id = ?', 'i', [$id]);
    if (!$s) {
        json_response(['success' => false, 'message' => 'That supplier no longer exists.'], 404);
    }

    $products = [];
    foreach (db_all(
        'SELECT sp.name, fc.name AS category FROM supplier_products sp
         JOIN food_categories fc ON fc.id = sp.category_id
         WHERE sp.supplier_id = ? ORDER BY fc.name, sp.name',
        'i',
        [$id]
    ) as $p) {
        $products[$p['category']][] = $p['name'];
    }

    // Everything received from them, one row per product (all batches, archived ones included).
    $delivered = db_all(
        'SELECT MIN(fi.name) AS name, MIN(fc.name) AS category, MAX(fi.unit) AS unit, SUM(sr.quantity) AS units,
                COUNT(*) AS times, SUM(sr.quantity * sr.unit_cost) AS cost, MIN(sr.received_at) AS first_at, MAX(sr.received_at) AS last_at
         FROM stock_receipts sr
         JOIN food_items fi ON fi.id = sr.food_item_id
         JOIN food_categories fc ON fc.id = fi.category_id
         WHERE fi.supplier_id = ? AND ' . SUPPLIER_RECEIPT_SQL . '
         GROUP BY LOWER(TRIM(fi.name))
         ORDER BY last_at DESC',
        'i',
        [$id]
    );
    $deliveries = db_all(
        "SELECT id, items, amount, payment_status, delivered_at FROM supplier_deliveries
         WHERE supplier_id = ? AND status = 'delivered' ORDER BY delivered_at DESC",
        'i',
        [$id]
    );

    $lastReceived = $delivered ? max(array_column($delivered, 'last_at')) : null;
    $lastDelivered = $deliveries[0]['delivered_at'] ?? null;
    $last = last_business($lastReceived, $lastDelivered);
    $first = array_filter([$delivered ? min(array_column($delivered, 'first_at')) : null, $deliveries ? end($deliveries)['delivered_at'] : null]);

    json_response([
        'success' => true,
        'supplier' => [
            'name' => $s['name'],
            'contact_person' => $s['contact_person'],
            'phone' => $s['phone'],
            'email' => $s['email'],
            'address' => $s['address'],
        ],
        'last_business' => $last ? [
            'date' => date('D, M j, Y', strtotime($last)),
            'ago' => time_ago($last),
            'what' => $last === $lastDelivered && $deliveries ? 'Delivery ' . delivery_ref((int) $deliveries[0]['id']) . ' arrived' : 'Stock received',
        ] : null,
        'since' => $first ? date('M j, Y', strtotime(min($first))) : null,
        'products' => array_map(fn ($cat, $names) => ['category' => $cat, 'names' => $names], array_keys($products), $products),
        'delivered' => array_map(fn ($r) => [
            'name' => $r['name'],
            'category' => $r['category'],
            'units' => number_format((int) $r['units']) . ' ' . ($r['unit'] ?: 'units'),
            'times' => (int) $r['times'],
            'cost' => peso($r['cost'], 0),
            'last' => date('M j, Y', strtotime($r['last_at'])),
            'ago' => time_ago($r['last_at']),
        ], $delivered),
        'deliveries' => array_map(fn ($d) => [
            'ref' => delivery_ref((int) $d['id']),
            'href' => 'deliveries.php?view=' . (int) $d['id'],
            'date' => date('M j, Y', strtotime($d['delivered_at'])),
            'items' => mb_strimwidth(preg_replace('/\s+/', ' ', $d['items']), 0, 90, '…'),
            'amount' => $d['amount'] !== null ? peso($d['amount']) : null,
            'paid' => $d['payment_status'] === 'paid',
        ], array_slice($deliveries, 0, 5)),
        'deliveries_total' => count($deliveries),
        'totals' => [
            'cost' => peso(array_sum(array_column($delivered, 'cost')), 0),
            'units' => number_format(array_sum(array_map('intval', array_column($delivered, 'units')))),
        ],
    ]);
}

/* ---------- Changes (admins and managers) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    if (($_POST['action'] ?? '') !== 'add_category') {
        json_response(['success' => false, 'message' => 'Unknown action.'], 400);
    }
    $name = trim(preg_replace('/\s+/', ' ', (string) ($_POST['name'] ?? '')));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
        json_response(['success' => false, 'message' => 'A category name needs 2 to 60 characters.']);
    }
    // Names compare without regard to case, so "dairy" finds the existing "Dairy".
    $existing = db_one('SELECT id, name FROM food_categories WHERE name = ?', 's', [$name]);
    if ($existing) {
        json_response(['success' => true, 'created' => false, 'category' => ['id' => (int) $existing['id'], 'name' => $existing['name']]]);
    }
    db_exec('INSERT INTO food_categories (name) VALUES (?)', 's', [$name]);
    json_response(['success' => true, 'created' => true, 'category' => ['id' => db_last_id(), 'name' => $name]]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        flash('error', 'Only admins and managers can change suppliers.');
        redirect('suppliers.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int) ($_POST['supplier_id'] ?? 0);
        $fields = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'contact_person' => trim((string) ($_POST['contact_person'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
        ];

        // Products they deliver: parallel lists of names and category ids. Rows without a name are skipped.
        $names = (array) ($_POST['product_name'] ?? []);
        $cats = (array) ($_POST['product_category'] ?? []);
        $validCategories = array_column(category_list(), 'name', 'id');
        $products = [];
        $productError = null;
        foreach ($names as $i => $productName) {
            $productName = trim(preg_replace('/\s+/', ' ', (string) $productName));
            if ($productName === '') {
                continue;
            }
            $categoryId = (int) ($cats[$i] ?? 0);
            if (mb_strlen($productName) > 255) {
                $productError = 'Product names can be at most 255 characters.';
            } elseif (!isset($validCategories[$categoryId])) {
                $productError = 'Choose a category for "' . $productName . '".';
            }
            $products[mb_strtolower($productName)] ??= [$productName, $categoryId];
        }

        $phoneDigits = preg_replace('/\D+/', '', $fields['phone']);
        if ($fields['name'] === '') {
            flash('error', 'Company name is required.');
        } elseif ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid email address.');
        } elseif ($fields['phone'] !== '' && (!preg_match('/^\+?[\d\s\-()]+$/', $fields['phone']) || strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15 || strlen($fields['phone']) > 20)) {
            flash('error', 'Phone number must be a number only, e.g. 0917 123 4567 or (02) 8123 4567.');
        } elseif (mb_strlen($fields['address']) > 500) {
            flash('error', 'Location can be at most 500 characters.');
        } elseif ($productError !== null) {
            flash('error', $productError);
        } elseif (count($products) > 200) {
            flash('error', 'A supplier can have at most 200 products.');
        } else {
            db_transaction(function () use (&$id, $fields, $products) {
                if ($id > 0) {
                    db_exec(
                        'UPDATE suppliers SET name = ?, contact_person = ?, email = ?, phone = ?, address = ? WHERE id = ?',
                        'sssssi',
                        [...array_values($fields), $id]
                    );
                } else {
                    db_exec(
                        'INSERT INTO suppliers (name, contact_person, email, phone, address) VALUES (?, ?, ?, ?, ?)',
                        'sssss',
                        array_values($fields)
                    );
                    $id = db_last_id();
                }
                db_exec('DELETE FROM supplier_products WHERE supplier_id = ?', 'i', [$id]);
                foreach ($products as [$productName, $categoryId]) {
                    db_exec('INSERT INTO supplier_products (supplier_id, category_id, name) VALUES (?, ?, ?)', 'iis', [$id, $categoryId, $productName]);
                }
            });
            $what = $products ? ' with ' . count($products) . ' product' . (count($products) === 1 ? '' : 's') : '';
            flash('success', 'Supplier "' . $fields['name'] . '" ' . ((int) ($_POST['supplier_id'] ?? 0) > 0 ? 'updated' : 'added') . $what . '.');
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['supplier_id'] ?? 0);
        $name = db_value('SELECT name FROM suppliers WHERE id = ?', 'i', [$id]);
        if ($name !== null) {
            // food_items.supplier_id is ON DELETE SET NULL, so items stay, just unlinked; its product list goes with it.
            db_exec('DELETE FROM suppliers WHERE id = ?', 'i', [$id]);
            flash('success', 'Supplier "' . $name . '" deleted. Its items are now unassigned.');
        }
    }

    redirect('suppliers.php');
}

/* ---------- The list ---------- */

$q = trim((string) ($_GET['q'] ?? ''));
$like = '%' . $q . '%';
$suppliers = db_all(
    "SELECT s.*,
            COUNT(fi.id) AS items_count,
            COALESCE(SUM(fi.quantity), 0) AS units,
            SUM(fi.quantity <= " . LOW_STOCK_THRESHOLD . ") AS low_stock,
            (SELECT MAX(sr.received_at) FROM stock_receipts sr JOIN food_items ri ON ri.id = sr.food_item_id
             WHERE ri.supplier_id = s.id AND " . SUPPLIER_RECEIPT_SQL . ") AS last_received,
            (SELECT MAX(d.delivered_at) FROM supplier_deliveries d WHERE d.supplier_id = s.id AND d.status = 'delivered') AS last_delivered
     FROM suppliers s
     LEFT JOIN food_items fi ON fi.supplier_id = s.id AND " . ACTIVE_ITEM_SQL . "
     WHERE ? = '' OR s.name LIKE ? OR s.contact_person LIKE ? OR s.email LIKE ? OR s.phone LIKE ? OR s.address LIKE ?
        OR EXISTS (SELECT 1 FROM supplier_products sp WHERE sp.supplier_id = s.id AND sp.name LIKE ?)
     GROUP BY s.id
     ORDER BY s.name",
    'sssssss',
    [$q, $like, $like, $like, $like, $like, $like]
);

// Delivery tracking per supplier: the next open delivery and the transaction totals.
$deliveryInfo = [];
foreach (db_all(
    "SELECT d.supplier_id,
            COUNT(*) AS total,
            SUM(d.status = 'delivered') AS delivered,
            COALESCE(SUM(CASE WHEN d.status = 'delivered' AND d.payment_status = 'unpaid' THEN d.amount END), 0) AS unpaid,
            (SELECT CONCAT(n.id, '|', n.status, '|', n.expected_date, '|', COALESCE(n.expected_time, ''))
             FROM supplier_deliveries n
             WHERE n.supplier_id = d.supplier_id AND n.status IN ('scheduled', 'on_the_way')
             ORDER BY n.status = 'on_the_way' DESC, n.expected_date, n.expected_time LIMIT 1) AS next_delivery
     FROM supplier_deliveries d
     WHERE d.supplier_id IS NOT NULL
     GROUP BY d.supplier_id"
) as $row) {
    $deliveryInfo[(int) $row['supplier_id']] = $row;
}

// Product lists (for the cards and the edit form) and the items each supplier has already delivered
// (offered in the edit form as a quick way to fill in the list).
$productsBySupplier = [];
foreach (db_all(
    'SELECT sp.supplier_id, sp.name, sp.category_id, fc.name AS category
     FROM supplier_products sp JOIN food_categories fc ON fc.id = sp.category_id
     ORDER BY fc.name, sp.name'
) as $p) {
    $productsBySupplier[(int) $p['supplier_id']][] = $p;
}
$deliveredBySupplier = [];
foreach (db_all(
    'SELECT fi.supplier_id, MIN(fi.name) AS name, MIN(fi.category_id) AS category_id
     FROM food_items fi WHERE fi.supplier_id IS NOT NULL
     GROUP BY fi.supplier_id, LOWER(TRIM(fi.name))
     ORDER BY name'
) as $d) {
    $deliveredBySupplier[(int) $d['supplier_id']][] = ['name' => $d['name'], 'category_id' => (int) $d['category_id']];
}
$categories = category_list();

render_shell_start($user, 'Suppliers', 'suppliers');
page_header('Suppliers', 'Who you buy from, what they deliver, and when they last delivered to you.');
?>

<form class="toolbar" method="GET">
    <div class="search-field">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Supplier or product…" aria-label="Search suppliers">
    </div>
    <span class="count-pill"><?= count($suppliers) ?> supplier<?= count($suppliers) === 1 ? '' : 's' ?></span>
    <?php if ($q !== ''): ?><a href="suppliers.php" class="btn btn-sm btn-ghost"><i class="fa-solid fa-xmark"></i> Clear</a><?php endif; ?>
    <span class="spacer"></span>
    <a href="deliveries.php" class="btn"><i class="fa-solid fa-truck-fast"></i> Deliveries</a>
    <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary" data-supplier-new><i class="fa-solid fa-plus"></i> Add Supplier</button>
    <?php endif; ?>
</form>

<?php if (!$suppliers): ?>
    <div class="card">
        <div class="empty">
            <i class="fa-solid fa-truck-field"></i>
            <strong><?= $q !== '' ? 'No suppliers match your search' : 'No suppliers yet' ?></strong>
            <?= $canManage && $q === '' ? 'Add your first supplier to link it to inventory items.' : '' ?>
        </div>
    </div>
<?php else: ?>
    <div class="card-grid">
        <?php foreach ($suppliers as $s):
            $sid = (int) $s['id'];
            $info = $deliveryInfo[$sid] ?? null;
            $next = $info && $info['next_delivery'] ? explode('|', $info['next_delivery']) : null;
            $products = $productsBySupplier[$sid] ?? [];
            $byCategory = array_count_values(array_column($products, 'category'));
            $last = last_business($s['last_received'], $s['last_delivered']);
            ?>
            <div class="card supplier-card">
                <div class="top">
                    <div>
                        <h3><button type="button" class="link-btn" data-supplier-view="<?= $sid ?>" title="View supplier details"><?= e($s['name']) ?></button></h3>
                        <div class="cell-sub"><i class="fa-solid fa-user"></i> <?= $s['contact_person'] ? e($s['contact_person']) : 'No contact person' ?></div>
                    </div>
                    <div class="menu-wrap">
                        <button type="button" class="kebab" data-menu aria-label="Actions for <?= e($s['name']) ?>"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                        <div class="menu">
                            <button type="button" class="menu-item" data-supplier-view="<?= $sid ?>"><i class="fa-solid fa-circle-info"></i> View details</button>
                            <?php if ($canManage): ?>
                                <button type="button" class="menu-item" data-supplier='<?= e(json_encode([
                                    'id' => $sid, 'name' => $s['name'], 'contact_person' => $s['contact_person'],
                                    'email' => $s['email'], 'phone' => $s['phone'], 'address' => $s['address'],
                                    'products' => array_map(fn ($p) => ['name' => $p['name'], 'category_id' => (int) $p['category_id']], $products),
                                    'delivered_items' => $deliveredBySupplier[$sid] ?? [],
                                ])) ?>'><i class="fa-solid fa-pen"></i> Edit</button>
                                <a class="menu-item" href="deliveries.php?supplier=<?= $sid ?>&amp;new=1"><i class="fa-solid fa-calendar-plus"></i> Schedule delivery</a>
                            <?php endif; ?>
                            <a class="menu-item" href="deliveries.php?tab=all&amp;supplier=<?= $sid ?>"><i class="fa-solid fa-receipt"></i> Transactions</a>
                            <a class="menu-item" href="food_items.php?q=<?= urlencode($s['name']) ?>"><i class="fa-solid fa-boxes-stacked"></i> View items</a>
                            <?php if ($canManage): ?>
                                <div class="menu-sep"></div>
                                <form method="POST" data-confirm="Delete supplier &quot;<?= e($s['name']) ?>&quot;? Its items will be kept but unassigned.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="supplier_id" value="<?= $sid ?>">
                                    <button type="submit" class="menu-item danger"><i class="fa-solid fa-trash"></i> Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="supplier-meta">
                    <span title="Phone number"><i class="fa-solid fa-phone"></i><?= $s['phone'] ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $s['phone'])) . '">' . e($s['phone']) . '</a>' : '<span class="muted">No phone number</span>' ?></span>
                    <span title="Email"><i class="fa-solid fa-envelope"></i><?= $s['email'] ? '<a href="mailto:' . e($s['email']) . '">' . e($s['email']) . '</a>' : '<span class="muted">No email</span>' ?></span>
                    <span title="Location"><i class="fa-solid fa-location-dot"></i><?= $s['address'] ? e(mb_strimwidth($s['address'], 0, 80, '…')) : '<span class="muted">No location</span>' ?></span>
                    <span title="Last time they delivered to us"><i class="fa-regular fa-clock"></i><?= $last
                        ? 'Last delivery <strong>' . e(time_ago($last)) . '</strong> · ' . date('M j, Y', strtotime($last))
                        : '<span class="muted">No deliveries yet</span>' ?></span>
                </div>
                <?php if ($products): ?>
                    <div class="supplier-products" title="<?= e(implode(', ', array_column($products, 'name'))) ?>">
                        <?php foreach ($byCategory as $category => $n): ?>
                            <span class="chip chip-teal"><?= e($category) ?> · <?= $n ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($next):
                    [$nextId, $nextStatus, $nextDate, $nextTime] = $next;
                    [$nextLabel, $nextTone, $nextIcon] = delivery_status_meta($nextStatus);
                    $nextLate = $nextStatus === 'scheduled' && $nextDate < date('Y-m-d');
                    ?>
                    <a class="next-delivery tone-<?= $nextLate ? 'red' : $nextTone ?>" href="deliveries.php?view=<?= (int) $nextId ?>">
                        <i class="fa-solid <?= $nextLate ? 'fa-triangle-exclamation' : $nextIcon ?>"></i>
                        <span><strong><?= $nextLate ? 'Overdue delivery' : e($nextLabel) ?></strong> · <?= e(delivery_when($nextDate, $nextTime ?: null)) ?></span>
                    </a>
                <?php endif; ?>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <span class="chip chip-green"><i class="fa-solid fa-box"></i> <?= (int) $s['items_count'] ?> item<?= (int) $s['items_count'] === 1 ? '' : 's' ?> in stock</span>
                    <span class="chip chip-gray"><?= number_format((int) $s['units']) ?> units</span>
                    <?php if ((int) $s['low_stock'] > 0): ?><span class="chip chip-amber"><?= (int) $s['low_stock'] ?> low stock</span><?php endif; ?>
                    <?php if ($info): ?><a class="chip chip-blue" href="deliveries.php?tab=all&amp;supplier=<?= $sid ?>"><i class="fa-solid fa-truck"></i> <?= (int) $info['delivered'] ?> deliver<?= (int) $info['delivered'] === 1 ? 'y' : 'ies' ?></a><?php endif; ?>
                    <?php if ($info && (float) $info['unpaid'] > 0): ?><span class="chip chip-red"><?= peso($info['unpaid'], 0) ?> unpaid</span><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="modal" id="supplierViewModal" role="dialog" aria-modal="true" aria-labelledby="supplierViewTitle">
    <div class="modal-card lg">
        <div class="modal-head">
            <h2 id="supplierViewTitle">Supplier</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="supplierViewBody"></div>
        <div class="modal-foot"><button type="button" class="btn" data-close-modal>Close</button></div>
    </div>
</div>

<?php if ($canManage): ?>
<div class="modal" id="supplierModal" role="dialog" aria-modal="true" aria-labelledby="supplierTitle">
    <form class="modal-card" method="POST" id="supplierForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="supplier_id" id="supId">
        <div class="modal-head">
            <h2 id="supplierTitle">Add supplier</h2>
            <button type="button" class="icon-btn" data-close-modal aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field span-2">
                    <label for="supName">Company name <span class="req">*</span></label>
                    <input class="input" type="text" name="name" id="supName" required maxlength="255" placeholder="e.g. Fresh Farms Trading Corp.">
                </div>
                <div class="field span-2">
                    <label for="supContact">Contact person</label>
                    <input class="input" type="text" name="contact_person" id="supContact" maxlength="255" placeholder="Who you talk to at this company">
                </div>
                <div class="field">
                    <label for="supPhone">Phone number</label>
                    <input class="input" type="tel" name="phone" id="supPhone" maxlength="20" inputmode="tel" pattern="\+?[0-9\s\-\(\)]{7,20}" placeholder="0917 123 4567" title="Numbers only, e.g. 0917 123 4567">
                </div>
                <div class="field">
                    <label for="supEmail">Email</label>
                    <input class="input" type="email" name="email" id="supEmail" maxlength="255">
                </div>
                <div class="field span-2">
                    <label for="supAddress">Location</label>
                    <textarea class="input" name="address" id="supAddress" rows="2" maxlength="500" placeholder="Street, barangay, city"></textarea>
                </div>

                <div class="field span-2 sp-section">
                    <span class="field-label"><i class="fa-solid fa-boxes-stacked"></i> Products they deliver</span>
                    <div class="sp-rows" id="spRows"></div>
                    <div class="sp-actions">
                        <button type="button" class="btn btn-sm" id="spAddRow"><i class="fa-solid fa-plus"></i> Add product</button>
                        <button type="button" class="btn btn-sm btn-ghost" id="spFromItems" hidden></button>
                    </div>
                </div>
                <div class="field span-2">
                    <label for="spNewCat">Category not in the list?</label>
                    <div class="sp-newcat">
                        <input class="input" type="text" id="spNewCat" maxlength="60" placeholder="New category, e.g. Frozen Foods" autocomplete="off">
                        <button type="button" class="btn" id="spNewCatBtn"><i class="fa-solid fa-folder-plus"></i> Add category</button>
                    </div>
                    <span class="hint" id="spNewCatHint">New categories are saved right away and also appear in Add Item on the Food Items page.</span>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary" id="supSubmit"><i class="fa-solid fa-floppy-disk"></i> Save supplier</button>
        </div>
    </form>
</div>
<script type="application/json" id="spCategories"><?= json_encode($categories, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>

<?php ob_start(); ?>
<script>
(function () {
    const $ = (id) => document.getElementById(id);
    const closeMenus = () => document.querySelectorAll('.menu.open').forEach((m) => m.classList.remove('open'));

    /* ---------- Supplier details ---------- */
    function renderDetails(d) {
        const s = d.supplier;
        const contact = [
            s.contact_person ? `<span><i class="fa-solid fa-user"></i>${escapeHtml(s.contact_person)}</span>` : '',
            s.phone ? `<span><i class="fa-solid fa-phone"></i>${escapeHtml(s.phone)}</span>` : '',
            s.email ? `<span><i class="fa-solid fa-envelope"></i>${escapeHtml(s.email)}</span>` : '',
            s.address ? `<span><i class="fa-solid fa-location-dot"></i>${escapeHtml(s.address)}</span>` : '',
        ].join('');
        const last = d.last_business
            ? `<div class="sd-last"><i class="fa-regular fa-clock"></i><div><strong>Last did business with us ${escapeHtml(d.last_business.ago)}</strong>
                   <span>${escapeHtml(d.last_business.date)} · ${escapeHtml(d.last_business.what)}${d.since ? ' · supplying us since ' + escapeHtml(d.since) : ''}</span></div></div>`
            : '<div class="sd-last is-none"><i class="fa-regular fa-clock"></i><div><strong>No deliveries yet</strong><span>Nothing has been received from this supplier.</span></div></div>';
        const products = d.products.length
            ? d.products.map((g) => `<div class="sd-cat"><span class="chip chip-teal">${escapeHtml(g.category)}</span><span>${g.names.map(escapeHtml).join(', ')}</span></div>`).join('')
            : '<p class="muted">No product list yet. Use Edit to add what they deliver.</p>';
        const delivered = d.delivered.length
            ? `<div class="table-scroll"><table class="data-table sd-table"><thead><tr><th>Product</th><th>Category</th><th class="text-right">Received</th><th class="center">Times</th><th>Last delivered</th></tr></thead><tbody>
                ${d.delivered.map((r) => `<tr><td class="cell-title">${escapeHtml(r.name)}</td><td>${escapeHtml(r.category)}</td><td class="text-right num">${escapeHtml(r.units)}</td>
                    <td class="center">${r.times}</td><td class="nowrap">${escapeHtml(r.last)}<div class="cell-sub">${escapeHtml(r.ago)}</div></td></tr>`).join('')}
               </tbody></table></div>
               <p class="sd-note">${escapeHtml(d.totals.units)} units received in all, worth ${escapeHtml(d.totals.cost)} at purchase price.</p>`
            : '<p class="muted">Nothing received from them yet.</p>';
        const deliveries = d.deliveries.length
            ? `<ul class="list">${d.deliveries.map((x) => `<li><div><div class="cell-title"><a href="${escapeHtml(x.href)}">${escapeHtml(x.ref)}</a> · ${escapeHtml(x.date)}</div><div class="cell-sub">${escapeHtml(x.items)}</div></div>
                   <span class="chip chip-${x.paid ? 'green' : 'red'}">${x.amount ? escapeHtml(x.amount) + ' · ' : ''}${x.paid ? 'Paid' : 'Unpaid'}</span></li>`).join('')}</ul>
               ${d.deliveries_total > d.deliveries.length ? `<p class="sd-note">${d.deliveries_total - d.deliveries.length} more on the Deliveries page.</p>` : ''}`
            : '<p class="muted">No completed deliveries recorded on the Deliveries page.</p>';
        $('supplierViewTitle').textContent = s.name;
        $('supplierViewBody').innerHTML = `
            ${contact ? `<div class="supplier-meta sd-contact">${contact}</div>` : ''}
            ${last}
            <h3 class="sd-title"><i class="fa-solid fa-boxes-stacked"></i> Products they deliver</h3>${products}
            <h3 class="sd-title"><i class="fa-solid fa-dolly"></i> Delivered to us</h3>${delivered}
            <h3 class="sd-title"><i class="fa-solid fa-truck"></i> Recent deliveries</h3>${deliveries}`;
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-supplier-view]');
        if (!btn) return;
        closeMenus();
        $('supplierViewTitle').textContent = 'Loading…';
        $('supplierViewBody').innerHTML = '<p class="muted"><i class="fa-solid fa-spinner fa-spin"></i> Loading supplier details…</p>';
        openModal('supplierViewModal');
        fetch('suppliers.php?details=' + encodeURIComponent(btn.dataset.supplierView), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' } })
            .then((r) => r.json())
            .then((d) => { if (d.success) renderDetails(d); else $('supplierViewBody').innerHTML = `<p>${escapeHtml(d.message || 'Could not load the supplier.')}</p>`; })
            .catch(() => { $('supplierViewBody').innerHTML = '<p>Could not load the supplier. Reload the page and try again.</p>'; });
    });

    /* ---------- Add / edit supplier (admins and managers) ---------- */
    const form = $('supplierForm');
    if (!form) return;
    const fields = { id: 'supId', name: 'supName', contact_person: 'supContact', email: 'supEmail', phone: 'supPhone', address: 'supAddress' };
    const categories = JSON.parse($('spCategories').textContent);
    const rows = $('spRows');
    let deliveredItems = [];

    function categoryOptions(selected) {
        return '<option value="">Category…</option>'
            + categories.map((c) => `<option value="${c.id}"${c.id === selected ? ' selected' : ''}>${escapeHtml(c.name)}</option>`).join('')
            + '<option value="__new">+ New category…</option>';
    }
    function addRow(name = '', categoryId = null) {
        const row = document.createElement('div');
        row.className = 'sp-row';
        row.innerHTML = `<input class="input" type="text" name="product_name[]" maxlength="255" placeholder="Product, e.g. Fresh Milk 1L" aria-label="Product name">
            <select class="select" name="product_category[]" aria-label="Product category">${categoryOptions(categoryId)}</select>
            <button type="button" class="icon-btn" data-sp-remove aria-label="Remove product" title="Remove"><i class="fa-solid fa-xmark"></i></button>`;
        row.querySelector('input').value = name;
        rows.appendChild(row);
        syncRequired(row);
        return row;
    }
    // A product with a name needs a category.
    function syncRequired(row) {
        row.querySelector('select').required = row.querySelector('input').value.trim() !== '';
    }
    function refreshFromItemsButton() {
        const have = new Set(Array.from(rows.querySelectorAll('input')).map((i) => i.value.trim().toLowerCase()));
        const missing = deliveredItems.filter((it) => !have.has(it.name.trim().toLowerCase()));
        const btn = $('spFromItems');
        btn.hidden = missing.length === 0;
        btn.innerHTML = `<i class="fa-solid fa-wand-magic-sparkles"></i> Add ${missing.length} item${missing.length === 1 ? '' : 's'} they already delivered`;
        btn.onclick = () => {
            rows.querySelectorAll('.sp-row').forEach((r) => { if (!r.querySelector('input').value.trim()) r.remove(); });
            missing.forEach((it) => addRow(it.name, it.category_id));
            refreshFromItemsButton();
        };
    }

    function fill(data, title) {
        Object.entries(fields).forEach(([key, id]) => { $(id).value = data[key] ?? ''; });
        $('supplierTitle').textContent = title;
        rows.innerHTML = '';
        (data.products || []).forEach((p) => addRow(p.name, p.category_id));
        if (!rows.children.length) addRow();
        deliveredItems = data.delivered_items || [];
        refreshFromItemsButton();
        $('spNewCat').value = '';
        $('spNewCatHint').textContent = 'New categories are saved right away and also appear in Add Item on the Food Items page.';
        $('spNewCatHint').className = 'hint';
        closeMenus();
        openModal('supplierModal');
    }
    document.querySelector('[data-supplier-new]')?.addEventListener('click', () => fill({}, 'Add supplier'));
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-supplier]');
        if (btn) fill(JSON.parse(btn.dataset.supplier), 'Edit supplier');
    });

    $('spAddRow').addEventListener('click', () => addRow().querySelector('input').focus());
    rows.addEventListener('click', (e) => {
        const remove = e.target.closest('[data-sp-remove]');
        if (!remove) return;
        remove.closest('.sp-row').remove();
        if (!rows.children.length) addRow();
        refreshFromItemsButton();
    });
    rows.addEventListener('input', (e) => { if (e.target.matches('input')) { syncRequired(e.target.closest('.sp-row')); refreshFromItemsButton(); } });
    rows.addEventListener('change', (e) => {
        if (!e.target.matches('select') || e.target.value !== '__new') return;
        e.target.value = '';
        e.target.dataset.waiting = '1';
        $('spNewCat').focus();
    });

    // New category: saved at once, then offered in every category list on this form.
    function addCategory() {
        const name = $('spNewCat').value.trim();
        const hint = $('spNewCatHint');
        if (!name) { $('spNewCat').focus(); return; }
        $('spNewCatBtn').disabled = true;
        postForm('suppliers.php', { action: 'add_category', name })
            .then((d) => {
                if (!d.success) { hint.textContent = d.message || 'Could not add the category.'; hint.className = 'hint hint-bad'; return; }
                const c = d.category;
                if (!categories.some((x) => x.id === c.id)) {
                    categories.push(c);
                    categories.sort((a, b) => a.name.localeCompare(b.name));
                }
                rows.querySelectorAll('select').forEach((sel) => {
                    const keep = sel.dataset.waiting === '1' ? c.id : (Number(sel.value) || null);
                    sel.innerHTML = categoryOptions(keep);
                    delete sel.dataset.waiting;
                });
                $('spNewCat').value = '';
                hint.textContent = d.created ? `"${c.name}" added. It is now in every category list, including Add Item.` : `"${c.name}" already exists, so it was not added twice.`;
                hint.className = 'hint hint-ok';
            })
            .catch(() => { hint.textContent = 'Could not add the category. Try again.'; hint.className = 'hint hint-bad'; })
            .finally(() => { $('spNewCatBtn').disabled = false; });
    }
    $('spNewCatBtn').addEventListener('click', addCategory);
    $('spNewCat').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addCategory(); } });
})();
</script>
<?php render_shell_end(ob_get_clean()); ?>
