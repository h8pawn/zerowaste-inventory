<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/store.php';

$s = store_settings();
$catalog = store_catalog();
$canReserve = pickup_enabled();
$slots = $canReserve ? pickup_slots() : [];

$deals = array_values(array_filter($catalog, fn ($i) => $i['discount'] > 0 && $i['available'] > 0));
usort($deals, fn ($a, $b) => [$b['discount'], $a['days_left']] <=> [$a['discount'], $b['days_left']]);

$inStockCount = count(array_filter($catalog, fn ($i) => $i['available'] > 0));
$newCount = count(array_filter($catalog, fn ($i) => $i['just_arrived'] && $i['available'] > 0));
$categoryCounts = array_count_values(array_column(array_filter($catalog, fn ($i) => $i['available'] > 0), 'category'));
ksort($categoryCounts);
$maxDiscount = $deals ? max(array_column($deals, 'discount')) : 0;
$status = store_status();

function stock_badge(array $item): array {
    return match (true) {
        $item['available'] <= 0 => ['Sold out', 'out'],
        $item['available'] <= 5 => ['Only ' . $item['available'] . ' left', 'low'],
        default => ['In stock', 'ok'],
    };
}

function product_card(array $item, bool $canReserve, int $order, bool $isDeal = false): string {
    [$icon, $tone] = category_style($item['category']);
    [$stockLabel, $stockTone] = stock_badge($item);
    $soldOut = $item['available'] <= 0;
    $maxQty = min($item['available'], MAX_LINE_QTY);
    ob_start();
    ?>
    <article class="product<?= $soldOut ? ' is-out' : '' ?><?= $isDeal ? ' is-deal' : '' ?>"
             data-id="<?= $item['id'] ?>" data-name="<?= e($item['name']) ?>" data-category="<?= e($item['category']) ?>"
             data-price="<?= $item['sale_price'] ?>" data-regular="<?= $item['price'] ?>" data-discount="<?= $item['discount'] ?>"
             data-available="<?= $item['available'] ?>" data-unit="<?= e($item['unit']) ?>" data-new="<?= $item['just_arrived'] ? 1 : 0 ?>"
             data-emoji="<?= e($item['emoji'] ?? '') ?>" data-order="<?= $order ?>">
        <div class="product-media tone-<?= $tone ?>">
            <?php if ($item['emoji']): ?>
                <span class="product-emoji" aria-hidden="true"><?= $item['emoji'] ?></span>
            <?php else: ?>
                <i class="fa-solid <?= $icon ?>" aria-hidden="true"></i>
            <?php endif; ?>
            <?php if ($item['discount']): ?><span class="badge badge-deal"><?= $item['discount'] ?>% off</span><?php endif; ?>
            <?php if ($item['just_arrived'] && !$item['discount']): ?><span class="badge badge-new">Just arrived</span><?php endif; ?>
        </div>
        <div class="product-body">
            <span class="product-cat"><?= e($item['category']) ?></span>
            <h3 class="product-name"><?= e($item['name']) ?></h3>
            <div class="product-price">
                <strong><?= peso($item['sale_price']) ?></strong><span class="per">/ <?= e($item['unit']) ?></span>
                <?php if ($item['discount']): ?><s><?= peso($item['price']) ?></s><?php endif; ?>
            </div>
            <div class="product-meta">
                <span class="stock stock-<?= $stockTone ?>"><?= e($stockLabel) ?></span>
                <span class="bb<?= $item['days_left'] <= 2 ? ' bb-soon' : '' ?>"><?= e(best_before_label($item['days_left'], $item['expiration_date'])) ?></span>
            </div>
            <?php if ($canReserve && !$soldOut): ?>
                <div class="product-actions">
                    <div class="qty" role="group" aria-label="Quantity">
                        <button type="button" data-step="-1" aria-label="Less">−</button>
                        <input type="number" value="1" min="1" max="<?= $maxQty ?>" inputmode="numeric" aria-label="Quantity of <?= e($item['name']) ?>">
                        <button type="button" data-step="1" aria-label="More">+</button>
                    </div>
                    <button type="button" class="add-btn" data-add><i class="fa-solid fa-basket-shopping"></i> Add</button>
                </div>
            <?php endif; ?>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

render_store_head($s['store_name'] . ' — Fresh groceries, reserved for pickup');
render_store_header();
?>

<main>
    <section class="hero">
        <div class="wrap hero-grid">
            <div class="hero-copy">
                <span class="kicker <?= $status['open'] ? 'is-open' : 'is-closed' ?>"><span class="status-dot"></span> <strong><?= e($status['label']) ?></strong> · <?= e($status['detail']) ?></span>
                <h1>Fresh groceries,<br>reserved and ready.</h1>
                <p class="lead"><?= e($s['tagline']) ?> See what's on our shelves right now, grab today's deals, and pick up at the counter.</p>
                <div class="cta-row">
                    <a class="btn btn-primary" href="#deals"><i class="fa-solid fa-tags"></i> Shop today's deals</a>
                    <a class="btn btn-light" href="#shop">Browse all products</a>
                </div>
                <dl class="hero-stats">
                    <div><dt>Products in stock</dt><dd><?= $inStockCount ?></dd></div>
                    <div><dt>Deals today</dt><dd><?= count($deals) ?></dd></div>
                    <?php if ($newCount): ?>
                        <div><dt>Just arrived</dt><dd><?= $newCount ?></dd></div>
                    <?php elseif ($maxDiscount): ?>
                        <div><dt>Deals up to</dt><dd><?= $maxDiscount ?>%</dd></div>
                    <?php else: ?>
                        <div><dt>Ready in</dt><dd><?= max(1, (int) round((int) $s['prep_minutes'] / 60)) ?> hr</dd></div>
                    <?php endif; ?>
                </dl>
                <p class="live-note"><span class="live-dot"></span> Live from our inventory · updated <?= date('g:i A') ?></p>
            </div>
            <?php /* The store photo is now the full-screen wallpaper (see "Wallpaper" in store.css). */ ?>
            <div class="hero-badge">
                <i class="fa-solid fa-leaf"></i>
                <div>
                    <strong>Less waste, lower prices</strong>
                    <span>Food close to its best-before date is marked down<?= $maxDiscount ? ' up to ' . $maxDiscount . '%' : '' ?>, so it gets eaten instead of thrown away.</span>
                </div>
            </div>
        </div>
        <a class="hero-scroll" href="#deals" aria-label="Scroll down to today's deals"><i class="fa-solid fa-chevron-down"></i></a>
    </section>

    <section id="deals" class="section section-deals">
        <div class="wrap">
            <div class="section-head">
                <div>
                    <span class="eyebrow"><i class="fa-solid fa-fire"></i> Save before it's gone</span>
                    <h2>Today's deals</h2>
                    <p>Perfectly good food that's close to its best-before date. Buy it today and help us waste less.</p>
                </div>
                <?php if ($deals): ?><span class="section-count"><?= count($deals) ?> deal<?= count($deals) === 1 ? '' : 's' ?></span><?php endif; ?>
            </div>
            <?php if ($deals): ?>
                <div class="product-grid deal-grid">
                    <?php foreach ($deals as $i => $item): ?>
                        <?= product_card($item, $canReserve, $i, true) ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-box"><i class="fa-solid fa-seedling"></i><strong>No deals right now</strong>Everything on our shelves is fresh. Check back tomorrow!</div>
            <?php endif; ?>
        </div>
    </section>

    <section id="shop" class="section">
        <div class="wrap">
            <div class="section-head">
                <div>
                    <span class="eyebrow"><i class="fa-solid fa-store"></i> On our shelves now</span>
                    <h2>Shop groceries</h2>
                    <p>Prices and stock come straight from our inventory system<?= $canReserve ? '. Reserve online and pay by cash, card, online payment or bank transfer.' : '.' ?></p>
                </div>
            </div>

            <?php if (!$catalog): ?>
                <div class="empty-box"><i class="fa-solid fa-box-open"></i><strong>Our online shelf is being restocked</strong>Please visit the store or check back soon.</div>
            <?php else: ?>
                <div class="shop-bar">
                    <label class="search">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <input type="search" id="shopSearch" placeholder="Search products…" aria-label="Search products">
                    </label>
                    <select id="shopSort" aria-label="Sort products">
                        <option value="featured">Sort: Featured</option>
                        <option value="name">Name A–Z</option>
                        <option value="price-asc">Price: low to high</option>
                        <option value="price-desc">Price: high to low</option>
                    </select>
                    <label class="switch"><input type="checkbox" id="inStockOnly" checked> <span>In stock only</span></label>
                </div>
                <div class="chips" id="shopChips" role="toolbar" aria-label="Filter by category">
                    <button type="button" class="chip is-active" data-filter="">All <b><?= $inStockCount ?></b></button>
                    <?php if ($deals): ?><button type="button" class="chip chip-deal" data-filter="__deal"><i class="fa-solid fa-tags"></i> Deals <b><?= count($deals) ?></b></button><?php endif; ?>
                    <?php if ($newCount): ?><button type="button" class="chip" data-filter="__new"><i class="fa-solid fa-truck-ramp-box"></i> Just arrived <b><?= $newCount ?></b></button><?php endif; ?>
                    <?php foreach ($categoryCounts as $cat => $n): [$icon] = category_style($cat); $catEmoji = category_emoji($cat); ?>
                        <button type="button" class="chip" data-filter="<?= e($cat) ?>"><?= $catEmoji ? '<span class="chip-emoji" aria-hidden="true">' . $catEmoji . '</span>' : '<i class="fa-solid ' . $icon . '"></i>' ?> <?= e($cat) ?> <b><?= $n ?></b></button>
                    <?php endforeach; ?>
                </div>

                <div class="product-grid" id="productGrid">
                    <?php foreach ($catalog as $i => $item): ?>
                        <?= product_card($item, $canReserve, $i) ?>
                    <?php endforeach; ?>
                </div>
                <div class="empty-box" id="shopEmpty" hidden><i class="fa-solid fa-magnifying-glass"></i><strong>No products match</strong>Try another search or category.</div>
            <?php endif; ?>
        </div>
    </section>

    <section id="how" class="section section-how">
        <div class="wrap">
            <div class="section-head center">
                <div>
                    <span class="eyebrow">Skip the line</span>
                    <h2>How pickup works</h2>
                </div>
            </div>
            <ol class="steps">
                <li><span class="step-icon"><i class="fa-solid fa-basket-shopping"></i></span><h3>1. Fill your basket</h3><p>Add products and today's deals. We only show what's actually in stock.</p></li>
                <li><span class="step-icon"><i class="fa-regular fa-calendar-check"></i></span><h3>2. Choose a pickup time</h3><p>Pick a time<?= $canReserve ? ' within the next ' . count($slots) . ' day' . (count($slots) === 1 ? '' : 's') : '' ?>. We set your items aside so they don't sell out.</p></li>
                <li><span class="step-icon"><i class="fa-solid fa-wallet"></i></span><h3>3. Pay your way</h3><p>Choose cash, card, online payment or bank transfer. Show your reservation code at the counter and you're done.</p></li>
            </ol>
        </div>
    </section>

    <section id="visit" class="section">
        <div class="wrap visit-grid">
            <div class="visit-card">
                <span class="eyebrow">Visit us</span>
                <h2><?= e($s['store_name']) ?></h2>
                <ul class="visit-list">
                    <li><i class="fa-regular fa-clock"></i><div>
                        <strong>Store hours</strong>
                        <span class="<?= $status['open'] ? 'txt-open' : 'txt-closed' ?>"><?= e($status['label']) ?></span> · <?= e($status['detail']) ?>
                        <dl class="hours-list">
                            <?php foreach (store_week_summary() as $row): ?>
                                <div class="<?= $row['today'] ? 'is-today' : '' ?>"><dt><?= e($row['days']) ?></dt><dd><?= e($row['hours']) ?></dd></div>
                            <?php endforeach; ?>
                        </dl>
                    </div></li>
                    <?php if ($s['address'] !== ''): ?>
                        <li><i class="fa-solid fa-location-dot"></i><div><strong>Address</strong><?= e($s['address']) ?> · <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= urlencode($s['address']) ?>" target="_blank" rel="noopener">Get directions</a></div></li>
                    <?php endif; ?>
                    <?php if ($s['phone'] !== ''): ?>
                        <li><i class="fa-solid fa-phone"></i><div><strong>Call or text</strong><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $s['phone'])) ?>"><?= e($s['phone']) ?></a></div></li>
                    <?php endif; ?>
                    <?php if ($s['email'] !== ''): ?>
                        <li><i class="fa-solid fa-envelope"></i><div><strong>Email</strong><a href="mailto:<?= e($s['email']) ?>"><?= e($s['email']) ?></a></div></li>
                    <?php endif; ?>
                    <?php if ($s['facebook_url'] !== ''): ?>
                        <li><i class="fa-brands fa-facebook"></i><div><strong>Facebook</strong><a href="<?= e($s['facebook_url']) ?>" target="_blank" rel="noopener">Follow us for daily deals</a></div></li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="mission-card">
                <i class="fa-solid fa-recycle"></i>
                <h3>Our zero-waste promise</h3>
                <p>We track every item from delivery to shelf. When food gets close to its best-before date, we mark it down here first, because good food belongs on a table, not in the bin.</p>
                <a class="btn btn-light" href="order_status.php"><i class="fa-solid fa-receipt"></i> Track a reservation</a>
            </div>
        </div>
    </section>
