<?php
// Read-only lookups on the store's records, shared by the AI Waste Advisor's chat: Claude calls them
// as tools (advisor_ai.php), and the built-in answers use them too (advisor_chat.php).
// Every function returns plain arrays that are safe to show or send: no passwords and no staff emails.

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/advisor.php';

const ADVISOR_TOOL_MAX_ROWS = 100;

function advisor_tool_text(array $in, string $key): string {
    return mb_substr(trim((string) ($in[$key] ?? '')), 0, 100);
}

function advisor_tool_choice(array $in, string $key, array $allowed, string $default): string {
    $value = (string) ($in[$key] ?? $default);
    if (!in_array($value, $allowed, true)) {
        throw new InvalidArgumentException($key . ' must be one of: ' . implode(', ', $allowed) . '.');
    }
    return $value;
}

function advisor_tool_limit(array $in): int {
    return max(1, min(ADVISOR_TOOL_MAX_ROWS, (int) ($in['limit'] ?? 50)));
}

// [from, to] as Y-m-d, from the input or the defaults.
function advisor_tool_range(array $in, string $defaultFrom): array {
    $range = [];
    foreach (['from' => $defaultFrom, 'to' => date('Y-m-d')] as $key => $default) {
        $value = trim((string) ($in[$key] ?? ''));
        if ($value !== '' && !valid_date($value)) {
            throw new InvalidArgumentException($key . ' must be a date written YYYY-MM-DD.');
        }
        $range[] = $value !== '' ? $value : $default;
    }
    if ($range[0] > $range[1]) {
        $range = [$range[1], $range[0]];
    }
    return $range;
}

function advisor_tool_money($value): float {
    return round((float) $value, 2);
}

function advisor_tool_waste_totals(string $from, string $to): array {
    $row = db_one(
        'SELECT COUNT(*) AS entries, COALESCE(SUM(quantity_wasted), 0) AS units, COALESCE(SUM(estimated_value), 0) AS value
         FROM waste_logs WHERE waste_date BETWEEN ? AND ?',
        'ss',
        [$from, $to]
    );
    return ['entries' => (int) $row['entries'], 'units' => (int) $row['units'], 'value' => advisor_tool_money($row['value'])];
}

function advisor_tool_received_cost(string $from, string $to): float {
    return advisor_tool_money(db_value(
        'SELECT COALESCE(SUM(quantity * unit_cost), 0) FROM stock_receipts WHERE received_at >= ? AND received_at < ? + INTERVAL 1 DAY',
        'ss',
        [$from, $to]
    ));
}

function advisor_tool_overview(): array {
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
    $lastMonthEnd = date('Y-m-t', strtotime('first day of last month'));

    $inv = db_one(
        'SELECT COUNT(*) AS records, COUNT(DISTINCT LOWER(TRIM(fi.name))) AS products,
                COALESCE(SUM(fi.quantity), 0) AS units,
                COALESCE(SUM(fi.quantity * COALESCE(fi.purchase_price, 0)), 0) AS value_cost,
                COALESCE(SUM(fi.quantity * COALESCE(fi.selling_price, fi.purchase_price * (1 + ? / 100), 0)), 0) AS value_retail,
                COALESCE(SUM(fi.quantity > 0), 0) AS in_stock,
                COALESCE(SUM(fi.quantity = 0), 0) AS out_of_stock,
                COALESCE(SUM(fi.quantity > 0 AND fi.quantity <= ?), 0) AS low_stock,
                COALESCE(SUM(fi.quantity >= ?), 0) AS overstock,
                COALESCE(SUM(fi.quantity > 0 AND fi.expiration_date BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY), 0) AS expiring,
                COALESCE(SUM(CASE WHEN fi.expiration_date BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY THEN fi.quantity END), 0) AS expiring_units,
                COALESCE(SUM(fi.purchase_price IS NULL), 0) AS without_price
         FROM food_items fi WHERE ' . ACTIVE_ITEM_SQL,
        'iiiii',
        [DEFAULT_MARKUP_PERCENT, LOW_STOCK_THRESHOLD, OVERSTOCK_THRESHOLD, EXPIRING_SOON_DAYS, EXPIRING_SOON_DAYS]
    );
    $categories = db_all(
        'SELECT fc.name AS category, COUNT(fi.id) AS item_records, COALESCE(SUM(fi.quantity), 0) AS units,
                COALESCE(SUM(fi.quantity * COALESCE(fi.purchase_price, 0)), 0) AS stock_value
         FROM food_categories fc
         LEFT JOIN food_items fi ON fi.category_id = fc.id AND ' . ACTIVE_ITEM_SQL . '
         GROUP BY fc.id, fc.name
         ORDER BY fc.name'
    );
    $returns = db_one(
        'SELECT COUNT(*) AS returns_count, COALESCE(SUM(quantity), 0) AS units,
                COALESCE(SUM(quantity * unit_value), 0) AS value, COALESCE(SUM(amount), 0) AS credit
         FROM item_returns WHERE return_date >= ?',
        's',
        [$monthStart]
    );

    return [
        'as_of' => date('Y-m-d H:i'),
        'inventory' => [
            'item_records' => (int) $inv['records'],
            'distinct_products' => (int) $inv['products'],
            'units_in_stock' => (int) $inv['units'],
            'stock_value_at_cost' => advisor_tool_money($inv['value_cost']),
            'stock_value_at_selling_price' => advisor_tool_money($inv['value_retail']),
            'records_in_stock' => (int) $inv['in_stock'],
            'records_out_of_stock' => (int) $inv['out_of_stock'],
            'records_low_stock' => (int) $inv['low_stock'],
            'records_overstocked' => (int) $inv['overstock'],
            'records_expiring_soon' => (int) $inv['expiring'],
            'units_expiring_soon' => (int) $inv['expiring_units'],
            'records_without_purchase_price' => (int) $inv['without_price'],
            'archived_records' => (int) db_value('SELECT COUNT(*) FROM food_items WHERE archived_at IS NOT NULL'),
        ],
        'by_category' => array_map(fn ($c) => [
            'category' => $c['category'],
            'item_records' => (int) $c['item_records'],
            'units' => (int) $c['units'],
            'stock_value' => advisor_tool_money($c['stock_value']),
        ], $categories),
        'waste' => [
            'today' => advisor_tool_waste_totals($today, $today),
            'this_month' => advisor_tool_waste_totals($monthStart, $today),
            'last_month' => advisor_tool_waste_totals($lastMonthStart, $lastMonthEnd),
        ],
        'stock_received_cost' => [
            'this_month' => advisor_tool_received_cost($monthStart, $today),
            'last_month' => advisor_tool_received_cost($lastMonthStart, $lastMonthEnd),
        ],
        'supplier_returns_this_month' => [
            'returns' => (int) $returns['returns_count'],
            'units' => (int) $returns['units'],
            'value' => advisor_tool_money($returns['value']),
            'credit' => advisor_tool_money($returns['credit']),
        ],
        'suppliers' => (int) db_value('SELECT COUNT(*) FROM suppliers'),
        'deliveries' => delivery_counts(),
        'categories' => (int) db_value('SELECT COUNT(*) FROM food_categories'),
        'product_templates' => (int) db_value('SELECT COUNT(*) FROM products'),
        'user_accounts' => array_map('intval', db_one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS active, COALESCE(SUM(is_active = 0), 0) AS waiting_or_deactivated,
                    COALESCE(SUM(role = 'admin'), 0) AS admins, COALESCE(SUM(role = 'manager'), 0) AS managers, COALESCE(SUM(role = 'staff'), 0) AS staff
             FROM users"
        )),
    ];
}

