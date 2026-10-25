<?php
// "Ask the advisor": answers an admin's or manager's typed question from the AI Waste Advisor's analysis
// (includes/advisor.php). It matches the question to an item name or a question type and
// replies only with facts from this store's records. Anything else gets "I only answer from
// this store's records".

require_once __DIR__ . '/advisor_tools.php';

// Keywords per question type. A trailing * matches the start of a word ("expir*" matches "expiring").
const ADVISOR_INTENTS = [
    'returns' => ['return*', 'sent back', 'send back', 'credit*', 'refund*'],
    'suppliers' => ['supplier*', 'vendor*', 'distributor*'],
    'deliveries' => ['deliver*', 'dlv', 'shipment*', 'arriv*', 'incoming', 'coming', 'on the way', 'unpaid', 'paid', 'payment*', 'pay', 'darating'],
    'archive' => ['archiv*', 'removed', 'discontinued', 'restor*'],
    'users' => ['user*', 'account*', 'staff', 'employee*', 'manager*', 'admin*', 'cashier*', 'worker*', 'role*', 'pending', 'approv*',
        'who logged', 'who recorded', 'who added', 'who received', 'who scheduled'],
    'templates' => ['template*', 'barcode*', 'quick scan', 'scan*'],
    'report' => ['report*', 'compar*', 'versus', 'vs', 'trend*', 'monthly', 'per month', 'month by month', 'this year', 'yearly', 'analytics',
        'this month', 'last month', 'january', 'february', 'march', 'april', 'june', 'july', 'august', 'september', 'october', 'november',
        'december', 'jan', 'feb', 'mar', 'apr', 'jul', 'aug', 'sep', 'sept', 'oct', 'nov', 'dec'],
    'categories' => ['categor*', 'department*'],
    'storage' => ['storage', 'stored', 'fridge', 'chiller', 'freez*', 'refrigerat*', 'temperature', 'keep fresh'],
    'expiring' => ['expir*', 'best before', 'about to', 'going bad'],
    'forecast' => ['forecast*', 'predict*', 'next month', 'next week', 'future', 'projection*', 'will be wasted'],
    'slow' => ['slow*', 'overstock*', 'too much', 'too many', 'not selling', 'not moving', 'excess', 'unsold'],
    'order' => ['order*', 'reorder*', 'buy*', 'purchas*', 'restock*'],
    'score' => ['score', 'grade', 'rating', 'how are we doing', 'how am i doing', 'performance'],
    'reasons' => ['reason*', 'why', 'cause*'],
    'top' => ['most wasted', 'most waste*', 'top', 'worst', 'biggest', 'highest', 'which item*', 'what item*', 'which product*'],
    'totals' => ['how much', 'total*', 'lost', 'lose', 'loss*', 'cost*', 'spent', 'this month', 'last month', 'summary', 'money', 'peso*'],
    'today' => ['today', 'what should i do', 'what do i do', 'what to do', 'priorit*', 'urgent*', 'first', 'right now'],
    'inventory' => ['how many', 'available', 'in stock', 'inventory', 'on hand', 'stock level*', 'stocks', 'ilan', 'meron'],
    'zero' => ['zero waste', 'zero', 'reduce*', 'lessen', 'minimi*', 'cut waste', 'improve*', 'tips', 'advice', 'recommend*', 'suggest*', 'plan', 'prevent*', 'avoid*', 'how can we', 'how do we'],
    'help' => ['help', 'hello', 'hi', 'hey', 'what can you*', 'who are you', 'how do you work', 'how does this work'],
];

// Words in item names that say nothing about which product is meant.
const ADVISOR_NAME_STOPWORDS = ['the', 'and', 'for', 'with', 'pack', 'pcs', 'piece', 'pieces', 'per', 'fresh', 'large', 'small',
    'medium', 'big', 'box', 'bag', 'bottle', 'can', 'tray', 'dozen', 'local', 'premium', 'organic', 'whole', 'sliced', 'kilo'];

// Words in supplier names that say nothing about which supplier is meant.
const ADVISOR_SUPPLIER_STOPWORDS = ['co', 'inc', 'corp', 'corporation', 'company', 'trading', 'traders', 'trader', 'supply', 'supplies',
    'supplier', 'produce', 'enterprise', 'enterprises', 'store', 'foods', 'food', 'distributor', 'distributors', 'mart'];

const ADVISOR_SUGGESTIONS = [
    'How many items are in stock?',
    'What should I do today?',
    'How can we reach zero waste?',
    'What will be wasted next month?',
    'Which items do we waste most?',
    'Which supplier causes the most waste?',
    'What deliveries are coming up?',
    'How much should I order?',
];

function advisor_answer(string $question, array $a): string {
    $q = ' ' . trim((string) preg_replace('/[^\p{L}\p{N}%]+/u', ' ', mb_strtolower($question))) . ' ';
    if (trim($q) === '') {
        return advisor_render([['p', 'Type a question about your stock or waste, or pick one of the suggestions.']]);
    }
    // A delivery by reference: "DLV-00012", "delivery 12".
    if (preg_match('/\b(dlv|delivery|deliveries)\s+(?:no\s+|number\s+)?0*(\d{1,7})\b/u', $q, $m)) {
        $blocks = advisor_answer_delivery((int) $m[2]);
        if ($blocks !== null || $m[1] === 'dlv') {
            return advisor_render($blocks ?? [['p', 'There is no delivery ' . delivery_ref((int) $m[2]) . ' in the system.'], ['links', [['Deliveries', 'deliveries.php?tab=all']]]]);
        }
    }

    // An item, supplier or user by name. A supplier name wins over an item name only when the
    // question is about suppliers ("Golden Dairy deliveries" vs "Golden Dairy milk").
    $product = $a['products'] ? advisor_match_product($q, $a['products']) : null;
    $supplier = advisor_match_supplier($q);
    if ($supplier !== null && ($product === null || preg_match('/\b(supplier|vendor|distributor|deliver|contact|supplies|supply)/u', $q))) {
        return advisor_render(advisor_answer_supplier($supplier, $a));
    }
    if ($product !== null) {
        return advisor_render(advisor_answer_product($product['match'], $product['others'], $product['exact'], $a));
    }
    if (($person = advisor_match_user($q)) !== null) {
        return advisor_render(advisor_answer_user($person));
    }

    $best = null;
    $bestHits = 0;
    foreach (ADVISOR_INTENTS as $intent => $keywords) {
        $hits = 0;
        foreach ($keywords as $kw) {
            $pattern = '/\b' . preg_quote(rtrim($kw, '*'), '/') . (str_ends_with($kw, '*') ? '' : '\b') . '/u';
            $hits += preg_match($pattern, $q);
        }
        if ($hits > $bestHits) {
            [$best, $bestHits] = [$intent, $hits];
        }
    }

    // "How much bakery waste?", "Which dairy items are in stock?"
    $category = advisor_match_category($q);
    if ($category !== null && in_array($best, [null, 'categories', 'totals', 'top', 'inventory', 'reasons', 'expiring'], true)) {
        return advisor_render(advisor_answer_category($category));
    }

    // These answer straight from the records; the rest need inventory to learn from.
    if (!$a['products'] && !in_array($best, ['returns', 'suppliers', 'deliveries', 'archive', 'users', 'templates', 'report', 'categories', 'help'], true)) {
        return advisor_render([['p', 'There is no inventory in the system yet, so I have nothing to learn from. Add items, receive stock and log waste, and I\'ll start recommending.']]);
    }

    // "Which supplier wastes most?" gets the waste breakdown; "Which suppliers do we have?" the list.
    $aboutWaste = (bool) preg_match('/\b(wast|spoil|loss|lost|lose|expir|rot|worst|cost|money|peso)/u', $q);
    $blocks = match ($best) {
        'returns' => advisor_answer_returns(),
        'suppliers' => $aboutWaste ? advisor_answer_groups($a['suppliers'], 'supplier', $a['recommendations']) : advisor_answer_supplier_list($category),
        'deliveries' => advisor_answer_deliveries($q),
        'archive' => advisor_answer_archive(),
        'users' => advisor_answer_users($aboutWaste),
        'templates' => advisor_answer_templates(),
        'report' => advisor_answer_report($q),
        'categories' => $aboutWaste ? advisor_answer_groups($a['categories'], 'category', $a['recommendations']) : advisor_answer_category_list(),
        'storage' => advisor_answer_storage($a),
        'expiring' => advisor_answer_expiring($a),
        'forecast' => advisor_answer_forecast($a),
        'slow' => advisor_answer_slow($a),
        'order' => advisor_answer_order($a),
        'score' => advisor_answer_score($a),
        'reasons' => advisor_answer_reasons($a),
        'top' => advisor_answer_top($a),
        'totals' => advisor_answer_totals($a),
        'today' => advisor_answer_today($a),
        'inventory' => advisor_answer_inventory(),
        'zero' => advisor_answer_zero($a),
        'help' => [
            ['p', 'I\'m the ZeroWaste AI Advisor. I learn only from this store\'s records: inventory and the archive, stock received, sales, waste and reports, '
                . 'supplier returns, suppliers and deliveries, categories, product templates and user accounts. Nothing comes from outside the system.'],
            ['p', 'Ask me what to do today, what will expire unsold, how much to order, which items, reasons, suppliers or categories waste the most, '
                . 'which deliveries are coming or unpaid, what was returned or archived, who recorded what, or for the report of any month. '
                . 'You can also ask about an item, a supplier, a user or a delivery (DLV-00012) by name.'],
        ],
        default => [
            ['p', 'I only answer from this store\'s ZeroWaste records, and I couldn\'t connect that question to anything in them.'],
            ['p', 'Try asking about an item by name, or one of the suggestions below the chat.'],
        ],
    };
    return advisor_render($blocks);
}

