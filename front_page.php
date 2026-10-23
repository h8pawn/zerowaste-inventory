<?php
// The store's public front page: who we are and how we keep food out of the bin.
// It shows no products and takes no orders; staff sign in from here.
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="description" content="<?= e(STORE_TAGLINE) ?>">
    <meta name="color-scheme" content="light dark">
    <title><?= e(STORE_NAME) ?> — Fresh groceries, less waste</title>
    <script>
        // Same theme choice as the staff pages (light, dark, or follow the device).
        try {
            var savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || savedTheme === 'light') document.documentElement.setAttribute('data-theme', savedTheme);
        } catch (e) {}
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php // Versioned like the CSS, so a replaced photo shows at once instead of after the browser cache expires. ?>
    <link rel="preload" as="image" href="<?= e(asset('store_background.webp')) ?>">
    <style>:root { --wall-photo: url("<?= e(asset('store_background.webp')) ?>"); }</style>
    <link rel="stylesheet" href="<?= asset('store.css') ?>">
</head>
<body>
    <header class="site-header">
        <div class="wrap header-row">
            <a class="logo" href="front_page.php">
                <span class="logo-mark">LG</span>
                <span class="logo-text"><?= e(strtoupper(STORE_NAME)) ?></span>
            </a>
            <nav class="site-nav" aria-label="Main">
                <a href="#deals">Deals</a>
                <a href="#visit">Visit us</a>
            </nav>
            <div class="header-actions">
                <button type="button" class="hbtn hbtn-ghost hbtn-icon" data-theme-toggle aria-label="Switch between light and dark mode" title="Dark mode"><i class="fa-solid fa-moon"></i></button>
                <a class="hbtn" href="index.php" title="Staff log in"><i class="fa-solid fa-right-to-bracket"></i><span>Log in</span></a>
            </div>
        </div>
    </header>

<main>
    <section class="hero">
        <div class="wrap hero-grid">
            <div class="hero-copy">
                <span class="kicker"><i class="fa-solid fa-leaf"></i> Your zero-waste neighborhood grocery</span>
                <h1>Fresh groceries,<br>ready when you are.</h1>
                <p class="lead"><?= e(STORE_TAGLINE) ?> Drop by for what's fresh on our shelves today, and grab today's deals while they last.</p>
                <div class="cta-row">
                    <a class="btn btn-primary" href="#deals"><i class="fa-solid fa-tags"></i> See today's deals</a>
                    <a class="btn btn-light" href="#visit">Our zero-waste promise</a>
                </div>
            </div>
            <div class="hero-badge">
                <i class="fa-solid fa-leaf"></i>
                <div>
                    <strong>Less waste, lower prices</strong>
                    <span>Food close to its best-before date is marked down up to 50%, so it gets eaten instead of thrown away.</span>
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
            </div>
            <div class="deal-tiles">
                <div class="deal-tile">
                    <strong>20%<small>off</small></strong>
                    <div><h3>Two days before its date</h3><p>Still fresh, marked down early so it sells in time.</p></div>
                </div>
                <div class="deal-tile">
                    <strong>50%<small>off</small></strong>
                    <div><h3>On its last day</h3><p>Our best price, for food that's best eaten today.</p></div>
                </div>
                <div class="deal-tile">
                    <i class="fa-solid fa-tag"></i>
                    <div><h3>Look for the markdown sticker</h3><p>Deals are on our shelves every day. Ask our staff what's on sale today.</p></div>
                </div>
            </div>
        </div>
    </section>

    <section id="visit" class="section">
        <div class="wrap visit-grid">
            <div class="visit-card">
                <span class="eyebrow">Visit us</span>
                <h2><?= e(STORE_NAME) ?></h2>
                <p><?= e(STORE_TAGLINE) ?></p>
                <ul class="visit-list">
                    <li><i class="fa-solid fa-carrot"></i><div><strong>Fresh produce</strong>Fruits, vegetables, meat and dairy, checked for freshness every day.</div></li>
                    <li><i class="fa-solid fa-basket-shopping"></i><div><strong>Daily essentials</strong>Pantry staples, bread and drinks for every day.</div></li>
                    <li><i class="fa-solid fa-face-smile"></i><div><strong>Friendly service</strong>Ask us about today's deals or anything you can't find.</div></li>
                </ul>
            </div>
            <div class="mission-card">
                <i class="fa-solid fa-recycle"></i>
                <h3>Our zero-waste promise</h3>
                <p>We track every item from delivery to shelf. When food gets close to its best-before date, we mark it down first, because good food belongs on a table, not in the bin.</p>
            </div>
        </div>
    </section>
</main>

    <footer class="site-footer">
        <div class="wrap footer-row">
            <div>
                <strong><?= e(STORE_NAME) ?></strong>
                <p><?= e(STORE_TAGLINE) ?></p>
            </div>
            <div class="footer-meta">
                <span><i class="fa-solid fa-leaf"></i> Inventory powered by ZeroWaste</span>
                <span><i class="fa-solid fa-right-to-bracket"></i> Staff? <a href="index.php">Log in</a></span>
            </div>
        </div>
    </footer>

    <script src="<?= asset('app.js') ?>"></script>
</body>
</html>