function advisor_tool_search_items(array $in): array {
    $status = advisor_tool_choice($in, 'status', ['any', 'in_stock', 'low_stock', 'out_of_stock', 'overstock', 'expiring_soon', 'fresh', 'expired'], 'any');
    $sort = advisor_tool_choice($in, 'sort', ['name', 'quantity_asc', 'quantity_desc', 'expiry', 'value_desc', 'recently_updated'], 'expiry');
    $where = empty($in['include_archived']) ? [ACTIVE_ITEM_SQL] : ['1 = 1'];
    $types = '';
    $params = [];

    if (($q = advisor_tool_text($in, 'query')) !== '') {
        $where[] = '(fi.name LIKE ? OR fi.barcode LIKE ? OR fc.name LIKE ? OR s.name LIKE ? OR fi.storage_location LIKE ?)';
        $types .= 'sssss';
        array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
    }
    if (($category = advisor_tool_text($in, 'category')) !== '') {
        $where[] = 'fc.name LIKE ?';
        $types .= 's';
        $params[] = '%' . $category . '%';
    }
    $where[] = match ($status) {
        'in_stock' => 'fi.quantity > 0',
        'low_stock' => 'fi.quantity > 0 AND fi.quantity <= ' . LOW_STOCK_THRESHOLD,
        'out_of_stock' => 'fi.quantity = 0',
        'overstock' => 'fi.quantity >= ' . OVERSTOCK_THRESHOLD,
        'expiring_soon' => 'fi.quantity > 0 AND fi.expiration_date BETWEEN CURDATE() AND CURDATE() + INTERVAL ' . EXPIRING_SOON_DAYS . ' DAY',
        'fresh' => 'fi.expiration_date > CURDATE() + INTERVAL ' . EXPIRING_SOON_DAYS . ' DAY',
        'expired' => 'fi.expiration_date < CURDATE()',
        default => '1 = 1',
    };
    $order = match ($sort) {
        'name' => 'fi.name, fi.expiration_date',
        'quantity_asc' => 'fi.quantity, fi.name',
        'quantity_desc' => 'fi.quantity DESC, fi.name',
        'value_desc' => 'fi.quantity * COALESCE(fi.purchase_price, 0) DESC, fi.name',
        'recently_updated' => 'fi.updated_at DESC',
        default => 'fi.expiration_date, fi.name',
    };
    $from = 'FROM food_items fi
             JOIN food_categories fc ON fc.id = fi.category_id
             LEFT JOIN suppliers s ON s.id = fi.supplier_id
             WHERE ' . implode(' AND ', $where);

    $rows = db_all(
        "SELECT fi.name, fi.barcode, fc.name AS category, fi.quantity, fi.unit, fi.purchase_price, fi.selling_price,
                fi.expiration_date, DATEDIFF(fi.expiration_date, CURDATE()) AS days_left, fi.storage_location,
                s.name AS supplier, fi.archive_reason, fi.archived_at
         $from ORDER BY $order LIMIT ?",
        $types . 'i',
        array_merge($params, [advisor_tool_limit($in)])
    );

    return [
        'total_matching' => (int) db_value("SELECT COUNT(*) $from", $types, $params),
        'items' => array_map(function ($r) {
            $qty = (int) $r['quantity'];
            $days = (int) $r['days_left'];
            return [
                'name' => $r['name'],
                'barcode' => $r['barcode'],
                'category' => $r['category'],
                'quantity' => $qty,
                'unit' => $r['unit'],
                'unit_cost' => $r['purchase_price'] !== null ? advisor_tool_money($r['purchase_price']) : null,
                'selling_price' => retail_price($r['selling_price'], $r['purchase_price']),
                'selling_price_is_default_markup' => $r['selling_price'] === null && $r['purchase_price'] !== null,
                'stock_value_at_cost' => advisor_tool_money($qty * (float) $r['purchase_price']),
                'expires' => $r['expiration_date'],
                'days_left' => $days,
                'freshness' => freshness_status($days)[0],
                'stock_status' => stock_status($qty)[0],
                'suggested_markdown_percent' => $qty > 0 ? discount_percent($days) : 0,
                'storage' => $r['storage_location'] ?: null,
                'supplier' => $r['supplier'],
                'archived' => $r['archived_at'] !== null ? archive_reason_label($r['archive_reason'])[0] : null,
            ];
        }, $rows),
    ];
}