// Blocks: ['p', text], ['ul', [text, ...]], ['ol', [text, ...]], ['links', [[label, href], ...]].
// Text is escaped; **double asterisks** become bold.
function advisor_render(array $blocks): string {
    $fmt = fn (string $text) => preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e($text));
    $html = '';
    foreach ($blocks as [$type, $content]) {
        if ($type === 'p') {
            $html .= '<p>' . $fmt($content) . '</p>';
        } elseif ($type === 'ul' || $type === 'ol') {
            $html .= "<$type>" . implode('', array_map(fn ($li) => '<li>' . $fmt($li) . '</li>', $content)) . "</$type>";
        } elseif ($type === 'links') {
            $html .= '<div class="ask-links">' . implode('', array_map(
                fn ($l) => '<a class="btn btn-sm" href="' . e($l[1]) . '">' . e($l[0]) . ' <i class="fa-solid fa-arrow-right"></i></a>',
                $content
            )) . '</div>';
        }
    }
    return $html;
}

// The product a question names, if any: ['match' => product, 'others' => [names]].
function advisor_match_product(string $q, array $products): ?array {
    $scored = [];
    foreach ($products as $p) {
        $name = trim((string) preg_replace('/[^\p{L}\p{N}%]+/u', ' ', mb_strtolower($p['name'])));
        if ($name !== '' && str_contains($q, ' ' . $name . ' ')) {
            $scored[] = [2.0, $p];
            continue;
        }
        $tokens = array_values(array_diff(
            // Sizes such as "5kg", "1l" or "30s" don't say which product is meant.
            array_filter(explode(' ', $name), fn ($t) => mb_strlen($t) >= 3 && !preg_match('/\d/', $t)),
            ADVISOR_NAME_STOPWORDS
        ));
        if (!$tokens) {
            continue;
        }
        $hits = 0;
        foreach ($tokens as $t) {
            $hits += preg_match('/\b' . preg_quote($t, '/') . '(s|es)?\b/u', $q);
        }
        if ($hits > 0 && $hits / count($tokens) >= 0.5) {
            $scored[] = [$hits / count($tokens), $p];
        }
    }
    if (!$scored) {
        return null;
    }
    usort($scored, fn ($x, $y) => [$y[0], (int) $y[1]['active'], $y[1]['value90']] <=> [$x[0], (int) $x[1]['active'], $x[1]['value90']]);
    $others = [];
    foreach (array_slice($scored, 1) as [$score, $p]) {
        if ($score >= $scored[0][0] && count($others) < 3) {
            $others[] = $p['name'];
        }
    }
    return ['match' => $scored[0][1], 'others' => $others, 'exact' => $scored[0][0] >= 1];
}

function advisor_answer_product(array $p, array $others, bool $exact, array $a): array {
    $facts = [];
    if ($p['batches']) {
        $next = $p['batches'][0];
        $facts[] = 'In stock: ' . advisor_qty($p['stock'], $p['unit']) . ' in ' . advisor_plural(count($p['batches']), 'batch')
            . '. Next expiry ' . advisor_day($next['expires'])
            . ' (' . advisor_plural(max(0, $next['days_left']), 'day') . ').';
    } else {
        $facts[] = $p['active'] ? 'Out of stock right now.' : 'No longer stocked (archived).';
    }
    $facts[] = 'Sales pace: ' . ($p['pace'] > 0 ? 'about ' : '') . advisor_pace($p['pace'], $p['unit']) . ', from ' . advisor_plural($p['days'], 'day') . ' of history ('
        . $p['confidence'] . ' confidence).';
    $facts[] = $p['waste90'] > 0
        ? 'Waste in the last ' . ADVISOR_WINDOW_DAYS . ' days: ' . advisor_qty($p['waste90'], $p['unit']) . ', ' . peso($p['value90'], 0)
            . ', mostly "' . $p['main_reason'] . '"' . ($p['waste_share'] !== null ? ' (' . number_format($p['waste_share'] * 100, 0) . '% of what was stocked).' : '.')
        : 'No waste logged in the last ' . ADVISOR_WINDOW_DAYS . ' days.';

    $left = array_values(array_filter($a['forecast'], fn ($r) => $r['product'] === $p['key']));
    if ($left) {
        $facts[] = 'Forecast: about ' . advisor_qty(array_sum(array_column($left, 'leftover')), $p['unit']) . ' will expire unsold. ' . $left[0]['action'];
    } elseif ($p['batches'] && !$p['too_new']) {
        $facts[] = 'Forecast: current stock should sell before it expires.';
    }
    if ($plan = $a['order_plan'][$p['key']] ?? null) {
        $facts[] = $plan['cap'] > 0
            ? 'Ordering: ' . ($plan['status'][2] === 1 ? 'once the old stock is cleared, ' : '') . 'order at most ' . advisor_qty($plan['cap'], $p['unit'])
                . ' at a time. It sells ' . advisor_pace($p['pace'], $p['unit']) . ' and ' . advisor_keeps($plan['shelf']) . '.'
            : 'Ordering: don\'t reorder yet. Current stock covers what sells within its ' . advisor_plural($plan['shelf'], 'day') . ' shelf life.';
    }
    foreach ($a['recommendations'] as $rec) {
        if ($rec['type'] === 'storage' && $rec['product'] === $p['key']) {
            $facts[] = 'Storage: ' . $rec['why'] . ' ' . $rec['actions'][0];
        }
    }

    $blocks = $exact ? [] : [['p', 'Closest item name in the system:']];
    $blocks[] = ['p', '**' . $p['name'] . '** · ' . $p['category'] . ' · ' . ($p['supplier'] ? 'supplier ' . $p['supplier'] : 'no supplier linked')];
    $blocks[] = ['ul', $facts];
    if ($others) {
        $blocks[] = ['p', 'Also matching: ' . implode(', ', $others) . '. Ask by full name for those.'];
    }
    $blocks[] = ['links', [['Open in Food Items', 'food_items.php?q=' . urlencode($p['name'])], ['Waste history', 'waste_history.php?q=' . urlencode($p['name'])]]];
    return $blocks;
}

