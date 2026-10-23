<?php
// Database upgrades. database/schema.sql creates a complete, current database; this brings
// databases made by older versions of the app up to date (archiving, barcodes and product
// templates, the stock receipts ledger, supplier returns, selling prices and supplier deliveries),
// and removes what older versions had for the online store and customer returns.
// The checks run until they all pass, then once per SCHEMA_VERSION. Bump it after adding a step.

const SCHEMA_VERSION = 3;

const REQUIRED_TABLES = [
    'users', 'food_categories', 'suppliers', 'food_items', 'waste_reasons', 'waste_logs', 'products',
    'stock_receipts', 'item_returns', 'app_settings', 'supplier_deliveries', 'supplier_delivery_events',
    'supplier_products',
];
const REQUIRED_ITEM_COLUMNS = ['barcode', 'archived_at', 'archive_reason', 'selling_price'];

// Left behind by the online store and customer returns, which have been removed.
const OBSOLETE_TABLES = ['customer_order_items', 'customer_orders'];
const OBSOLETE_ITEM_COLUMNS = ['is_listed'];
const OBSOLETE_RETURN_COLUMNS = ['return_type', 'outcome', 'waste_log_id'];
const OBSOLETE_SETTINGS = [
    'store_name', 'tagline', 'address', 'phone', 'email', 'facebook_url', 'open_time', 'close_time',
    'default_markup', 'pickup_enabled', 'pickup_days', 'prep_minutes', 'announcement', 'weekly_hours',
    'store_status', 'status_date', 'payment_online_details', 'payment_bank_details',
];

function ensure_schema(mysqli $conn): void {
    $result = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'schema_version'");
    if ($result && ($row = $result->fetch_row()) && (int) $row[0] >= SCHEMA_VERSION) {
        return;
    }

    [$tables, $columns] = schema_inventory($conn);
    foreach (['users', 'food_categories', 'suppliers', 'food_items', 'waste_reasons', 'waste_logs'] as $core) {
        if (!isset($tables[$core])) {
            render_setup_required();
        }
    }

    ensureUserRoleEnum($conn);
    ensureAppSchema($conn, $tables, $columns);
    removeStoreAndCustomerReturns($conn);

    // Only record the version once everything is really there (and the leftovers are gone),
    // so a failed step is retried.
    [$tables, $columns] = schema_inventory($conn);
    $missing = array_diff(REQUIRED_TABLES, array_keys($tables)) || array_diff(REQUIRED_ITEM_COLUMNS, array_keys($columns));
    $leftover = array_intersect(OBSOLETE_TABLES, array_keys($tables)) || array_intersect(OBSOLETE_ITEM_COLUMNS, array_keys($columns))
        || array_intersect(OBSOLETE_RETURN_COLUMNS, table_columns($conn, 'item_returns'));
    if (!$missing && !$leftover) {
        $conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('schema_version', '" . SCHEMA_VERSION . "')
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    }
}

// [table names, food_items column names], lower-cased as array keys.
function schema_inventory(mysqli $conn): array {
    $tables = [];
    $result = $conn->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()');
    while ($result && ($row = $result->fetch_row())) {
        $tables[strtolower($row[0])] = true;
    }
    $columns = isset($tables['food_items']) ? array_fill_keys(table_columns($conn, 'food_items'), true) : [];
    return [$tables, $columns];
}