function advisor_tool_waste(array $in): array {
    [$from, $to] = advisor_tool_range($in, date('Y-m-01'));
    $groupBy = advisor_tool_choice($in, 'group_by', ['none', 'item', 'reason', 'category', 'supplier', 'day', 'month', 'staff'], 'none');
    $where = ['wl.waste_date BETWEEN ? AND ?'];
    $types = 'ss';
    $params = [$from, $to];
    foreach (['item' => 'fi.name', 'reason' => 'wr.reason'] as $key => $column) {
        if (($text = advisor_tool_text($in, $key)) !== '') {
            $where[] = "$column LIKE ?";
            $types .= 's';
            $params[] = '%' . $text . '%';
        }
    }
    $fromSql = 'FROM waste_logs wl
                JOIN food_items fi ON fi.id = wl.food_item_id
                JOIN waste_reasons wr ON wr.id = wl.waste_reason_id
                JOIN food_categories fc ON fc.id = fi.category_id
                LEFT JOIN suppliers s ON s.id = fi.supplier_id
                JOIN users u ON u.id = wl.user_id
                WHERE ' . implode(' AND ', $where);

    $totals = db_one("SELECT COUNT(*) AS entries, COALESCE(SUM(wl.quantity_wasted), 0) AS units, COALESCE(SUM(wl.estimated_value), 0) AS value $fromSql", $types, $params);
    $result = [
        'from' => $from,
        'to' => $to,
        'totals' => ['entries' => (int) $totals['entries'], 'units' => (int) $totals['units'], 'value' => advisor_tool_money($totals['value'])],
    ];

    if ($groupBy === 'none') {
        $rows = db_all(
            "SELECT wl.waste_date, fi.name, wl.quantity_wasted, fi.unit, wr.reason, wl.estimated_value, wl.notes, u.username
             $fromSql ORDER BY wl.waste_date DESC, wl.id DESC LIMIT ?",
            $types . 'i',
            array_merge($params, [advisor_tool_limit($in)])
        );
        $result['entries'] = array_map(fn ($r) => [
            'date' => $r['waste_date'],
            'item' => $r['name'],
            'quantity' => (int) $r['quantity_wasted'],
            'unit' => $r['unit'],
            'reason' => $r['reason'],
            'value' => advisor_tool_money($r['estimated_value']),
            'cleared_automatically_after_expiry' => str_starts_with((string) $r['notes'], 'Auto-moved by system'),
            'notes' => $r['notes'] ?: null,
            'logged_by' => $r['username'],
        ], $rows);
        return $result;
    }

    $group = match ($groupBy) {
        'item' => 'fi.name',
        'reason' => 'wr.reason',
        'category' => 'fc.name',
        'supplier' => "COALESCE(s.name, 'No supplier')",
        'day' => 'wl.waste_date',
        'month' => "DATE_FORMAT(wl.waste_date, '%Y-%m')",
        'staff' => 'u.username',
    };
    $order = in_array($groupBy, ['day', 'month'], true) ? 'grp' : 'value DESC, units DESC';
    $rows = db_all(
        "SELECT $group AS grp, MAX(fi.unit) AS unit, COUNT(*) AS entries, SUM(wl.quantity_wasted) AS units, SUM(wl.estimated_value) AS value
         $fromSql GROUP BY grp ORDER BY $order LIMIT ?",
        $types . 'i',
        array_merge($params, [advisor_tool_limit($in)])
    );
    $result['groups'] = array_map(fn ($r) => [$groupBy => $r['grp'], 'entries' => (int) $r['entries'], 'units' => (int) $r['units']]
        + ($groupBy === 'item' ? ['unit' => $r['unit']] : []) + ['value' => advisor_tool_money($r['value'])], $rows);
    return $result;
}

function advisor_tool_stock_received(array $in): array {
    [$from, $to] = advisor_tool_range($in, date('Y-m-01'));
    $groupBy = advisor_tool_choice($in, 'group_by', ['none', 'item', 'supplier', 'category', 'month', 'source'], 'item');
    $where = ['sr.received_at >= ?', 'sr.received_at < ? + INTERVAL 1 DAY'];
    $types = 'ss';
    $params = [$from, $to];
    foreach (['item' => 'fi.name', 'supplier' => 's.name'] as $key => $column) {
        if (($text = advisor_tool_text($in, $key)) !== '') {
            $where[] = "$column LIKE ?";
            $types .= 's';
            $params[] = '%' . $text . '%';
        }
    }
    $fromSql = 'FROM stock_receipts sr
                JOIN food_items fi ON fi.id = sr.food_item_id
                JOIN food_categories fc ON fc.id = fi.category_id
                LEFT JOIN suppliers s ON s.id = fi.supplier_id
                WHERE ' . implode(' AND ', $where);

    $totals = db_one("SELECT COUNT(*) AS receipts, COALESCE(SUM(sr.quantity), 0) AS units, COALESCE(SUM(sr.quantity * sr.unit_cost), 0) AS cost $fromSql", $types, $params);
    $result = [
        'from' => $from,
        'to' => $to,
        'sources' => 'initial = new item or first stock, receive = stock received, scan = Quick Scan, adjust = quantity corrected upward, restore = restored from the archive',
        'totals' => ['receipts' => (int) $totals['receipts'], 'units' => (int) $totals['units'], 'cost' => advisor_tool_money($totals['cost'])],
    ];

    if ($groupBy === 'none') {
        $rows = db_all(
            "SELECT DATE(sr.received_at) AS day, fi.name, sr.quantity, fi.unit, sr.unit_cost, sr.source
             $fromSql ORDER BY sr.received_at DESC, sr.id DESC LIMIT ?",
            $types . 'i',
            array_merge($params, [advisor_tool_limit($in)])
        );
        $result['receipts'] = array_map(fn ($r) => [
            'date' => $r['day'],
            'item' => $r['name'],
            'quantity' => (int) $r['quantity'],
            'unit' => $r['unit'],
            'unit_cost' => advisor_tool_money($r['unit_cost']),
            'cost' => advisor_tool_money($r['quantity'] * $r['unit_cost']),
            'source' => $r['source'],
        ], $rows);
        return $result;
    }

    $group = match ($groupBy) {
        'item' => 'fi.name',
        'supplier' => "COALESCE(s.name, 'No supplier')",
        'category' => 'fc.name',
        'month' => "DATE_FORMAT(sr.received_at, '%Y-%m')",
        'source' => 'sr.source',
    };
    $order = $groupBy === 'month' ? 'grp' : 'cost DESC';
    $rows = db_all(
        "SELECT $group AS grp, MAX(fi.unit) AS unit, COUNT(*) AS receipts, SUM(sr.quantity) AS units, SUM(sr.quantity * sr.unit_cost) AS cost
         $fromSql GROUP BY grp ORDER BY $order LIMIT ?",
        $types . 'i',
        array_merge($params, [advisor_tool_limit($in)])
    );
    $result['groups'] = array_map(fn ($r) => [$groupBy => $r['grp'], 'receipts' => (int) $r['receipts'], 'units' => (int) $r['units']]
        + ($groupBy === 'item' ? ['unit' => $r['unit']] : []) + ['cost' => advisor_tool_money($r['cost'])], $rows);
    return $result;
}