function advisor_answer_today(array $a): array {
    $todo = array_values(array_filter($a['recommendations'], fn ($r) => $r['priority'] !== 'good'));
    if (!$todo) {
        return [['p', 'Nothing needs doing right now: stock is on track to sell before it expires and nothing stands out in the last ' . ADVISOR_WINDOW_DAYS . ' days. Keep logging waste the day it happens.']];
    }
    $lines = array_map(fn ($r) => '**' . rtrim($r['title'], '.') . '**. ' . ($r['actions'][0] ?? $r['why']), array_slice($todo, 0, 3));
    return [
        ['p', 'Here is what will cut the most waste right now, based on today\'s stock and the last ' . ADVISOR_WINDOW_DAYS . ' days:'],
        ['ol', $lines],
        ['p', count($todo) > 3 ? 'There are ' . (count($todo) - 3) . ' more recommendations on this page.' : 'That\'s everything for now.'],
    ];
}

function advisor_answer_zero(array $a): array {
    $blocks = [];
    if ($s = $a['score']) {
        $blocks[] = ['p', 'Your Zero-Waste Score is **' . $s['score'] . '/100 (' . $s['grade'][1] . ')**. Waste was ' . number_format($s['rate'], 1)
            . '% of stock cost in the last ' . $s['rate_span'] . ' days; near zero waste means ' . ADVISOR_TARGET_RATE . '% or less.'];
    }
    $todo = array_values(array_filter($a['recommendations'], fn ($r) => $r['priority'] !== 'good'));
    if ($todo) {
        $blocks[] = ['p', 'The plan, most important first:'];
        $blocks[] = ['ol', array_map(fn ($r) => '**' . $r['title'] . '**' . ($r['impact'] ? ' (' . peso($r['impact'], 0) . ' ' . $r['impact_label'] . ')' : ''), array_slice($todo, 0, 5))];
    } else {
        $blocks[] = ['p', 'There is nothing to fix right now. Keep doing what you are doing.'];
    }
    $blocks[] = ['p', 'Work down the zero-waste ladder: order only what sells, mark down what is close to expiry, donate what can\'t be sold, and compost the rest. The bin is the last resort.'];
    return $blocks;
}

function advisor_answer_expiring(array $a): array {
    $lines = [];
    foreach ($a['products'] as $p) {
        foreach ($p['batches'] as $b) {
            if ($b['days_left'] > ADVISOR_URGENT_DAYS) {
                continue;
            }
            $lines[$b['days_left'] * 100000 + $b['id']] = '**' . $p['name'] . '**: ' . advisor_qty($b['quantity'], $p['unit']) . ', expires ' . advisor_day($b['expires']) . '. '
                . ($b['leftover'] > 0 ? 'About ' . $b['leftover'] . ' won\'t sell in time. ' . advisor_batch_action($b) : 'Should sell in time.');
        }
    }
    if (!$lines) {
        return [['p', 'Nothing in stock expires in the next ' . ADVISOR_URGENT_DAYS . ' days.']];
    }
    ksort($lines);
    return [
        ['p', count($lines) . ' batch' . (count($lines) === 1 ? '' : 'es') . ' expire in the next ' . ADVISOR_URGENT_DAYS . ' days:'],
        ['ul', array_slice(array_values($lines), 0, 8)],
        ['links', [['Expiring Soon', 'food_items.php?status=expiring']]],
    ];
}

function advisor_answer_forecast(array $a): array {
    if (!$a['forecast']) {
        return [['p', 'Good news: at the current sales pace, everything in stock should sell before it expires in the next ' . ADVISOR_HORIZON_DAYS . ' days.']];
    }
    $lines = array_map(fn ($r) => '**' . $r['name'] . '**: about ' . advisor_qty($r['leftover'], $r['unit']) . ' by ' . advisor_day($r['expires']) . ' (' . peso($r['value'], 0) . ')', array_slice($a['forecast'], 0, 6));
    return [
        ['p', 'If nothing changes, about **' . number_format($a['at_risk_units']) . ' units (' . peso($a['at_risk_value'], 0) . ')** will expire unsold in the next '
            . ADVISOR_HORIZON_DAYS . ' days. For comparison, ' . peso($a['totals']['value30'], 0) . ' was wasted in the last 30 days.'],
        ['ul', $lines],
        ['p', 'The forecast assumes each item keeps selling at its usual pace and the oldest batch sells first. Acting on the recommendations brings this number down.'],
    ];
}

function advisor_answer_top(array $a): array {
    $wasted = array_filter($a['products'], fn ($p) => $p['waste90'] > 0);
    if (!$wasted) {
        return [['p', 'No waste has been logged in the last ' . ADVISOR_WINDOW_DAYS . ' days.']];
    }
    usort($wasted, fn ($x, $y) => [$y['value90'], $y['waste90']] <=> [$x['value90'], $x['waste90']]);
    return [
        ['p', 'Most wasted items in the last ' . ADVISOR_WINDOW_DAYS . ' days, by value:'],
        ['ol', array_map(fn ($p) => '**' . $p['name'] . '**: ' . advisor_qty($p['waste90'], $p['unit']) . ', ' . peso($p['value90'], 0) . ', mostly "' . $p['main_reason'] . '"', array_slice($wasted, 0, 5))],
        ['links', [['Reports', 'reports.php?period=90d']]],
    ];
}

function advisor_answer_reasons(array $a): array {
    if (!$a['reasons']) {
        return [['p', 'No waste has been logged in the last ' . ADVISOR_WINDOW_DAYS . ' days.']];
    }
    $total = max(0.01, array_sum(array_map(fn ($r) => (float) $r['value'], $a['reasons'])));
    $blocks = [
        ['p', 'Why stock was wasted in the last ' . ADVISOR_WINDOW_DAYS . ' days:'],
        ['ul', array_map(fn ($r) => '**' . $r['reason'] . '**: ' . number_format((int) $r['units']) . ' units, ' . peso($r['value'], 0) . ' (' . number_format((float) $r['value'] / $total * 100, 0) . '%)', array_slice($a['reasons'], 0, 6))],
    ];
    $top = $a['reasons'][0]['reason'];
    if (isset(ADVISOR_PLAYBOOKS[$top])) {
        $blocks[] = ['p', 'To fix the biggest one: ' . ADVISOR_PLAYBOOKS[$top]['actions'][0]];
    }
    return $blocks;
}

