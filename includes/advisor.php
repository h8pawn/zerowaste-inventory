<?php
// AI Waste Advisor (admins and managers): studies this store's own records and recommends what to do
// next to bring waste down toward zero. It uses no outside service and no outside data: every
// number comes from the database, and storage advice comes from the shelf-life profiles in
// shelf_life.php.
//
// How it reasons:
//   Sales pace  units that left stock without being wasted or sent back
//               (received − wasted − sent back − in stock now) ÷ days since first stocked.
//   Forecast    batches sell in expiry order (first in, first out) at that pace; whatever
//               cannot sell before its best-before date is predicted waste.
//   Order size  what sells within one shelf life (30 days at most), minus the stock that will still sell.
//   Rules       waste reasons, suppliers, storage and trends are checked against fixed
//               thresholds and turned into ranked recommendations.

require_once __DIR__ . '/shelf_life.php';

const ADVISOR_WINDOW_DAYS = 90;    // history the advisor learns from
const ADVISOR_HORIZON_DAYS = 30;   // how far ahead the expiry forecast looks
const ADVISOR_URGENT_DAYS = 7;     // "act this week"
const ADVISOR_TARGET_RATE = 2.0;   // waste as % of stock cost that counts as near zero waste

const ADVISOR_PRIORITIES = [
    'high' => ['High priority', 'red'],
    'medium' => ['Medium priority', 'amber'],
    'low' => ['Low priority', 'gray'],
    'good' => ['Good news', 'green'],
];

// "We had more than we could sell" versus "the stock itself was bad".
const ADVISOR_EXCESS_REASONS = ['Expired', 'Overstock'];
const ADVISOR_QUALITY_REASONS = ['Damaged/Spoiled', 'Quality Control', 'Unsaleable'];

// What to do about each waste reason when it is a large share of all waste.
const ADVISOR_PLAYBOOKS = [
    'Expired' => [
        'title' => 'Most waste is stock that expired before it sold',
        'actions' => [
            'Order smaller amounts more often. The Smart order sizes table shows a safe maximum per item.',
            'Put the batch that expires first at the front of the shelf (first in, first out).',
            'Check Expiring Soon every morning and mark items down as suggested: 20% off 2 days before their date, 50% off on the last day.',
        ],
    ],
    'Damaged/Spoiled' => [
        'title' => 'Spoiled and damaged stock is a big share of waste',
        'actions' => [
            'Check fridge and chiller temperatures every day.',
            'Handle produce gently and don\'t stack heavy items on soft ones.',
            'Inspect deliveries on arrival and send damaged stock back to the supplier for credit instead of wasting it.',
        ],
    ],
    'Overstock' => [
        'title' => 'Over-ordering is a big share of waste',
        'actions' => [
            'Check the Smart order sizes table before every order.',
            'Ask suppliers for smaller packs or split deliveries.',
        ],
    ],
    'Spillage' => [
        'title' => 'Spills and accidents are a big share of waste',
        'actions' => [
            'Review how these items are shelved and carried; use trays or bins for loose and liquid stock.',
            'Show staff the safest way to move and stack them.',
        ],
    ],
    'Quality Control' => [
        'title' => 'Stock failing quality checks is a big share of waste',
        'actions' => [
            'Inspect deliveries before accepting them.',
            'Send failed stock back to the supplier (Returns) to recover credit.',
        ],
    ],
    'Unsaleable' => [
        'title' => 'Unsaleable stock is a big share of waste',
        'actions' => [
            'Check packaging on delivery and refuse damaged cartons.',
            'Return unsaleable stock to the supplier for credit when you can.',
        ],
    ],
    'Recall' => [
        'title' => 'Product recalls are a big share of waste',
        'actions' => [
            'Recalls are outside your control: record the supplier credit for recalled stock under Returns.',
        ],
    ],
    'Other' => [
        'title' => 'A lot of waste is logged as "Other"',
        'actions' => [
            'Choose a specific reason when logging waste, so the advisor can tell what to fix.',
        ],
    ],
];

/* ---------- Formatting ---------- */

function advisor_qty($units, ?string $unit): string {
    return number_format((float) $units, floor((float) $units) == (float) $units ? 0 : 1) . ' ' . ($unit ?: 'units');
}

function advisor_pace(float $perDay, ?string $unit): string {
    if ($perDay <= 0) {
        return 'no sales yet';
    }
    return $perDay >= 1
        ? advisor_qty(round($perDay, 1), $unit) . '/day'
        : advisor_qty(round($perDay * 7, 1), $unit) . '/week';
}

function advisor_day(string $date): string {
    $days = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($date))->format('%r%a');
    return match ($days) {
        0 => 'today',
        1 => 'tomorrow',
        default => date('D, M j', strtotime($date)),
    };
}

// "keeps about 240 days, so the limit is 30 days of sales"
function advisor_keeps(int $shelfDays): string {
    return 'keeps about ' . advisor_plural($shelfDays, 'day')
        . ($shelfDays > ADVISOR_HORIZON_DAYS ? ', so the limit is ' . ADVISOR_HORIZON_DAYS . ' days of sales' : '');
}

function advisor_plural(int $n, string $word): string {
    if ($n !== 1 && preg_match('/[^aeiou]y$/', $word)) {
        return $n . ' ' . substr($word, 0, -1) . 'ies';
    }
    return $n . ' ' . $word . ($n === 1 ? '' : (preg_match('/(ch|sh|s|x)$/', $word) ? 'es' : 's'));
}

// 1 at or below $best, 0 at or beyond $worst, straight line in between.
function advisor_scale(float $value, float $best, float $worst): float {
    return max(0.0, min(1.0, ($worst - $value) / ($worst - $best)));
}