function advisor_tool_supplier_returns(array $in): array {
    [$from, $to] = advisor_tool_range($in, date('Y-m-d', strtotime('-89 days')));
    $where = ['r.return_date BETWEEN ? AND ?'];
    $types = 'ss';
    $params = [$from, $to];
    foreach (['supplier' => 's.name', 'item' => 'fi.name'] as $key => $column) {
        if (($text = advisor_tool_text($in, $key)) !== '') {
            $where[] = "$column LIKE ?";
            $types .= 's';
            $params[] = '%' . $text . '%';
        }
    }
    $rows = db_all(
        'SELECT r.return_date, fi.name, COALESCE(s.name, \'No supplier\') AS supplier, r.quantity, fi.unit, r.unit_value, r.amount, r.reason, u.username
         FROM item_returns r
         JOIN food_items fi ON fi.id = r.food_item_id
         JOIN users u ON u.id = r.user_id
         LEFT JOIN suppliers s ON s.id = fi.supplier_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY r.return_date DESC, r.id DESC',
        $types,
        $params
    );
    $value = array_sum(array_map(fn ($r) => $r['quantity'] * $r['unit_value'], $rows));
    $credit = array_sum(array_column($rows, 'amount'));
    return [
        'from' => $from,
        'to' => $to,
        'totals' => [
            'returns' => count($rows),
            'units' => array_sum(array_map('intval', array_column($rows, 'quantity'))),
            'stock_value' => advisor_tool_money($value),
            'credit_received' => advisor_tool_money($credit),
            'not_credited' => advisor_tool_money(max(0, $value - $credit)),
        ],
        'returns' => array_map(fn ($r) => [
            'date' => $r['return_date'],
            'item' => $r['name'],
            'supplier' => $r['supplier'],
            'quantity' => (int) $r['quantity'],
            'unit' => $r['unit'],
            'stock_value' => advisor_tool_money($r['quantity'] * $r['unit_value']),
            'credit' => advisor_tool_money($r['amount']),
            'reason' => $r['reason'] ?: null,
            'recorded_by' => $r['username'],
        ], array_slice($rows, 0, advisor_tool_limit($in))),
    ];
}

function advisor_tool_suppliers(array $in): array {
    $where = '1 = 1';
    $types = '';
    $params = [];
    if (($q = advisor_tool_text($in, 'query')) !== '') {
        $where = '(s.name LIKE ? OR s.contact_person LIKE ? OR EXISTS (SELECT 1 FROM supplier_products sp WHERE sp.supplier_id = s.id AND sp.name LIKE ?))';
        $types = 'sss';
        $params = array_fill(0, 3, '%' . $q . '%');
    }
    $rows = db_all(
        "SELECT s.name, s.contact_person, s.phone, s.email, s.address,
                COUNT(fi.id) AS active_items, COALESCE(SUM(fi.quantity), 0) AS units,
                (SELECT COUNT(*) FROM supplier_deliveries d WHERE d.supplier_id = s.id AND d.status IN ('scheduled', 'on_the_way')) AS open_deliveries,
                (SELECT MAX(DATE(d.delivered_at)) FROM supplier_deliveries d WHERE d.supplier_id = s.id AND d.status = 'delivered') AS last_delivered,
                (SELECT MAX(DATE(sr.received_at)) FROM stock_receipts sr JOIN food_items ri ON ri.id = sr.food_item_id
                 WHERE ri.supplier_id = s.id AND sr.source <> 'adjust') AS last_received,
                (SELECT GROUP_CONCAT(CONCAT(sp.name, ' (', fc.name, ')') ORDER BY fc.name, sp.name SEPARATOR '; ')
                 FROM supplier_products sp JOIN food_categories fc ON fc.id = sp.category_id WHERE sp.supplier_id = s.id) AS products
         FROM suppliers s
         LEFT JOIN food_items fi ON fi.supplier_id = s.id AND " . ACTIVE_ITEM_SQL . "
         WHERE $where
         GROUP BY s.id
         ORDER BY s.name
         LIMIT " . ADVISOR_TOOL_MAX_ROWS,
        $types,
        $params
    );
    return ['suppliers' => array_map(fn ($r) => [
        'name' => $r['name'],
        'contact_person' => $r['contact_person'] ?: null,
        'phone' => $r['phone'] ?: null,
        'email' => $r['email'] ?: null,
        'address' => $r['address'] ?: null,
        'active_items' => (int) $r['active_items'],
        'units_in_stock' => (int) $r['units'],
        'products_they_deliver' => $r['products'] ? explode('; ', $r['products']) : [],
        'open_deliveries' => (int) $r['open_deliveries'],
        'last_delivery_or_stock_received' => max($r['last_delivered'] ?? '', $r['last_received'] ?? '') ?: null,
    ], $rows)];
}

function advisor_tool_delivery_row(array $r): array {
    return [
        'reference' => delivery_ref((int) $r['id']),
        'supplier' => $r['supplier_name'],
        'items' => $r['items'],
        'amount' => $r['amount'] !== null ? advisor_tool_money($r['amount']) : null,
        'expected_date' => $r['expected_date'],
        'expected_time' => $r['expected_time'] ?: null,
        'status' => delivery_status_meta($r['status'])[0],
        'overdue' => $r['status'] === 'scheduled' && $r['expected_date'] < date('Y-m-d'),
        'payment' => $r['payment_status'],
        'notes' => $r['notes'] ?: null,
        'shipped_at' => $r['shipped_at'],
        'delivered_at' => $r['delivered_at'],
    ];
}

