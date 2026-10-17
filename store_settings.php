<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/store.php';

$user = require_login();
require_role($user, ['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = fn (string $key) => trim((string) ($_POST[$key] ?? ''));
    $values = [
        'store_name' => $in('store_name'),
        'tagline' => $in('tagline'),
        'announcement' => $in('announcement'),
        'address' => $in('address'),
        'phone' => $in('phone'),
        'email' => $in('email'),
        'facebook_url' => $in('facebook_url'),
        'default_markup' => $in('default_markup'),
        'pickup_enabled' => isset($_POST['pickup_enabled']) ? '1' : '0',
        'pickup_days' => $in('pickup_days'),
        'prep_minutes' => $in('prep_minutes'),
        'store_status' => array_key_exists($in('store_status'), STORE_STATUS_OPTIONS) ? $in('store_status') : 'auto',
        // "Open now" / "Closed now" only last for the day they are saved.
        'status_date' => date('Y-m-d'),
        'payment_online_details' => $in('payment_online_details'),
        'payment_bank_details' => $in('payment_bank_details'),
    ];

    // Weekly schedule: one row per day, unticked = closed that day.
    $week = [];
    $weekError = null;
    foreach (WEEK_DAYS as $n => $label) {
        $closed = !isset($_POST['day_open'][$n]);
        $from = trim((string) ($_POST['day_from'][$n] ?? ''));
        $to = trim((string) ($_POST['day_to'][$n] ?? ''));
        if (!$closed && (!valid_hhmm($from) || !valid_hhmm($to))) {
            $weekError ??= "$label: enter both an opening and a closing time.";
        } elseif (!$closed && $to <= $from) {
            $weekError ??= "$label: closing time must be later than opening time.";
        }
        $week[$n] = [
            'open' => valid_hhmm($from) ? $from : STORE_DEFAULTS['open_time'],
            'close' => valid_hhmm($to) ? $to : STORE_DEFAULTS['close_time'],
            'closed' => $closed ? 1 : 0,
        ];
    }
    $values['weekly_hours'] = json_encode($week);

    $error = match (true) {
        $values['store_name'] === '' || mb_strlen($values['store_name']) > 80 => 'Store name is required (max 80 characters).',
        mb_strlen($values['tagline']) > 200 => 'Tagline can be at most 200 characters.',
        mb_strlen($values['announcement']) > 200 => 'Announcement can be at most 200 characters.',
        $values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL) => 'Please enter a valid email address.',
        $values['facebook_url'] !== '' && !preg_match('#^https?://#i', $values['facebook_url']) => 'The Facebook link must start with https://',
        $weekError !== null => $weekError,
        !is_numeric($values['default_markup']) || (float) $values['default_markup'] < 0 || (float) $values['default_markup'] > 300 => 'Default markup must be between 0 and 300%.',
        !ctype_digit($values['pickup_days']) || (int) $values['pickup_days'] < 1 || (int) $values['pickup_days'] > 7 => 'Pickup days must be between 1 and 7.',
        !ctype_digit($values['prep_minutes']) || (int) $values['prep_minutes'] > 240 => 'Preparation time must be between 0 and 240 minutes.',
        mb_strlen($values['payment_online_details']) > 200 || mb_strlen($values['payment_bank_details']) > 200 => 'Payment details can be at most 200 characters each.',
        default => null,
    };

    if ($error) {
        flash('error', $error);
        $_SESSION['settings_old'] = $values;
    } else {
        $values['default_markup'] = (string) round((float) $values['default_markup'], 2);
        save_store_settings($values);
        flash('success', 'Store settings saved. The storefront is already using them.');
    }
    redirect('store_settings.php');
}

$old = $_SESSION['settings_old'] ?? null;
unset($_SESSION['settings_old']);
$s = array_merge(store_settings(), $old ?? []);
$week = store_week();
if ($old) {
    // Re-show what was typed, even though it wasn't saved.
    foreach (json_decode($old['weekly_hours'], true) ?: [] as $n => $day) {
        $week[$n] = ['open' => $day['open'], 'close' => $day['close'], 'closed' => !empty($day['closed'])];
    }
}
$currentStatus = $old['store_status'] ?? store_override();
$status = store_status();

$online = db_one(
    "SELECT SUM(fi.is_listed = 1 AND (fi.selling_price IS NOT NULL OR fi.purchase_price IS NOT NULL) AND fi.expiration_date >= CURDATE()) AS shown,
            SUM(fi.is_listed = 0) AS hidden,
            SUM(fi.is_listed = 1 AND fi.selling_price IS NULL AND fi.purchase_price IS NULL) AS unpriced,
            SUM(fi.selling_price IS NOT NULL) AS custom_price
     FROM food_items fi
     WHERE fi.archived_at IS NULL"
);
$slots = pickup_slots();

render_shell_start($user, 'Store Settings', 'store_settings');
page_header(
    'Store Settings',
    'What customers see on the storefront, and how online pickup reservations work.',
    '<a class="btn" href="front_page.php" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open storefront</a>'
);
?>

