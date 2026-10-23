<?php
// "Ask the advisor" with a real language model: Claude (Anthropic) answers an admin's or manager's
// question in plain language. It reads the store's records only through the read-only lookups
// ("tools") below, so every figure comes from this database and it cannot change anything.
// Without an API key (ANTHROPIC_API_KEY in config.local.php) the chat uses the built-in answers
// in advisor_chat.php instead.
//
// The API is called over plain HTTPS with curl, so the app still needs no Composer packages.

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/advisor_chat.php';

const ADVISOR_AI_MODEL = 'claude-opus-5-5';
const ADVISOR_AI_MAX_TOOL_ROUNDS = 8;   // lookups per question before it must answer
const ADVISOR_AI_HISTORY_TURNS = 8;     // earlier questions and answers it remembers
const ADVISOR_AI_SESSION_KEY = 'advisor_ai_history';
const ADVISOR_AI_MAX_ROWS = 100;
const ADVISOR_AI_JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

final class AdvisorAiException extends RuntimeException {
    public function __construct(string $message, public readonly string $kind = 'api_error', int $status = 0) {
        parent::__construct($message, $status);
    }
}

function advisor_ai_key(): string {
    return trim(config_value('ANTHROPIC_API_KEY', ''));
}

function advisor_ai_enabled(): bool {
    return advisor_ai_key() !== '' && function_exists('curl_init');
}

/* ---------- Prompt and tools (kept byte-identical between requests so they stay cached) ---------- */

function advisor_ai_system_prompt(): string {
    return 'You are the ZeroWaste AI Advisor for ' . STORE_NAME . ', a neighborhood grocery store in the Philippines. '
        . 'You help its admins and managers understand their inventory, expiry dates, waste, stock received, stock returned '
        . 'to suppliers, suppliers and deliveries, and you give practical advice for reducing food waste.

How to answer:
- Get every fact from the tools. Never guess or invent a number, item, supplier or date. If the tools have no data for something, say so.
- Call as many tools as you need. When lookups don\'t depend on each other, call them together.
- Lead with the direct answer, then the few details that matter. Keep it short: the reply shows in a small chat window.
- Format with short paragraphs, "-" bullet lists or numbered lists, and **bold** for key figures. No headings. Use a table only for a short comparison of 2 to 4 columns.
- Money is Philippine pesos, written like ₱1,250.00. Dates like "Fri, Oct 23".
- Reply in the language the user writes in (English, Filipino or Taglish).
- Each user message starts with today\'s date and time and who is asking.

Store vocabulary:
- Each row on the Food Items page is one batch of a product, with its own quantity and expiration date. When asked how many items there are, give the number of item records in stock and the total units, and mention low or out-of-stock items if relevant.
- Low stock means 1 to ' . LOW_STOCK_THRESHOLD . ' units; overstock means ' . OVERSTOCK_THRESHOLD . ' units or more; "expiring soon" means within ' . EXPIRING_SOON_DAYS . ' days.
- Waste value is quantity × purchase price. Expired stock is logged as "Expired" waste and archived automatically the day after its expiration date.
- Suggested markdowns: 20% off 2 days before expiry, 50% off on the last day.
- Selling price is the item\'s own price, or the purchase price plus a ' . DEFAULT_MARKUP_PERCENT . '% default markup.
- Returns are stock sent back to suppliers; credit is the money the supplier gave back.
- The store records no sales, so you cannot report sales or profit. The "sales pace" from get_advisor_insights is an estimate: units that left stock without being wasted or returned.

You can only read data. To change something, tell the user where to do it: Food Items (add, edit, receive stock, print barcode labels), Expiring Soon, Low Stock, Archive, Log Waste, Returns, Waste History, Reports, Suppliers, Deliveries, and User Accounts.

If a question has nothing to do with this store, its stock, food waste or running a grocery, say in one sentence that you only help with the store, and suggest something you can answer.';
}

