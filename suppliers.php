<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$canManage = can_manage($user);

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

        $phoneDigits = preg_replace('/\D+/', '', $fields['phone']);
        if ($fields['name'] === '') {
            flash('error', 'Company name is required.');
        } elseif ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid email address.');
        } elseif ($fields['phone'] !== '' && (!preg_match('/^\+?[\d\s\-()]+$/', $fields['phone']) || strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15 || strlen($fields['phone']) > 20)) {
            flash('error', 'Phone number must be a number only, e.g. 0917 123 4567 or (02) 8123 4567.');
        } elseif (mb_strlen($fields['address']) > 500) {
            flash('error', 'Location can be at most 500 characters.');
        } elseif ($id > 0) {
            db_exec(
                'UPDATE suppliers SET name = ?, contact_person = ?, email = ?, phone = ?, address = ? WHERE id = ?',
                'sssssi',
                [...array_values($fields), $id]
            );
            flash('success', 'Supplier "' . $fields['name'] . '" updated.');
        } else {
            db_exec(
                'INSERT INTO suppliers (name, contact_person, email, phone, address) VALUES (?, ?, ?, ?, ?)',
                'sssss',
                array_values($fields)
            );
            flash('success', 'Supplier "' . $fields['name'] . '" added.');
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['supplier_id'] ?? 0);
        $name = db_value('SELECT name FROM suppliers WHERE id = ?', 'i', [$id]);
        if ($name !== null) {
            // food_items.supplier_id is ON DELETE SET NULL, so items stay, just unlinked.
            db_exec('DELETE FROM suppliers WHERE id = ?', 'i', [$id]);
            flash('success', 'Supplier "' . $name . '" deleted. Its items are now unassigned.');
        }
    }

    redirect('suppliers.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$like = '%' . $q . '%';
$suppliers = db_all(
    "SELECT s.*,
            COUNT(fi.id) AS items_count,
            COALESCE(SUM(fi.quantity), 0) AS units,
            SUM(fi.quantity <= " . LOW_STOCK_THRESHOLD . ") AS low_stock
     FROM suppliers s
     LEFT JOIN food_items fi ON fi.supplier_id = s.id AND " . ACTIVE_ITEM_SQL . "
     WHERE ? = '' OR s.name LIKE ? OR s.contact_person LIKE ? OR s.email LIKE ? OR s.phone LIKE ? OR s.address LIKE ?
     GROUP BY s.id
     ORDER BY s.name",
    'ssssss',
    [$q, $like, $like, $like, $like, $like]
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

render_shell_start($user, 'Suppliers', 'suppliers');
page_header('Suppliers', 'Who you buy from, their deliveries, and how much of your current stock came from each.');
?>

<form class="toolbar" method="GET">
    <div class="search-field">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Company, contact, location…" aria-label="Search suppliers">
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
            $info = $deliveryInfo[(int) $s['id']] ?? null;
            $next = $info && $info['next_delivery'] ? explode('|', $info['next_delivery']) : null;
            ?>
            <div class="card supplier-card">
                <div class="top">
                    <div>
                        <h3><?= e($s['name']) ?></h3>
                        <div class="cell-sub"><i class="fa-solid fa-user"></i> <?= $s['contact_person'] ? e($s['contact_person']) : 'No contact person' ?></div>
                    </div>
                    <?php if ($canManage): ?>
                        <div class="menu-wrap">
                            <button type="button" class="kebab" data-menu aria-label="Actions for <?= e($s['name']) ?>"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                            <div class="menu">
                                <button type="button" class="menu-item" data-supplier='<?= e(json_encode([
                                    'id' => (int) $s['id'], 'name' => $s['name'], 'contact_person' => $s['contact_person'],
                                    'email' => $s['email'], 'phone' => $s['phone'], 'address' => $s['address'],
                                ])) ?>'><i class="fa-solid fa-pen"></i> Edit</button>
                                <a class="menu-item" href="deliveries.php?supplier=<?= (int) $s['id'] ?>&amp;new=1"><i class="fa-solid fa-calendar-plus"></i> Schedule delivery</a>
                                <a class="menu-item" href="deliveries.php?tab=all&amp;supplier=<?= (int) $s['id'] ?>"><i class="fa-solid fa-receipt"></i> Transactions</a>
                                <a class="menu-item" href="food_items.php?q=<?= urlencode($s['name']) ?>"><i class="fa-solid fa-boxes-stacked"></i> View items</a>
                                <div class="menu-sep"></div>
                                <form method="POST" data-confirm="Delete supplier &quot;<?= e($s['name']) ?>&quot;? Its items will be kept but unassigned.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="supplier_id" value="<?= (int) $s['id'] ?>">
                                    <button type="submit" class="menu-item danger"><i class="fa-solid fa-trash"></i> Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="supplier-meta">
                    <span title="Phone number"><i class="fa-solid fa-phone"></i><?= $s['phone'] ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $s['phone'])) . '">' . e($s['phone']) . '</a>' : '<span class="muted">No phone number</span>' ?></span>
                    <span title="Email"><i class="fa-solid fa-envelope"></i><?= $s['email'] ? '<a href="mailto:' . e($s['email']) . '">' . e($s['email']) . '</a>' : '<span class="muted">No email</span>' ?></span>
                    <span title="Location"><i class="fa-solid fa-location-dot"></i><?= $s['address'] ? e(mb_strimwidth($s['address'], 0, 80, '…')) : '<span class="muted">No location</span>' ?></span>
                </div>
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
                    <span class="chip chip-green"><i class="fa-solid fa-box"></i> <?= (int) $s['items_count'] ?> item<?= (int) $s['items_count'] === 1 ? '' : 's' ?></span>
                    <span class="chip chip-gray"><?= number_format((int) $s['units']) ?> units</span>
                    <?php if ((int) $s['low_stock'] > 0): ?><span class="chip chip-amber"><?= (int) $s['low_stock'] ?> low stock</span><?php endif; ?>
                    <?php if ($info): ?><a class="chip chip-blue" href="deliveries.php?tab=all&amp;supplier=<?= (int) $s['id'] ?>"><i class="fa-solid fa-truck"></i> <?= (int) $info['delivered'] ?> deliver<?= (int) $info['delivered'] === 1 ? 'y' : 'ies' ?></a><?php endif; ?>
                    <?php if ($info && (float) $info['unpaid'] > 0): ?><span class="chip chip-red"><?= peso($info['unpaid'], 0) ?> unpaid</span><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="modal" id="supplierModal" role="dialog" aria-modal="true" aria-labelledby="supplierTitle">
    <form class="modal-card" method="POST">
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
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary" id="supSubmit"><i class="fa-solid fa-floppy-disk"></i> Save supplier</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php ob_start(); ?>
<script>
(function () {
    const fields = { id: 'supId', name: 'supName', contact_person: 'supContact', email: 'supEmail', phone: 'supPhone', address: 'supAddress' };
    function fill(data, title) {
        Object.entries(fields).forEach(([key, id]) => { document.getElementById(id).value = data[key] ?? ''; });
        document.getElementById('supplierTitle').textContent = title;
        document.querySelectorAll('.menu.open').forEach((m) => m.classList.remove('open'));
        openModal('supplierModal');
    }
    document.querySelector('[data-supplier-new]')?.addEventListener('click', () => fill({}, 'Add supplier'));
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-supplier]');
        if (btn) fill(JSON.parse(btn.dataset.supplier), 'Edit supplier');
    });
})();
</script>
<?php render_shell_end(ob_get_clean()); ?>
