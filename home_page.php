<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();

$active = ACTIVE_ITEM_SQL;

$inventory = db_one(
    "SELECT COUNT(*) AS items,
            COALESCE(SUM(fi.quantity), 0) AS units,
            COALESCE(SUM(fi.quantity * COALESCE(fi.purchase_price, 0)), 0) AS stock_value,
            SUM(fi.quantity > 0 AND fi.quantity <= ?) AS low_stock,
            SUM(fi.quantity = 0) AS out_of_stock,
            SUM(fi.quantity >= ?) AS overstock
     FROM food_items fi
     WHERE $active",
    'ii',
    [LOW_STOCK_THRESHOLD, OVERSTOCK_THRESHOLD]
);

$waste = db_one(
    "SELECT COALESCE(SUM(CASE WHEN waste_date = CURDATE() THEN quantity_wasted END), 0) AS today_units,
            COALESCE(SUM(quantity_wasted), 0) AS month_units,
            COALESCE(SUM(estimated_value), 0) AS month_value,
            COUNT(*) AS month_entries
     FROM waste_logs
     WHERE waste_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
);

$expiring = db_all(
    "SELECT fi.id, fi.name, fi.quantity, fi.unit, fi.expiration_date, fc.name AS category,
            DATEDIFF(fi.expiration_date, CURDATE()) AS days_left
     FROM food_items fi
     LEFT JOIN food_categories fc ON fc.id = fi.category_id
     WHERE $active AND fi.quantity > 0
       AND fi.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
     ORDER BY fi.expiration_date ASC, fi.name ASC",
    'i',
    [EXPIRING_SOON_DAYS]
);

$lowStock = db_all(
    "SELECT fi.id, fi.name, fi.quantity, fi.unit, s.name AS supplier
     FROM food_items fi
     LEFT JOIN suppliers s ON s.id = fi.supplier_id
     WHERE $active AND fi.quantity <= ?
     ORDER BY fi.quantity ASC, fi.name ASC
     LIMIT 6",
    'i',
    [LOW_STOCK_THRESHOLD]
);

/* ---------- Monthly series (last 6 months, empty months included) ---------- */
//   Cost  = stock received, valued at purchase price
//   Waste = value of everything logged as waste
//   Loss  = waste + supplier returns that were not fully credited back
$sinceSql = "DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)";
$wasteRows = array_column(db_all(
    "SELECT DATE_FORMAT(waste_date, '%Y-%m') AS ym, SUM(quantity_wasted) AS units, SUM(estimated_value) AS value
     FROM waste_logs WHERE waste_date >= $sinceSql GROUP BY ym"
), null, 'ym');
$costRows = array_column(db_all(
    "SELECT DATE_FORMAT(received_at, '%Y-%m') AS ym, SUM(quantity * unit_cost) AS v
     FROM stock_receipts WHERE received_at >= $sinceSql GROUP BY ym"
), 'v', 'ym');
$supplierRows = array_column(db_all(
    "SELECT DATE_FORMAT(return_date, '%Y-%m') AS ym,
            SUM(GREATEST(quantity * unit_value - amount, 0)) AS unrecovered, SUM(amount) AS credits
     FROM item_returns WHERE return_type = 'supplier' AND return_date >= $sinceSql GROUP BY ym"
), null, 'ym');

$months = [];
$monthStart = new DateTimeImmutable('first day of this month');
for ($i = 5; $i >= 0; $i--) {
    $m = $monthStart->modify("-$i months");
    $key = $m->format('Y-m');
    $wasteValue = (float) ($wasteRows[$key]['value'] ?? 0);
    $cost = (float) ($costRows[$key] ?? 0);
    $loss = $wasteValue + (float) ($supplierRows[$key]['unrecovered'] ?? 0);
    $months[] = [
        'label' => $m->format('M'),
        'full' => $m->format('F Y'),
        'units' => (int) ($wasteRows[$key]['units'] ?? 0),
        'cost' => $cost,
        'waste' => $wasteValue,
        'loss' => $loss,
        'recovered' => (float) ($supplierRows[$key]['credits'] ?? 0),
        'waste_rate' => $cost > 0 ? $wasteValue / $cost * 100 : null,
        'loss_rate' => $cost > 0 ? $loss / $cost * 100 : null,
    ];
}
$now = $months[5];
$prev = $months[4];

