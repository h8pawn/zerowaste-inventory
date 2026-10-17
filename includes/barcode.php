<?php
// Barcode generation and rendering (EAN-13 and Code 128) as inline SVG, no external libraries.

// GS1 reserves EAN-13 prefixes 20–29 for in-store use, so generated codes never
// collide with real manufacturer barcodes.
const STORE_BARCODE_PREFIX = '20';

const EAN_L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
const EAN_G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
const EAN_R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];
const EAN_PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

// Code 128 bar/space widths for values 0–106 (103–105 = Start A/B/C, 106 = Stop).
const CODE128_PATTERNS = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
    '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
    '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
    '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
    '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
    '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
    '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];

function ean13_check_digit(string $first12): int {
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int) $first12[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return (10 - $sum % 10) % 10;
}

function is_valid_ean13(string $code): bool {
    return (bool) preg_match('/^\d{13}$/', $code) && ean13_check_digit(substr($code, 0, 12)) === (int) $code[12];
}

// A new EAN-13 store code that isn't used by any item or product template.
function generate_store_barcode(): string {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $body = STORE_BARCODE_PREFIX;
        for ($i = 0; $i < 10; $i++) {
            $body .= random_int(0, 9);
        }
        $code = $body . ean13_check_digit($body);
        $taken = db_value(
            'SELECT (SELECT COUNT(*) FROM food_items WHERE barcode = ?) + (SELECT COUNT(*) FROM products WHERE barcode = ?)',
            'ss',
            [$code, $code]
        );
        if (!$taken) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate a unique barcode. Please try again.');
}

function is_printable_barcode(string $code): bool {
    return $code !== '' && strlen($code) <= 48 && (bool) preg_match('/^[\x20-\x7E]+$/', $code);
}

// 95 modules: start guard, 6 left digits (L/G by first digit), centre guard, 6 right digits, end guard.
function ean13_modules(string $code): string {
    $parity = EAN_PARITY[(int) $code[0]];
    $bits = '101';
    for ($i = 1; $i <= 6; $i++) {
        $bits .= ($parity[$i - 1] === 'L' ? EAN_L : EAN_G)[(int) $code[$i]];
    }
    $bits .= '01010';
    for ($i = 7; $i <= 12; $i++) {
        $bits .= EAN_R[(int) $code[$i]];
    }
    return $bits . '101';
}

// Code 128 symbol values. All-digit codes use subset C (two digits per symbol, so bars are
// wider on the same label), switching to subset A for a trailing odd digit. Anything else uses subset B.
function code128_values(string $text): array {
    if (preg_match('/^\d{4,}$/', $text)) {
        $values = [105];
        $even = strlen($text) - strlen($text) % 2;
        for ($i = 0; $i < $even; $i += 2) {
            $values[] = (int) substr($text, $i, 2);
        }
        if ($even < strlen($text)) {
            $values[] = 101; // switch to subset A (digits have the same value in A and B)
            $values[] = ord($text[$even]) - 32;
        }
        return $values;
    }
    $values = [104];
    foreach (str_split($text) as $char) {
        $values[] = ord($char) - 32;
    }
    return $values;
}

// Start, data symbols, mod-103 checksum, Stop — as a string of 1 (bar) / 0 (space) modules.
function code128_modules(string $text): string {
    $values = code128_values($text);
    $checksum = $values[0];
    foreach (array_slice($values, 1) as $i => $v) {
        $checksum += $v * ($i + 1);
    }
    $values[] = $checksum % 103;
    $values[] = 106;

    $bits = '';
    foreach ($values as $v) {
        foreach (str_split(CODE128_PATTERNS[$v]) as $i => $width) {
            $bits .= str_repeat($i % 2 === 0 ? '1' : '0', (int) $width);
        }
    }
    return $bits;
}

function modules_to_rects(string $bits, float $x0, float $y, float $height, array $tallRanges = [], float $tallExtra = 0): string {
    $rects = '';
    $len = strlen($bits);
    for ($i = 0; $i < $len; $i++) {
        if ($bits[$i] !== '1') {
            continue;
        }
        $start = $i;
        while ($i + 1 < $len && $bits[$i + 1] === '1') {
            $i++;
        }
        $h = $height;
        foreach ($tallRanges as [$from, $to]) {
            if ($start >= $from && $start < $to) {
                $h += $tallExtra;
            }
        }
        $rects .= '<rect x="' . ($x0 + $start) . '" y="' . $y . '" width="' . ($i - $start + 1) . '" height="' . $h . '"/>';
    }
    return $rects;
}

/**
 * Barcode as an SVG string in module units (scale it with CSS width/height).
 * Valid EAN-13 codes are drawn as EAN-13 with guard bars; anything else as Code 128.
 */
function barcode_svg(string $code, bool $withText = true, string $class = 'barcode-svg'): string {
    if (!is_printable_barcode($code)) {
        return '';
    }
    $esc = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $barHeight = 50;
    $font = 'font-family="ui-monospace, Consolas, monospace" font-size="9" text-anchor="middle" fill="#000"';

    if (is_valid_ean13($code)) {
        $quietLeft = 11;
        $width = $quietLeft + 95 + 7;
        $height = $withText ? $barHeight + 12 : $barHeight;
        // Guard bars run down into the text line, as on printed EAN-13 labels.
        $rects = modules_to_rects(ean13_modules($code), $quietLeft, 0, $withText ? $barHeight - 4 : $barHeight, [[0, 3], [45, 50], [92, 95]], $withText ? 6 : 0);
        $text = '';
        if ($withText) {
            $baseline = $barHeight + 7;
            $text = '<text x="5" y="' . $baseline . '" ' . $font . '>' . $code[0] . '</text>'
                . '<text x="' . ($quietLeft + 3 + 21) . '" y="' . $baseline . '" ' . $font . ' letter-spacing="1.6">' . substr($code, 1, 6) . '</text>'
                . '<text x="' . ($quietLeft + 50 + 21) . '" y="' . $baseline . '" ' . $font . ' letter-spacing="1.6">' . substr($code, 7, 6) . '</text>';
        }
    } else {
        $bits = code128_modules($code);
        $quiet = 10;
        $width = strlen($bits) + $quiet * 2;
        $height = $withText ? $barHeight + 12 : $barHeight;
        $rects = modules_to_rects($bits, $quiet, 0, $barHeight);
        $text = $withText ? '<text x="' . ($width / 2) . '" y="' . ($barHeight + 10) . '" ' . $font . '>' . $esc . '</text>' : '';
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 ' . $width . ' ' . $height . '"'
        . ' role="img" aria-label="Barcode ' . $esc . '" shape-rendering="crispEdges">'
        . '<rect width="' . $width . '" height="' . $height . '" fill="#fff"/><g fill="#000">' . $rects . '</g>' . $text . '</svg>';
}
