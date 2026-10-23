<?php
// "Ask the advisor": answers an admin's or manager's typed question from the AI Waste Advisor's analysis
// (includes/advisor.php). It matches the question to an item name or a question type and
// replies only with facts from this store's records. Anything else gets "I only answer from
// this store's records".

require_once __DIR__ . '/advisor.php';

// Keywords per question type. A trailing * matches the start of a word ("expir*" matches "expiring").
const ADVISOR_INTENTS = [
    'suppliers' => ['supplier*', 'vendor*', 'distributor*'],
    'categories' => ['categor*', 'department*'],
    'storage' => ['storage', 'stored', 'fridge', 'chiller', 'freez*', 'refrigerat*', 'temperature', 'keep fresh'],
    'expiring' => ['expir*', 'best before', 'about to', 'going bad'],
    'forecast' => ['forecast*', 'predict*', 'next month', 'next week', 'future', 'projection*', 'will be wasted'],
    'slow' => ['slow*', 'overstock*', 'too much', 'too many', 'not selling', 'not moving', 'excess', 'unsold'],
    'order' => ['order*', 'reorder*', 'buy*', 'purchas*', 'restock*'],
    'score' => ['score', 'grade', 'rating', 'how are we doing', 'how am i doing', 'performance'],
    'reasons' => ['reason*', 'why', 'cause*'],
    'top' => ['most wasted', 'most waste*', 'top', 'worst', 'biggest', 'highest', 'which item*', 'what item*', 'which product*'],
    'totals' => ['how much', 'total*', 'lost', 'lose', 'loss*', 'cost*', 'spent', 'this month', 'last month', 'summary', 'report*', 'money', 'peso*'],
    'today' => ['today', 'what should i do', 'what do i do', 'what to do', 'priorit*', 'urgent*', 'first', 'right now'],
    'zero' => ['zero waste', 'zero', 'reduce*', 'lessen', 'minimi*', 'cut waste', 'improve*', 'tips', 'advice', 'recommend*', 'suggest*', 'plan', 'prevent*', 'avoid*', 'how can we', 'how do we'],
    'help' => ['help', 'hello', 'hi', 'hey', 'what can you*', 'who are you', 'how do you work', 'how does this work'],
];

// Words in item names that say nothing about which product is meant.
const ADVISOR_NAME_STOPWORDS = ['the', 'and', 'for', 'with', 'pack', 'pcs', 'piece', 'pieces', 'per', 'fresh', 'large', 'small',
    'medium', 'big', 'box', 'bag', 'bottle', 'can', 'tray', 'dozen', 'local', 'premium', 'organic', 'whole', 'sliced', 'kilo'];

const ADVISOR_SUGGESTIONS = [
    'What should I do today?',
    'How can we reach zero waste?',
    'What will be wasted next month?',
    'Which items do we waste most?',
    'Which supplier causes the most waste?',
    'How much should I order?',
];

function advisor_answer(string $question, array $a): string {
    $q = ' ' . trim((string) preg_replace('/[^\p{L}\p{N}%]+/u', ' ', mb_strtolower($question))) . ' ';
    if (trim($q) === '') {
        return advisor_render([['p', 'Type a question about your stock or waste, or pick one of the suggestions.']]);
    }
    if (!$a['products']) {
        return advisor_render([['p', 'There is no inventory in the system yet, so I have nothing to learn from. Add items, receive stock and log waste, and I\'ll start recommending.']]);
    }

    $product = advisor_match_product($q, $a['products']);
    if ($product !== null) {
        return advisor_render(advisor_answer_product($product['match'], $product['others'], $product['exact'], $a));
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

    $blocks = match ($best) {
        'suppliers' => advisor_answer_groups($a['suppliers'], 'supplier', $a['recommendations']),
        'categories' => advisor_answer_groups($a['categories'], 'category', $a['recommendations']),
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
        'zero' => advisor_answer_zero($a),
        'help' => [
            ['p', 'I\'m the ZeroWaste AI Advisor. I learn only from this store\'s records: inventory, stock received, sales, waste, supplier returns and suppliers. Nothing comes from outside the system.'],
            ['p', 'Ask me what to do today, what will expire unsold, how much to order, which items, reasons, suppliers or categories waste the most, or about any item by name.'],
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
    $facts[] = 'Sales pace: about ' . advisor_pace($p['pace'], $p['unit']) . ', from ' . advisor_plural($p['days'], 'day') . ' of history ('
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
    $lines = array_map(fn ($r) => '**' . $r['title'] . '**. ' . ($r['actions'][0] ?? $r['why']), array_slice($todo, 0, 3));
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
            $blocks[] = ['p', '**' . $rec['title'] . '**. ' . implode(' ', $rec['actions'])];
        }
    }
    return $blocks;
}

function advisor_answer_storage(array $a): array {
    $recs = array_values(array_filter($a['recommendations'], fn ($r) => $r['type'] === 'storage'));
    if (!$recs) {
        return [['p', 'No storage problems found: none of the items with spoiled or expired waste are kept warm when the shelf-life profiles say they would last much longer chilled.']];
    }
    return [['ul', array_map(fn ($r) => '**' . $r['title'] . '**. ' . $r['why'], $recs)]];
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