$unitsMax = max(1, max(array_column($months, 'units')));
$unitsPct = $prev['units'] > 0 ? round(($now['units'] - $prev['units']) / $prev['units'] * 100, 1) : null;

// Rate chart scale: round the highest rate up to a readable step.
$rateMax = max(array_filter(array_merge(array_column($months, 'loss_rate'), [1]), fn ($v) => $v !== null));
$rateStep = $rateMax <= 10 ? 5 : ($rateMax <= 40 ? 10 : ($rateMax <= 100 ? 25 : 50));
$rateTop = max($rateStep, (int) ceil($rateMax / $rateStep) * $rateStep);

function money_change(float $now, float $before, bool $upIsBad = true): string {
    if ($before <= 0) {
        return '<span class="trend flat">No data last month</span>';
    }
    $pct = round(($now - $before) / $before * 100, 1);
    if ($pct == 0) {
        return '<span class="trend flat">Same as last month</span>';
    }
    $tone = !$upIsBad ? 'flat' : ($pct > 0 ? 'up' : 'down');
    return '<span class="trend ' . $tone . '" title="Compared with last month"><i class="fa-solid fa-arrow-' . ($pct > 0 ? 'up' : 'down') . '"></i> ' . abs($pct) . '%</span>';
}

/* ---------- Where this month's stock money went (donut) ---------- */
$otherLoss = max(0, $now['loss'] - $now['waste']);
$moneyTotal = max($now['cost'], $now['loss']);
$moneySlices = [
    ['label' => 'Kept / sellable', 'value' => max(0, $moneyTotal - $now['loss']), 'color' => 'var(--c-kept)', 'display' => peso(max(0, $moneyTotal - $now['loss']), 0)],
    ['label' => 'Wasted', 'value' => $now['waste'], 'color' => 'var(--c-waste)', 'display' => peso($now['waste'], 0)],
    ['label' => 'Unrecovered returns', 'value' => $otherLoss, 'color' => 'var(--c-loss)', 'display' => peso($otherLoss, 0)],
];

/* ---------- Waste by reason this month (donut: top 3 + Other) ---------- */
$reasonRows = db_all(
    "SELECT wr.reason, SUM(wl.quantity_wasted) AS units, SUM(wl.estimated_value) AS value
     FROM waste_logs wl
     JOIN waste_reasons wr ON wr.id = wl.waste_reason_id
     WHERE wl.waste_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
     GROUP BY wr.id, wr.reason
     ORDER BY value DESC"
);
$reasonColors = ['var(--c-r1)', 'var(--c-r2)', 'var(--c-r3)'];
$reasonSlices = [];
foreach (array_slice($reasonRows, 0, 3) as $i => $r) {
    $reasonSlices[] = ['label' => $r['reason'], 'value' => (float) $r['value'], 'color' => $reasonColors[$i], 'display' => peso($r['value'], 0)];
}
$rest = array_slice($reasonRows, 3);
if ($rest) {
    $restValue = array_sum(array_column($rest, 'value'));
    $reasonSlices[] = ['label' => 'Other (' . count($rest) . ')', 'value' => (float) $restValue, 'color' => 'var(--c-other)', 'display' => peso($restValue, 0)];
}

$recentWaste = db_all(
    "SELECT wl.quantity_wasted, wl.waste_date, wl.estimated_value, fi.name AS item, fi.unit, wr.reason, u.username
     FROM waste_logs wl
     JOIN food_items fi ON fi.id = wl.food_item_id
     JOIN waste_reasons wr ON wr.id = wl.waste_reason_id
     JOIN users u ON u.id = wl.user_id
     ORDER BY wl.logged_at DESC, wl.id DESC
     LIMIT 5"
);