</main>

<?php render_store_footer(); ?>

<?php if ($canReserve): ?>
<div class="drawer" id="basket" hidden>
    <div class="drawer-scrim" data-basket-close></div>
    <aside class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="basketTitle">
        <header class="drawer-head">
            <h2 id="basketTitle"><i class="fa-solid fa-basket-shopping"></i> Your pickup basket</h2>
            <button type="button" class="icon-close" data-basket-close aria-label="Close basket"><i class="fa-solid fa-xmark"></i></button>
        </header>

        <div class="drawer-body" id="basketView">
            <ul class="basket-list" id="basketList"></ul>
            <div class="basket-empty" id="basketEmpty">
                <i class="fa-solid fa-basket-shopping"></i>
                <strong>Your basket is empty</strong>
                <a href="#shop" data-basket-close>Start shopping</a>
            </div>

            <div id="basketCheckout" hidden>
                <dl class="basket-summary">
                    <div><dt>Subtotal</dt><dd id="sumRegular"></dd></div>
                    <div class="save" id="sumSaveRow"><dt>Deal savings</dt><dd id="sumSave"></dd></div>
                    <div class="total"><dt>Total to pay</dt><dd id="sumTotal"></dd></div>
                </dl>

                <?php if (!$slots): ?>
                    <p class="form-error">No pickup times are available right now. Please try again later.</p>
                <?php else: ?>
                    <form id="reserveForm" novalidate>
                        <h3>Pickup details</h3>
                        <div class="f-row">
                            <label>Your name<input type="text" name="name" id="fName" maxlength="100" autocomplete="name" required></label>
                        </div>
                        <div class="f-grid">
                            <label><span>Mobile number</span><input type="tel" name="phone" id="fPhone" placeholder="0917 123 4567" autocomplete="tel" required></label>
                            <label><span>Email <small>(optional)</small></span><input type="email" name="email" id="fEmail" autocomplete="email"></label>
                        </div>
                        <div class="f-grid">
                            <label>Pickup day<select name="pickup_date" id="fDate" required></select></label>
                            <label>Pickup time<select name="pickup_time" id="fTime" required></select></label>
                        </div>
                        <fieldset class="pay-options">
                            <legend>Mode of payment</legend>
                            <?php foreach (PAYMENT_METHODS as $value => [$label, $icon, $desc]): ?>
                                <label class="pay-option">
                                    <input type="radio" name="payment_method" value="<?= e($value) ?>" <?= $value === 'cash' ? 'checked' : '' ?>>
                                    <span class="pay-card">
                                        <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i>
                                        <span><strong><?= e($label) ?></strong><small><?= e($desc) ?></small></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="pay-note" id="payNote"></p>
                        <div class="f-row">
                            <label><span>Notes for the store <small>(optional)</small></span><textarea name="notes" maxlength="500" rows="2" placeholder="e.g. ripe mangoes please"></textarea></label>
                        </div>
                        <div class="hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                        <p class="form-error" id="formError" hidden></p>
                        <button type="submit" class="btn btn-primary btn-block" id="reserveBtn"><i class="fa-solid fa-check"></i> Reserve for pickup</button>
                        <p class="fine">Please come on your chosen day; unclaimed reservations are released after closing.</p>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="drawer-body done" id="basketDone" hidden>
            <div class="done-icon"><i class="fa-solid fa-circle-check"></i></div>
            <h3>You're all set!</h3>
            <p>Show this code at the counter:</p>
            <div class="done-code" id="doneCode"></div>
            <dl class="basket-summary">
                <div><dt>Pickup</dt><dd id="donePickup"></dd></div>
                <div><dt>Payment</dt><dd id="donePayment"></dd></div>
                <div class="save" id="doneSaveRow"><dt>You saved</dt><dd id="doneSave"></dd></div>
                <div class="total"><dt>Total to pay</dt><dd id="doneTotal"></dd></div>
            </dl>
            <p class="pay-note" id="donePayNote"></p>
            <a class="btn btn-light btn-block" id="doneTrack" href="order_status.php"><i class="fa-solid fa-receipt"></i> Track this reservation</a>
            <button type="button" class="btn btn-primary btn-block" data-basket-close>Continue shopping</button>
        </div>
    </aside>
</div>
<div class="toast" id="toast" role="status" hidden></div>
<script type="application/json" id="pickupSlots"><?= json_encode($slots, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="application/json" id="paymentNotes"><?= json_encode(array_map('payment_instructions', array_combine(array_keys(PAYMENT_METHODS), array_keys(PAYMENT_METHODS))), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>

<script src="<?= asset('store.js') ?>"></script>
</body>
</html>
