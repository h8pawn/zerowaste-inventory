<?php
// Database upgrades. database/schema.sql creates a complete, current database; this brings
// databases made by older versions of the app up to date (archiving, barcodes and product
// templates, the stock receipts ledger, returns, storefront pricing, online pickup orders,
// store settings and supplier deliveries).
// The checks run until they all pass, then once per SCHEMA_VERSION. Bump it after adding a step.

const SCHEMA_VERSION = 1;

const REQUIRED_TABLES = [
    'users', 'food_categories', 'suppliers', 'food_items', 'waste_reasons', 'waste_logs', 'products',
    'stock_receipts', 'item_returns', 'customer_orders', 'customer_order_items', 'app_settings',
    'supplier_deliveries', 'supplier_delivery_events',
];
const REQUIRED_ITEM_COLUMNS = ['barcode', 'archived_at', 'archive_reason', 'selling_price', 'is_listed'];

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

    // Only record the version once everything is really there, so a failed step is retried.
    [$tables, $columns] = schema_inventory($conn);
    $missing = array_diff(REQUIRED_TABLES, array_keys($tables)) || array_diff(REQUIRED_ITEM_COLUMNS, array_keys($columns));
    if (!$missing) {
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
    $columns = [];
    $result = isset($tables['food_items']) ? $conn->query('SHOW COLUMNS FROM food_items') : false;
    while ($result && ($row = $result->fetch_row())) {
        $columns[strtolower($row[0])] = true;
    }
    return [$tables, $columns];
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
        // NULL selling price = use the store's default markup on the purchase price.
        $conn->query("ALTER TABLE food_items
                        ADD COLUMN selling_price DECIMAL(10,2) NULL DEFAULT NULL,
                        ADD COLUMN is_listed TINYINT(1) NOT NULL DEFAULT 1");
    }

    if (!isset($tables['customer_orders'])) {
        $conn->query("CREATE TABLE IF NOT EXISTS customer_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) NOT NULL UNIQUE,
            customer_name VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(255) NULL,
            pickup_date DATE NOT NULL,
            pickup_time CHAR(5) NOT NULL,
            notes VARCHAR(500) NULL,
            status ENUM('pending', 'ready', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
            cancel_reason VARCHAR(255) NULL,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            savings DECIMAL(10,2) NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) NULL,
            handled_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_order_status (status),
            INDEX idx_order_phone (phone),
            INDEX idx_order_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conn->query("CREATE TABLE IF NOT EXISTS customer_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            food_item_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            unit VARCHAR(50) NULL,
            quantity INT NOT NULL,
            regular_price DECIMAL(10,2) NOT NULL,
            discount_percent INT NOT NULL DEFAULT 0,
            unit_price DECIMAL(10,2) NOT NULL,
            line_total DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (order_id) REFERENCES customer_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (food_item_id) REFERENCES food_items(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!isset($tables['app_settings'])) {
        $conn->query("CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // How the customer will pay for an online reservation (chosen in the basket).
    $result = $conn->query("SHOW COLUMNS FROM customer_orders LIKE 'payment_method'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE customer_orders ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER notes");
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

    $conn->query("CREATE TABLE IF NOT EXISTS item_returns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        food_item_id INT NOT NULL,
        return_type ENUM('customer', 'supplier') NOT NULL,
        outcome ENUM('restocked', 'disposed', 'sent_back') NOT NULL,
        quantity INT NOT NULL,
        unit_value DECIMAL(10,2) NOT NULL DEFAULT 0,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        reason VARCHAR(255) NULL,
        return_date DATE NOT NULL,
        user_id INT NOT NULL,
        waste_log_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (food_item_id) REFERENCES food_items(id) ON DELETE RESTRICT,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY (waste_log_id) REFERENCES waste_logs(id) ON DELETE SET NULL,
        INDEX idx_return_date (return_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("INSERT IGNORE INTO waste_reasons (reason, description)
                  VALUES ('Customer Return', 'Returned by a customer and not fit to resell')");

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