function advisor_answer_groups(array $groups, string $kind, array $recommendations): array {
    $groups = array_values(array_filter($groups, fn ($g) => $g['value'] > 0 || $g['unrecovered'] > 0));
    if (!$groups) {
        return [['p', 'No waste has been logged in the last ' . ADVISOR_WINDOW_DAYS . ' days.']];
    }
    $lines = array_map(function ($g) {
        return '**' . $g['name'] . '**: ' . peso($g['value'], 0) . ' wasted'
            . ($g['rate'] !== null ? ' (' . number_format($g['rate'], 1) . '% of the ' . peso($g['cost'], 0) . ' received)' : '')
            . ($g['unrecovered'] >= 1 ? '; ' . peso($g['unrecovered'], 0) . ' of returns not credited' : '');
    }, array_slice($groups, 0, 6));
    $blocks = [['p', 'Waste by ' . $kind . ' in the last ' . ADVISOR_WINDOW_DAYS . ' days:'], ['ul', $lines]];
    foreach ($recommendations as $rec) {
        if ($kind === 'supplier' && $rec['type'] === 'supplier') {
            $blocks[] = ['p', '**' . rtrim($rec['title'], '.') . '**. ' . implode(' ', $rec['actions'])];
        }
    }
    return $blocks;
}

function advisor_answer_storage(array $a): array {
    $recs = array_values(array_filter($a['recommendations'], fn ($r) => $r['type'] === 'storage'));
    if (!$recs) {
        return [['p', 'No storage problems found: none of the items with spoiled or expired waste are kept warm when the shelf-life profiles say they would last much longer chilled.']];
    }
    return [['ul', array_map(fn ($r) => '**' . rtrim($r['title'], '.') . '**. ' . $r['why'], $recs)]];
}

function advisor_answer_slow(array $a): array {
    $lines = array_map(fn ($r) => '**' . $r['name'] . '**: sells ' . advisor_pace($r['pace'], $r['unit']) . ', ' . $r['quantity'] . ' in stock, about ' . $r['leftover'] . ' left over by ' . advisor_day($r['expires']),
        array_slice(array_values(array_filter($a['forecast'], fn ($r) => $r['days_left'] > ADVISOR_URGENT_DAYS)), 0, 6));
    foreach ($a['products'] as $p) {
        if ($p['stock'] > 0 && $p['used'] === 0 && $p['days'] >= 14) {
            $lines[] = '**' . $p['name'] . '**: ' . advisor_qty($p['stock'], $p['unit']) . ' in stock and none sold in ' . $p['days'] . ' days';
        }
    }
    if (!$lines) {
        return [['p', 'Nothing is overstocked: every item is on track to sell before it expires.']];
    }
    return [['p', 'Slow movers and overstock. Hold reorders on these until they sell down:'], ['ul', array_slice($lines, 0, 8)]];
}

function advisor_answer_order(array $a): array {
    if (!$a['order_plan']) {
        return [['p', 'There isn\'t enough sales history yet to size orders. Once items have sold for a week or two, I can work out safe order sizes.']];
    }
    $lines = array_map(function ($o) {
        $what = $o['cap'] === 0 ? 'don\'t order yet' : 'order at most ' . advisor_qty($o['cap'], $o['unit'])
            . ($o['status'][2] === 1 ? ' once the old stock is cleared' : '');
        return '**' . $o['name'] . '**: ' . $what . ' (sells ' . advisor_pace($o['pace'], $o['unit']) . ', keeps ~' . advisor_plural($o['shelf'], 'day') . ')';
    }, array_slice($a['order_plan'], 0, 8));
    return [
        ['p', 'Safe order sizes: never more than sells within one shelf life, minus what is already on the shelf.'],
        ['ul', $lines],
        ['p', 'Ask about one item by name for its details.'],
    ];
}

function advisor_answer_inventory(): array {
    $s = array_map('floatval', db_one(
        'SELECT COUNT(*) AS records, COUNT(DISTINCT LOWER(TRIM(name))) AS products, COALESCE(SUM(quantity), 0) AS units,
                COALESCE(SUM(quantity > 0), 0) AS in_stock, COALESCE(SUM(quantity = 0), 0) AS out_of_stock,
                COALESCE(SUM(quantity > 0 AND quantity <= ?), 0) AS low_stock,
                COALESCE(SUM(quantity > 0 AND expiration_date BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY), 0) AS expiring,
                COALESCE(SUM(quantity * COALESCE(purchase_price, 0)), 0) AS value
         FROM food_items WHERE archived_at IS NULL',
        'ii',
        [LOW_STOCK_THRESHOLD, EXPIRING_SOON_DAYS]
    ));
    return [
        ['p', '**' . advisor_plural((int) $s['in_stock'], 'item') . ' available** right now: ' . number_format($s['units']) . ' units in stock, worth '
            . peso($s['value'], 0) . ' at purchase price.'],
        ['ul', [
            number_format($s['records']) . ' item records in inventory (' . advisor_plural((int) $s['products'], 'product') . ', counting each batch separately)',
            number_format($s['out_of_stock']) . ' out of stock, ' . number_format($s['low_stock']) . ' low (' . LOW_STOCK_THRESHOLD . ' units or fewer)',
            number_format($s['expiring']) . ' expiring within ' . EXPIRING_SOON_DAYS . ' days',
        ]],
        ['links', [['Food Items', 'food_items.php'], ['Low Stock', 'food_items.php?status=low']]],
    ];
}

function advisor_answer_score(array $a): array {
    $s = $a['score'];
    if (!$s) {
        return [['p', 'There isn\'t enough data for a score yet. Receive some stock and log waste first.']];
    }
    return [
        ['p', 'Zero-Waste Score: **' . $s['score'] . '/100**, grade ' . $s['grade'][0] . ' (' . $s['grade'][1] . ').'],
        ['ul', array_map(fn ($p) => '**' . $p['label'] . '**: ' . number_format($p['points'], 0) . ' of ' . $p['max'] . '. ' . $p['detail'], $s['parts'])],
    ];
}

function advisor_answer_totals(array $a): array {
    $t = $a['totals'];
    $change = $t['value_prev30'] > 0 ? ($t['value30'] - $t['value_prev30']) / $t['value_prev30'] * 100 : null;
    $rate90 = $t['cost90'] > 0 ? $t['value90'] / $t['cost90'] * 100 : null;
    return [['ul', [
        'Last 30 days: **' . peso($t['value30'], 0) . '** (' . number_format($t['units30']) . ' units)'
            . ($change !== null ? ', ' . ($change <= 0 ? 'down ' : 'up ') . number_format(abs($change), 0) . '% on the 30 days before.' : '.'),
        'This month so far: ' . peso($t['month'], 0) . '. Last month: ' . peso($t['last_month'], 0) . '.',
        'Last ' . ADVISOR_WINDOW_DAYS . ' days: ' . peso($t['value90'], 0) . ' across ' . number_format($t['entries90']) . ' waste entries'
            . ($rate90 !== null ? ', ' . number_format($rate90, 1) . '% of the ' . peso($t['cost90'], 0) . ' of stock received.' : '.'),
        'Forecast: ' . peso($a['at_risk_value'], 0) . ' more will expire unsold in the next ' . ADVISOR_HORIZON_DAYS . ' days if nothing changes.',
    ]], ['links', [['Reports', 'reports.php']]]];
}

/* ---------- Answers straight from the records: suppliers, deliveries, returns, archive, users, reports ---------- */

// "Oct 20, 2026 (5 days ago)"
function advisor_ago(?string $date): string {
    if (!$date) {
        return 'never';
    }
    $days = (int) (new DateTimeImmutable(substr($date, 0, 10)))->diff(new DateTimeImmutable('today'))->format('%r%a');
    return date('M j, Y', strtotime($date)) . ' (' . match (true) {
        $days <= 0 => 'today',
        $days === 1 => 'yesterday',
        default => $days . ' days ago',
    } . ')';
}

