<?php
// The store's public front page: who we are and how we keep food out of the bin, on one screen.
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
            <div class="header-actions">
                <button type="button" class="hbtn hbtn-ghost hbtn-icon" data-theme-toggle aria-label="Switch between light and dark mode" title="Dark mode"><i class="fa-solid fa-moon"></i></button>
                <a class="hbtn" href="index.php" title="Staff log in"><i class="fa-solid fa-right-to-bracket"></i><span>Log in</span></a>
            </div>
        </div>
    </header>

    <main class="front">
        <div class="wrap">
            <div class="front-stack">
                <section class="hero-copy">
                    <span class="kicker"><i class="fa-solid fa-leaf"></i> Your zero-waste neighborhood grocery</span>
                    <h1>Fresh groceries,<br>ready when you are.</h1>
                    <p class="lead"><?= e(STORE_TAGLINE) ?> Drop by for what's fresh on our shelves today.</p>
                    <div class="visit">
                        <span class="eyebrow">Visit <?= e(STORE_NAME) ?></span>
                        <ul class="visit-list">
                            <li><i class="fa-solid fa-carrot"></i><div><strong>Fresh produce</strong><span>Fruits, vegetables, meat and dairy, checked daily.</span></div></li>
                            <li><i class="fa-solid fa-basket-shopping"></i><div><strong>Daily essentials</strong><span>Pantry staples, bread and drinks.</span></div></li>
                            <li><i class="fa-solid fa-face-smile"></i><div><strong>Friendly service</strong><span>Ask us for anything you can't find.</span></div></li>
                        </ul>
                    </div>
                </section>

                <section class="green-card">
                    <div class="green-item">
                        <i class="fa-solid fa-leaf"></i>
                        <div>
                            <strong>Less waste, lower prices</strong>
                            <span>Food close to its best-before date is marked down up to 50%, so it gets eaten instead of thrown away.</span>
                        </div>
                    </div>
                    <div class="green-item">
                        <i class="fa-solid fa-recycle"></i>
                        <div>
                            <strong>Our zero-waste promise</strong>
                            <span>We track every item from delivery to shelf. When food gets close to its best-before date, we mark it down first, because good food belongs on a table, not in the bin.</span>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>

    <footer class="site-footer">
        <div class="wrap footer-row">
            <span>© <?= date('Y') ?> <strong><?= e(STORE_NAME) ?></strong></span>
            <span><i class="fa-solid fa-leaf"></i> Inventory powered by ZeroWaste · Staff? <a href="index.php">Log in</a></span>
        </div>
    </footer>

    <script src="<?= asset('app.js') ?>"></script>
</body>
</html>
