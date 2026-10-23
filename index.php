<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';

if (!empty($_SESSION['user_id'])) {
    redirect('home_page.php');
}

$activeForm = ($_SESSION['auth_form'] ?? 'login') === 'register' ? 'register' : 'login';
$old = $_SESSION['auth_old'] ?? [];
$flashes = pull_flashes();
unset($_SESSION['auth_form'], $_SESSION['auth_old']);

$noUsersYet = (int) db_value('SELECT COUNT(*) FROM users') === 0;
if ($noUsersYet && !$flashes) {
    $activeForm = 'register';
}

render_head('Sign in', 'auth-body');
?>
    <section class="auth-hero">
        <a class="brand" href="front_page.php">
            <span class="brand-logo">ZW</span>
            <span class="brand-text">
                <strong><?= strtoupper(APP_NAME) ?></strong>
                <small><?= e(strtoupper(APP_TAGLINE)) ?></small>
            </span>
        </a>
        <div>
            <h1>Less waste.<br>Smarter stock.</h1>
            <p>Track inventory, catch expiring items before they spoil, and see exactly where your losses come from.</p>
            <div class="auth-features">
                <div class="auth-feature"><i class="fa-solid fa-barcode"></i> Barcode quick-scan stock in</div>
                <div class="auth-feature"><i class="fa-solid fa-bell"></i> Expiry alerts and markdown suggestions</div>
                <div class="auth-feature"><i class="fa-solid fa-chart-column"></i> Waste reports by item and reason</div>
            </div>
        </div>
        <small style="opacity:.6">© <?= date('Y') ?> <?= e(STORE_NAME) ?></small>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <div class="auth-top">
                <a href="front_page.php" class="btn btn-sm"><i class="fa-solid fa-store"></i> Store front</a>
                <button type="button" class="btn btn-sm" data-theme-toggle title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
            </div>

            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?>" role="status">
                    <i class="fa-solid <?= $f['type'] === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
                    <span><?= e($f['message']) ?></span>
                </div>
            <?php endforeach; ?>

            <div class="auth-box <?= $activeForm === 'login' ? 'active' : '' ?>" id="login-box">
                <h2>Welcome back</h2>
                <p class="page-sub">Sign in to your account</p>
                <form action="login_register.php" method="POST">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="login-email">Email address</label>
                        <input class="input" type="email" id="login-email" name="email" value="<?= e($old['email'] ?? '') ?>" placeholder="you@example.com" required autocomplete="email" <?= $activeForm === 'login' ? 'autofocus' : '' ?>>
                    </div>
                    <div class="field">
                        <label for="login-password">Password</label>
                        <div class="pw-field">
                            <input class="input" type="password" id="login-password" name="password" placeholder="Enter your password" required autocomplete="current-password" style="width:100%">
                            <button type="button" class="icon-btn pw-toggle" data-pw-toggle aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary btn-lg btn-block"><i class="fa-solid fa-right-to-bracket"></i> Sign in</button>
                </form>
                <p class="auth-switch">New staff member? <button type="button" data-auth-show="register-box">Request an account</button></p>
            </div>

            <div class="auth-box <?= $activeForm === 'register' ? 'active' : '' ?>" id="register-box">
                <h2><?= $noUsersYet ? 'Create the admin account' : 'Request an account' ?></h2>
                <p class="page-sub">
                    <?= $noUsersYet
                        ? 'No accounts exist yet. The first account becomes the administrator.'
                        : 'An admin will review and activate your account.' ?>
                </p>
                <form action="login_register.php" method="POST">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="register-username">Username</label>
                        <input class="input" type="text" id="register-username" name="username" value="<?= e($old['username'] ?? '') ?>" maxlength="100" required autocomplete="username">
                    </div>
                    <div class="field">
                        <label for="register-email">Email address</label>
                        <input class="input" type="email" id="register-email" name="email" value="<?= $activeForm === 'register' ? e($old['email'] ?? '') : '' ?>" required autocomplete="email">
                    </div>
                    <div class="field">
                        <label for="register-password">Password</label>
                        <div class="pw-field">
                            <input class="input" type="password" id="register-password" name="password" minlength="6" required autocomplete="new-password" placeholder="At least 6 characters" style="width:100%">
                            <button type="button" class="icon-btn pw-toggle" data-pw-toggle aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="field">
                        <label for="register-confirm-password">Confirm password</label>
                        <div class="pw-field">
                            <input class="input" type="password" id="register-confirm-password" name="confirm_password" minlength="6" required autocomplete="new-password" placeholder="Type the same password again" aria-describedby="confirm-hint" style="width:100%">
                            <button type="button" class="icon-btn pw-toggle" data-pw-toggle aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                        <span class="hint" id="confirm-hint" aria-live="polite"></span>
                    </div>
                    <button type="submit" name="register" class="btn btn-primary btn-lg btn-block"><i class="fa-solid fa-user-plus"></i> <?= $noUsersYet ? 'Create admin account' : 'Submit request' ?></button>
                </form>
                <?php if (!$noUsersYet): ?>
                    <p class="auth-switch">Already have an account? <button type="button" data-auth-show="login-box">Sign in</button></p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <script src="<?= asset('app.js') ?>"></script>
    <script>
        document.querySelectorAll('[data-auth-show]').forEach((btn) => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.auth-box').forEach((box) => box.classList.remove('active'));
                const target = document.getElementById(btn.dataset.authShow);
                target.classList.add('active');
                target.querySelector('input:not([type=hidden])')?.focus();
            });
        });

        // Confirm password: say right away whether the two passwords match.
        (function () {
            const pw = document.getElementById('register-password');
            const confirm = document.getElementById('register-confirm-password');
            const hint = document.getElementById('confirm-hint');
            function check() {
                if (!confirm.value) {
                    hint.textContent = '';
                    hint.className = 'hint';
                    confirm.setCustomValidity('');
                    return;
                }
                const match = confirm.value === pw.value;
                hint.innerHTML = match
                    ? '<i class="fa-solid fa-circle-check"></i> Passwords match'
                    : '<i class="fa-solid fa-circle-exclamation"></i> Passwords do not match';
                hint.className = 'hint ' + (match ? 'hint-ok' : 'hint-bad');
                confirm.setCustomValidity(match ? '' : 'Passwords do not match');
            }
            pw.addEventListener('input', check);
            confirm.addEventListener('input', check);
        })();
    </script>
</body>
</html>