// The supplier a question names: its full name, or every telling word of it ("Golden Dairy").
function advisor_match_supplier(string $q): ?array {
    $compact = str_replace(' ', '', $q);
    foreach (db_all('SELECT id, name FROM suppliers ORDER BY CHAR_LENGTH(name) DESC') as $s) {
        $name = trim((string) preg_replace('/[^\p{L}\p{N}%]+/u', ' ', mb_strtolower($s['name'])));
        if ($name === '') {
            continue;
        }
        if (str_contains($q, ' ' . $name . ' ')) {
            return $s;
        }
        $tokens = array_diff(array_filter(explode(' ', $name), fn ($t) => mb_strlen($t) >= 3), ADVISOR_SUPPLIER_STOPWORDS, ADVISOR_NAME_STOPWORDS);
        if (!$tokens) {
            continue;
        }
        foreach ($tokens as $t) {
            // "fresh farm" still finds "FreshFarm".
            if (!preg_match('/\b' . preg_quote($t, '/') . '(s|es)?\b/u', $q) && !(mb_strlen($t) >= 6 && str_contains($compact, $t))) {
                continue 2;
            }
        }
        return $s;
    }
    return null;
}

// The user account a question names by its full username.
function advisor_match_user(string $q): ?array {
    foreach (db_all('SELECT id, username FROM users ORDER BY CHAR_LENGTH(username) DESC') as $u) {
        $name = trim((string) preg_replace('/[^\p{L}\p{N}%]+/u', ' ', mb_strtolower($u['username'])));
        if (mb_strlen($name) >= 3 && !in_array($name, ['admin', 'manager', 'staff', 'user', 'users'], true) && str_contains($q, ' ' . $name . ' ')) {
            return $u;
        }
    }
    return null;
}

function advisor_answer_supplier(array $s, array $a): array {
    $info = current(array_filter(advisor_tool_suppliers(['query' => $s['name']])['suppliers'], fn ($r) => $r['name'] === $s['name']));
    $open = advisor_tool_deliveries(['status' => 'open', 'supplier' => $s['name'], 'limit' => 3])['deliveries'];
    $returns = advisor_tool_supplier_returns(['supplier' => $s['name']])['totals'];
    $waste = current(array_filter($a['suppliers'], fn ($g) => $g['id'] === (int) $s['id']));

    $facts = [];
    if ($contact = implode(' · ', array_filter([$info['contact_person'], $info['phone'], $info['email']]))) {
        $facts[] = 'Contact: ' . $contact;
    }
    if ($info['address']) {
        $facts[] = 'Address: ' . $info['address'];
    }
    $facts[] = $info['products_they_deliver']
        ? 'Delivers: ' . implode(', ', $info['products_they_deliver'])
        : 'No products listed for them yet. Add them on the Suppliers page.';
    $facts[] = $info['last_delivery_or_stock_received']
        ? 'Last delivery or stock received: ' . advisor_ago($info['last_delivery_or_stock_received'])
        : 'Nothing has been received from them yet.';
    $facts[] = $open
        ? 'Next delivery: ' . advisor_delivery_line($open[0]) . ($info['open_deliveries'] > 1 ? ' (' . $info['open_deliveries'] . ' open in all)' : '')
        : 'No delivery from them is scheduled.';
    $facts[] = 'In stock now: ' . advisor_plural($info['active_items'], 'item') . ', ' . number_format($info['units_in_stock']) . ' units.';
    $facts[] = $waste && $waste['value'] > 0
        ? 'Waste in the last ' . ADVISOR_WINDOW_DAYS . ' days: ' . peso($waste['value'], 0)
            . ($waste['rate'] !== null ? ' (' . number_format($waste['rate'], 1) . '% of the ' . peso($waste['cost'], 0) . ' received)' : '') . '.'
        : 'No waste from their items in the last ' . ADVISOR_WINDOW_DAYS . ' days.';
    if ($returns['returns']) {
        $facts[] = 'Returned to them in the last 90 days: ' . advisor_plural($returns['returns'], 'return') . ', ' . peso($returns['stock_value'], 0)
            . ' of stock, ' . peso($returns['credit_received'], 0) . ' credited'
            . ($returns['not_credited'] >= 1 ? ', **' . peso($returns['not_credited'], 0) . ' not credited**' : '') . '.';
    }
    return [
        ['p', '**' . $s['name'] . '**'],
        ['ul', $facts],
        ['links', [['Suppliers', 'suppliers.php'], ['Their deliveries', 'deliveries.php?tab=all&supplier=' . (int) $s['id']]]],
    ];
}

function advisor_answer_supplier_list(?array $category = null): array {
    $rows = advisor_tool_suppliers([])['suppliers'];
    if (!$rows) {
        return [['p', 'No suppliers have been added yet.'], ['links', [['Suppliers', 'suppliers.php']]]];
    }
    if ($category !== null) {
        $names = advisor_category_suppliers((int) $category['id']);
        $rows = array_values(array_filter($rows, fn ($s) => in_array($s['name'], $names, true)));
        if (!$rows) {
            return [['p', 'No supplier is linked to ' . $category['name'] . ' yet. On the Suppliers page, list the products each supplier delivers.'], ['links', [['Suppliers', 'suppliers.php']]]];
        }
    }
    $lines = array_map(function ($s) {
        // "Carrots (Vegetables)" → "Vegetables"
        $categories = array_unique(array_map(fn ($p) => preg_match('/\(([^()]+)\)$/', $p, $m) ? $m[1] : $p, $s['products_they_deliver']));
        return '**' . $s['name'] . '**: '
            . ($categories ? implode(', ', $categories) . ' (' . advisor_plural(count($s['products_they_deliver']), 'product') . ')' : 'no products listed')
            . ' · last delivery ' . advisor_ago($s['last_delivery_or_stock_received'])
            . ($s['open_deliveries'] ? ' · ' . advisor_plural($s['open_deliveries'], 'open delivery') : '');
    }, $rows);
    return [
        ['p', $category !== null
            ? advisor_plural(count($rows), 'supplier') . ' for ' . $category['name'] . ' (listed as delivering it, or we have received ' . $category['name'] . ' items from them):'
            : advisor_plural(count($rows), 'supplier') . ', what each one delivers and when we last did business with them:'],
        ['ul', array_slice($lines, 0, 10)],
        ['p', 'Ask about a supplier by name for its contact details, products, deliveries and waste.'],
        ['links', [['Suppliers', 'suppliers.php']]],
    ];
}

function advisor_answer_category_list(): array {
    $data = advisor_tool_categories();
    $lines = array_map(fn ($c) => '**' . $c['category'] . '**: ' . advisor_plural($c['active_items'], 'item') . ', '
        . number_format($c['units_in_stock']) . ' units in stock (' . peso($c['stock_value'], 0) . ')'
        . ($c['suppliers_who_deliver_it'] ? ' · from ' . implode(', ', $c['suppliers_who_deliver_it']) : '')
        . ' · ' . ($c['waste_last_90_days']['value'] > 0 ? peso($c['waste_last_90_days']['value'], 0) . ' wasted' : 'no waste') . ' in ' . ADVISOR_WINDOW_DAYS . ' days',
        $data['categories']);
    return [
        ['p', advisor_plural(count($lines), 'category') . ':'],
        ['ul', $lines],
        ['p', 'Ask "which category wastes the most" for the waste breakdown. New categories can be added from the Suppliers page.'],
        ['links', [['Food Items', 'food_items.php']]],
    ];
}

