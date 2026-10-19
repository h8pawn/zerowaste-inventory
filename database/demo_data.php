<?php
// Sample inventory, sales, waste, returns and orders for trying out the AI Waste Advisor and the
// dashboards. Dates are relative to the day it runs, so the samples always look current.
// Command line only, and only into an empty inventory so it never mixes with real records:
//
//   php database/demo_data.php
//
// To remove the samples: Users & System → Danger zone → Reset system data.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/barcode.php';
require_once __DIR__ . '/../includes/store.php';

if ((int) db_value('SELECT COUNT(*) FROM food_items') > 0) {
    fwrite(STDERR, "The inventory already has items, so no demo data was added.\n"
        . "Demo data only goes into an empty inventory. To start over: Users & System > Danger zone > Reset system data.\n");
    exit(1);
}
$adminId = (int) db_value("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
if (!$adminId) {
    fwrite(STDERR, "Create the admin account first (open the site and register), then run this again.\n");
    exit(1);
}
// Waste and stock are logged by a manager when there is one, as they would be day to day.
$staffId = (int) db_value("SELECT id FROM users WHERE role IN ('admin', 'manager') AND is_active = 1 ORDER BY role = 'manager' DESC, id LIMIT 1");

$day = fn (int $offset): string => date('Y-m-d', strtotime(sprintf('%+d days', $offset)));
$at = fn (int $offset): string => $day($offset) . ' 09:00:00';

$category = function (string $name): int {
    $id = db_value('SELECT id FROM food_categories WHERE name = ?', 's', [$name]);
    if ($id === null) {
        db_exec('INSERT INTO food_categories (name) VALUES (?)', 's', [$name]);
        return db_last_id();
    }
    return (int) $id;
};
$reasons = array_column(db_all('SELECT id, reason FROM waste_reasons'), 'id', 'reason');

$supplier = function (string $name, string $contact, string $phone, string $address): int {
    db_exec('INSERT INTO suppliers (name, contact_person, phone, address) VALUES (?, ?, ?, ?)', 'ssss', [$name, $contact, $phone, $address]);
    return db_last_id();
};

// One batch. $expires and $added are days from today; archived batches are expired and empty.
$item = function (string $name, string $cat, int $qty, string $unit, ?float $price, int $expires, string $storage, ?int $supplierId, int $added, bool $archived = false, bool $listed = true) use ($day, $at, $category): int {
    db_exec(
        'INSERT INTO food_items (name, barcode, category_id, quantity, unit, purchase_price, expiration_date, storage_location, supplier_id,
                                 added_date, archived_at, archive_reason, is_listed)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'ssiisdssisssi',
        [$name, generate_store_barcode(), $category($cat), $qty, $unit, $price, $day($expires), $storage, $supplierId, $at($added),
            $archived ? $day($expires) . ' 23:00:00' : null, $archived ? 'expired' : null, $listed ? 1 : 0]
    );
    return db_last_id();
};

$receive = function (int $itemId, int $qty, float $cost, int $daysAgo, string $source = 'receive') use ($at, $staffId): void {
    db_exec(
        'INSERT INTO stock_receipts (food_item_id, quantity, unit_cost, source, received_at, user_id) VALUES (?, ?, ?, ?, ?, ?)',
        'iidssi',
        [$itemId, $qty, $cost, $source, $at($daysAgo), $staffId]
    );
};

// Waste the system cleared itself after the expiry date carries the same note as the real thing.
$waste = function (int $itemId, int $qty, string $reason, int $daysAgo, float $unitPrice, string $notes = '') use ($day, $reasons, $staffId): void {
    db_exec(
        'INSERT INTO waste_logs (food_item_id, quantity_wasted, waste_reason_id, waste_date, user_id, notes, estimated_value)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        'iiisisd',
        [$itemId, $qty, (int) $reasons[$reason], $day($daysAgo), $staffId, $notes, $qty * $unitPrice]
    );
};
$autoNote = fn (int $expiredDaysAgo): string => 'Auto-moved by system after expiration date (' . $day($expiredDaysAgo) . ').';