/* ---------- Learning from the records ---------- */

// Every product (batches grouped by item name) with its lifetime flows, last-90-day waste,
// sales pace and a first-in-first-out forecast for the batches on the shelf now.
function advisor_products(string $since): array {
    $rows = db_all(
        "SELECT fi.id, fi.name, fi.unit, fi.quantity, fi.purchase_price,
                fi.expiration_date, DATEDIFF(fi.expiration_date, CURDATE()) AS days_left,
                fi.storage_location, fi.archived_at IS NULL AS active, DATE(fi.added_date) AS added,
                fi.supplier_id, s.name AS supplier, fc.name AS category,
                COALESCE(rc.units, 0) AS received, rc.first_at,
                -- Restocking moves the expiry date but not added_date, so measure from the last delivery.
                DATEDIFF(fi.expiration_date, DATE(COALESCE(rc.last_at, fi.added_date))) AS shelf_days,
                COALESCE(w.units, 0) AS wasted, COALESCE(rt.units, 0) AS sent_back
         FROM food_items fi
         JOIN food_categories fc ON fc.id = fi.category_id
         LEFT JOIN suppliers s ON s.id = fi.supplier_id
         LEFT JOIN (SELECT food_item_id, SUM(quantity) AS units, MIN(received_at) AS first_at, MAX(received_at) AS last_at
                    FROM stock_receipts GROUP BY food_item_id) rc ON rc.food_item_id = fi.id
         LEFT JOIN (SELECT food_item_id, SUM(quantity_wasted) AS units
                    FROM waste_logs GROUP BY food_item_id) w ON w.food_item_id = fi.id
         LEFT JOIN (SELECT food_item_id, SUM(quantity) AS units
                    FROM item_returns GROUP BY food_item_id) rt ON rt.food_item_id = fi.id
         ORDER BY fi.expiration_date, fi.id"
    );

    $recent = array_column(db_all(
        'SELECT food_item_id, SUM(quantity) AS units FROM stock_receipts WHERE received_at >= ? GROUP BY food_item_id',
        's',
        [$since]
    ), 'units', 'food_item_id');

    $products = [];
    $keyOf = [];
    foreach ($rows as $r) {
        $key = normalizeItemName($r['name']);
        $keyOf[(int) $r['id']] = $key;
        $products[$key] ??= [
            'key' => $key, 'name' => $r['name'], 'unit' => $r['unit'], 'category' => $r['category'],
            'supplier' => $r['supplier'], 'storage' => (string) $r['storage_location'], 'active' => false,
            'received' => 0, 'wasted' => 0, 'sent_back' => 0, 'on_hand' => 0, 'first' => null,
            'stock' => 0, 'batches' => [], 'unpriced' => false, 'shelf_samples' => [],
            'recv90' => 0, 'waste90' => 0, 'value90' => 0.0, 'reasons90' => [],
        ];
        $p = &$products[$key];
        if ((int) $r['shelf_days'] >= 1 && (int) $r['shelf_days'] <= 730) {
            $p['shelf_samples'][] = (int) $r['shelf_days'];
        }
        $p['received'] += (int) $r['received'];
        $p['wasted'] += (int) $r['wasted'];
        $p['sent_back'] += (int) $r['sent_back'];
        $p['on_hand'] += (int) $r['quantity'];
        $p['recv90'] += (int) ($recent[$r['id']] ?? 0);
        $first = $r['first_at'] ? substr($r['first_at'], 0, 10) : $r['added'];
        $p['first'] = $p['first'] === null ? $first : min($p['first'], $first);

        if ($r['active']) {
            // Rows come in expiry order, so the last active batch describes the product as stocked today.
            $p['active'] = true;
            $p['name'] = $r['name'];
            $p['unit'] = $r['unit'];
            $p['category'] = $r['category'];
            $p['supplier'] = $r['supplier'];
            $p['storage'] = (string) $r['storage_location'];
            $p['unpriced'] = $p['unpriced'] || $r['purchase_price'] === null;
            if ((int) $r['quantity'] > 0) {
                $p['batches'][] = [
                    'id' => (int) $r['id'],
                    'quantity' => (int) $r['quantity'],
                    'days_left' => (int) $r['days_left'],
                    'expires' => $r['expiration_date'],
                    'price' => (float) ($r['purchase_price'] ?? 0),
                ];
                $p['stock'] += (int) $r['quantity'];
            }
        }
        unset($p);
    }

    $waste = db_all(
        'SELECT wl.food_item_id, wr.reason, SUM(wl.quantity_wasted) AS units, COALESCE(SUM(wl.estimated_value), 0) AS value
         FROM waste_logs wl
         JOIN waste_reasons wr ON wr.id = wl.waste_reason_id
         WHERE wl.waste_date >= ?
         GROUP BY wl.food_item_id, wr.reason',
        's',
        [$since]
    );
    foreach ($waste as $w) {
        $key = $keyOf[(int) $w['food_item_id']] ?? null;
        if ($key === null) {
            continue;
        }
        $products[$key]['waste90'] += (int) $w['units'];
        $products[$key]['value90'] += (float) $w['value'];
        $products[$key]['reasons90'][$w['reason']] = ($products[$key]['reasons90'][$w['reason']] ?? 0) + (int) $w['units'];
    }

    $today = new DateTimeImmutable('today');
    foreach ($products as &$p) {
        $p['used'] = max(0, $p['received'] - $p['wasted'] - $p['sent_back'] - $p['on_hand']);
        $p['days'] = max(1, (int) (new DateTimeImmutable((string) $p['first']))->diff($today)->days);
        $p['pace'] = $p['used'] / $p['days'];
        $p['confidence'] = $p['days'] < 7 || $p['used'] < 3 ? 'low' : ($p['days'] < 30 ? 'medium' : 'high');
        $p['too_new'] = $p['days'] < 7 && $p['used'] === 0;
        arsort($p['reasons90']);
        $p['main_reason'] = array_key_first($p['reasons90']);
        // Share of stock that ended up as waste: last 90 days, or all time when nothing came in lately.
        [$in, $out] = $p['recv90'] > 0 ? [$p['recv90'], $p['waste90']] : [$p['received'], $p['wasted']];
        $p['waste_share'] = $in > 0 ? min(1.0, $out / $in) : null;
        advisor_simulate($p);
    }
    unset($p);

    return $products;
}