// "DLV-00012 · FreshFarm Produce · Carrots, Tomato · Sun, Oct 27 2:00 PM · Scheduled · ₱1,200 unpaid"
function advisor_delivery_line(array $d): string {
    return '**' . $d['reference'] . '** · ' . $d['supplier'] . ' · ' . mb_strimwidth($d['items'], 0, 60, '…')
        . ' · ' . ($d['delivered_at']
            ? 'delivered ' . date('M j', strtotime($d['delivered_at']))
            : advisor_day($d['expected_date']) . ($d['expected_time'] ? ' ' . date('g:i A', strtotime($d['expected_time'])) : '') . ' · ' . $d['status'])
        . ($d['overdue'] ? ' (overdue)' : '')
        . ($d['amount'] !== null ? ' · ' . peso($d['amount'], 0) . ' ' . $d['payment'] : '');
}

function advisor_answer_delivery(int $id): ?array {
    try {
        $d = advisor_tool_deliveries(['reference' => (string) $id])['delivery'];
    } catch (InvalidArgumentException) {
        return null;
    }
    $facts = [
        'Items: ' . $d['items'],
        ($d['delivered_at'] ? 'Delivered ' . date('M j, Y g:i A', strtotime($d['delivered_at'])) . '; expected ' : 'Expected ')
            . advisor_day($d['expected_date']) . ($d['expected_time'] ? ' at ' . date('g:i A', strtotime($d['expected_time'])) : '')
            . ($d['overdue'] ? ', **overdue**' : '') . '.',
        $d['amount'] !== null
            ? 'Amount: ' . peso($d['amount']) . ', ' . $d['payment'] . ($d['paid_at'] ? ' on ' . date('M j, Y', strtotime($d['paid_at'])) : '') . '.'
            : 'No amount recorded.',
    ];
    if ($d['notes']) {
        $facts[] = 'Notes: ' . $d['notes'];
    }
    $log = array_map(fn ($e) => date('M j, g:i A', strtotime($e['when'])) . ': ' . $e['what'] . ($e['by'] ? ' by ' . $e['by'] : '') . ($e['note'] ? ' (' . $e['note'] . ')' : ''),
        $d['transaction_log']);
    return array_merge(
        [['p', '**' . $d['reference'] . '** from **' . $d['supplier'] . '**: ' . $d['status'] . '.'], ['ul', $facts]],
        $log ? [['p', 'Transaction log:'], ['ul', $log]] : [],
        [['links', [['Open delivery', 'deliveries.php?tab=all&view=' . $id]]]]
    );
}

function advisor_answer_deliveries(string $q): array {
    $open = advisor_tool_deliveries(['status' => 'open', 'limit' => 8]);
    $c = $open['counts'];
    $count = $c['scheduled'] + $c['on_the_way'];
    $parts = array_filter([
        $c['scheduled'] ? $c['scheduled'] . ' scheduled' : '',
        $c['on_the_way'] ? $c['on_the_way'] . ' on the way' : '',
        $c['due_today'] ? $c['due_today'] . ' due today' : '',
        $c['due_tomorrow'] ? $c['due_tomorrow'] . ' due tomorrow' : '',
    ]);
    $coming = [['p', $count
        ? '**' . advisor_plural($count, 'delivery') . ' coming**: ' . implode(', ', $parts)
            . ($c['overdue'] ? '. **' . $c['overdue'] . ' overdue**: the expected date has passed and it hasn\'t shipped.' : '.')
        : 'No deliveries are scheduled or on the way right now.']];
    if ($open['deliveries']) {
        $coming[] = ['ul', array_map('advisor_delivery_line', $open['deliveries'])];
    }
    $unpaid = [];
    if ($c['delivered_but_unpaid']) {
        $delivered = delivery_status_meta('delivered')[0];
        $rows = array_filter(advisor_tool_deliveries(['status' => 'unpaid', 'limit' => 20])['deliveries'], fn ($d) => $d['status'] === $delivered);
        $unpaid[] = ['p', '**' . peso($c['unpaid_amount'], 0) . ' still to pay** for ' . advisor_plural($c['delivered_but_unpaid'], 'delivered delivery') . ':'];
        $unpaid[] = ['ul', array_map('advisor_delivery_line', array_slice(array_values($rows), 0, 5))];
    } elseif (preg_match('/\b(unpaid|paid|pay|payment)/u', $q)) {
        $unpaid[] = ['p', 'Every delivered delivery has been paid.'];
    }
    $blocks = preg_match('/\b(unpaid|paid|pay|payment)/u', $q) ? array_merge($unpaid, $coming) : array_merge($coming, $unpaid);
    if ($last = advisor_tool_deliveries(['status' => 'delivered', 'limit' => 1])['deliveries'][0] ?? null) {
        $blocks[] = ['p', 'Last delivered: ' . $last['reference'] . ' from ' . $last['supplier'] . ' on ' . advisor_ago($last['delivered_at'] ?? $last['expected_date']) . '.'];
    }
    $blocks[] = ['links', [['Deliveries', 'deliveries.php']]];
    return $blocks;
}

function advisor_answer_returns(): array {
    $r = advisor_tool_supplier_returns(['limit' => ADVISOR_TOOL_MAX_ROWS]);
    $t = $r['totals'];
    if (!$t['returns']) {
        return [['p', 'No stock has been returned to suppliers in the last 90 days.'], ['links', [['Returns', 'returns.php']]]];
    }
    $blocks = [['p', 'In the last 90 days, **' . advisor_plural($t['returns'], 'return') . '** (' . number_format($t['units']) . ' units) went back to suppliers: '
        . peso($t['stock_value'], 0) . ' of stock, ' . peso($t['credit_received'], 0) . ' credited'
        . ($t['not_credited'] >= 1 ? ', **' . peso($t['not_credited'], 0) . ' not credited yet**.' : '.')]];
    $bySupplier = [];
    foreach ($r['returns'] as $row) {
        $g = $bySupplier[$row['supplier']] ?? ['n' => 0, 'value' => 0, 'credit' => 0];
        $bySupplier[$row['supplier']] = ['n' => $g['n'] + 1, 'value' => $g['value'] + $row['stock_value'], 'credit' => $g['credit'] + $row['credit']];
    }
    if (count($bySupplier) > 1) {
        uasort($bySupplier, fn ($x, $y) => $y['value'] <=> $x['value']);
        $blocks[] = ['ul', array_map(fn ($name, $g) => '**' . $name . '**: ' . advisor_plural($g['n'], 'return') . ', ' . peso($g['value'], 0) . ' of stock, '
            . peso($g['credit'], 0) . ' credited', array_keys($bySupplier), $bySupplier)];
    }
    $blocks[] = ['p', 'Most recent:'];
    $blocks[] = ['ul', array_map(fn ($row) => '**' . $row['item'] . '**: ' . advisor_qty($row['quantity'], $row['unit']) . ' to ' . $row['supplier']
        . ' on ' . date('M j', strtotime($row['date'])) . ', ' . peso($row['credit'], 0) . ' credited' . ($row['reason'] ? ' (' . $row['reason'] . ')' : '')
        . ', recorded by ' . $row['recorded_by'], array_slice($r['returns'], 0, 5))];
    $blocks[] = ['links', [['Returns', 'returns.php']]];
    return $blocks;
}

