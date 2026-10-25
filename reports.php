<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();

$today = new DateTimeImmutable('today');
$periods = REPORT_PERIODS;
$period = array_key_exists($_GET['period'] ?? '', $periods) ? $_GET['period'] : 'this_month';
[$start, $end, $prevStart, $prevEnd] = report_period($period, $today);

$range = [$start->format('Y-m-d'), $end->format('Y-m-d')];
$prevRange = [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')];

function period_totals(array $range): array {
    return db_one(
        'SELECT COUNT(*) AS entries, COALESCE(SUM(quantity_wasted), 0) AS units, COALESCE(SUM(estimated_value), 0) AS value
         FROM waste_logs WHERE waste_date BETWEEN ? AND ?',
        'ss',
        $range
    );
}

$current = period_totals($range);
$previous = period_totals($prevRange);

function change_badge(float $now, float $before): string {
    if ($before <= 0) {
        return $now > 0 ? '<span class="trend flat">No prior data</span>' : '<span class="trend flat">—</span>';
    }
    $pct = round((($now - $before) / $before) * 100, 1);
    if ($pct == 0) {
        return '<span class="trend flat">No change</span>';
    }
    // For waste, going up is bad (red) and going down is good (green).
    return '<span class="trend ' . ($pct > 0 ? 'up' : 'down') . '"><i class="fa-solid fa-arrow-' . ($pct > 0 ? 'up' : 'down') . '"></i> ' . abs($pct) . '%</span>';
}

$topItems = db_all(
    'SELECT fi.name, fi.unit, COUNT(*) AS entries, SUM(wl.quantity_wasted) AS units, SUM(wl.estimated_value) AS value
     FROM waste_logs wl
     JOIN food_items fi ON fi.id = wl.food_item_id
     WHERE wl.waste_date BETWEEN ? AND ?
     GROUP BY wl.food_item_id, fi.name, fi.unit
     ORDER BY value DESC, units DESC
     LIMIT 10',
    'ss',
    $range
);

$byReason = db_all(
    'SELECT wr.reason, COUNT(*) AS entries, SUM(wl.quantity_wasted) AS units, SUM(wl.estimated_value) AS value
     FROM waste_logs wl
     JOIN waste_reasons wr ON wr.id = wl.waste_reason_id
     WHERE wl.waste_date BETWEEN ? AND ?
     GROUP BY wr.id, wr.reason
     ORDER BY units DESC',
    'ss',
    $range
);

$byCategory = db_all(
    'SELECT fc.name AS category, SUM(wl.quantity_wasted) AS units, SUM(wl.estimated_value) AS value
     FROM waste_logs wl
     JOIN food_items fi ON fi.id = wl.food_item_id
     JOIN food_categories fc ON fc.id = fi.category_id
     WHERE wl.waste_date BETWEEN ? AND ?
     GROUP BY fc.id, fc.name
     ORDER BY value DESC',
    'ss',
    $range
);

// 12-month value trend
$trendRows = db_all(
    "SELECT DATE_FORMAT(waste_date, '%Y-%m') AS ym, SUM(estimated_value) AS value, SUM(quantity_wasted) AS units
     FROM waste_logs
     WHERE waste_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
     GROUP BY ym"
);
$trendBy = array_column($trendRows, null, 'ym');
$trend = [];
$monthStart = $today->modify('first day of this month');
for ($i = 11; $i >= 0; $i--) {
    $m = $monthStart->modify("-$i months");
    $trend[] = ['label' => $m->format('M'), 'full' => $m->format('F Y'), 'value' => (float) ($trendBy[$m->format('Y-m')]['value'] ?? 0), 'units' => (int) ($trendBy[$m->format('Y-m')]['units'] ?? 0)];
}
$trendMax = max(1, max(array_column($trend, 'value')));

$reasonTotal = max(1, array_sum(array_column($byReason, 'units')));
$categoryMax = max(1, (float) max(array_column($byCategory, 'value') ?: [0]));

$rangeLabel = $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
$prevLabel = $prevStart->format('M j') . ' – ' . $prevEnd->format('M j, Y');

render_shell_start($user, 'Reports', 'reports');
page_header('Reports & Analytics', $rangeLabel . ' · compared with ' . $prevLabel);
?>

<form class="toolbar" method="GET">
    <select class="select" name="period" onchange="this.form.submit()" aria-label="Period">
        <?php foreach ($periods as $key => $label): ?>
            <option value="<?= $key ?>" <?= $period === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <noscript><button class="btn" type="submit">Apply</button></noscript>
    <span class="spacer"></span>
    <a class="btn" href="waste_history.php?<?= e(http_build_query(['from' => $range[0], 'to' => $range[1]])) ?>"><i class="fa-solid fa-list"></i> View entries</a>
    <a class="btn" href="waste_history.php?<?= e(http_build_query(['from' => $range[0], 'to' => $range[1], 'export' => 'csv'])) ?>"><i class="fa-solid fa-download"></i> Export</a>
</form>

<div class="stats">
    <div class="stat">
        <span class="stat-icon tone-red"><i class="fa-solid fa-peso-sign"></i></span>
        <span>
            <div class="stat-label">Value lost</div>
            <div class="stat-value"><?= peso($current['value']) ?></div>
            <div class="stat-sub"><?= change_badge((float) $current['value'], (float) $previous['value']) ?> vs <?= peso($previous['value'], 0) ?></div>
        </span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-amber"><i class="fa-solid fa-cubes"></i></span>
        <span>
            <div class="stat-label">Units wasted</div>
            <div class="stat-value"><?= number_format((int) $current['units']) ?></div>
            <div class="stat-sub"><?= change_badge((float) $current['units'], (float) $previous['units']) ?> vs <?= number_format((int) $previous['units']) ?></div>
        </span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-teal"><i class="fa-solid fa-list"></i></span>
        <span>
            <div class="stat-label">Waste entries</div>
            <div class="stat-value"><?= number_format((int) $current['entries']) ?></div>
            <div class="stat-sub"><?= change_badge((float) $current['entries'], (float) $previous['entries']) ?> vs <?= number_format((int) $previous['entries']) ?></div>
        </span>
    </div>
</div>

<div class="card" style="margin-top:18px">
    <div class="card-head">
        <div>
            <div class="card-title"><i class="fa-solid fa-chart-column"></i> Value lost per month</div>
            <div class="card-sub">Last 12 months · current month highlighted</div>
        </div>
    </div>
    <div class="card-body">
        <div class="bar-chart" role="img" aria-label="Monthly value lost">
            <?php foreach ($trend as $i => $t): ?>
                <div class="bar-col <?= $i === 11 ? 'current' : '' ?>" title="<?= e($t['full']) ?>: <?= peso($t['value']) ?> · <?= $t['units'] ?> units">
                    <span class="bar-val"><?= $t['value'] > 0 ? '₱' . ($t['value'] >= 1000 ? round($t['value'] / 1000, 1) . 'k' : number_format($t['value'], 0)) : '' ?></span>
                    <div class="bar" style="height: calc((100% - 24px) * <?= round($t['value'] / $trendMax, 3) ?>)"></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="bar-labels"><?php foreach ($trend as $t): ?><span><?= e($t['label']) ?></span><?php endforeach; ?></div>
    </div>
</div>

<div class="grid-2" style="margin-top:18px">
    <div class="card">
        <div class="card-head"><div class="card-title"><i class="fa-solid fa-list-ol"></i> Waste by reason</div></div>
        <div class="card-body">
            <?php if ($byReason): ?>
                <ul class="hbar-list">
                    <?php foreach ($byReason as $r): $pct = round(((int) $r['units'] / $reasonTotal) * 100, 1); ?>
                        <li class="hbar-row">
                            <span class="name"><?= e($r['reason']) ?></span>
                            <span class="val"><?= (int) $r['units'] ?> units · <?= $pct ?>% · <?= peso($r['value'], 0) ?></span>
                            <span class="hbar-track"><span class="hbar-fill" style="display:block;width:<?= $pct ?>%"></span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="empty"><i class="fa-solid fa-leaf"></i><strong>No waste in this period</strong></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><div class="card-title"><i class="fa-solid fa-layer-group"></i> Waste by category</div></div>
        <div class="card-body">
            <?php if ($byCategory): ?>
                <ul class="hbar-list">
                    <?php foreach ($byCategory as $c): ?>
                        <li class="hbar-row">
                            <span class="name"><?= e($c['category']) ?></span>
                            <span class="val"><?= peso($c['value']) ?> · <?= (int) $c['units'] ?> units</span>
                            <span class="hbar-track"><span class="hbar-fill" style="display:block;background:var(--purple);width:<?= round(((float) $c['value'] / $categoryMax) * 100, 1) ?>%"></span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="empty"><i class="fa-solid fa-leaf"></i><strong>No waste in this period</strong></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="table-card" style="margin-top:18px">
    <div class="card-head" style="padding-bottom:14px">
        <div>
            <div class="card-title"><i class="fa-solid fa-ranking-star"></i> Top wasted items</div>
            <div class="card-sub"><?= e($periods[$period]) ?>, ranked by value lost</div>
        </div>
    </div>
    <?php if ($topItems): ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th class="center">#</th><th>Item</th><th class="center">Entries</th><th class="center">Units</th><th class="text-right">Value Lost</th><th class="text-right">Share</th></tr></thead>
                <tbody>
                <?php $totalValue = max(0.01, (float) $current['value']); foreach ($topItems as $i => $it): ?>
                    <tr>
                        <td class="center strong"><?= $i + 1 ?></td>
                        <td class="cell-title"><?= e($it['name']) ?></td>
                        <td class="center"><?= (int) $it['entries'] ?></td>
                        <td class="center"><?= (int) $it['units'] ?> <span class="muted"><?= e($it['unit']) ?></span></td>
                        <td class="text-right strong num"><?= peso($it['value']) ?></td>
                        <td class="text-right num"><?= round(((float) $it['value'] / $totalValue) * 100, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty"><i class="fa-solid fa-leaf"></i><strong>No waste in this period</strong></div>
    <?php endif; ?>
</div>

<?php render_shell_end(); ?>
