<?php
// Page chrome shared by every signed-in page: <head>, top bar, sidebar, flashes.

require_once __DIR__ . '/deliveries.php';

const APP_NAME = 'ZeroWaste';
const APP_TAGLINE = 'Lisa Grocery · Ops';

// Archived items (expired batches, discontinued or removed items) are hidden from
// inventory lists and counts but keep their waste, return and cost history.
const ACTIVE_ITEM_SQL = "fi.archived_at IS NULL";

// Adds the file's modification time so browsers fetch the new version after every change.
function asset(string $path): string {
    $file = __DIR__ . '/../' . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function render_head(string $title, string $bodyClass = ''): void {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> — <?= APP_NAME ?></title>
    <script>
        try {
            var savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || savedTheme === 'light') document.documentElement.setAttribute('data-theme', savedTheme);
        } catch (e) {}
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('style.css') ?>">
</head>
<body class="<?= e($bodyClass) ?>">
    <?php
}

function nav_counts(array $user): array {
    $counts = db_one(
        "SELECT
            SUM(" . ACTIVE_ITEM_SQL . ") AS items,
            SUM(fi.quantity > 0 AND fi.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL " . EXPIRING_SOON_DAYS . " DAY)) AS expiring,
            SUM(fi.quantity <= " . LOW_STOCK_THRESHOLD . " AND " . ACTIVE_ITEM_SQL . ") AS low_stock
         FROM food_items fi"
    ) ?? [];

    $counts['archived'] = (int) db_value('SELECT COUNT(*) FROM food_items WHERE archived_at IS NOT NULL');
    $counts['orders_open'] = (int) db_value("SELECT COUNT(*) FROM customer_orders WHERE status IN ('pending', 'ready')");
    $counts['returns_month'] = (int) db_value("SELECT COUNT(*) FROM item_returns WHERE return_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    $counts['waste_month'] = (int) db_value("SELECT COUNT(*) FROM waste_logs WHERE waste_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    $counts['suppliers'] = (int) db_value('SELECT COUNT(*) FROM suppliers');
    $d = delivery_counts();
    $counts['deliveries'] = ($d['overdue'] ?? 0) + ($d['due_today'] ?? 0) + ($d['on_the_way'] ?? 0);
    $counts['pending_users'] = can_manage($user) ? (int) db_value('SELECT COUNT(*) FROM users WHERE is_active = 0') : 0;

    return array_map('intval', $counts);
}