// First in, first out: batches sell in expiry order at the product's pace, and whatever is
// still on the shelf at the end of its last day is predicted waste.
function advisor_simulate(array &$p): void {
    $sold = 0.0;
    $p['leftover'] = 0;
    $p['sellable'] = 0;
    foreach ($p['batches'] as &$b) {
        $capacity = $p['pace'] * (max(0, $b['days_left']) + 1);
        $sells = min($b['quantity'], max(0.0, $capacity - $sold));
        $sold += $sells;
        $b['leftover'] = (int) round($b['quantity'] - $sells);
        $p['leftover'] += $b['leftover'];
        $p['sellable'] += $b['quantity'] - $b['leftover'];
    }
    unset($b);
}

// Zero-waste ladder for one batch: sell it, then donate it, then compost it. The bin is last.
function advisor_batch_action(array $b): string {
    $days = $b['days_left'];
    $mostlyLeft = $b['quantity'] > 0 && $b['leftover'] / $b['quantity'] >= 0.5;

    return match (true) {
        $days <= 1 => 'Last selling day' . ($days === 1 ? ' is tomorrow' : '') . ': sell at 50% off, then donate what is left to a community pantry or compost it.',
        $days === 2 => 'Move it to the front of the shelf and mark it 20% off now, then 50% off on its last day.',
        $days <= ADVISOR_URGENT_DAYS => $mostlyLeft
            ? 'Mark it down or bundle it with a fast seller now, and line up a donation for what won\'t sell.'
            : 'Display it up front and bundle it with a fast seller.',
        default => 'Don\'t reorder until it sells down. Display it at eye level.',
    };
}

// Batches forecast to still be on the shelf when they expire within the horizon.
function advisor_forecast(array $products): array {
    $rows = [];
    foreach ($products as $p) {
        foreach ($p['batches'] as $b) {
            if ($b['leftover'] < 1 || $b['days_left'] > ADVISOR_HORIZON_DAYS) {
                continue;
            }
            // A brand-new product hasn't had time to sell; only flag it when its date is close.
            if ($p['too_new'] && $b['days_left'] > EXPIRING_SOON_DAYS) {
                continue;
            }
            $rows[] = [
                'product' => $p['key'], 'name' => $p['name'], 'unit' => $p['unit'], 'id' => $b['id'],
                'quantity' => $b['quantity'], 'days_left' => $b['days_left'],
                'expires' => $b['expires'], 'leftover' => $b['leftover'], 'value' => $b['leftover'] * $b['price'],
                'pace' => $p['pace'], 'confidence' => $p['too_new'] ? 'none' : $p['confidence'],
                'action' => advisor_batch_action($b),
            ];
        }
    }
    usort($rows, fn ($x, $y) => [$x['days_left'], -$x['value']] <=> [$y['days_left'], -$y['value']]);
    return $rows;
}

// The most of each product worth ordering at once: what sells within one shelf life (or 30 days
// for long-life stock), minus the stock already on hand that will still sell.
function advisor_order_plan(array $products): array {
    $plan = [];
    foreach ($products as $p) {
        if (!$p['active'] || $p['pace'] <= 0) {
            continue;
        }
        // How long this store's own batches lasted from delivery to best-before; the estimate otherwise.
        $shelf = $p['shelf_samples']
            ? (int) round(array_sum($p['shelf_samples']) / count($p['shelf_samples']))
            : (int) estimateShelfLifeDays($p['name'], $p['storage'])['days'];
        $cover = $p['sellable'] / $p['pace'];
        $cap = max(0, (int) floor($p['pace'] * min($shelf, ADVISOR_HORIZON_DAYS) - $p['sellable']));
        // [label, chip tone, sort rank]
        $status = match (true) {
            $p['leftover'] > 0 => ['Clear old stock first', 'purple', 1],
            $cap === 0 => ['Don\'t order yet', 'green', 3],
            $cover < 3 => ['Reorder soon', 'amber', 0],
            default => ['Stock OK', 'gray', 2],
        };
        $plan[$p['key']] = [
            'key' => $p['key'], 'name' => $p['name'], 'unit' => $p['unit'], 'pace' => $p['pace'],
            'confidence' => $p['confidence'], 'shelf' => $shelf, 'sellable' => (int) round($p['sellable']),
            'cover' => $cover, 'cap' => $cap, 'waste_share' => $p['waste_share'], 'status' => $status,
        ];
    }
    // Items about to run out first, then stock to clear, then the ones wasting the most.
    uasort($plan, fn ($x, $y) => [$x['status'][2], -($x['waste_share'] ?? 0), $x['cover']]
        <=> [$y['status'][2], -($y['waste_share'] ?? 0), $y['cover']]);
    return $plan;
}