function advisor_ai_tools(): array {
    $date = fn (string $what) => ['type' => 'string', 'description' => $what . ' (YYYY-MM-DD).'];
    $limit = ['type' => 'integer', 'description' => 'Most rows to return, 1 to ' . ADVISOR_AI_MAX_ROWS . '. Default 50.'];
    $tools = [
        [
            'name' => 'get_store_overview',
            'description' => 'Store-wide totals right now: item records and units in stock, stock value at cost and at selling price, '
                . 'low, out-of-stock, overstocked and expiring-soon counts, archived items, counts per category, waste today, this month '
                . 'and last month, stock received this month and last month, supplier returns this month, number of suppliers and '
                . 'delivery status counts. Call this first for "how many", "how much" and "how are we doing" questions.',
            'input_schema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        [
            'name' => 'search_items',
            'description' => 'Find inventory items (batches) and their stock, unit, unit cost, selling price, stock value, expiration date, '
                . 'days left, freshness, stock status, suggested markdown, storage location and supplier. Use it for questions about '
                . 'specific products, categories, suppliers or storage places, or for lists such as low stock, out of stock or expiring soon.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Text to look for in the item name, barcode, category, supplier or storage location. Leave out to list everything.'],
                    'category' => ['type' => 'string', 'description' => 'Only items in a category whose name contains this text.'],
                    'status' => ['type' => 'string', 'enum' => ['any', 'in_stock', 'low_stock', 'out_of_stock', 'overstock', 'expiring_soon', 'fresh', 'expired'], 'description' => 'Stock or freshness filter. Default any.'],
                    'include_archived' => ['type' => 'boolean', 'description' => 'Also include archived items (expired, removed or archived by staff). Default false.'],
                    'sort' => ['type' => 'string', 'enum' => ['name', 'quantity_asc', 'quantity_desc', 'expiry', 'value_desc', 'recently_updated'], 'description' => 'Order of the results. Default expiry (soonest first).'],
                    'limit' => $limit,
                ],
            ],
        ],
        [
            'name' => 'get_waste',
            'description' => 'Waste that was logged in a date range: totals, plus either the individual entries or totals grouped by item, '
                . 'reason, category, supplier, day, month or staff member. Use it for questions about what was thrown away, why, '
                . 'how much it cost, and trends.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'from' => $date('First day to include. Default: the first day of this month'),
                    'to' => $date('Last day to include. Default: today'),
                    'group_by' => ['type' => 'string', 'enum' => ['none', 'item', 'reason', 'category', 'supplier', 'day', 'month', 'staff'], 'description' => 'none lists the entries, newest first. Default none.'],
                    'item' => ['type' => 'string', 'description' => 'Only items whose name contains this text.'],
                    'reason' => ['type' => 'string', 'description' => 'Only this waste reason, e.g. Expired, Damaged/Spoiled, Overstock, Spillage.'],
                    'limit' => $limit,
                ],
            ],
        ],
        [
            'name' => 'get_stock_received',
            'description' => 'Stock that came in during a date range (deliveries received, Quick Scan, new items and upward corrections), '
                . 'valued at purchase price: totals, plus the individual receipts or totals grouped by item, supplier, category, month or source. '
                . 'Use it for questions about purchases, spending on stock and how much of something came in.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'from' => $date('First day to include. Default: the first day of this month'),
                    'to' => $date('Last day to include. Default: today'),
                    'group_by' => ['type' => 'string', 'enum' => ['none', 'item', 'supplier', 'category', 'month', 'source'], 'description' => 'none lists the receipts, newest first. Default item.'],
                    'item' => ['type' => 'string', 'description' => 'Only items whose name contains this text.'],
                    'limit' => $limit,
                ],
            ],
        ],
        [
            'name' => 'get_supplier_returns',
            'description' => 'Stock sent back to suppliers in a date range, with its value, the credit received and the reason. '
                . 'Use it for questions about returns, credits and money not recovered from suppliers.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'from' => $date('First day to include. Default: 90 days ago'),
                    'to' => $date('Last day to include. Default: today'),
                    'supplier' => ['type' => 'string', 'description' => 'Only suppliers whose name contains this text.'],
                    'item' => ['type' => 'string', 'description' => 'Only items whose name contains this text.'],
                    'limit' => $limit,
                ],
            ],
        ],
        [
            'name' => 'get_suppliers',
            'description' => 'Supplier records: contact person, phone, email and address, the products they deliver (with categories), '
                . 'how many active items and units come from each, their open deliveries and when they last delivered. '
                . 'Use it for supplier contact details, who supplies what, and supplier lists.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Text to look for in the supplier name, contact person or the products they deliver. Leave out to list all.'],
                ],
            ],
        ],
        [
            'name' => 'get_deliveries',
            'description' => 'Supplier deliveries with their reference (DLV-00012), items, amount, expected date and time, status '
                . '(scheduled, on the way, delivered, cancelled), payment status and notes. Use it for questions about upcoming, '
                . 'overdue or past deliveries and unpaid deliveries.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['any', 'open', 'scheduled', 'on_the_way', 'delivered', 'cancelled', 'unpaid'], 'description' => 'open means scheduled or on the way. Default any.'],
                    'supplier' => ['type' => 'string', 'description' => 'Only suppliers whose name contains this text.'],
                    'from' => $date('Earliest expected date to include'),
                    'to' => $date('Latest expected date to include'),
                    'limit' => $limit,
                ],
            ],
        ],
        [
            'name' => 'get_advisor_insights',
            'description' => 'The built-in waste analysis of the last ' . ADVISOR_WINDOW_DAYS . ' days: the Zero-Waste Score and how it is made, '
                . 'ranked recommendations, the expiry forecast (stock that will expire unsold at its usual sales pace), safe order sizes, '
                . 'and per-product sales pace and waste. Use it for "what should I do", "what will be wasted", "how much should I order", '
                . 'slow movers and advice on reducing waste.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'section' => ['type' => 'string', 'enum' => ['all', 'summary', 'recommendations', 'expiry_forecast', 'order_plan'], 'description' => 'Part of the analysis to return. Default all.'],
                    'item' => ['type' => 'string', 'description' => 'Only products whose name contains this text; also adds their sales pace and waste details.'],
                ],
            ],
        ],
    ];
    usort($tools, fn ($a, $b) => strcmp($a['name'], $b['name']));
    return $tools;
}

