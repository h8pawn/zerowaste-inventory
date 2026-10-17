-- ZeroWaste: complete database schema with default data.
-- Import into an EMPTY database (phpMyAdmin > Import, or: mysql -u USER -p DBNAME < database/schema.sql).
-- Works on MySQL 5.7+ / 8.x and MariaDB 10.3+. Databases from older app versions are upgraded
-- automatically by includes/schema.php, so never re-import this over existing data.

SET NAMES utf8mb4;

-- Accounts. The first account registered on a fresh database becomes the admin.
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager', 'staff') NOT NULL DEFAULT 'staff',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS food_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255),
    email VARCHAR(255),
    phone VARCHAR(20),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inventory. Archived items are hidden from lists but keep their history.
-- selling_price NULL = store default markup on purchase_price.
CREATE TABLE IF NOT EXISTS food_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    barcode VARCHAR(255) NULL DEFAULT NULL,
    category_id INT NOT NULL,
    quantity INT NOT NULL,
    unit VARCHAR(50),
    purchase_price DECIMAL(10,2),
    expiration_date DATE NOT NULL,
    storage_location VARCHAR(255),
    supplier_id INT,
    added_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at DATETIME NULL DEFAULT NULL,
    archive_reason VARCHAR(20) NULL DEFAULT NULL,
    selling_price DECIMAL(10,2) NULL DEFAULT NULL,
    is_listed TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY barcode (barcode),
    FOREIGN KEY (category_id) REFERENCES food_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    INDEX idx_expiration (expiration_date),
    INDEX idx_category (category_id),
    INDEX idx_archived (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS waste_reasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reason VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS waste_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    food_item_id INT NOT NULL,
    quantity_wasted INT NOT NULL,
    waste_reason_id INT NOT NULL,
    waste_date DATE NOT NULL,
    user_id INT NOT NULL,
    notes TEXT,
    estimated_value DECIMAL(10,2),
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (food_item_id) REFERENCES food_items(id) ON DELETE CASCADE,
    FOREIGN KEY (waste_reason_id) REFERENCES waste_reasons(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_waste_date (waste_date),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Product templates: what a barcode is, so Quick Scan can add a new batch on its own.
CREATE TABLE IF NOT EXISTS products (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every unit that comes into stock, so dashboards can report what stock cost.
CREATE TABLE IF NOT EXISTS stock_receipts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS item_returns (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Online pickup reservations from the storefront. Stock is deducted when picked up.
CREATE TABLE IF NOT EXISTS customer_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    customer_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(255) NULL,
    pickup_date DATE NOT NULL,
    pickup_time CHAR(5) NOT NULL,
    notes VARCHAR(500) NULL,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_order_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Store settings (Store Settings page) and the schema version.
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Supplier transactions: scheduled deliveries, tracked from "on the way" to "delivered".
CREATE TABLE IF NOT EXISTS supplier_deliveries (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_delivery_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT NOT NULL,
    event VARCHAR(20) NOT NULL,
    note VARCHAR(255) NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_id) REFERENCES supplier_deliveries(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_event_delivery (delivery_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default data
INSERT INTO waste_reasons (reason, description) VALUES
('Expired', 'Product has passed expiration date'),
('Damaged/Spoiled', 'Product is damaged or physically spoiled'),
('Unsaleable', 'Product cannot be sold due to packaging or quality issues'),
('Overstock', 'Excess inventory that cannot be used'),
('Recall', 'Product subject to safety recall'),
('Spillage', 'Product lost due to spill or accident'),
('Quality Control', 'Product failed quality inspection'),
('Other', 'Miscellaneous waste reason'),
('Customer Return', 'Returned by a customer and not fit to resell')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO food_categories (name, description) VALUES
('Vegetables', 'Fresh vegetables'),
('Fruits', 'Fresh fruits'),
('Dairy', 'Milk products and cheese'),
('Meat/Seafood', 'Meat and seafood products'),
('Bakery', 'Bread and bakery items'),
('Pantry', 'Dry goods and pantry items')
ON DUPLICATE KEY UPDATE description = VALUES(description);