// Lower-cased column names of a table, or [] when it doesn't exist.
function table_columns(mysqli $conn, string $table): array {
    $stmt = $conn->prepare('SELECT LOWER(column_name) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
    if (!$stmt || !$stmt->bind_param('s', $table) || !$stmt->execute()) {
        return [];
    }
    return array_column($stmt->get_result()->fetch_all(), 0);
}

function render_setup_required(): never {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Database not set up</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:640px;margin:10vh auto;padding:24px;border:1px solid #f5d27a;border-radius:14px;background:#fffbea;color:#5c4400">'
        . '<h2 style="margin-top:0">The database is not set up yet</h2>'
        . '<p>Import <code>database/schema.sql</code> into the database this site is configured to use, then reload this page.</p></div>';
    exit();
}

function ensureUserRoleEnum(mysqli $conn): void {
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    if (!$check || $check->num_rows === 0) {
        return;
    }

    $column = $check->fetch_assoc();
    if (isset($column['Type']) && stripos($column['Type'], "enum('admin','manager','staff')") !== false) {
        return;
    }

    $conn->query("UPDATE users SET role = 'staff' WHERE role = 'user'");
    $conn->query("ALTER TABLE users MODIFY role ENUM('admin', 'manager', 'staff') NOT NULL DEFAULT 'staff'");
}

function ensureAppSchema(mysqli $conn, array $tables, array $columns): void {
    if (!isset($columns['barcode'])) {
        $conn->query("ALTER TABLE food_items
                        ADD COLUMN barcode VARCHAR(255) NULL DEFAULT NULL AFTER name,
                        ADD UNIQUE KEY barcode (barcode)");
    }

    // Product templates: what a barcode is, so Quick Scan can add a new batch on its own.
    if (!isset($tables['products'])) {
        $conn->query("CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            barcode VARCHAR(255) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            category_id INT NULL,
            unit VARCHAR(50) NOT NULL,
            default_price DECIMAL(10,2) NULL,
            default_shelf_life_days INT NULL,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES food_categories(id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_products_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
            INDEX idx_products_name (name),
            INDEX idx_products_category (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!isset($columns['archived_at'])) {
        $conn->query("ALTER TABLE food_items
                        ADD COLUMN archived_at DATETIME NULL DEFAULT NULL,
                        ADD COLUMN archive_reason VARCHAR(20) NULL DEFAULT NULL,
                        ADD INDEX idx_archived (archived_at)");
        // Expired items with no stock used to be hidden implicitly; make that explicit.
        $conn->query("UPDATE food_items SET archived_at = NOW(), archive_reason = 'expired'
                      WHERE quantity = 0 AND expiration_date < CURDATE()");
    }

    if (!isset($tables['stock_receipts']) || !isset($tables['item_returns'])) {
        ensureLedgerTables($conn);
    }

    if (!isset($columns['selling_price'])) {
        // NULL selling price = the default markup on the purchase price.
        $conn->query("ALTER TABLE food_items ADD COLUMN selling_price DECIMAL(10,2) NULL DEFAULT NULL");
    }

    if (!isset($tables['app_settings'])) {
        $conn->query("CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // What each supplier delivers, by category (set on the Suppliers page).
    if (!isset($tables['supplier_products'])) {
        $conn->query("CREATE TABLE IF NOT EXISTS supplier_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT NOT NULL,
            category_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            FOREIGN KEY (category_id) REFERENCES food_categories(id) ON DELETE CASCADE,
            UNIQUE KEY supplier_product (supplier_id, name),
            INDEX idx_supplier_products_category (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Supplier transactions: scheduled deliveries, tracked from "on the way" to "delivered".
    if (!isset($tables['supplier_deliveries'])) {
        $conn->query("CREATE TABLE IF NOT EXISTS supplier_deliveries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT NULL,
            supplier_name VARCHAR(255) NOT NULL,
            items TEXT NOT NULL,
            amount DECIMAL(10,2) NULL,
            expected_date DATE NOT NULL,
            expected_time CHAR(5) NULL,
            status ENUM('scheduled', 'on_the_way', 'delivered', 'cancelled') NOT NULL DEFAULT 'scheduled',
            payment_status ENUM('unpaid', 'paid') NOT NULL DEFAULT 'unpaid',
            notes VARCHAR(500) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            shipped_at DATETIME NULL,
            delivered_at DATETIME NULL,
            paid_at DATETIME NULL,
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_delivery_status (status),
            INDEX idx_delivery_expected (expected_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conn->query("CREATE TABLE IF NOT EXISTS supplier_delivery_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            delivery_id INT NOT NULL,
            event VARCHAR(20) NOT NULL,
            note VARCHAR(255) NULL,
            user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (delivery_id) REFERENCES supplier_deliveries(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_event_delivery (delivery_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

function ensureLedgerTables(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS stock_receipts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        food_item_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
        source VARCHAR(20) NOT NULL DEFAULT 'receive',
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        user_id INT NULL,
        FOREIGN KEY (food_item_id) REFERENCES food_items(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_received (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Stock sent back to suppliers; amount is the credit the supplier gave back.
    $conn->query("CREATE TABLE IF NOT EXISTS item_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        food_item_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_value DECIMAL(10,2) NOT NULL DEFAULT 0,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        reason VARCHAR(255) NULL,
        return_date DATE NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (food_item_id) REFERENCES food_items(id) ON DELETE RESTRICT,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
        INDEX idx_return_date (return_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Give existing items an opening receipt (current stock + everything wasted) so cost reports have a baseline.
    $conn->query("INSERT INTO stock_receipts (food_item_id, quantity, unit_cost, source, received_at)
                  SELECT fi.id, fi.quantity + COALESCE(w.units, 0), COALESCE(fi.purchase_price, 0), 'initial',
                         LEAST(fi.added_date, COALESCE(CAST(w.first_date AS DATETIME), fi.added_date))
                  FROM food_items fi
                  LEFT JOIN (SELECT food_item_id, SUM(quantity_wasted) AS units, MIN(waste_date) AS first_date
                             FROM waste_logs GROUP BY food_item_id) w ON w.food_item_id = fi.id
                  WHERE fi.quantity + COALESCE(w.units, 0) > 0
                    AND NOT EXISTS (SELECT 1 FROM stock_receipts sr WHERE sr.food_item_id = fi.id)");
}

// The online store and customer returns were removed: drop their tables, columns and settings.
// Customer returns are deleted, but anything they were logged as in waste_logs stays.
function removeStoreAndCustomerReturns(mysqli $conn): void {
    // Order lines come first in the list because they reference the orders.
    foreach (OBSOLETE_TABLES as $table) {
        $conn->query("DROP TABLE IF EXISTS `$table`");
    }

    if (in_array('is_listed', table_columns($conn, 'food_items'), true)) {
        $conn->query('ALTER TABLE food_items DROP COLUMN is_listed');
    }

    $conn->query("DELETE FROM app_settings WHERE setting_key IN ('" . implode("', '", OBSOLETE_SETTINGS) . "')");

    $returnColumns = table_columns($conn, 'item_returns');
    if (in_array('return_type', $returnColumns, true)) {
        $conn->query("DELETE FROM item_returns WHERE return_type <> 'supplier'");
    }
    if (in_array('waste_log_id', $returnColumns, true)) {
        // A foreign key has to be dropped before its column, and its name depends on how the table was made.
        $keys = $conn->query("SELECT constraint_name FROM information_schema.key_column_usage
                              WHERE table_schema = DATABASE() AND table_name = 'item_returns'
                                AND column_name = 'waste_log_id' AND referenced_table_name IS NOT NULL");
        while ($keys && ($row = $keys->fetch_row())) {
            $conn->query('ALTER TABLE item_returns DROP FOREIGN KEY `' . $row[0] . '`');
        }
    }
    $drop = array_intersect(OBSOLETE_RETURN_COLUMNS, $returnColumns);
    if ($drop) {
        $conn->query('ALTER TABLE item_returns ' . implode(', ', array_map(fn ($c) => "DROP COLUMN `$c`", $drop)));
    }

    // This reason was only used for disposed customer returns; it stays if any waste was logged with it.
    $conn->query("DELETE wr FROM waste_reasons wr
                  LEFT JOIN waste_logs wl ON wl.waste_reason_id = wr.id
                  WHERE wr.reason = 'Customer Return' AND wl.id IS NULL");
}