$openOrders = db_one(
    "SELECT COUNT(*) AS open_count,
            COALESCE(SUM(status = 'pending'), 0) AS new_count,
            COALESCE(SUM(pickup_date = CURDATE()), 0) AS today_count
     FROM customer_orders WHERE status IN ('pending', 'ready')"
);

$deliveryCounts = delivery_counts();

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

render_shell_start($user, 'Dashboard', 'dashboard');

$actions = can_manage($user)
    ? '<a href="food_items.php?scan=1" class="btn"><i class="fa-solid fa-barcode"></i> Quick Scan</a>'
        . '<a href="log_waste.php" class="btn btn-primary"><i class="fa-solid fa-trash-can"></i> Log Waste</a>'
    : '<a href="food_items.php" class="btn"><i class="fa-solid fa-print"></i> Print labels</a>'
        . '<a href="reports.php" class="btn btn-primary"><i class="fa-solid fa-chart-column"></i> Reports</a>';
page_header('Dashboard', $greeting . ', ' . $user['username'] . ' · ' . date('l, F j, Y'), $actions);
?>

<?php if ($expiring): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span><?= count($expiring) ?> item<?= count($expiring) === 1 ? '' : 's' ?> expire within <?= EXPIRING_SOON_DAYS ?> days. Mark them down or use them before they turn into waste.</span>
        <a href="food_items.php?status=expiring" class="btn btn-sm">Review</a>
    </div>
<?php endif; ?>

<?php if ((int) $openOrders['open_count'] > 0): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-basket-shopping"></i>
        <span>
            <?= (int) $openOrders['open_count'] ?> online pickup order<?= (int) $openOrders['open_count'] === 1 ? '' : 's' ?> open
            (<?= (int) $openOrders['new_count'] ?> new, <?= (int) $openOrders['today_count'] ?> for pickup today).
        </span>
        <a href="orders.php" class="btn btn-sm">View orders</a>
    </div>
<?php endif; ?>

<?php
$deliveryParts = array_filter([
    $deliveryCounts['overdue'] ? $deliveryCounts['overdue'] . ' overdue' : null,
    $deliveryCounts['due_today'] ? $deliveryCounts['due_today'] . ' due today' : null,
    $deliveryCounts['on_the_way'] ? $deliveryCounts['on_the_way'] . ' on the way' : null,
    $deliveryCounts['delivered_recent'] ? $deliveryCounts['delivered_recent'] . ' delivered in the last 24 hours' : null,
]);
?>
<?php if ($deliveryParts): ?>
    <div class="alert <?= $deliveryCounts['overdue'] ? 'alert-error' : 'alert-info' ?>">
        <i class="fa-solid fa-truck-fast"></i>
        <span>Supplier deliveries: <?= e(implode(', ', $deliveryParts)) ?>.</span>
        <a href="deliveries.php" class="btn btn-sm">View deliveries</a>
    </div>
<?php endif; ?>