// Waste and stock cost in the window, grouped by category or supplier.
function advisor_breakdown(string $by, string $since): array {
    [$join, $id, $name] = $by === 'supplier'
        ? ['LEFT JOIN suppliers g ON g.id = fi.supplier_id', 'COALESCE(g.id, 0)', "COALESCE(g.name, 'No supplier')"]
        : ['JOIN food_categories g ON g.id = fi.category_id', 'g.id', 'g.name'];
    $quality = "'" . implode("', '", ADVISOR_QUALITY_REASONS) . "'";

    $groups = [];
    $blank = ['units' => 0, 'value' => 0.0, 'quality_units' => 0, 'cost' => 0.0, 'returned' => 0.0, 'credit' => 0.0];
    $waste = db_all(
        "SELECT $id AS gid, $name AS gname, SUM(wl.quantity_wasted) AS units, COALESCE(SUM(wl.estimated_value), 0) AS value,
                SUM(CASE WHEN wr.reason IN ($quality) THEN wl.quantity_wasted ELSE 0 END) AS quality_units
         FROM waste_logs wl
         JOIN waste_reasons wr ON wr.id = wl.waste_reason_id
         JOIN food_items fi ON fi.id = wl.food_item_id
         $join
         WHERE wl.waste_date >= ?
         GROUP BY gid, gname",
        's',
        [$since]
    );
    foreach ($waste as $r) {
        $groups[$r['gid']] = ['id' => (int) $r['gid'], 'name' => $r['gname'], 'units' => (int) $r['units'],
            'value' => (float) $r['value'], 'quality_units' => (int) $r['quality_units']] + $blank;
    }
    $cost = db_all(
        "SELECT $id AS gid, $name AS gname, SUM(sr.quantity * sr.unit_cost) AS cost
         FROM stock_receipts sr
         JOIN food_items fi ON fi.id = sr.food_item_id
         $join
         WHERE sr.received_at >= ?
         GROUP BY gid, gname",
        's',
        [$since]
    );
    foreach ($cost as $r) {
        $groups[$r['gid']] ??= ['id' => (int) $r['gid'], 'name' => $r['gname']] + $blank;
        $groups[$r['gid']]['cost'] = (float) $r['cost'];
    }
    if ($by === 'supplier') {
        $returns = db_all(
            "SELECT $id AS gid, $name AS gname, SUM(r.quantity * r.unit_value) AS returned, SUM(r.amount) AS credit
             FROM item_returns r
             JOIN food_items fi ON fi.id = r.food_item_id
             $join
             WHERE r.return_date >= ?
             GROUP BY gid, gname",
            's',
            [$since]
        );
        foreach ($returns as $r) {
            $groups[$r['gid']] ??= ['id' => (int) $r['gid'], 'name' => $r['gname']] + $blank;
            $groups[$r['gid']]['returned'] = (float) $r['returned'];
            $groups[$r['gid']]['credit'] = (float) $r['credit'];
        }
    }
    foreach ($groups as &$g) {
        $g['rate'] = $g['cost'] > 0 ? $g['value'] / $g['cost'] * 100 : null;
        $g['unrecovered'] = max(0.0, $g['returned'] - $g['credit']);
    }
    unset($g);
    usort($groups, fn ($x, $y) => [$y['value'], $y['unrecovered']] <=> [$x['value'], $x['unrecovered']]);
    return $groups;
}

function advisor_totals(string $since): array {
    $since30 = date('Y-m-d', strtotime('-29 days'));
    $since60 = date('Y-m-d', strtotime('-59 days'));
    $monthStart = date('Y-m-01');
    $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));

    $w = db_one(
        'SELECT COALESCE(SUM(CASE WHEN waste_date >= ? THEN estimated_value END), 0) AS value30,
                COALESCE(SUM(CASE WHEN waste_date >= ? THEN quantity_wasted END), 0) AS units30,
                COALESCE(SUM(CASE WHEN waste_date >= ? AND waste_date < ? THEN estimated_value END), 0) AS value_prev30,
                COALESCE(SUM(CASE WHEN waste_date >= ? THEN estimated_value END), 0) AS value90,
                COALESCE(SUM(CASE WHEN waste_date >= ? THEN quantity_wasted END), 0) AS units90,
                COALESCE(SUM(waste_date >= ?), 0) AS entries90,
                COALESCE(SUM(CASE WHEN waste_date >= ? THEN estimated_value END), 0) AS month,
                COALESCE(SUM(CASE WHEN waste_date >= ? AND waste_date < ? THEN estimated_value END), 0) AS last_month
         FROM waste_logs
         WHERE waste_date >= LEAST(?, ?)',
        'ssssssssssss',
        [$since30, $since30, $since60, $since30, $since, $since, $since, $monthStart, $lastMonthStart, $monthStart, $since, $lastMonthStart]
    );
    $c = db_one(
        'SELECT COALESCE(SUM(CASE WHEN received_at >= ? THEN quantity * unit_cost END), 0) AS cost30,
                COALESCE(SUM(quantity * unit_cost), 0) AS cost90,
                COUNT(*) AS receipts90
         FROM stock_receipts WHERE received_at >= ?',
        'ss',
        [$since30, $since]
    );
    return array_map('floatval', $w + $c);
}

function advisor_reasons(string $since): array {
    return db_all(
        "SELECT wr.reason, COUNT(*) AS entries, SUM(wl.quantity_wasted) AS units, COALESCE(SUM(wl.estimated_value), 0) AS value,
                SUM(CASE WHEN wl.notes LIKE 'Auto-moved by system%' THEN wl.quantity_wasted ELSE 0 END) AS auto_units
         FROM waste_logs wl
         JOIN waste_reasons wr ON wr.id = wl.waste_reason_id
         WHERE wl.waste_date >= ?
         GROUP BY wr.id, wr.reason
         ORDER BY value DESC, units DESC",
        's',
        [$since]
    );
}