function advisor_answer_archive(): array {
    $r = advisor_tool_archive(['limit' => 8]);
    $t = $r['totals'];
    if (!$t['archived_items']) {
        return [['p', 'The archive is empty. Items move there when they expire, when staff archive them, or when an item with history is removed.']];
    }
    $parts = array_filter([
        $t['expired'] ? $t['expired'] . ' expired' : '',
        $t['archived_by_staff'] ? $t['archived_by_staff'] . ' archived by staff' : '',
        $t['removed_with_history'] ? $t['removed_with_history'] . ' removed with history' : '',
    ]);
    return [
        ['p', '**' . advisor_plural($t['archived_items'], 'item') . '** in the archive: ' . implode(', ', $parts) . '. Their lifetime waste: ' . peso($t['lifetime_waste_value'], 0) . '.'],
        ['p', 'Most recently archived:'],
        ['ul', array_map(fn ($i) => '**' . $i['name'] . '** (' . $i['category'] . '): ' . $i['reason'] . ', archived ' . date('M j', strtotime($i['archived_on']))
            . ($i['last_expiry'] ? ' (expiry date ' . date('M j', strtotime($i['last_expiry'])) . ')' : '')
            . ($i['units_left'] > 0 ? ', ' . advisor_qty($i['units_left'], $i['unit']) . ' left' : '')
            . ($i['lifetime_waste']['value'] > 0 ? ', ' . peso($i['lifetime_waste']['value'], 0) . ' wasted in all' : ''), $r['items'])],
        ['p', 'Archived items are hidden from inventory but keep their history for reports. They can be restored from the Archive page.'],
        ['links', [['Archive', 'archive.php']]],
    ];
}

// "12 waste entries (₱1,200), 3 returns, 10 stock receipts · last active Oct 20, 2026 (5 days ago)"
function advisor_user_activity(array $u): string {
    $did = array_filter([
        $u['waste_entries_logged'] ? advisor_plural($u['waste_entries_logged'], 'waste entry') . ' (' . peso($u['waste_value_logged'], 0) . ')' : '',
        $u['supplier_returns_recorded'] ? advisor_plural($u['supplier_returns_recorded'], 'return') : '',
        $u['stock_receipts_recorded'] ? advisor_plural($u['stock_receipts_recorded'], 'stock receipt') : '',
        $u['deliveries_scheduled'] ? advisor_plural($u['deliveries_scheduled'], 'delivery') . ' scheduled' : '',
    ]);
    return ($did ? implode(', ', $did) : 'nothing recorded yet') . ($u['last_activity'] ? ' · last active ' . advisor_ago($u['last_activity']) : '');
}

function advisor_answer_users(bool $byWaste = false): array {
    $users = advisor_tool_users([])['users'];
    if ($byWaste) {
        usort($users, fn ($x, $y) => [$y['waste_value_logged'], $y['waste_entries_logged']] <=> [$x['waste_value_logged'], $x['waste_entries_logged']]);
    }
    $roles = array_count_values(array_column($users, 'role'));
    $inactive = count(array_filter($users, fn ($u) => !str_starts_with($u['status'], 'Active')));
    return [
        ['p', '**' . advisor_plural(count($users), 'user account') . '**: ' . implode(', ', array_map(fn ($role, $n) => $n . ' ' . strtolower($role) . ($n === 1 || $role === 'Staff' ? '' : 's'), array_keys($roles), $roles))
            . ($inactive ? '. **' . $inactive . ' inactive** (waiting for approval or deactivated).' : '. All are active; none is waiting for approval.')],
        ['ul', array_map(fn ($u) => '**' . $u['username'] . '** · ' . $u['role'] . (str_starts_with($u['status'], 'Active') ? '' : ' · inactive') . ': ' . advisor_user_activity($u), array_slice($users, 0, 12))],
        ['p', 'Staff can view and print; managers manage stock, waste, suppliers and staff accounts; admins can do everything. Emails and passwords are never shown here.'],
        ['links', [['User accounts', 'admin_panel.php']]],
    ];
}

function advisor_answer_user(array $person): array {
    $u = current(array_filter(advisor_tool_users(['query' => $person['username']])['users'], fn ($r) => $r['username'] === $person['username']));
    $facts = [
        $u['status'] . ', joined ' . date('M j, Y', strtotime($u['joined'])) . '.',
        'Waste logged by hand: ' . advisor_plural($u['waste_entries_logged'], 'entry') . ($u['waste_entries_logged'] ? ', ' . peso($u['waste_value_logged'], 0) : '') . '.',
        'Supplier returns recorded: ' . $u['supplier_returns_recorded'] . '. Stock receipts recorded: ' . $u['stock_receipts_recorded'] . '. Deliveries scheduled: ' . $u['deliveries_scheduled'] . '.',
    ];
    if ($u['expired_stock_cleared_automatically_under_their_name']) {
        $facts[] = advisor_plural($u['expired_stock_cleared_automatically_under_their_name'], 'expired batch') . ' cleared automatically by the system under their name.';
    }
    $facts[] = 'Last activity: ' . advisor_ago($u['last_activity']) . '. Sign-in times are not recorded.';
    return [
        ['p', '**' . $u['username'] . '** · ' . $u['role']],
        ['ul', $facts],
        ['links', [['User accounts', 'admin_panel.php'], ['Waste they logged', 'waste_history.php?q=' . urlencode($u['username'])]]],
    ];
}

function advisor_answer_templates(): array {
    $r = advisor_tool_product_templates(['limit' => 8]);
    if (!$r['total']) {
        return [['p', 'No product templates yet. When you add an item with a barcode, tick "Save as product template" so Quick Scan can fill it in next time.']];
    }
    return [
        ['p', '**' . advisor_plural($r['total'], 'product template') . '** saved for Quick Scan:'],
        ['ul', array_map(fn ($t) => '**' . $t['name'] . '** · barcode ' . $t['barcode'] . ' · ' . ($t['category'] ?? 'no category')
            . ($t['default_purchase_price'] !== null ? ' · ' . peso($t['default_purchase_price']) : '')
            . ($t['default_shelf_life_days'] ? ' · keeps ' . advisor_plural($t['default_shelf_life_days'], 'day') : '')
            . ' · ' . number_format($t['units_in_stock_now']) . ' in stock now', $r['templates'])],
        ['links', [['Food Items', 'food_items.php']]],
    ];
}