<div class="stats">
    <a class="stat" href="food_items.php">
        <span class="stat-icon tone-green"><i class="fa-solid fa-boxes-stacked"></i></span>
        <span>
            <div class="stat-label">In inventory</div>
            <div class="stat-value"><?= number_format((int) $inventory['items']) ?></div>
            <div class="stat-sub"><?= number_format((int) $inventory['units']) ?> units · <?= peso($inventory['stock_value'], 0) ?></div>
        </span>
    </a>
    <a class="stat" href="food_items.php?status=expiring">
        <span class="stat-icon tone-amber"><i class="fa-solid fa-hourglass-half"></i></span>
        <span>
            <div class="stat-label">Expiring soon</div>
            <div class="stat-value"><?= count($expiring) ?></div>
            <div class="stat-sub">Within <?= EXPIRING_SOON_DAYS ?> days</div>
        </span>
    </a>
    <a class="stat" href="food_items.php?status=low">
        <span class="stat-icon tone-red"><i class="fa-solid fa-arrow-trend-down"></i></span>
        <span>
            <div class="stat-label">Low / out of stock</div>
            <div class="stat-value"><?= (int) $inventory['low_stock'] + (int) $inventory['out_of_stock'] ?></div>
            <div class="stat-sub"><?= (int) $inventory['out_of_stock'] ?> out · <?= (int) $inventory['overstock'] ?> overstocked</div>
        </span>
    </a>
    <a class="stat" href="waste_history.php?from=<?= date('Y-m-01') ?>">
        <span class="stat-icon tone-purple"><i class="fa-solid fa-peso-sign"></i></span>
        <span>
            <div class="stat-label">Waste this month</div>
            <div class="stat-value"><?= peso($waste['month_value'], 0) ?></div>
            <div class="stat-sub"><?= number_format((int) $waste['month_units']) ?> units · <?= number_format((int) $waste['today_units']) ?> today</div>
        </span>
    </a>
</div>