<div class="stats" style="margin-bottom:18px">
    <div class="stat">
        <span class="stat-icon <?= $status['open'] ? 'tone-green' : 'tone-red' ?>"><i class="fa-solid fa-door-<?= $status['open'] ? 'open' : 'closed' ?>"></i></span>
        <span><div class="stat-label">Storefront shows</div><div class="stat-value"><?= e($status['label']) ?></div><div class="stat-sub"><?= e($status['detail']) ?></div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-green"><i class="fa-solid fa-store"></i></span>
        <span><div class="stat-label">Shown online</div><div class="stat-value"><?= (int) $online['shown'] ?></div><div class="stat-sub">Active items with a price</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-gray"><i class="fa-solid fa-eye-slash"></i></span>
        <span><div class="stat-label">Hidden</div><div class="stat-value"><?= (int) $online['hidden'] ?></div><div class="stat-sub">Switched off per item</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-amber"><i class="fa-solid fa-tag"></i></span>
        <span><div class="stat-label">Custom prices</div><div class="stat-value"><?= (int) $online['custom_price'] ?></div><div class="stat-sub">Others use the default markup</div></span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-red"><i class="fa-solid fa-circle-question"></i></span>
        <span><div class="stat-label">No price</div><div class="stat-value"><?= (int) $online['unpriced'] ?></div><div class="stat-sub">Add a cost or selling price to list them</div></span>
    </div>
</div>