const ADVISOR_MONTHS = ['january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'june' => 6, 'july' => 7, 'august' => 8,
    'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12, 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
    'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

// Which report a question asks for: a Reports page period, or one named month (the latest one that has started).
function advisor_report_input(string $q): array {
    $month = null;
    foreach (ADVISOR_MONTHS as $name => $n) {
        if (preg_match('/\b' . $name . '\b/u', $q)) {
            $month = $n;
            break;
        }
    }
    // "may" is usually the verb, so only "in May", "May 2026" and the like.
    if ($month === null && preg_match('/\b(in|of|for|during|last) may\b|\bmay \d{4}\b/u', $q)) {
        $month = 5;
    }
    if ($month !== null) {
        $year = preg_match('/\b(20\d\d)\b/', $q, $m) ? (int) $m[1] : (int) date('Y') - ($month > (int) date('n') ? 1 : 0);
        $start = new DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
        if ($start->format('Y-m') === date('Y-m')) {
            return ['period' => 'this_month'];
        }
        return ['from' => $start->format('Y-m-d'), 'to' => $start->modify('last day of this month')->format('Y-m-d')];
    }
    return ['period' => match (true) {
        (bool) preg_match('/\bthis month\b/u', $q) => 'this_month',
        (bool) preg_match('/\b(last|previous) month\b/u', $q) => 'last_month',
        (bool) preg_match('/\b(this|whole|past) year\b|\byearly\b|\bannual/u', $q) => 'year',
        (bool) preg_match('/\b90\b|\b3 months\b|\bthree months\b|\bquarter/u', $q) => '90d',
        (bool) preg_match('/\b30\b/u', $q) => '30d',
        default => 'this_month',
    }];
}

function advisor_answer_report(string $q): array {
    $in = advisor_report_input($q);
    $r = advisor_tool_report($in);
    $w = $r['waste'];
    $change = $r['change_percent']['value'];
    $range = date('M j', strtotime($r['from'])) . ' – ' . date('M j, Y', strtotime($r['to']));
    $label = isset($in['from']) ? date('F Y', strtotime($r['from'])) : $r['period'];
    $link = isset($in['from'])
        ? ['Waste entries', 'waste_history.php?' . http_build_query(['from' => $r['from'], 'to' => $r['to']])]
        : ['Reports', 'reports.php?period=' . $in['period']];

    $blocks = [['p', '**' . $label . '** (' . $range . '): **' . peso($w['value'], 0) . ' lost**, ' . number_format($w['units']) . ' units in '
        . advisor_plural($w['entries'], 'waste entry')
        . match (true) {
            $change === null => '.',
            $change == 0 => ', the same as the period before.',
            default => ', ' . ($change < 0 ? 'down ' : 'up ') . number_format(abs($change), 1) . '% on the period before (' . peso($r['waste_previous_period']['value'], 0) . ').',
        }]];
    $top = fn (array $rows, bool $share = false) => implode(', ', array_map(
        fn ($g) => $g['name'] . ' (' . ($share && $g['share_percent'] !== null ? $g['share_percent'] . '%' : peso($g['value'], 0)) . ')',
        array_slice($rows, 0, 3)
    ));
    $facts = [];
    if ($r['stock_received_cost'] > 0) {
        $facts[] = 'Stock received: ' . peso($r['stock_received_cost'], 0)
            . ($r['waste_percent_of_stock_cost'] !== null ? '. Waste was ' . $r['waste_percent_of_stock_cost'] . '% of it.' : '.');
    }
    if ($w['entries']) {
        $facts[] = 'Top wasted items: ' . $top($r['top_items']);
        $facts[] = 'Main reasons: ' . $top($r['by_reason'], true);
        $facts[] = 'By category: ' . $top($r['by_category']);
        $facts[] = 'By supplier: ' . $top($r['by_supplier']);
    } else {
        $facts[] = 'No waste was logged in this period.';
    }
    if ($ret = $r['supplier_returns']['returns']) {
        $facts[] = 'Supplier returns: ' . $ret . ', ' . peso($r['supplier_returns']['stock_value'], 0) . ' of stock, ' . peso($r['supplier_returns']['credit_received'], 0) . ' credited.';
    }
    $blocks[] = ['ul', $facts];
    $blocks[] = ['links', [$link]];
    return $blocks;
}

// Words in category names that say nothing about which category is meant.
const ADVISOR_CATEGORY_STOPWORDS = ['goods', 'good', 'other', 'others', 'items', 'item', 'misc', 'general', 'products', 'product', 'and'];

// The category a question names: its full name, or one telling word of it ("vegetable", "seafood").
function advisor_match_category(string $q): ?array {
    foreach (db_all('SELECT id, name FROM food_categories ORDER BY CHAR_LENGTH(name) DESC') as $c) {
        $name = trim((string) preg_replace('/[^\p{L}\p{N}%]+/u', ' ', mb_strtolower($c['name'])));
        if ($name !== '' && str_contains($q, ' ' . $name . ' ')) {
            return $c;
        }
        foreach (array_diff(explode(' ', $name), ADVISOR_CATEGORY_STOPWORDS) as $t) {
            $stem = mb_strlen($t) > 4 && str_ends_with($t, 's') ? substr($t, 0, -1) : $t;
            if (mb_strlen($stem) >= 4 && preg_match('/\b' . preg_quote($stem, '/') . '(s|es)?\b/u', $q)) {
                return $c;
            }
        }
    }
    return null;
}

// Suppliers listed as delivering a category, or that we have received its items from.
function advisor_category_suppliers(int $categoryId): array {
    return array_column(db_all(
        'SELECT s.name FROM suppliers s
         WHERE EXISTS (SELECT 1 FROM supplier_products sp WHERE sp.supplier_id = s.id AND sp.category_id = ?)
            OR EXISTS (SELECT 1 FROM food_items fi WHERE fi.supplier_id = s.id AND fi.category_id = ?)
         ORDER BY s.name',
        'ii',
        [$categoryId, $categoryId]
    ), 'name');
}

function advisor_answer_category(array $cat): array {
    $c = current(array_filter(advisor_tool_categories()['categories'], fn ($r) => $r['category'] === $cat['name']));
    $since = date('Y-m-d', strtotime('-' . (ADVISOR_WINDOW_DAYS - 1) . ' days'));
    $top = db_all(
        'SELECT fi.name, SUM(wl.estimated_value) AS value
         FROM waste_logs wl JOIN food_items fi ON fi.id = wl.food_item_id
         WHERE fi.category_id = ? AND wl.waste_date >= ?
         GROUP BY fi.name ORDER BY value DESC LIMIT 3',
        'is',
        [$cat['id'], $since]
    );
    $reason = db_value(
        'SELECT wr.reason FROM waste_logs wl
         JOIN food_items fi ON fi.id = wl.food_item_id
         JOIN waste_reasons wr ON wr.id = wl.waste_reason_id
         WHERE fi.category_id = ? AND wl.waste_date >= ?
         GROUP BY wr.id, wr.reason ORDER BY SUM(wl.estimated_value) DESC LIMIT 1',
        'is',
        [$cat['id'], $since]
    );
    $expiring = (int) db_value(
        'SELECT COUNT(*) FROM food_items fi WHERE fi.category_id = ? AND ' . ACTIVE_ITEM_SQL . '
         AND fi.quantity > 0 AND fi.expiration_date BETWEEN CURDATE() AND CURDATE() + INTERVAL ? DAY',
        'ii',
        [$cat['id'], EXPIRING_SOON_DAYS]
    );
    $suppliers = advisor_category_suppliers((int) $cat['id']);
    $waste = $c['waste_last_90_days'];

    $facts = [
        'In stock: ' . advisor_plural($c['active_items'], 'item') . ', ' . number_format($c['units_in_stock']) . ' units, worth ' . peso($c['stock_value'], 0)
            . ($expiring ? '; **' . $expiring . ' expiring within ' . EXPIRING_SOON_DAYS . ' days**' : '') . '.',
        $suppliers ? 'Suppliers: ' . rtrim(implode(', ', $suppliers), '.') . '.' : 'No supplier is linked to it yet.',
        $waste['value'] > 0
            ? 'Waste in the last ' . ADVISOR_WINDOW_DAYS . ' days: ' . peso($waste['value'], 0) . ' (' . number_format($waste['units']) . ' units)' . ($reason ? ', mostly "' . $reason . '"' : '') . '.'
            : 'No waste in the last ' . ADVISOR_WINDOW_DAYS . ' days.',
    ];
    if ($top) {
        $facts[] = 'Most wasted: ' . implode(', ', array_map(fn ($r) => $r['name'] . ' (' . peso($r['value'], 0) . ')', $top)) . '.';
    }
    if ($more = array_filter([
        $c['archived_items'] ? advisor_plural($c['archived_items'], 'archived item') : '',
        $c['product_templates'] ? advisor_plural($c['product_templates'], 'product template') : '',
    ])) {
        $facts[] = ucfirst(implode(', ', $more)) . '.';
    }
    return [
        ['p', '**' . $cat['name'] . '**'],
        ['ul', $facts],
        ['links', [['Food Items', 'food_items.php?category=' . (int) $cat['id']]]],
    ];
}