// The matching shelf-life profile for an item name: [keyword, [ambient, chilled, frozen days]].
function advisor_profile(string $name): ?array {
    $normalized = normalizeItemName($name);
    foreach (getShelfLifeProfiles() as $keyword => $profile) {
        if (str_contains($normalized, $keyword)) {
            return [$keyword, $profile];
        }
    }
    return null;
}

/* ---------- Analysis ---------- */

function advisor_analyze(): array {
    static $analysis = null;
    if ($analysis !== null) {
        return $analysis;
    }

    $since = date('Y-m-d', strtotime('-' . (ADVISOR_WINDOW_DAYS - 1) . ' days'));
    $products = advisor_products($since);
    $forecast = advisor_forecast($products);

    $stockValue = 0.0;
    foreach ($products as $p) {
        foreach ($p['batches'] as $b) {
            $stockValue += $b['quantity'] * $b['price'];
        }
    }

    $a = [
        'since' => $since,
        'products' => $products,
        'forecast' => $forecast,
        'order_plan' => advisor_order_plan($products),
        'totals' => advisor_totals($since),
        'reasons' => advisor_reasons($since),
        'categories' => advisor_breakdown('category', $since),
        'suppliers' => advisor_breakdown('supplier', $since),
        'stock_value' => $stockValue,
        'at_risk_value' => array_sum(array_column($forecast, 'value')),
        'at_risk_units' => array_sum(array_column($forecast, 'leftover')),
    ];
    $a['score'] = advisor_score($a);
    $a['recommendations'] = advisor_recommendations($a);
    return $analysis = $a;
}

// Zero-Waste Score out of 100, with the parts that make it up.
function advisor_score(array $a): ?array {
    $t = $a['totals'];
    [$waste, $cost, $span] = $t['cost30'] > 0 ? [$t['value30'], $t['cost30'], 30] : [$t['value90'], $t['cost90'], ADVISOR_WINDOW_DAYS];
    if ($cost <= 0 && $waste <= 0 && $a['stock_value'] <= 0) {
        return null;
    }
    $rate = $cost > 0 ? $waste / $cost * 100 : ($waste > 0 ? 100.0 : 0.0);
    $parts = [];
    $parts[] = [
        'label' => 'Waste vs stock cost',
        'points' => 50 * advisor_scale($rate, ADVISOR_TARGET_RATE, 25),
        'max' => 50,
        'detail' => match (true) {
            $cost > 0 => number_format($rate, 1) . '% of stock cost wasted in the last ' . $span . ' days (target ' . ADVISOR_TARGET_RATE . '% or less)',
            $waste > 0 => 'Waste logged but no stock received in the last ' . $span . ' days',
            default => 'Nothing received or wasted in the last ' . $span . ' days',
        },
    ];

    $risk = $a['stock_value'] > 0 ? $a['at_risk_value'] / $a['stock_value'] * 100 : 0;
    $parts[] = [
        'label' => 'Stock at risk',
        'points' => 25 * advisor_scale($risk, 0, 20),
        'max' => 25,
        'detail' => number_format($risk, 1) . '% of stock value is forecast to expire unsold in the next ' . ADVISOR_HORIZON_DAYS . ' days',
    ];

    if ($t['value_prev30'] > 0) {
        $change = ($t['value30'] - $t['value_prev30']) / $t['value_prev30'] * 100;
        $parts[] = [
            'label' => 'Trend',
            'points' => 15 * advisor_scale($change, -10, 50),
            'max' => 15,
            'detail' => 'Waste ' . ($change <= 0 ? 'down ' : 'up ') . number_format(abs($change), 0) . '% on the previous 30 days',
        ];
    } else {
        $parts[] = [
            'label' => 'Trend',
            'points' => $t['value30'] > 0 ? 7.5 : 15,
            'max' => 15,
            'detail' => $t['value30'] > 0 ? 'No waste in the previous 30 days to compare with' : 'No waste logged in the last 60 days',
        ];
    }

    $expired = array_values(array_filter($a['reasons'], fn ($r) => $r['reason'] === 'Expired'))[0] ?? null;
    $expiredUnits = (int) ($expired['units'] ?? 0);
    $autoUnits = (int) ($expired['auto_units'] ?? 0);
    $parts[] = [
        'label' => 'Caught before expiry',
        'points' => 10 * ($expiredUnits > 0 ? 1 - $autoUnits / $expiredUnits : 1),
        'max' => 10,
        'detail' => $expiredUnits > 0
            ? number_format($autoUnits) . ' of ' . number_format($expiredUnits) . ' expired units were only cleared by the system after their date passed'
            : 'No expired stock in the last ' . ADVISOR_WINDOW_DAYS . ' days',
    ];

    $score = (int) round(array_sum(array_column($parts, 'points')));
    $grade = match (true) {
        $score >= 85 => ['A', 'Near zero waste', 'green'],
        $score >= 70 => ['B', 'Good', 'teal'],
        $score >= 50 => ['C', 'Needs work', 'amber'],
        default => ['D', 'High waste', 'red'],
    };
    return ['score' => $score, 'grade' => $grade, 'rate' => $rate, 'rate_span' => $span, 'parts' => $parts];
}

/* ---------- Recommendations ---------- */