<div class="card" style="margin-top:18px">
    <div class="card-head">
        <div>
            <div class="card-title"><i class="fa-solid fa-scale-balanced"></i> Cost vs Waste vs Loss · <?= e($now['full']) ?></div>
            <div class="card-sub">Cost = stock received · Waste = value thrown out · Loss = waste + supplier returns not credited back</div>
        </div>
        <a href="reports.php" class="btn btn-sm btn-ghost">Reports <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="card-body">
        <div class="money-grid">
            <section>
                <h3>This month vs last</h3>
                <div class="kpi-list">
                    <div class="kpi-row cost">
                        <div><div class="stat-label">Cost</div><div class="stat-value"><?= peso($now['cost'], 0) ?></div></div>
                        <?= money_change($now['cost'], $prev['cost'], false) ?>
                        <div class="stat-sub">Last month <?= peso($prev['cost'], 0) ?></div>
                    </div>
                    <div class="kpi-row waste">
                        <div><div class="stat-label">Waste</div><div class="stat-value"><?= peso($now['waste'], 0) ?></div></div>
                        <?= money_change($now['waste'], $prev['waste']) ?>
                        <div class="stat-sub"><?= $now['waste_rate'] !== null ? number_format($now['waste_rate'], 1) . '% of cost' : 'No stock received' ?> · last month <?= peso($prev['waste'], 0) ?></div>
                    </div>
                    <div class="kpi-row loss">
                        <div><div class="stat-label">Loss</div><div class="stat-value"><?= peso($now['loss'], 0) ?></div></div>
                        <?= money_change($now['loss'], $prev['loss']) ?>
                        <div class="stat-sub"><?= peso($now['recovered'], 0) ?> credit recovered · last month <?= peso($prev['loss'], 0) ?></div>
                    </div>
                </div>
            </section>

            <section>
                <h3>Where this month's stock money went</h3>
                <?php if ($moneyTotal > 0): ?>
                    <?= donut_chart(
                        $moneySlices,
                        $now['loss_rate'] !== null ? number_format($now['loss_rate'], 1) . '%' : peso($now['loss'], 0),
                        $now['loss_rate'] !== null ? 'of cost lost' : 'lost',
                        'Share of this month\'s stock cost that was kept, wasted, or lost to unrecovered returns'
                    ) ?>
                <?php else: ?>
                    <div class="empty"><i class="fa-solid fa-chart-pie"></i><strong>No stock received this month</strong></div>
                <?php endif; ?>
            </section>

            <section>
                <h3>Waste &amp; loss as % of cost</h3>
                <div class="legend" style="margin-bottom:8px">
                    <span><i style="background:var(--c-waste)"></i>Waste</span>
                    <span><i style="background:var(--c-loss)"></i>Loss</span>
                </div>
                <div class="rate-chart" role="img" aria-label="Waste and loss as a percentage of cost, last 6 months">
                    <?php foreach ([$rateTop, $rateTop / 2] as $g): ?>
                        <div class="gridline" style="bottom: calc((100% - 18px) * <?= round($g / $rateTop, 3) ?>)"><span><?= rtrim(rtrim(number_format($g, 1), '0'), '.') ?>%</span></div>
                    <?php endforeach; ?>
                    <?php foreach ($months as $i => $m):
                        $tip = $m['full'] . "\nCost " . peso($m['cost'], 0) . "\nWaste " . peso($m['waste'], 0)
                            . ($m['waste_rate'] !== null ? ' (' . number_format($m['waste_rate'], 1) . '%)' : '')
                            . "\nLoss " . peso($m['loss'], 0) . ($m['loss_rate'] !== null ? ' (' . number_format($m['loss_rate'], 1) . '%)' : '');
                        ?>
                        <div class="rate-month" data-tip="<?= e($tip) ?>" tabindex="0">
                            <?php foreach (['waste', 'loss'] as $kind): $rate = $m[$kind . '_rate']; ?>
                                <div class="rate-bar <?= $kind ?>" style="height: calc((100% - 18px) * <?= $rate !== null ? round(min($rate, $rateTop) / $rateTop, 3) : 0 ?>)"></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="rate-labels">
                    <?php foreach ($months as $i => $m): ?><span class="<?= $i === 5 ? 'now' : '' ?>"><?= e($m['label']) ?></span><?php endforeach; ?>
                </div>
                <?php if ($now['waste_rate'] !== null): ?>
                    <p class="rate-caption">
                        <?= e($now['label']) ?>: <strong><?= number_format($now['waste_rate'], 1) ?>%</strong> wasted,
                        <strong><?= number_format($now['loss_rate'], 1) ?>%</strong> lost
                        <?php if ($prev['waste_rate'] !== null): ?>· <?= e($prev['label']) ?>: <?= number_format($prev['waste_rate'], 1) ?>% / <?= number_format($prev['loss_rate'], 1) ?>%<?php endif; ?>
                    </p>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<div class="grid-3-2">
    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-title"><i class="fa-solid fa-chart-column"></i> Units wasted per month</div>
                <div class="card-sub">Last 6 months · hover a bar for the peso value</div>
            </div>
            <?php if ($unitsPct === null): ?>
                <span class="trend flat"><?= $now['units'] > 0 ? 'New this month' : 'No change' ?></span>
            <?php elseif ($unitsPct != 0): ?>
                <span class="trend <?= $unitsPct > 0 ? 'up' : 'down' ?>"><i class="fa-solid fa-arrow-<?= $unitsPct > 0 ? 'up' : 'down' ?>"></i> <?= abs($unitsPct) ?>% vs last month</span>
            <?php else: ?>
                <span class="trend flat">Same as last month</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="bar-chart" role="img" aria-label="Units wasted per month">
                <?php foreach ($months as $i => $m): ?>
                    <div class="bar-col <?= $i === 5 ? 'current' : '' ?>" data-tip="<?= e($m['full'] . ': ' . $m['units'] . ' units · ' . peso($m['waste'])) ?>" tabindex="0">
                        <span class="bar-val"><?= $m['units'] ?></span>
                        <div class="bar" style="height: calc((100% - 24px) * <?= round($m['units'] / $unitsMax, 3) ?>)"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="bar-labels">
                <?php foreach ($months as $m): ?><span><?= e($m['label']) ?></span><?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-title"><i class="fa-solid fa-chart-pie"></i> Waste by reason</div>
                <div class="card-sub">This month, by value</div>
            </div>
            <a href="reports.php" class="btn btn-sm btn-ghost">Details <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="card-body">
            <?php if ($reasonSlices): ?>
                <div class="donut-row">
                    <?= donut_chart($reasonSlices, peso($now['waste'], 0), 'wasted', 'This month\'s waste value by reason') ?>
                </div>
            <?php else: ?>
                <div class="empty"><i class="fa-solid fa-leaf"></i><strong>No waste logged this month</strong>Nice work.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid-3-2">
    <div class="table-card">
        <div class="card-head" style="padding-bottom:14px">
            <div>
                <div class="card-title"><i class="fa-solid fa-bell"></i> Expiring within <?= EXPIRING_SOON_DAYS ?> days</div>
                <div class="card-sub">Suggested markdowns to sell before expiry</div>
            </div>
            <a href="food_items.php?status=expiring" class="btn btn-sm btn-ghost">View all <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <?php if ($expiring): ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Item</th><th class="center">Qty</th><th>Expires</th><th>Markdown</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($expiring, 0, 6) as $item):
                        $days = (int) $item['days_left'];
                        [$label, $tone] = freshness_status($days);
                        $discount = discount_percent($days);
                        ?>
                        <tr>
                            <td><div class="cell-title"><?= e($item['name']) ?></div><div class="cell-sub"><?= e($item['category'] ?? 'Uncategorized') ?></div></td>
                            <td class="center qty-big"><?= (int) $item['quantity'] ?></td>
                            <td><span class="chip chip-<?= $tone ?>"><?= e($label) ?></span></td>
                            <td><?= $discount ? '<span class="chip chip-purple">' . $discount . '% off</span>' : '<span class="muted">—</span>' ?></td>
                            <td class="text-right"><?php if (can_manage($user)): ?><a class="btn btn-sm" href="log_waste.php?item=<?= (int) $item['id'] ?>">Log waste</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($expiring) > 6): ?>
                <div class="table-foot"><span><?= count($expiring) - 6 ?> more expiring soon</span><a class="btn btn-sm" href="food_items.php?status=expiring">See all</a></div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty"><i class="fa-solid fa-circle-check"></i><strong>Nothing expiring soon</strong>All stock is fresh for the next <?= EXPIRING_SOON_DAYS ?> days.</div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-title"><i class="fa-solid fa-arrow-trend-down"></i> Needs restock</div>
                <div class="card-sub"><?= LOW_STOCK_THRESHOLD ?> units or fewer</div>
            </div>
            <a href="food_items.php?status=low" class="btn btn-sm btn-ghost">View all <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="card-body">
            <?php if ($lowStock): ?>
                <ul class="list">
                    <?php foreach ($lowStock as $item): [$label, $tone] = stock_status((int) $item['quantity']); ?>
                        <li>
                            <div>
                                <div class="cell-title"><?= e($item['name']) ?></div>
                                <div class="cell-sub"><?= e($item['supplier'] ?? 'No supplier') ?></div>
                            </div>
                            <span class="chip chip-<?= $tone ?>"><?= (int) $item['quantity'] ?> <?= e($item['unit'] ?: 'units') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="empty"><i class="fa-solid fa-circle-check"></i><strong>Stock levels look healthy</strong></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="table-card" style="margin-top:18px">
    <div class="card-head" style="padding-bottom:14px">
        <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent waste activity</div>
        <a href="waste_history.php" class="btn btn-sm btn-ghost">Full history <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <?php if ($recentWaste): ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Item</th><th class="center">Qty</th><th>Reason</th><th>Date</th><th class="text-right">Value</th><th>Logged by</th></tr></thead>
                <tbody>
                <?php foreach ($recentWaste as $w): ?>
                    <tr>
                        <td class="cell-title"><?= e($w['item']) ?></td>
                        <td class="center"><?= (int) $w['quantity_wasted'] ?> <?= e($w['unit']) ?></td>
                        <td><span class="chip chip-gray"><?= e($w['reason']) ?></span></td>
                        <td class="nowrap"><?= date('M j, Y', strtotime($w['waste_date'])) ?></td>
                        <td class="text-right strong num"><?= peso($w['estimated_value']) ?></td>
                        <td><?= e($w['username']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty"><i class="fa-solid fa-leaf"></i><strong>No waste recorded yet</strong></div>
    <?php endif; ?>
</div>

<?php render_shell_end(); ?>