db_transaction(function () use ($supplier, $item, $receive, $waste, $autoNote, $day, $at, $adminId) {
    $freshFarm = $supplier('FreshFarm Produce', 'Mang Romy Dela Cruz', '0917 555 0101', 'Balintawak Market, Quezon City');
    $goldenDairy = $supplier('Golden Dairy Co.', 'Ms. Liza Santos', '0918 555 0202', 'Marilao, Bulacan');
    $lolas = $supplier('Lola\'s Bakery', 'Aling Nena Reyes', '0919 555 0303', 'Brgy. San Roque, Marikina');
    $metro = $supplier('Metro Pantry Supply', 'Mr. Carlo Tan', '0920 555 0404', 'Divisoria, Manila');
    $meats = $supplier('Pampanga Meat Traders', 'Mr. Jun Manalo', '0921 555 0505', 'San Fernando, Pampanga');

    // Banana: ordered in bigger lots than it sells, so batches keep expiring → "Order less", sell this week.
    $b = $item('Banana Lakatan', 'Fruits', 0, 'pcs', 6, -78, 'Shelf A', $freshFarm, -85, true);
    $receive($b, 120, 6, -85, 'initial');
    $waste($b, 30, 'Expired', -77, 6, $autoNote(-78));
    $b = $item('Banana Lakatan', 'Fruits', 0, 'pcs', 6, -26, 'Shelf A', $freshFarm, -32, true);
    $receive($b, 120, 6, -32, 'initial');
    $waste($b, 25, 'Expired', -25, 6, 'Too ripe to sell');
    $b = $item('Banana Lakatan', 'Fruits', 40, 'pcs', 6, 2, 'Shelf A', $freshFarm, -4);
    $receive($b, 60, 6, -4, 'initial');

    // Milk: restocked in place; some will expire this week; 4 bottles reserved for pickup.
    $milk = $item('Fresh Milk 1L', 'Dairy', 30, 'bottle', 85, 5, 'Chiller 1', $goldenDairy, -60);
    $receive($milk, 60, 85, -60, 'initial');
    foreach ([[-40, 60], [-20, 60], [-3, 30]] as [$ago, $qty]) {
        $receive($milk, $qty, 85, $ago);
    }
    $waste($milk, 4, 'Expired', -21, 85);
    $waste($milk, 2, 'Spillage', -10, 85, 'Crate dropped while unloading');

    // Lettuce: wilts on an ambient shelf → "Keep chilled", sell or donate tomorrow.
    $l = $item('Lettuce Romaine', 'Vegetables', 0, 'head', 45, -35, 'Shelf B', $freshFarm, -40, true);
    $receive($l, 30, 45, -40, 'initial');
    $waste($l, 8, 'Damaged/Spoiled', -37, 45, 'Wilted');
    $waste($l, 5, 'Expired', -34, 45, $autoNote(-35));
    $l = $item('Lettuce Romaine', 'Vegetables', 12, 'head', 45, 1, 'Shelf B', $freshFarm, -2);
    $receive($l, 20, 45, -2, 'initial');
    $waste($l, 3, 'Damaged/Spoiled', -1, 45, 'Wilted');

    // Pandesal: two batches on the rack, one expiring today → "Sell older batches first".
    $p = $item('Pandesal Pack', 'Bakery', 10, 'pack', 25, 0, 'Bread rack', $lolas, -20);
    $receive($p, 150, 25, -20, 'initial');
    $waste($p, 10, 'Expired', -10, 25);
    $p = $item('Pandesal Pack', 'Bakery', 20, 'pack', 25, 3, 'Bread rack', $lolas, 0);
    $receive($p, 20, 25, 0, 'initial');

    // Sardines: far more on hand than sells before the date → "Hold orders" (slow mover).
    $s = $item('Canned Sardines', 'Pantry', 80, 'can', 22, 25, 'Aisle 3', $metro, -70);
    $receive($s, 100, 22, -70, 'initial');

    // Cheddar: expiring but switched off on the storefront → "List on the storefront".
    $c = $item('Cheddar Cheese', 'Dairy', 8, 'block', 120, 2, 'Chiller 1', $goldenDairy, -30, false, false);
    $receive($c, 20, 120, -30, 'initial');

    // Chicken: no purchase price and no supplier → "Give the advisor better data".
    $ch = $item('Chicken Breast', 'Meat/Seafood', 10, 'kg', null, 60, 'Freezer', null, -10);
    $receive($ch, 15, 0, -10, 'initial');
    $waste($ch, 2, 'Damaged/Spoiled', -4, 0, 'Freezer burn');

    // Tomato: bruised deliveries, a return not fully credited, no storage set → "Talk to FreshFarm".
    $t = $item('Tomato', 'Vegetables', 12, 'kg', 15, 3, '', $freshFarm, -45);
    $receive($t, 50, 15, -45, 'initial');
    $receive($t, 40, 15, -15);
    $waste($t, 10, 'Quality Control', -14, 15, 'Bruised on arrival');
    $waste($t, 4, 'Damaged/Spoiled', -5, 15);
    db_exec(
        "INSERT INTO item_returns (food_item_id, return_type, outcome, quantity, unit_value, amount, reason, return_date, user_id)
         VALUES (?, 'supplier', 'sent_back', 10, 15, 50, 'Soft and bruised', ?, ?)",
        'isi',
        [$t, $day(-12), $adminId]
    );

    // Eggs: kept on an ambient shelf and expiring → "Keep chilled", "Order less".
    $e = $item('Eggs Tray (30s)', 'Dairy', 5, 'tray', 210, 12, 'Shelf C', $goldenDairy, -50);
    $receive($e, 20, 210, -50, 'initial');
    $receive($e, 10, 210, -20);
    $waste($e, 6, 'Expired', -22, 210, $autoNote(-23));

    // Rice: long shelf life, nearly sold out → "Reorder soon" in Smart order sizes.
    $r = $item('Rice 5kg', 'Pantry', 2, 'bag', 280, 180, 'Aisle 1', $metro, -60);
    $receive($r, 50, 280, -60, 'initial');

    // Healthy items that sell steadily with little or no waste.
    $soda = $item('Soft Drink 1.5L', 'Pantry', 20, 'bottle', 65, 150, 'Aisle 2', $metro, -60);
    $receive($soda, 48, 65, -60, 'initial');
    $receive($soda, 48, 65, -30);
    $receive($soda, 24, 65, -5);
    $noodles = $item('Instant Noodles', 'Pantry', 60, 'pack', 14, 200, 'Aisle 2', $metro, -50);
    $receive($noodles, 100, 14, -50, 'initial');
    $receive($noodles, 100, 14, -20);
    $carrot = $item('Carrots', 'Vegetables', 9, 'kg', 60, 18, 'Chiller 2', $freshFarm, -30);
    $receive($carrot, 20, 60, -30, 'initial');
    $receive($carrot, 20, 60, -12);
    $receive($carrot, 15, 60, -3);
    $waste($carrot, 1, 'Damaged/Spoiled', -8, 60);
    $pork = $item('Pork Liempo', 'Meat/Seafood', 8, 'kg', 320, 45, 'Freezer', $meats, -40);
    $receive($pork, 15, 320, -40, 'initial');
    $receive($pork, 15, 320, -15);

    // Online pickup orders: two picked up, one still open and holding 4 bottles of milk.
    $order = function (string $name, string $phone, int $pickupDay, string $status, array $lines) use ($day, $at, $adminId): void {
        $total = array_sum(array_map(fn ($l) => $l[2] * $l[3], $lines));
        db_exec(
            'INSERT INTO customer_orders (code, customer_name, phone, pickup_date, pickup_time, payment_method, status, total, handled_by, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'sssssssdis',
            [new_order_code(), $name, $phone, $day($pickupDay), '10:00', 'cash', $status, $total, $status === 'completed' ? $adminId : null,
                $status === 'completed' ? $at($pickupDay) : null]
        );
        $orderId = db_last_id();
        foreach ($lines as [$itemId, $itemName, $qty, $price]) {
            db_exec(
                'INSERT INTO customer_order_items (order_id, food_item_id, item_name, unit, quantity, regular_price, unit_price, line_total)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?)',
                'iisiddd',
                [$orderId, $itemId, $itemName, $qty, $price, $price, $qty * $price]
            );
        }
    };
    $order('Ana Villanueva', '09170000001', -5, 'completed', [[$milk, 'Fresh Milk 1L', 2, 106.25], [$noodles, 'Instant Noodles', 6, 17.50]]);
    $order('Ben Ramos', '09170000002', -2, 'completed', [[$soda, 'Soft Drink 1.5L', 2, 81.25]]);
    $order('Cara Mendoza', '09170000003', 1, 'pending', [[$milk, 'Fresh Milk 1L', 4, 106.25]]);
});

$counts = db_one(
    'SELECT (SELECT COUNT(DISTINCT name) FROM food_items) AS products, (SELECT COUNT(*) FROM food_items) AS batches,
            (SELECT COUNT(*) FROM waste_logs) AS waste, (SELECT COUNT(*) FROM stock_receipts) AS receipts,
            (SELECT COUNT(*) FROM suppliers) AS suppliers, (SELECT COUNT(*) FROM customer_orders) AS orders'
);
echo "Demo data added: {$counts['products']} products ({$counts['batches']} batches), {$counts['receipts']} stock receipts, "
    . "{$counts['waste']} waste entries, {$counts['suppliers']} suppliers, {$counts['orders']} online orders.\n"
    . "Sign in as the admin and open Admin > AI Waste Advisor.\n"
    . "To remove it: Users & System > Danger zone > Reset system data.\n";