<form method="POST" class="grid-2">
    <?= csrf_field() ?>
    <div class="stack">
        <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-shop"></i> Store details</div></div>
            <div class="card-body">
                <div class="field">
                    <label for="storeName">Store name <span class="req">*</span></label>
                    <input class="input" type="text" id="storeName" name="store_name" maxlength="80" required value="<?= e($s['store_name']) ?>">
                </div>
                <div class="field">
                    <label for="tagline">Tagline</label>
                    <input class="input" type="text" id="tagline" name="tagline" maxlength="200" value="<?= e($s['tagline']) ?>">
                </div>
                <div class="field">
                    <label for="announcement">Announcement bar</label>
                    <input class="input" type="text" id="announcement" name="announcement" maxlength="200" placeholder="e.g. Closed on Nov 1 for All Saints' Day" value="<?= e($s['announcement']) ?>">
                    <span class="hint">Shown in a yellow bar at the top of the storefront. Leave blank to hide.</span>
                </div>
                <div class="field">
                    <label for="address">Address</label>
                    <input class="input" type="text" id="address" name="address" maxlength="200" value="<?= e($s['address']) ?>">
                    <span class="hint">Adds a "Get directions" link to Google Maps.</span>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="phone">Phone number</label>
                        <input class="input" type="tel" id="phone" name="phone" maxlength="40" value="<?= e($s['phone']) ?>">
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input class="input" type="email" id="email" name="email" maxlength="120" value="<?= e($s['email']) ?>">
                    </div>
                    <div class="field span-2">
                        <label for="fb">Facebook page</label>
                        <input class="input" type="url" id="fb" name="facebook_url" maxlength="200" placeholder="https://facebook.com/yourstore" value="<?= e($s['facebook_url']) ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-wallet"></i> Payment options</div></div>
            <div class="card-body">
                <p class="muted" style="margin-top:0">Customers choose <strong>Cash</strong>, <strong>Card</strong>, <strong>Online payment</strong> or <strong>Bank transfer</strong> when they reserve. These details are shown to customers who pick online payment or bank transfer.</p>
                <div class="field">
                    <label for="payOnline"><i class="fa-solid fa-mobile-screen-button"></i> Online payment details</label>
                    <input class="input" type="text" id="payOnline" name="payment_online_details" maxlength="200" placeholder="e.g. GCash 0917 123 4567 (Lisa M.)" value="<?= e($s['payment_online_details']) ?>">
                </div>
                <div class="field">
                    <label for="payBank"><i class="fa-solid fa-building-columns"></i> Bank transfer details</label>
                    <input class="input" type="text" id="payBank" name="payment_bank_details" maxlength="200" placeholder="e.g. BDO 0012 3456 7890 · Lisa Grocery Store" value="<?= e($s['payment_bank_details']) ?>">
                    <span class="hint">Leave blank and customers are told the store will text them the details.</span>
                </div>
            </div>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-regular fa-clock"></i> Open / Closed &amp; store hours</div></div>
            <div class="card-body">
                <div class="field">
                    <label for="storeStatus">Store status</label>
                    <select class="select" id="storeStatus" name="store_status">
                        <?php foreach (STORE_STATUS_OPTIONS as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">Right now the storefront says <strong class="<?= $status['open'] ? 'hint-ok' : 'hint-bad' ?>"><?= e($status['label']) ?></strong> · <?= e($status['detail']) ?>. Closed days and "Closed" also stop pickup times on those days.</span>
                </div>

                <div class="field">
                    <span class="field-label">Weekly schedule</span>
                    <div class="table-scroll">
                        <table class="hours-table">
                            <thead><tr><th>Day</th><th>Open?</th><th>Opens</th><th>Closes</th></tr></thead>
                            <tbody>
                            <?php foreach (WEEK_DAYS as $n => $label): $day = $week[$n]; ?>
                                <tr class="<?= $day['closed'] ? 'is-closed' : '' ?><?= (int) date('N') === $n ? ' is-today' : '' ?>" data-day-row>
                                    <td class="day"><?= e($label) ?><?= (int) date('N') === $n ? ' <span class="chip chip-green">Today</span>' : '' ?></td>
                                    <td><label class="check"><input type="checkbox" name="day_open[<?= $n ?>]" value="1" <?= $day['closed'] ? '' : 'checked' ?> data-day-toggle aria-label="Open on <?= e($label) ?>"> <span class="day-state"><?= $day['closed'] ? 'Closed' : 'Open' ?></span></label></td>
                                    <td><input class="input" type="time" name="day_from[<?= $n ?>]" value="<?= e($day['open']) ?>" aria-label="<?= e($label) ?> opening time"></td>
                                    <td><input class="input" type="time" name="day_to[<?= $n ?>]" value="<?= e($day['close']) ?>" aria-label="<?= e($label) ?> closing time"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div><button type="button" class="btn btn-sm" id="copyMonday"><i class="fa-regular fa-copy"></i> Copy Monday's hours to every open day</button></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-basket-shopping"></i> Pricing &amp; pickup</div></div>
            <div class="card-body">
                <div class="field">
                    <label for="markup">Default markup (%)</label>
                    <input class="input" type="number" id="markup" name="default_markup" min="0" max="300" step="0.5" required value="<?= e($s['default_markup']) ?>">
                    <span class="hint" id="markupHint">Used for items without their own selling price. Example: a ₱100 cost sells for ₱<?= number_format(100 * (1 + (float) $s['default_markup'] / 100), 2) ?>.</span>
                </div>
                <div class="alert alert-info">
                    <i class="fa-solid fa-tags"></i>
                    <span>Deals are automatic: items 2 days from their best-before date get 20% off, and items expiring today or tomorrow get 50% off (the same markdowns shown in Food Items).</span>
                </div>
                <div class="field">
                    <span class="field-label">Online reservations</span>
                    <label class="check"><input type="checkbox" name="pickup_enabled" value="1" <?= $s['pickup_enabled'] === '1' ? 'checked' : '' ?>> Let customers reserve items for pickup</label>
                    <span class="hint">When off, the storefront still shows products and deals, but without a basket.</span>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="pickupDays">Pickup window (days)</label>
                        <input class="input" type="number" id="pickupDays" name="pickup_days" min="1" max="7" required value="<?= e($s['pickup_days']) ?>">
                        <span class="hint">1 = today only</span>
                    </div>
                    <div class="field">
                        <label for="prep">Preparation time (minutes)</label>
                        <input class="input" type="number" id="prep" name="prep_minutes" min="0" max="240" step="15" required value="<?= e($s['prep_minutes']) ?>">
                        <span class="hint">Earliest pickup after ordering</span>
                    </div>
                </div>
                <div class="field">
                    <span class="field-label">Pickup times customers can choose right now</span>
                    <?php if ($slots): ?>
                        <ul class="list">
                            <?php foreach ($slots as $day): ?>
                                <li><span class="cell-title"><?= e($day['label']) ?></span><span class="muted"><?= e(reset($day['times'])) ?> – <?= e(end($day['times'])) ?> · <?= count($day['times']) ?> slots</span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted" style="margin:0">No pickup times left in the current window.</p>
                    <?php endif; ?>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php ob_start(); ?>
<script>
document.getElementById('markup').addEventListener('input', (e) => {
    const pct = Math.max(0, Number(e.target.value) || 0);
    document.getElementById('markupHint').textContent =
        `Used for items without their own selling price. Example: a ₱100 cost sells for ₱${(100 * (1 + pct / 100)).toFixed(2)}.`;
});

// Weekly schedule: grey out closed days, and copy Monday's hours to the other open days.
(function () {
    const rows = Array.from(document.querySelectorAll('[data-day-row]'));
    function paint(row) {
        const open = row.querySelector('[data-day-toggle]').checked;
        row.classList.toggle('is-closed', !open);
        row.querySelector('.day-state').textContent = open ? 'Open' : 'Closed';
        row.querySelectorAll('input[type=time]').forEach((t) => { t.required = open; });
    }
    rows.forEach((row) => {
        row.querySelector('[data-day-toggle]').addEventListener('change', () => paint(row));
        paint(row);
    });
    document.getElementById('copyMonday').addEventListener('click', () => {
        const [from, to] = rows[0].querySelectorAll('input[type=time]');
        rows.slice(1).forEach((row) => {
            if (!row.querySelector('[data-day-toggle]').checked) return;
            const [f, t] = row.querySelectorAll('input[type=time]');
            f.value = from.value;
            t.value = to.value;
        });
    });
})();
</script>
<?php render_shell_end(ob_get_clean()); ?>
