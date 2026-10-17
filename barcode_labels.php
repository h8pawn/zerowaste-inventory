<?php
// Printable barcode labels for one or more items: barcode_labels.php?ids=12,15&copies=3
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/barcode.php';

$user = require_login();

$ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))), fn ($id) => $id > 0))), 0, 200);
$copies = max(1, min(50, (int) ($_GET['copies'] ?? 1)));
$sizes = ['sm' => 'Small · 40 × 25 mm', 'md' => 'Medium · 50 × 30 mm', 'lg' => 'Large · 70 × 40 mm'];
$size = array_key_exists($_GET['size'] ?? '', $sizes) ? $_GET['size'] : 'md';
// Checkboxes default to on for the first visit (no "set" marker yet).
$isSet = isset($_GET['set']);
$showPrice = $isSet ? isset($_GET['price']) : true;
$showDate = $isSet ? isset($_GET['date']) : false;
$cutLines = $isSet ? isset($_GET['cut']) : true;

$items = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $items = db_all(
        "SELECT id, name, barcode, unit, selling_price, purchase_price, expiration_date
         FROM food_items WHERE id IN ($placeholders) ORDER BY FIELD(id, $placeholders)",
        str_repeat('i', count($ids) * 2),
        array_merge($ids, $ids)
    );
}
$printable = array_values(array_filter($items, fn ($i) => $i['barcode'] !== null && is_printable_barcode($i['barcode'])));
$missing = array_values(array_filter($items, fn ($i) => $i['barcode'] === null || !is_printable_barcode($i['barcode'])));
$storeName = store_settings()['store_name'];
$labelCount = count($printable) * $copies;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barcode labels — <?= e($storeName) ?></title>
    <script>
        try {
            var savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || savedTheme === 'light') document.documentElement.setAttribute('data-theme', savedTheme);
        } catch (e) {}
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Plus Jakarta Sans", system-ui, sans-serif; background: #eef2ef; color: #15231b; }
        .toolbar {
            position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center;
            padding: 12px 20px; background: #0c5234; color: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.15);
        }
        .toolbar h1 { font-size: 16px; margin: 0 12px 0 0; }
        .toolbar label { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; }
        .toolbar select, .toolbar input[type=number] { height: 34px; border-radius: 8px; border: 0; padding: 0 8px; font: inherit; font-size: 13px; }
        .toolbar input[type=number] { width: 64px; }
        .toolbar input[type=checkbox] { width: 16px; height: 16px; accent-color: #16925b; }
        .toolbar .spacer { flex: 1; }
        .tbtn { display: inline-flex; align-items: center; gap: 7px; height: 36px; padding: 0 14px; border-radius: 9px; border: 1px solid rgba(255,255,255,.35);
                background: rgba(255,255,255,.1); color: #fff; font: inherit; font-size: 13px; font-weight: 800; text-decoration: none; cursor: pointer; }
        .tbtn.primary { background: #f6c453; border-color: #f6c453; color: #3d2c00; }
        .notice { margin: 16px 20px 0; padding: 10px 14px; border-radius: 10px; background: #fff5cf; color: #8a6100; font-size: 13.5px; font-weight: 600; }
        .summary { margin: 14px 20px 0; font-size: 13px; color: #56655c; }
        .sheet { display: flex; flex-wrap: wrap; gap: 3mm; padding: 16px 20px 40px; align-content: flex-start; }

        .label {
            background: #fff; color: #000; display: flex; flex-direction: column; justify-content: space-between;
            overflow: hidden; page-break-inside: avoid; break-inside: avoid;
        }
        .cut .label { outline: 1px dashed #b9c2bd; outline-offset: 0; }
        .label .l-top { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; }
        .label .l-store { font-weight: 800; letter-spacing: .3px; text-transform: uppercase; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .label .l-price { font-weight: 800; white-space: nowrap; }
        .label .l-price small { font-weight: 600; }
        .label .l-name { font-weight: 800; line-height: 1.15; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .label .l-code svg { display: block; width: 100%; height: auto; }
        .label .l-date { font-weight: 700; }

        .size-sm .label { width: 40mm; height: 25mm; padding: 1.2mm 1.8mm; }
        .size-sm .l-store { font-size: 4.8pt; } .size-sm .l-price { font-size: 8pt; } .size-sm .l-name { font-size: 6.2pt; -webkit-line-clamp: 1; } .size-sm .l-date { font-size: 5pt; }
        .size-sm .l-code svg { max-height: 12mm; }
        .size-md .label { width: 50mm; height: 30mm; padding: 1.6mm 2.2mm; }
        .size-md .l-store { font-size: 5.5pt; } .size-md .l-price { font-size: 10pt; } .size-md .l-name { font-size: 7.5pt; } .size-md .l-date { font-size: 5.8pt; }
        .size-md .l-code svg { max-height: 14.5mm; }
        .size-lg .label { width: 70mm; height: 40mm; padding: 2.2mm 3mm; }
        .size-lg .l-store { font-size: 6.5pt; } .size-lg .l-price { font-size: 14pt; } .size-lg .l-name { font-size: 10pt; } .size-lg .l-date { font-size: 7pt; }
        .size-lg .l-code svg { max-height: 20mm; }

        /* Dark mode on screen only; the labels themselves stay black on white so they scan and print. */
        @media screen and (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) body { background: #0c1511; color: #e4eee8; }
            :root:not([data-theme="light"]) .notice { background: #3a2f0c; color: #f5cf5c; }
            :root:not([data-theme="light"]) .summary { color: #8fa399; }
        }
        @media screen {
            :root[data-theme="dark"] body { background: #0c1511; color: #e4eee8; }
            :root[data-theme="dark"] .notice { background: #3a2f0c; color: #f5cf5c; }
            :root[data-theme="dark"] .summary { color: #8fa399; }
        }

        @media print {
            @page { margin: 6mm; }
            body { background: #fff; }
            .toolbar, .notice, .summary { display: none !important; }
            .sheet { padding: 0; }
        }
    </style>
</head>
<body>
    <form class="toolbar" method="GET">
        <input type="hidden" name="ids" value="<?= e(implode(',', $ids)) ?>">
        <input type="hidden" name="set" value="1">
        <h1><i class="fa-solid fa-barcode"></i> Barcode labels</h1>
        <label>Size <select name="size" onchange="this.form.submit()">
            <?php foreach ($sizes as $key => $label): ?><option value="<?= $key ?>" <?= $size === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select></label>
        <label>Copies each <input type="number" name="copies" min="1" max="50" value="<?= $copies ?>" onchange="this.form.submit()"></label>
        <label><input type="checkbox" name="price" value="1" <?= $showPrice ? 'checked' : '' ?> onchange="this.form.submit()"> Price</label>
        <label><input type="checkbox" name="date" value="1" <?= $showDate ? 'checked' : '' ?> onchange="this.form.submit()"> Best before</label>
        <label><input type="checkbox" name="cut" value="1" <?= $cutLines ? 'checked' : '' ?> onchange="this.form.submit()"> Cut lines</label>
        <noscript><button class="tbtn" type="submit">Apply</button></noscript>
        <span class="spacer"></span>
        <a class="tbtn" href="food_items.php"><i class="fa-solid fa-arrow-left"></i> Food Items</a>
        <button type="button" class="tbtn primary" onclick="window.print()" <?= $labelCount ? '' : 'disabled' ?>><i class="fa-solid fa-print"></i> Print <?= $labelCount ?> label<?= $labelCount === 1 ? '' : 's' ?></button>
    </form>

    <?php if (!$ids): ?>
        <p class="notice">No items selected. Choose items on the Food Items page and use "Print labels".</p>
    <?php endif; ?>
    <?php if ($missing): ?>
        <p class="notice">
            Skipped <?= count($missing) ?> item<?= count($missing) === 1 ? '' : 's' ?> without a barcode:
            <?= e(implode(', ', array_column($missing, 'name'))) ?>.
            <?= can_manage($user) ? 'Use "Generate missing barcodes" on the Food Items page first.' : 'Ask a manager to generate barcodes for them.' ?>
        </p>
    <?php endif; ?>
    <?php if ($printable): ?>
        <p class="summary">Tip: in the print dialog, set scale to 100% (Actual size) so the barcodes scan reliably.</p>
    <?php endif; ?>

    <main class="sheet size-<?= e($size) ?> <?= $cutLines ? 'cut' : '' ?>">
        <?php foreach ($printable as $item):
            $price = retail_price($item['selling_price'], $item['purchase_price']);
            $svg = barcode_svg($item['barcode']);
            for ($i = 0; $i < $copies; $i++): ?>
                <div class="label">
                    <div class="l-top">
                        <span class="l-store"><?= e($storeName) ?></span>
                        <?php if ($showPrice && $price !== null): ?><span class="l-price"><?= peso($price) ?><small>/<?= e($item['unit'] ?: 'pc') ?></small></span><?php endif; ?>
                    </div>
                    <div class="l-name"><?= e($item['name']) ?></div>
                    <div class="l-code"><?= $svg ?></div>
                    <?php if ($showDate): ?><div class="l-date">Best before <?= date('M j, Y', strtotime($item['expiration_date'])) ?></div><?php endif; ?>
                </div>
            <?php endfor;
        endforeach; ?>
    </main>
</body>
</html>