/* ---------- The tools: read-only queries on the store's records ---------- */

function advisor_ai_run_tool(string $name, array $in): array {
    return match ($name) {
        'get_store_overview' => advisor_tool_overview(),
        'search_items' => advisor_tool_search_items($in),
        'get_waste' => advisor_tool_waste($in),
        'get_stock_received' => advisor_tool_stock_received($in),
        'get_supplier_returns' => advisor_tool_supplier_returns($in),
        'get_suppliers' => advisor_tool_suppliers($in),
        'get_deliveries' => advisor_tool_deliveries($in),
        'get_advisor_insights' => advisor_tool_insights($in),
        default => throw new InvalidArgumentException('There is no tool called ' . $name . '.'),
    };
}

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
    return max(1, min(ADVISOR_AI_MAX_ROWS, (int) ($in['limit'] ?? 50)));
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
    if (($item = advisor_tool_text($in, 'item')) !== '') {
        $where[] = 'fi.name LIKE ?';
        $types .= 's';
        $params[] = '%' . $item . '%';
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
         LIMIT " . ADVISOR_AI_MAX_ROWS,
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

function advisor_tool_deliveries(array $in): array {
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
    return [
        'counts' => delivery_counts(),
        'deliveries' => array_map(fn ($r) => [
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
        ], $rows),
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

/* ---------- Calling Claude ---------- */

// One Messages API request. Returns [decoded as arrays (for reading), decoded as objects (to send
// back unchanged)]. Retries rate limits, overloads and network errors twice.
function advisor_ai_request(array $body): array {
    static $withFallbacks = true; // turned off for the rest of the request if the account can't use them
    $request = $body;
    $headers = ['content-type: application/json', 'x-api-key: ' . advisor_ai_key(), 'anthropic-version: 2023-06-01'];
    if ($withFallbacks) {
        // If a safety classifier declines a benign question, the API retries it on another model.
        $request['fallbacks'] = 'default';
        $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
    }
    $payload = json_encode($request, ADVISOR_AI_JSON | JSON_THROW_ON_ERROR);
    $url = rtrim(config_value('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/') . '/v1/messages';

    for ($attempt = 0; ; $attempt++) {
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $networkError = $raw === false ? curl_error($ch) : '';
        unset($ch);

        if (($raw === false || in_array($status, [408, 409, 429, 500, 502, 503, 504, 529], true)) && $attempt < 2) {
            $wait = (float) ($responseHeaders['retry-after'] ?? 0) ?: 2 ** $attempt;
            usleep((int) (min($wait, 10) * 1000000));
            continue;
        }
        if ($raw === false) {
            throw new AdvisorAiException('Could not reach the AI service: ' . $networkError, 'network');
        }
        $data = json_decode($raw, true);
        if ($status === 200 && is_array($data)) {
            return [$data, json_decode($raw)];
        }
        $type = (string) ($data['error']['type'] ?? 'api_error');
        $message = (string) ($data['error']['message'] ?? 'HTTP ' . $status);
        if ($withFallbacks && $status === 400 && stripos($message, 'fallback') !== false) {
            $withFallbacks = false;
            return advisor_ai_request($body);
        }
        throw new AdvisorAiException($message, $type, $status);
    }
}

// The response content to send back on the next request. After a fallback, only the text the
// declined model wrote before the last "fallback" marker is kept (the API's echo rule).
function advisor_ai_content_to_echo(array $blocks): array {
    $lastFallback = -1;
    foreach ($blocks as $i => $block) {
        if (($block->type ?? '') === 'fallback') {
            $lastFallback = $i;
        }
    }
    $kept = [];
    foreach ($blocks as $i => $block) {
        $type = $block->type ?? '';
        if ($type === 'fallback' || ($i < $lastFallback && $type !== 'text')) {
            continue;
        }
        $kept[] = $block;
    }
    return $kept;
}

/**
 * Answers one question. $history holds the earlier turns of this chat as
 * ['prompt' => what was sent, 'question' => what the user typed, 'answer' => the reply text].
 * Earlier turns go back as plain text (no tool calls or thinking), so each request's prefix is
 * the previous request's prefix plus the new turn. That keeps the prompt cache warm and never
 * replays a thinking block outside the conversation that produced it.
 * Returns ['prompt' => ..., 'answer' => Markdown text, 'remember' => bool].
 */
function advisor_ai_answer(string $question, array $user, array $history): array {
    $messages = [];
    foreach (array_slice($history, -ADVISOR_AI_HISTORY_TURNS) as $turn) {
        $messages[] = ['role' => 'user', 'content' => $turn['prompt']];
        $messages[] = ['role' => 'assistant', 'content' => $turn['answer']];
    }
    $prompt = 'Today is ' . date('l, F j, Y, g:i A') . ' (Philippine time). Asked by ' . $user['username'] . ' (' . $user['role'] . ").\n\n" . $question;
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $base = [
        'model' => ADVISOR_AI_MODEL,
        'max_tokens' => 16000,
        // A chat answer needs quick lookups more than long deliberation.
        'output_config' => ['effort' => 'low'],
        // The marker on the system prompt caches tools + instructions; the top-level one caches the chat so far.
        'cache_control' => ['type' => 'ephemeral'],
        'system' => [['type' => 'text', 'text' => advisor_ai_system_prompt(), 'cache_control' => ['type' => 'ephemeral']]],
        'tools' => advisor_ai_tools(),
    ];

    for ($round = 0; $round <= ADVISOR_AI_MAX_TOOL_ROUNDS; $round++) {
        [$data, $raw] = advisor_ai_request($base + ['messages' => $messages]);
        $stop = (string) ($data['stop_reason'] ?? '');
        if ($stop === 'refusal') {
            return ['prompt' => $prompt, 'answer' => 'I can\'t help with that request. Ask me about the store\'s stock, waste, suppliers or deliveries.', 'remember' => false];
        }

        $content = advisor_ai_content_to_echo(is_array($raw->content ?? null) ? $raw->content : []);
        $text = trim(implode("\n\n", array_map(fn ($b) => (string) $b->text, array_filter($content, fn ($b) => ($b->type ?? '') === 'text'))));
        $calls = array_values(array_filter($content, fn ($b) => ($b->type ?? '') === 'tool_use'));

        if ($stop !== 'tool_use' || !$calls) {
            if ($stop === 'max_tokens') {
                $text .= "\n\n(The answer was cut short. Ask a narrower question for the rest.)";
            }
            return ['prompt' => $prompt, 'answer' => $text !== '' ? $text : 'I couldn\'t put an answer together. Please try asking another way.', 'remember' => $text !== ''];
        }

        $messages[] = ['role' => 'assistant', 'content' => $content];
        $results = [];
        foreach ($calls as $call) {
            try {
                $input = json_decode(json_encode($call->input ?? new stdClass()), true) ?: [];
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $call->id, 'content' => json_encode(advisor_ai_run_tool((string) $call->name, $input), ADVISOR_AI_JSON)];
            } catch (InvalidArgumentException $e) {
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $call->id, 'is_error' => true, 'content' => $e->getMessage()];
            } catch (Throwable $e) {
                error_log('ZeroWaste AI advisor tool ' . $call->name . ': ' . $e);
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $call->id, 'is_error' => true, 'content' => 'The lookup failed on the server.'];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $results];
    }

    return ['prompt' => $prompt, 'answer' => 'That question needed more lookups than I\'m allowed for one answer. Try asking something more specific.', 'remember' => false];
}

// What to tell staff when the AI could not answer (details go to the error log).
function advisor_ai_problem(AdvisorAiException $e): string {
    return match (true) {
        $e->kind === 'network' => 'The server couldn\'t reach the AI service.',
        $e->getCode() === 401 => 'The AI key in config.local.php was rejected.',
        $e->getCode() === 403 => 'This AI key isn\'t allowed to use the AI model.',
        stripos($e->getMessage(), 'credit balance') !== false => 'The Anthropic account has run out of credit.',
        $e->getCode() === 429 => 'The AI is getting too many questions right now.',
        $e->getCode() === 529 || $e->kind === 'overloaded_error' => 'The AI service is busy right now.',
        default => 'The AI service had a problem.',
    };
}

/* ---------- Showing the reply ---------- */

// Claude's Markdown reply as safe HTML for the chat bubble: paragraphs, bullet and numbered lists,
// **bold**, `code` and simple tables. Everything is escaped first.
function advisor_ai_render(string $markdown): string {
    $inline = function (string $text): string {
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', e($text));
        return preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
    };
    $lines = preg_split('/\R/u', trim($markdown));
    $html = '';
    $paragraph = [];
    $list = null;
    $items = [];
    $flush = function () use (&$html, &$paragraph, &$list, &$items, $inline): void {
        if ($paragraph) {
            $html .= '<p>' . implode('<br>', array_map($inline, $paragraph)) . '</p>';
        }
        if ($list !== null) {
            $html .= "<$list>" . implode('', array_map(fn ($li) => '<li>' . $inline($li) . '</li>', $items)) . "</$list>";
        }
        [$paragraph, $list, $items] = [[], null, []];
    };

    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $line = rtrim($lines[$i]);
        if (trim($line) === '' || preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
            $flush();
        } elseif (preg_match('/^\s*\|.*\|\s*$/', $line) && preg_match('/^\s*\|?\s*:?-{2,}/', $lines[$i + 1] ?? '')) {
            $flush();
            $rows = [];
            for (; $i < $n && preg_match('/^\s*\|.*\|\s*$/', $lines[$i]); $i++) {
                $rows[] = array_map('trim', explode('|', trim(trim($lines[$i]), '|')));
            }
            $i--;
            $cells = fn (array $row, string $tag) => implode('', array_map(fn ($c) => "<$tag>" . $inline($c) . "</$tag>", $row));
            $html .= '<div class="ask-table"><table><thead><tr>' . $cells($rows[0], 'th') . '</tr></thead><tbody>'
                . implode('', array_map(fn ($row) => '<tr>' . $cells($row, 'td') . '</tr>', array_slice($rows, 2)))
                . '</tbody></table></div>';
        } elseif (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) {
            $flush();
            $html .= '<p><strong>' . $inline(str_replace('**', '', $m[1])) . '</strong></p>';
        } elseif (preg_match('/^\s*(?:[-*+•])\s+(.+)$/u', $line, $m) || preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $m)) {
            $type = preg_match('/^\s*\d/', $line) ? 'ol' : 'ul';
            if ($list !== $type) {
                $flush();
                $list = $type;
            }
            $items[] = $m[1];
        } elseif ($list !== null && preg_match('/^\s{2,}\S/', $line)) {
            $items[count($items) - 1] .= ' ' . trim($line); // a list item that wraps onto the next line
        } else {
            if ($list !== null) {
                $flush();
            }
            $paragraph[] = $line;
        }
    }
    $flush();
    return $html;
}