function advisor_recommendations(array $a): array {
    $recs = [];
    $add = function (array $rec) use (&$recs): void {
        $recs[] = $rec + ['impact' => null, 'impact_label' => '', 'link' => null];
    };
    $t = $a['totals'];
    $products = $a['products'];
    $plan = $a['order_plan'];

    // 1. Stock that won't sell before it expires this week.
    $urgent = array_values(array_filter($a['forecast'], fn ($r) => $r['days_left'] <= ADVISOR_URGENT_DAYS));
    if ($urgent) {
        $actions = [];
        foreach (array_slice($urgent, 0, 5) as $r) {
            $actions[] = '**' . $r['name'] . '**: about ' . advisor_qty($r['leftover'], $r['unit']) . ' of ' . $r['quantity']
                . ' won\'t sell by ' . advisor_day($r['expires']) . '. ' . $r['action'];
        }
        if (count($urgent) > 5) {
            $actions[] = (count($urgent) - 5) . ' more in the expiry forecast below.';
        }
        $value = array_sum(array_column($urgent, 'value'));
        $add([
            'type' => 'urgent',
            'priority' => min(array_column($urgent, 'days_left')) <= 2 ? 'high' : 'medium',
            'icon' => 'fa-hourglass-half',
            'title' => 'Sell or donate ' . advisor_plural(count($urgent), 'item') . ' before ' . (count($urgent) === 1 ? 'it expires' : 'they expire'),
            'why' => 'At the current sales pace, ' . number_format(array_sum(array_column($urgent, 'leftover'))) . ' units worth '
                . peso($value, 0) . ' will still be on the shelf when they expire in the next ' . ADVISOR_URGENT_DAYS . ' days.',
            'actions' => $actions,
            'impact' => $value,
            'impact_label' => 'at risk this week',
            'link' => ['Expiring Soon', 'food_items.php?status=expiring'],
        ]);
    }

    // 2. Slow movers that will expire with stock left later this month.
    $slow = array_values(array_filter($a['forecast'], fn ($r) => $r['days_left'] > ADVISOR_URGENT_DAYS));
    if ($slow) {
        usort($slow, fn ($x, $y) => $y['value'] <=> $x['value']);
        $actions = [];
        foreach (array_slice($slow, 0, 5) as $r) {
            $actions[] = '**' . $r['name'] . '**: sells ' . advisor_pace($r['pace'], $r['unit']) . ', ' . $r['quantity']
                . ' in stock until ' . advisor_day($r['expires']) . ', so about ' . advisor_qty($r['leftover'], $r['unit']) . ' would be left. Hold the next order.';
        }
        $actions[] = 'Display them at eye level and bundle them with fast sellers so they sell down.';
        $value = array_sum(array_column($slow, 'value'));
        $add([
            'type' => 'slow',
            'priority' => 'medium',
            'icon' => 'fa-pause',
            'title' => 'Hold orders for ' . advisor_plural(count($slow), 'slow-moving item'),
            'why' => 'These won\'t sell out before they expire within ' . ADVISOR_HORIZON_DAYS . ' days: about '
                . number_format(array_sum(array_column($slow, 'leftover'))) . ' units (' . peso($value, 0) . ') would be left over.',
            'actions' => $actions,
            'impact' => $value,
            'impact_label' => 'at risk in ' . ADVISOR_HORIZON_DAYS . ' days',
            'link' => ['Food Items', 'food_items.php'],
        ]);
    }

    // 3. Products that are regularly ordered in larger amounts than they sell.
    $overOrdered = array_filter($products, function ($p) {
        $excess = array_sum(array_intersect_key($p['reasons90'], array_flip(ADVISOR_EXCESS_REASONS)));
        return $p['waste90'] >= 2 && $p['waste_share'] !== null && $p['waste_share'] >= 0.15 && $excess * 2 >= $p['waste90'];
    });
    usort($overOrdered, fn ($x, $y) => $y['value90'] <=> $x['value90']);
    foreach (array_slice($overOrdered, 0, 3) as $p) {
        $share = $p['waste_share'] * 100;
        $stocked = $p['recv90'] > 0 ? $p['recv90'] : $p['received'];
        $actions = ['Cut each order by about ' . (int) min(70, max(10, round($share / 5) * 5)) . '%.'];
        if (isset($plan[$p['key']])) {
            $o = $plan[$p['key']];
            $actions[] = 'Order at most ' . advisor_qty($o['cap'], $p['unit']) . ' at a time: it sells about '
                . advisor_pace($p['pace'], $p['unit']) . ' and ' . advisor_keeps($o['shelf']) . '.';
            if ($o['shelf'] <= 14) {
                $actions[] = 'Order smaller amounts more often instead of one large delivery.';
            }
        }
        $add([
            'type' => 'order',
            'priority' => $share >= 30 ? 'high' : 'medium',
            'icon' => 'fa-cart-arrow-down',
            'title' => 'Order less ' . $p['name'],
            'why' => number_format($p['waste90']) . ' of ' . advisor_qty($stocked, $p['unit']) . ' stocked (' . number_format($share, 0)
                . '%) were thrown away in the last ' . ADVISOR_WINDOW_DAYS . ' days, mostly as "' . $p['main_reason'] . '". That cost ' . peso($p['value90'], 0) . '.',
            'actions' => $actions,
            'impact' => $p['value90'],
            'impact_label' => 'lost in ' . ADVISOR_WINDOW_DAYS . ' days',
            'link' => ['Open item', 'food_items.php?q=' . urlencode($p['name'])],
        ]);
    }

    // 4. Root causes: the waste reasons behind most of the waste.
    $reasonTotal = array_sum(array_map(fn ($r) => (float) $r['value'], $a['reasons']));
    $unitTotal = array_sum(array_map(fn ($r) => (int) $r['units'], $a['reasons']));
    $shown = 0;
    foreach ($a['reasons'] as $r) {
        $share = $reasonTotal > 0 ? (float) $r['value'] / $reasonTotal * 100 : ($unitTotal > 0 ? (int) $r['units'] / $unitTotal * 100 : 0);
        if ($share < 20 || $shown >= 2) {
            continue;
        }
        $shown++;
        $playbook = ADVISOR_PLAYBOOKS[$r['reason']] ?? ['title' => '"' . $r['reason'] . '" is a big share of waste', 'actions' => ['Review these entries in Waste History and look for a pattern.']];
        $top = array_filter($products, fn ($p) => isset($p['reasons90'][$r['reason']]));
        uasort($top, fn ($x, $y) => $y['reasons90'][$r['reason']] <=> $x['reasons90'][$r['reason']]);
        $names = array_map(fn ($p) => $p['name'], array_slice($top, 0, 3));
        $actions = $playbook['actions'];
        if ($r['reason'] === 'Expired' && (int) $r['auto_units'] > 0) {
            $actions[] = number_format((int) $r['auto_units']) . ' of these units were only cleared by the system after their date passed, so nobody acted on them in time.';
        }
        $add([
            'type' => 'reason',
            'priority' => $share >= 40 ? 'high' : 'medium',
            'icon' => 'fa-magnifying-glass-chart',
            'title' => $playbook['title'],
            'why' => '"' . $r['reason'] . '": ' . number_format((int) $r['units']) . ' units, ' . peso($r['value'], 0) . ' (' . number_format($share, 0)
                . '% of all waste in the last ' . ADVISOR_WINDOW_DAYS . ' days).' . ($names ? ' Most affected: ' . implode(', ', $names) . '.' : ''),
            'actions' => $actions,
            'impact' => (float) $r['value'],
            'impact_label' => 'lost in ' . ADVISOR_WINDOW_DAYS . ' days',
            'link' => ['Waste History', 'waste_history.php?from=' . $a['since']],
        ]);
    }

    // 5. Suppliers whose stock is wasted far more than average, or isn't credited when returned.
    $supplierRecs = [];
    foreach ($a['suppliers'] as $s) {
        if ($s['id'] === 0) {
            continue;
        }
        $highRate = $s['rate'] !== null && $s['rate'] >= 15 && $s['value'] >= 0.1 * $t['value90'];
        $quality = $s['quality_units'] >= max(3, 0.3 * $s['units']);
        if (!$highRate && !$quality && $s['unrecovered'] < 1) {
            continue;
        }
        $why = [];
        $actions = [];
        if ($s['value'] > 0) {
            $why[] = peso($s['value'], 0) . ' of their stock was wasted in the last ' . ADVISOR_WINDOW_DAYS . ' days'
                . ($s['rate'] !== null ? ' (' . number_format($s['rate'], 0) . '% of the ' . peso($s['cost'], 0) . ' received)' : '') . '.';
        }
        if ($quality) {
            $why[] = number_format($s['quality_units']) . ' units arrived damaged, spoiled or failed quality checks.';
            $actions[] = 'Inspect their deliveries on arrival and send damaged stock back for credit instead of wasting it.';
        }
        if ($highRate) {
            $actions[] = 'Ask for smaller pack sizes, more frequent deliveries or stock with longer best-before dates.';
        }
        if ($s['unrecovered'] >= 1) {
            $why[] = peso($s['unrecovered'], 0) . ' of stock you sent back was not credited.';
            $actions[] = 'Ask them for the missing credit, and record it on the Returns page.';
        }
        $supplierRecs[] = [
            'type' => 'supplier',
            'priority' => $highRate || $quality ? 'medium' : 'low',
            'icon' => 'fa-truck-field',
            'title' => 'Talk to ' . $s['name'],
            'why' => implode(' ', $why),
            'actions' => $actions,
            'impact' => $s['value'] + $s['unrecovered'],
            'impact_label' => 'lost in ' . ADVISOR_WINDOW_DAYS . ' days',
            'link' => ['Suppliers', 'suppliers.php'],
        ];
    }
    usort($supplierRecs, fn ($x, $y) => $y['impact'] <=> $x['impact']);
    foreach (array_slice($supplierRecs, 0, 2) as $rec) {
        $add($rec);
    }

    // 6. Items kept at room temperature that the shelf-life profiles say last much longer chilled.
    $storage = [];
    foreach ($products as $p) {
        $spoiled = ($p['reasons90']['Damaged/Spoiled'] ?? 0) + ($p['reasons90']['Expired'] ?? 0);
        if (!$p['active'] || $spoiled === 0 || detectStorageType($p['storage']) !== 'ambient') {
            continue;
        }
        $profile = advisor_profile($p['name']);
        if ($profile === null) {
            continue;
        }
        [$keyword, $days] = $profile;
        if ($days['chilled'] < max($days['ambient'] + 3, $days['ambient'] * 1.5)) {
            continue;
        }
        $storage[] = [
            'type' => 'storage',
            'product' => $p['key'],
            'priority' => 'medium',
            'icon' => 'fa-temperature-low',
            'title' => 'Keep ' . $p['name'] . ' chilled',
            'why' => number_format($spoiled) . ' ' . ($p['unit'] ?: 'units') . ' expired or spoiled in the last ' . ADVISOR_WINDOW_DAYS . ' days. It is stored '
                . ($p['storage'] !== '' ? 'at "' . $p['storage'] . '"' : 'with no storage location set') . ', where ' . $keyword . ' keeps about '
                . advisor_plural($days['ambient'], 'day') . '. Chilled, it keeps about ' . advisor_plural($days['chilled'], 'day') . '.',
            'actions' => [
                'Move it to the chiller or fridge.',
                'Update its storage location in Food Items so expiry suggestions use the longer shelf life.',
            ],
            'impact' => $p['value90'],
            'impact_label' => 'lost in ' . ADVISOR_WINDOW_DAYS . ' days',
            'link' => ['Open item', 'food_items.php?q=' . urlencode($p['name'])],
        ];
    }
    usort($storage, fn ($x, $y) => $y['impact'] <=> $x['impact']);
    foreach (array_slice($storage, 0, 3) as $rec) {
        $add($rec);
    }

    // 7. Several batches on the shelf: the forecast assumes the oldest sells first.
    $fifo = [];
    foreach ($products as $p) {
        $b = $p['batches'];
        if (count($b) < 2) {
            continue;
        }
        [$old, $new] = [$b[0], $b[count($b) - 1]];
        if ($old['days_left'] <= ADVISOR_URGENT_DAYS && $old['expires'] !== $new['expires']) {
            $fifo[] = '**' . $p['name'] . '**: ' . $old['quantity'] . ' expire ' . advisor_day($old['expires']) . ', '
                . $new['quantity'] . ' expire ' . advisor_day($new['expires']) . '. Put the ' . advisor_day($old['expires']) . ' batch in front.';
        }
    }
    if ($fifo) {
        $add([
            'type' => 'fifo',
            'priority' => 'low',
            'icon' => 'fa-layer-group',
            'title' => 'Sell older batches first',
            'why' => advisor_plural(count($fifo), 'item') . ' have more than one batch on the shelf. If customers pick the newer batch, the older one expires.',
            'actions' => array_slice($fifo, 0, 5),
            'link' => ['Food Items', 'food_items.php?sort=expiration_date&dir=asc'],
        ]);
    }

    // 8. Direction of travel.
    if ($t['value_prev30'] > 0) {
        $change = ($t['value30'] - $t['value_prev30']) / $t['value_prev30'] * 100;
        if ($change >= 20) {
            $add([
                'type' => 'trend',
                'priority' => 'medium',
                'icon' => 'fa-arrow-trend-up',
                'title' => 'Waste is up ' . number_format($change, 0) . '% on the previous 30 days',
                'why' => peso($t['value30'], 0) . ' was wasted in the last 30 days, against ' . peso($t['value_prev30'], 0) . ' in the 30 days before.',
                'actions' => [
                    'Start with the high-priority recommendations on this page.',
                    'Check Reports for which items, reasons or categories grew.',
                ],
                'impact' => $t['value30'] - $t['value_prev30'],
                'impact_label' => 'more than before',
                'link' => ['Reports', 'reports.php?period=30d'],
            ]);
        } elseif ($change <= -20) {
            $add([
                'type' => 'trend',
                'priority' => 'good',
                'icon' => 'fa-arrow-trend-down',
                'title' => 'Waste is down ' . number_format(abs($change), 0) . '%. Keep it up',
                'why' => peso($t['value30'], 0) . ' was wasted in the last 30 days, against ' . peso($t['value_prev30'], 0) . ' in the 30 days before.',
                'actions' => ['Keep doing what changed. The recommendations above show what is left to fix.'],
                'link' => ['Reports', 'reports.php?period=30d'],
            ]);
        }
    } elseif ($t['value30'] <= 0 && $t['cost30'] > 0) {
        $add([
            'type' => 'trend',
            'priority' => 'good',
            'icon' => 'fa-leaf',
            'title' => 'No waste logged in the last 30 days',
            'why' => 'Stock worth ' . peso($t['cost30'], 0) . ' came in and none of it was thrown away. That is zero waste.',
            'actions' => ['Keep logging waste the day it happens so this stays accurate.'],
        ]);
    }

    // 9. Gaps in the data that limit what the advisor can see.
    $active = array_filter($products, fn ($p) => $p['active']);
    $gaps = [];
    $unpriced = count(array_filter($active, fn ($p) => $p['unpriced']));
    $noStorage = count(array_filter($active, fn ($p) => $p['storage'] === ''));
    $noSupplier = count(array_filter($active, fn ($p) => $p['supplier'] === null));
    if ($unpriced) {
        $gaps[] = 'Add a purchase price to ' . advisor_plural($unpriced, 'item') . ', so their waste isn\'t counted as ₱0.';
    }
    if ($noStorage) {
        $gaps[] = 'Set a storage location for ' . advisor_plural($noStorage, 'item') . ', so storage problems can be spotted.';
    }
    if ($noSupplier) {
        $gaps[] = 'Link a supplier to ' . advisor_plural($noSupplier, 'item') . ', so supplier problems can be spotted.';
    }
    foreach ($a['reasons'] as $r) {
        $share = $unitTotal > 0 ? (int) $r['units'] / $unitTotal * 100 : 0;
        if ($r['reason'] === 'Other' && $share >= 10 && $share < 20) {
            $gaps[] = number_format($share, 0) . '% of wasted units are logged as "Other". Choose a specific reason when logging waste.';
        }
    }
    if ($gaps) {
        $add([
            'type' => 'data',
            'priority' => 'low',
            'icon' => 'fa-database',
            'title' => 'Give the advisor better data',
            'why' => 'Recommendations are only as good as the records behind them.',
            'actions' => $gaps,
            'link' => ['Food Items', 'food_items.php'],
        ]);
    }

    $order = array_flip(array_keys(ADVISOR_PRIORITIES));
    usort($recs, fn ($x, $y) => [$order[$x['priority']], -($x['impact'] ?? 0)] <=> [$order[$y['priority']], -($y['impact'] ?? 0)]);
    return $recs;
}