function advisor_tool_deliveries(array $in): array {
    // A single delivery ("DLV-00012" or 12) with who did what and when.
    $reference = advisor_tool_text($in, 'reference');
    if ($reference !== '') {
        $id = (int) preg_replace('/\D+/', '', $reference);
        $d = $id > 0 ? db_one('SELECT d.*, u.username AS created_by_name FROM supplier_deliveries d LEFT JOIN users u ON u.id = d.created_by WHERE d.id = ?', 'i', [$id]) : null;
        if (!$d) {
            throw new InvalidArgumentException('There is no delivery ' . $reference . '.');
        }
        $events = db_all(
            'SELECT e.event, e.note, e.created_at, u.username FROM supplier_delivery_events e LEFT JOIN users u ON u.id = e.user_id
             WHERE e.delivery_id = ? ORDER BY e.created_at, e.id',
            'i',
            [$id]
        );
        return ['delivery' => advisor_tool_delivery_row($d) + [
            'scheduled_by' => $d['created_by_name'],
            'scheduled_at' => $d['created_at'],
            'paid_at' => $d['paid_at'],
            'transaction_log' => array_map(fn ($e) => [
                'when' => $e['created_at'],
                'what' => DELIVERY_EVENTS[$e['event']][0] ?? ucfirst($e['event']),
                'note' => $e['note'] ?: null,
                'by' => $e['username'],
            ], $events),
        ]];
    }

    $status = advisor_tool_choice($in, 'status', ['any', 'open', 'scheduled', 'on_the_way', 'delivered', 'cancelled', 'unpaid'], 'any');
    $where = [match ($status) {
        'open' => "status IN ('scheduled', 'on_the_way')",
        'unpaid' => "payment_status = 'unpaid' AND status <> 'cancelled'",
        'any' => '1 = 1',
        default => "status = '$status'",
    }];
    $types = '';
    $params = [];
    if (($supplier = advisor_tool_text($in, 'supplier')) !== '') {
        $where[] = 'supplier_name LIKE ?';
        $types .= 's';
        $params[] = '%' . $supplier . '%';
    }
    foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
        $value = trim((string) ($in[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        if (!valid_date($value)) {
            throw new InvalidArgumentException($key . ' must be a date written YYYY-MM-DD.');
        }
        $where[] = "expected_date $op ?";
        $types .= 's';
        $params[] = $value;
    }
    // Upcoming work reads best soonest first; history newest first.
    $order = in_array($status, ['open', 'scheduled', 'on_the_way'], true) ? 'expected_date, expected_time' : 'expected_date DESC, id DESC';
    $rows = db_all(
        'SELECT id, supplier_name, items, amount, expected_date, expected_time, status, payment_status, notes, shipped_at, delivered_at
         FROM supplier_deliveries WHERE ' . implode(' AND ', $where) . " ORDER BY $order LIMIT ?",
        $types . 'i',
        array_merge($params, [advisor_tool_limit($in)])
    );
    $unpaid = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM supplier_deliveries WHERE status = 'delivered' AND payment_status = 'unpaid'");
    return [
        'counts' => delivery_counts() + ['delivered_but_unpaid' => (int) $unpaid['n'], 'unpaid_amount' => advisor_tool_money($unpaid['total'])],
        'deliveries' => array_map('advisor_tool_delivery_row', $rows),
    ];
}

function advisor_tool_insights(array $in): array {
    $section = advisor_tool_choice($in, 'section', ['all', 'summary', 'recommendations', 'expiry_forecast', 'order_plan'], 'all');
    $item = mb_strtolower(advisor_tool_text($in, 'item'));
    $matches = fn (string $name) => $item === '' || str_contains(mb_strtolower($name), $item);
    $plain = fn (string $text) => str_replace('**', '', $text);
    $a = advisor_analyze();
    $t = $a['totals'];
    $out = ['history_days' => ADVISOR_WINDOW_DAYS, 'forecast_days' => ADVISOR_HORIZON_DAYS];

    if (in_array($section, ['all', 'summary'], true)) {
        $s = $a['score'];
        $out['zero_waste_score'] = $s ? [
            'score' => $s['score'],
            'grade' => $s['grade'][0] . ' (' . $s['grade'][1] . ')',
            'waste_rate_percent_of_stock_cost' => round($s['rate'], 1),
            'rate_measured_over_days' => $s['rate_span'],
            'target_rate_percent' => ADVISOR_TARGET_RATE,
            'parts' => array_map(fn ($p) => ['part' => $p['label'], 'points' => round($p['points'], 1), 'out_of' => $p['max'], 'detail' => $p['detail']], $s['parts']),
        ] : null;
        $out['waste_value'] = [
            'last_30_days' => advisor_tool_money($t['value30']),
            'previous_30_days' => advisor_tool_money($t['value_prev30']),
            'last_90_days' => advisor_tool_money($t['value90']),
        ];
        $out['stock_cost_received'] = ['last_30_days' => advisor_tool_money($t['cost30']), 'last_90_days' => advisor_tool_money($t['cost90'])];
        $out['forecast_waste_next_30_days'] = ['value' => advisor_tool_money($a['at_risk_value']), 'units' => (int) $a['at_risk_units']];
    }
    if (in_array($section, ['all', 'recommendations'], true)) {
        $out['recommendations'] = array_map(fn ($r) => [
            'priority' => ADVISOR_PRIORITIES[$r['priority']][0],
            'title' => $r['title'],
            'why' => $plain($r['why']),
            'actions' => array_map($plain, $r['actions']),
            'money_at_stake' => $r['impact'] ? advisor_tool_money($r['impact']) . ' ' . $r['impact_label'] : null,
        ], $a['recommendations']);
    }
    if (in_array($section, ['all', 'expiry_forecast'], true)) {
        $rows = array_values(array_filter($a['forecast'], fn ($r) => $matches($r['name'])));
        $out['expiry_forecast'] = array_map(fn ($r) => [
            'item' => $r['name'],
            'in_stock' => $r['quantity'],
            'unit' => $r['unit'],
            'sells_per_day' => round($r['pace'], 2),
            'expires' => $r['expires'],
            'days_left' => $r['days_left'],
            'forecast_left_unsold' => $r['leftover'],
            'value_at_risk' => advisor_tool_money($r['value']),
            'confidence' => $r['confidence'],
            'what_to_do' => $r['action'],
        ], array_slice($rows, 0, 25));
    }
    if (in_array($section, ['all', 'order_plan'], true)) {
        $rows = array_values(array_filter($a['order_plan'], fn ($o) => $matches($o['name'])));
        $out['order_plan'] = array_map(fn ($o) => [
            'item' => $o['name'],
            'unit' => $o['unit'],
            'sells_per_day' => round($o['pace'], 2),
            'shelf_life_days' => $o['shelf'],
            'stock_that_will_still_sell' => $o['sellable'],
            'days_of_stock' => round($o['cover'], 1),
            'order_at_most' => $o['cap'],
            'share_wasted_percent' => $o['waste_share'] !== null ? round($o['waste_share'] * 100) : null,
            'status' => $o['status'][0],
            'confidence' => $o['confidence'],
        ], array_slice($rows, 0, 25));
    }
    if ($item !== '') {
        $products = array_values(array_filter($a['products'], fn ($p) => $matches($p['name'])));
        $out['products'] = array_map(fn ($p) => [
            'item' => $p['name'],
            'category' => $p['category'],
            'supplier' => $p['supplier'],
            'storage' => $p['storage'] ?: null,
            'still_stocked' => $p['active'],
            'in_stock' => $p['stock'],
            'unit' => $p['unit'],
            'batches' => array_map(fn ($b) => ['quantity' => $b['quantity'], 'expires' => $b['expires'], 'days_left' => $b['days_left']], $p['batches']),
            'estimated_sales_per_day' => round($p['pace'], 2),
            'history_days' => $p['days'],
            'confidence' => $p['confidence'],
            'received_all_time' => $p['received'],
            'wasted_all_time' => $p['wasted'],
            'returned_to_supplier_all_time' => $p['sent_back'],
            'wasted_last_90_days' => ['units' => $p['waste90'], 'value' => advisor_tool_money($p['value90']), 'main_reason' => $p['main_reason']],
        ], array_slice($products, 0, 10));
    }
    return $out;
}

// Percent change from $before to $now, or null when there is nothing to compare with.
function advisor_tool_change(float $now, float $before): ?float {
    return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
}

// The Reports page for any period: totals against the period before, top items, reasons,
// categories and suppliers, stock cost, supplier returns and the 12-month trend.
function advisor_tool_report(array $in): array {
    if (trim((string) ($in['from'] ?? '')) !== '' || trim((string) ($in['to'] ?? '')) !== '') {
        [$from, $to] = advisor_tool_range($in, date('Y-m-01'));
        $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
        $prev = [date('Y-m-d', strtotime("$from -$days days")), date('Y-m-d', strtotime("$from -1 day"))];
        $label = 'Custom range';
    } else {
        $period = advisor_tool_choice($in, 'period', array_keys(REPORT_PERIODS), 'this_month');
        [$start, $end, $prevStart, $prevEnd] = report_period($period);
        [$from, $to] = [$start->format('Y-m-d'), $end->format('Y-m-d')];
        $prev = [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')];
        $label = REPORT_PERIODS[$period];
    }

    $now = advisor_tool_waste_totals($from, $to);
    $before = advisor_tool_waste_totals(...$prev);
    $cost = advisor_tool_received_cost($from, $to);
    $grouped = function (string $column, string $join = '') use ($from, $to): array {
        $rows = db_all(
            "SELECT $column AS grp, COUNT(*) AS entries, SUM(wl.quantity_wasted) AS units, COALESCE(SUM(wl.estimated_value), 0) AS value
             FROM waste_logs wl
             JOIN food_items fi ON fi.id = wl.food_item_id
             $join
             WHERE wl.waste_date BETWEEN ? AND ?
             GROUP BY grp ORDER BY value DESC, units DESC LIMIT 10",
            'ss',
            [$from, $to]
        );
        $total = array_sum(array_column($rows, 'value'));
        return array_map(fn ($r) => [
            'name' => $r['grp'], 'entries' => (int) $r['entries'], 'units' => (int) $r['units'],
            'value' => advisor_tool_money($r['value']),
            'share_percent' => $total > 0 ? round((float) $r['value'] / $total * 100, 1) : null,
        ], $rows);
    };
    $returns = db_one(
        'SELECT COUNT(*) AS n, COALESCE(SUM(quantity * unit_value), 0) AS value, COALESCE(SUM(amount), 0) AS credit
         FROM item_returns WHERE return_date BETWEEN ? AND ?',
        'ss',
        [$from, $to]
    );
    $trend = array_column(db_all(
        "SELECT DATE_FORMAT(waste_date, '%Y-%m') AS ym, SUM(quantity_wasted) AS units, SUM(estimated_value) AS value
         FROM waste_logs WHERE waste_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
         GROUP BY ym"
    ), null, 'ym');
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("first day of -$i months"));
        $months[] = ['month' => $ym, 'units' => (int) ($trend[$ym]['units'] ?? 0), 'value' => advisor_tool_money($trend[$ym]['value'] ?? 0)];
    }

    return [
        'period' => $label,
        'from' => $from,
        'to' => $to,
        'compared_with' => ['from' => $prev[0], 'to' => $prev[1]],
        'waste' => $now,
        'waste_previous_period' => $before,
        'change_percent' => [
            'value' => advisor_tool_change($now['value'], $before['value']),
            'units' => advisor_tool_change($now['units'], $before['units']),
            'entries' => advisor_tool_change($now['entries'], $before['entries']),
        ],
        'stock_received_cost' => $cost,
        'waste_percent_of_stock_cost' => $cost > 0 ? round($now['value'] / $cost * 100, 1) : null,
        'top_items' => $grouped('fi.name'),
        'by_reason' => $grouped('wr.reason', 'JOIN waste_reasons wr ON wr.id = wl.waste_reason_id'),
        'by_category' => $grouped('fc.name', 'JOIN food_categories fc ON fc.id = fi.category_id'),
        'by_supplier' => $grouped("COALESCE(s.name, 'No supplier')", 'LEFT JOIN suppliers s ON s.id = fi.supplier_id'),
        'supplier_returns' => [
            'returns' => (int) $returns['n'],
            'stock_value' => advisor_tool_money($returns['value']),
            'credit_received' => advisor_tool_money($returns['credit']),
        ],
        'last_12_months' => $months,
    ];
}

// User accounts and what each person has recorded. Emails and passwords are never included.
// The store records no sign-in times, so "last activity" is the latest thing they recorded.
function advisor_tool_users(array $in): array {
    $role = advisor_tool_choice($in, 'role', ['any', 'admin', 'manager', 'staff'], 'any');
    $status = advisor_tool_choice($in, 'status', ['any', 'active', 'inactive'], 'any');
    $where = ['1 = 1'];
    $types = '';
    $params = [];
    if ($role !== 'any') {
        $where[] = 'u.role = ?';
        $types .= 's';
        $params[] = $role;
    }
    if ($status !== 'any') {
        $where[] = 'u.is_active = ' . ($status === 'active' ? 1 : 0);
    }
    if (($q = advisor_tool_text($in, 'query')) !== '') {
        $where[] = 'u.username LIKE ?';
        $types .= 's';
        $params[] = '%' . $q . '%';
    }
    $auto = "w.notes LIKE 'Auto-moved by system%'";
    $rows = db_all(
        "SELECT u.username, u.role, u.is_active, u.created_at,
                (SELECT COUNT(*) FROM waste_logs w WHERE w.user_id = u.id AND NOT ($auto)) AS waste_by_hand,
                (SELECT COUNT(*) FROM waste_logs w WHERE w.user_id = u.id AND $auto) AS waste_auto,
                (SELECT COALESCE(SUM(w.estimated_value), 0) FROM waste_logs w WHERE w.user_id = u.id AND NOT ($auto)) AS waste_value,
                (SELECT COUNT(*) FROM item_returns r WHERE r.user_id = u.id) AS returns_count,
                (SELECT COUNT(*) FROM stock_receipts sr WHERE sr.user_id = u.id) AS receipts,
                (SELECT COUNT(*) FROM supplier_deliveries d WHERE d.created_by = u.id) AS deliveries,
                GREATEST(
                    COALESCE((SELECT MAX(w.logged_at) FROM waste_logs w WHERE w.user_id = u.id AND NOT ($auto)), '1970-01-01'),
                    COALESCE((SELECT MAX(r.created_at) FROM item_returns r WHERE r.user_id = u.id), '1970-01-01'),
                    COALESCE((SELECT MAX(sr.received_at) FROM stock_receipts sr WHERE sr.user_id = u.id), '1970-01-01'),
                    COALESCE((SELECT MAX(e.created_at) FROM supplier_delivery_events e WHERE e.user_id = u.id), '1970-01-01')
                ) AS last_activity
         FROM users u
         WHERE " . implode(' AND ', $where) . "
         ORDER BY u.is_active DESC, FIELD(u.role, 'admin', 'manager', 'staff'), u.username",
        $types,
        $params
    );
    return [
        'note' => 'Roles: staff can only view and print; managers manage stock, waste, suppliers and staff accounts; admins can do everything. Emails and passwords are not available here.',
        'users' => array_map(fn ($r) => [
            'username' => $r['username'],
            'role' => ucfirst($r['role']),
            'status' => $r['is_active'] ? 'Active' : 'Inactive (waiting for approval or deactivated)',
            'joined' => substr($r['created_at'], 0, 10),
            'waste_entries_logged' => (int) $r['waste_by_hand'],
            'waste_value_logged' => advisor_tool_money($r['waste_value']),
            'expired_stock_cleared_automatically_under_their_name' => (int) $r['waste_auto'],
            'supplier_returns_recorded' => (int) $r['returns_count'],
            'stock_receipts_recorded' => (int) $r['receipts'],
            'deliveries_scheduled' => (int) $r['deliveries'],
            'last_activity' => substr($r['last_activity'], 0, 4) !== '1970' ? substr($r['last_activity'], 0, 10) : null,
        ], $rows),
    ];
}

// The Archive page: expired batches, items archived by staff and removed items, with their history.
function advisor_tool_archive(array $in): array {
    $reason = advisor_tool_choice($in, 'reason', ['any', 'expired', 'manual', 'removed'], 'any');
    $where = ['fi.archived_at IS NOT NULL'];
    $types = '';
    $params = [];
    if ($reason !== 'any') {
        $where[] = 'fi.archive_reason = ?';
        $types .= 's';
        $params[] = $reason;
    }
    if (($q = advisor_tool_text($in, 'query')) !== '') {
        $where[] = '(fi.name LIKE ? OR fi.barcode LIKE ? OR fc.name LIKE ?)';
        $types .= 'sss';
        array_push($params, ...array_fill(0, 3, '%' . $q . '%'));
    }
    $fromSql = 'FROM food_items fi
                JOIN food_categories fc ON fc.id = fi.category_id
                LEFT JOIN suppliers s ON s.id = fi.supplier_id
                LEFT JOIN (SELECT food_item_id, COUNT(*) AS entries, SUM(quantity_wasted) AS units, SUM(estimated_value) AS value
                           FROM waste_logs GROUP BY food_item_id) w ON w.food_item_id = fi.id
                WHERE ' . implode(' AND ', $where);
    $totals = db_one(
        "SELECT COUNT(*) AS items, COALESCE(SUM(fi.archive_reason = 'expired'), 0) AS expired,
                COALESCE(SUM(fi.archive_reason = 'manual'), 0) AS manual, COALESCE(SUM(fi.archive_reason = 'removed'), 0) AS removed,
                COALESCE(SUM(w.value), 0) AS waste_value $fromSql",
        $types,
        $params
    );
    $rows = db_all(
        "SELECT fi.name, fi.barcode, fc.name AS category, s.name AS supplier, fi.archive_reason, fi.archived_at, fi.expiration_date,
                fi.quantity, fi.unit, COALESCE(w.units, 0) AS wasted_units, COALESCE(w.value, 0) AS wasted_value
         $fromSql ORDER BY fi.archived_at DESC, fi.name LIMIT ?",
        $types . 'i',
        array_merge($params, [advisor_tool_limit($in)])
    );
    return [
        'note' => 'Archived items are hidden from inventory but keep their history; they can be restored from the Archive page.',
        'totals' => [
            'archived_items' => (int) $totals['items'],
            'expired' => (int) $totals['expired'],
            'archived_by_staff' => (int) $totals['manual'],
            'removed_with_history' => (int) $totals['removed'],
            'lifetime_waste_value' => advisor_tool_money($totals['waste_value']),
        ],
        'items' => array_map(fn ($r) => [
            'name' => $r['name'],
            'barcode' => $r['barcode'],
            'category' => $r['category'],
            'supplier' => $r['supplier'],
            'reason' => archive_reason_label($r['archive_reason'])[0],
            'archived_on' => substr($r['archived_at'], 0, 10),
            'last_expiry' => $r['expiration_date'],
            'units_left' => (int) $r['quantity'],
            'unit' => $r['unit'],
            'lifetime_waste' => ['units' => (int) $r['wasted_units'], 'value' => advisor_tool_money($r['wasted_value'])],
        ], $rows),
    ];
}

// Every category with its stock, archive, templates, suppliers and recent waste, plus the waste reasons.
function advisor_tool_categories(): array {
    $since = date('Y-m-d', strtotime('-89 days'));
    $active = ACTIVE_ITEM_SQL;
    $rows = db_all(
        "SELECT fc.id, fc.name,
                (SELECT COUNT(*) FROM food_items fi WHERE fi.category_id = fc.id AND $active) AS active_items,
                (SELECT COALESCE(SUM(fi.quantity), 0) FROM food_items fi WHERE fi.category_id = fc.id AND $active) AS units,
                (SELECT COALESCE(SUM(fi.quantity * COALESCE(fi.purchase_price, 0)), 0) FROM food_items fi WHERE fi.category_id = fc.id AND $active) AS stock_value,
                (SELECT COUNT(*) FROM food_items fi WHERE fi.category_id = fc.id AND fi.archived_at IS NOT NULL) AS archived,
                (SELECT COUNT(*) FROM products p WHERE p.category_id = fc.id) AS templates,
                (SELECT GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id
                 WHERE sp.category_id = fc.id) AS suppliers,
                (SELECT COALESCE(SUM(wl.quantity_wasted), 0) FROM waste_logs wl JOIN food_items fi ON fi.id = wl.food_item_id
                 WHERE fi.category_id = fc.id AND wl.waste_date >= ?) AS waste_units,
                (SELECT COALESCE(SUM(wl.estimated_value), 0) FROM waste_logs wl JOIN food_items fi ON fi.id = wl.food_item_id
                 WHERE fi.category_id = fc.id AND wl.waste_date >= ?) AS waste_value
         FROM food_categories fc ORDER BY fc.name",
        'ss',
        [$since, $since]
    );
    $reasons = db_all(
        'SELECT wr.reason, wr.description, COUNT(wl.id) AS entries, COALESCE(SUM(wl.estimated_value), 0) AS value
         FROM waste_reasons wr LEFT JOIN waste_logs wl ON wl.waste_reason_id = wr.id AND wl.waste_date >= ?
         GROUP BY wr.id, wr.reason, wr.description ORDER BY value DESC, wr.reason',
        's',
        [$since]
    );
    return [
        'categories' => array_map(fn ($r) => [
            'category' => $r['name'],
            'active_items' => (int) $r['active_items'],
            'units_in_stock' => (int) $r['units'],
            'stock_value' => advisor_tool_money($r['stock_value']),
            'archived_items' => (int) $r['archived'],
            'product_templates' => (int) $r['templates'],
            'suppliers_who_deliver_it' => $r['suppliers'] ? explode(', ', $r['suppliers']) : [],
            'waste_last_90_days' => ['units' => (int) $r['waste_units'], 'value' => advisor_tool_money($r['waste_value'])],
        ], $rows),
        'waste_reasons' => array_map(fn ($r) => [
            'reason' => $r['reason'],
            'meaning' => $r['description'],
            'used_last_90_days' => ['entries' => (int) $r['entries'], 'value' => advisor_tool_money($r['value'])],
        ], $reasons),
    ];
}

// Product templates: what each barcode is, so Quick Scan can add new batches by itself.
function advisor_tool_product_templates(array $in): array {
    $where = '1 = 1';
    $types = '';
    $params = [];
    if (($q = advisor_tool_text($in, 'query')) !== '') {
        $where = '(p.name LIKE ? OR p.barcode LIKE ? OR fc.name LIKE ?)';
        $types = 'sss';
        $params = array_fill(0, 3, '%' . $q . '%');
    }
    $active = ACTIVE_ITEM_SQL;
    $rows = db_all(
        "SELECT p.barcode, p.name, fc.name AS category, p.unit, p.default_price, p.default_shelf_life_days, u.username,
                (SELECT COALESCE(SUM(fi.quantity), 0) FROM food_items fi WHERE fi.barcode = p.barcode AND $active) AS in_stock
         FROM products p
         LEFT JOIN food_categories fc ON fc.id = p.category_id
         LEFT JOIN users u ON u.id = p.created_by_user_id
         WHERE $where ORDER BY p.name LIMIT ?",
        $types . 'i',
        array_merge($params, [advisor_tool_limit($in)])
    );
    return [
        'total' => (int) db_value('SELECT COUNT(*) FROM products'),
        'templates' => array_map(fn ($r) => [
            'barcode' => $r['barcode'],
            'name' => $r['name'],
            'category' => $r['category'],
            'unit' => $r['unit'],
            'default_purchase_price' => $r['default_price'] !== null ? advisor_tool_money($r['default_price']) : null,
            'default_shelf_life_days' => $r['default_shelf_life_days'] !== null ? (int) $r['default_shelf_life_days'] : null,
            'units_in_stock_now' => (int) $r['in_stock'],
            'created_by' => $r['username'],
        ], $rows),
    ];
}