function nav_link(string $href, string $icon, string $label, bool $active, ?int $badge = null, string $badgeTone = 'green'): string {
    $html = '<a href="' . e($href) . '" class="nav-link' . ($active ? ' active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>';
    $html .= '<i class="fa-solid ' . e($icon) . '"></i><span>' . e($label) . '</span>';
    if ($badge !== null && $badge > 0) {
        $html .= '<span class="nav-badge tone-' . e($badgeTone) . '">' . ($badge > 999 ? '999+' : $badge) . '</span>';
    }
    return $html . '</a>';
}

/**
 * Opens the app shell. $active is one of: dashboard, items, expiring, lowstock, archive,
 * log_waste, returns, history, reports, orders, suppliers, deliveries, advisor, admin, store_settings.
 */
function render_shell_start(array $user, string $title, string $active): void {
    render_head($title, 'app-body');
    $c = nav_counts($user);
    $notifications = delivery_notifications();
    $initial = strtoupper(mb_substr($user['username'], 0, 1));
    ?>
    <header class="topbar">
        <button class="icon-btn sidebar-toggle" type="button" data-sidebar-toggle aria-label="Open menu">
            <i class="fa-solid fa-bars"></i>
        </button>
        <a class="brand" href="home_page.php">
            <span class="brand-logo">ZW</span>
            <span class="brand-text">
                <strong><?= strtoupper(APP_NAME) ?></strong>
                <small><?= e(strtoupper(APP_TAGLINE)) ?></small>
            </span>
        </a>
        <span class="sync-status" title="Page loaded at <?= date('g:i A') ?>">Synced <?= date('g:i A') ?></span>

        <form class="top-search" action="food_items.php" method="GET" role="search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" name="q" placeholder="Search item, barcode, supplier…" value="<?= e($active === 'items' ? ($_GET['q'] ?? '') : '') ?>" aria-label="Search inventory">
        </form>

        <div class="top-actions">
            <div class="notif-wrap">
                <button class="pill-btn notif-btn" type="button" data-notif-toggle aria-label="Delivery notifications" aria-expanded="false" aria-controls="notifPanel" title="Delivery notifications">
                    <i class="fa-solid fa-bell"></i><span class="notif-count" data-notif-count hidden>0</span>
                </button>
                <div class="notif-panel" id="notifPanel" data-notif-panel hidden>
                    <div class="notif-head">
                        <strong><i class="fa-solid fa-truck-field"></i> Deliveries</strong>
                        <a href="deliveries.php">View all</a>
                    </div>
                    <?php if ($notifications): ?>
                        <ul class="notif-list">
                            <?php foreach ($notifications as $n): ?>
                                <li data-notif-key="<?= e($n['key']) ?>">
                                    <a href="<?= e($n['href']) ?>">
                                        <span class="notif-icon tone-<?= e($n['tone']) ?>"><i class="fa-solid <?= e($n['icon']) ?>"></i></span>
                                        <span class="notif-text"><strong><?= e($n['title']) ?></strong><small><?= e($n['text']) ?></small></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="notif-empty"><i class="fa-regular fa-bell-slash"></i> No delivery updates right now.</p>
                    <?php endif; ?>
                </div>
            </div>
            <button class="pill-btn" type="button" data-theme-toggle title="Toggle dark mode">
                <i class="fa-solid fa-moon"></i><span class="hide-sm">Theme</span>
            </button>
            <a class="pill-btn hide-sm" href="front_page.php"><i class="fa-solid fa-store"></i>Store</a>
            <span class="user-pill" title="<?= e($user['email']) ?>">
                <span class="avatar"><?= e($initial) ?></span>
                <span class="hide-sm"><?= e($user['username']) ?></span>
                <span class="role-tag"<?= can_manage($user) ? '' : ' title="View and print only"' ?>><?= e(ucfirst($user['role'])) ?><?= can_manage($user) ? '' : ' · View only' ?></span>
            </span>
        </div>
    </header>

    <div class="app">
        <aside class="sidebar" id="sidebar">
            <nav>
                <?= nav_link('home_page.php', 'fa-table-cells-large', 'Dashboard', $active === 'dashboard') ?>

                <div class="nav-label">Inventory</div>
                <?= nav_link('food_items.php', 'fa-boxes-stacked', 'Food Items', $active === 'items', $c['items'] ?? 0, 'green') ?>
                <?= nav_link('food_items.php?status=expiring', 'fa-hourglass-half', 'Expiring Soon', $active === 'expiring', $c['expiring'] ?? 0, 'amber') ?>
                <?= nav_link('food_items.php?status=low', 'fa-arrow-trend-down', 'Low Stock', $active === 'lowstock', $c['low_stock'] ?? 0, 'red') ?>
                <?= nav_link('archive.php', 'fa-box-archive', 'Archive', $active === 'archive', $c['archived'] ?? 0, 'gray') ?>

                <div class="nav-label">Waste &amp; Returns</div>
                <?php if (can_manage($user)): ?>
                    <?= nav_link('log_waste.php', 'fa-trash-can', 'Log Waste', $active === 'log_waste') ?>
                <?php endif; ?>
                <?= nav_link('returns.php', 'fa-rotate-left', 'Returns', $active === 'returns', $c['returns_month'] ?? 0, 'teal') ?>
                <?= nav_link('waste_history.php', 'fa-clock-rotate-left', 'Waste History', $active === 'history', $c['waste_month'] ?? 0, 'teal') ?>
                <?= nav_link('reports.php', 'fa-chart-column', 'Reports', $active === 'reports') ?>

                <div class="nav-label">Store</div>
                <?= nav_link('orders.php', 'fa-basket-shopping', 'Online Orders', $active === 'orders', $c['orders_open'] ?? 0, 'amber') ?>
                <?= nav_link('suppliers.php', 'fa-truck-field', 'Suppliers', $active === 'suppliers', $c['suppliers'] ?? 0, 'gray') ?>
                <?= nav_link('deliveries.php', 'fa-truck-fast', 'Deliveries', $active === 'deliveries', $c['deliveries'] ?? 0, 'amber') ?>

                <?php if (can_manage($user)): ?>
                    <div class="nav-label">Admin</div>
                    <?php if (is_admin($user)): ?>
                        <?= nav_link('ai_advisor.php', 'fa-robot', 'AI Waste Advisor', $active === 'advisor') ?>
                    <?php endif; ?>
                    <?= nav_link('admin_panel.php', 'fa-user-shield', is_admin($user) ? 'Users & System' : 'User Accounts', $active === 'admin', $c['pending_users'] ?? 0, 'red') ?>
                    <?php if (is_admin($user)): ?>
                        <?= nav_link('store_settings.php', 'fa-sliders', 'Store Settings', $active === 'store_settings') ?>
                    <?php endif; ?>
                <?php endif; ?>
            </nav>

            <form action="logout.php" method="POST" class="sidebar-footer">
                <?= csrf_field() ?>
                <button type="submit" class="nav-link logout-link"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></button>
            </form>
        </aside>
        <div class="sidebar-scrim" data-sidebar-toggle></div>

        <main class="main">
            <?php foreach (pull_flashes() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?>" role="status">
                    <i class="fa-solid <?= $f['type'] === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
                    <span><?= e($f['message']) ?></span>
                    <?php if (!empty($f['link'])): ?>
                        <a class="btn btn-sm" href="<?= e($f['link']['href']) ?>"<?= !empty($f['link']['new_tab']) ? ' target="_blank" rel="noopener"' : '' ?>><?= e($f['link']['label']) ?></a>
                    <?php endif; ?>
                    <button type="button" class="alert-close" data-dismiss aria-label="Dismiss">&times;</button>
                </div>
            <?php endforeach; ?>
    <?php
}

function render_shell_end(string $extraScript = ''): void {
    ?>
        </main>
    </div>
    <script src="<?= asset('app.js') ?>"></script>
    <?= $extraScript ?>
</body>
</html>
    <?php
}

function page_header(string $title, string $subtitle = '', string $actionsHtml = ''): void {
    ?>
    <div class="page-head">
        <div>
            <h1 class="page-title"><?= e($title) ?></h1>
            <?php if ($subtitle !== ''): ?><p class="page-sub"><?= e($subtitle) ?></p><?php endif; ?>
        </div>
        <?php if ($actionsHtml !== ''): ?><div class="page-actions"><?= $actionsHtml ?></div><?php endif; ?>
    </div>
    <?php
}

/* ---------- Charts ---------- */

/**
 * Donut chart with a legend. Each slice: ['label', 'value' (float), 'color' (CSS color/var), 'display' (text for legend)].
 * Slices are drawn clockwise from 12 o'clock with a small surface gap between them.
 */
function donut_chart(array $slices, string $centerValue, string $centerLabel, string $ariaLabel): string {
    $total = array_sum(array_map(fn ($s) => max(0, (float) $s['value']), $slices));
    $r = 15.9155; // circumference = 100, so dash lengths are percentages
    $svg = '<svg viewBox="0 0 42 42" class="donut-svg" role="img" aria-label="' . e($ariaLabel) . '">'
        . '<circle cx="21" cy="21" r="' . $r . '" fill="none" stroke="var(--gray-bg)" stroke-width="6"></circle>';

    if ($total > 0) {
        $visible = array_values(array_filter($slices, fn ($s) => (float) $s['value'] > 0));
        $gap = count($visible) > 1 ? 0.8 : 0;
        $offset = 0.0;
        foreach ($visible as $s) {
            $pct = (float) $s['value'] / $total * 100;
            $dash = max(0.01, $pct - $gap);
            $tip = $s['label'] . ': ' . $s['display'] . ' (' . number_format($pct, 1) . '%)';
            $svg .= '<circle class="donut-slice" cx="21" cy="21" r="' . $r . '" fill="none" stroke="' . e($s['color']) . '" stroke-width="6"'
                . ' stroke-dasharray="' . round($dash, 3) . ' ' . round(100 - $dash, 3) . '" stroke-dashoffset="' . round(25 - $offset, 3) . '"'
                . ' data-tip="' . e($tip) . '" tabindex="0"><title>' . e($tip) . '</title></circle>';
            $offset += $pct;
        }
    }
    $svg .= '</svg>';

    $legend = '<ul class="donut-legend">';
    foreach ($slices as $s) {
        $pct = $total > 0 ? (float) $s['value'] / $total * 100 : 0;
        $legend .= '<li><i style="background:' . e($s['color']) . '"></i><span class="name">' . e($s['label']) . '</span>'
            . '<span class="val">' . e($s['display']) . '</span><span class="pct">' . number_format($pct, 1) . '%</span></li>';
    }
    $legend .= '</ul>';

    return '<div class="donut"><div class="donut-figure">' . $svg
        . '<div class="donut-center"><strong>' . e($centerValue) . '</strong><span>' . e($centerLabel) . '</span></div></div>'
        . $legend . '</div>';
}

/* ---------- Status helpers shared by inventory views ---------- */

function stock_status(int $quantity): array {
    if ($quantity <= 0) return ['Out of Stock', 'red'];
    if ($quantity <= LOW_STOCK_THRESHOLD) return ['Low Stock', 'amber'];
    if ($quantity >= OVERSTOCK_THRESHOLD) return ['Overstock', 'purple'];
    return ['In Stock', 'green'];
}

function freshness_status(?int $daysLeft): array {
    if ($daysLeft === null) return ['—', 'gray'];
    if ($daysLeft < 0) return ['Expired', 'red'];
    if ($daysLeft === 0) return ['Expires today', 'red'];
    if ($daysLeft <= EXPIRING_SOON_DAYS) return [$daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left', 'amber'];
    return ['Fresh', 'green'];
}

// Markdown suggestion for items close to expiry.
function discount_percent(?int $daysLeft): int {
    return match ($daysLeft) {
        0, 1 => 50,
        2 => 20,
        default => 0,
    };
}
