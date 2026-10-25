<?php
// "Ask the advisor" with a real language model: Claude (Anthropic) answers an admin's or manager's
// question in plain language. It reads the store's records only through the read-only lookups
// ("tools") below, so every figure comes from this database and it cannot change anything.
// Without an API key (ANTHROPIC_API_KEY in config.local.php) the chat uses the built-in answers
// in advisor_chat.php instead.
//
// The API is called over plain HTTPS with curl, so the app still needs no Composer packages.

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/advisor_chat.php';

const ADVISOR_AI_MODEL = 'claude-opus-5-5';
const ADVISOR_AI_MAX_TOOL_ROUNDS = 8;   // lookups per question before it must answer
const ADVISOR_AI_HISTORY_TURNS = 8;     // earlier questions and answers it remembers
const ADVISOR_AI_SESSION_KEY = 'advisor_ai_history';
const ADVISOR_AI_JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

final class AdvisorAiException extends RuntimeException {
    public function __construct(string $message, public readonly string $kind = 'api_error', int $status = 0) {
        parent::__construct($message, $status);
    }
}

function advisor_ai_key(): string {
    return trim(config_value('ANTHROPIC_API_KEY', ''));
}

function advisor_ai_enabled(): bool {
    return advisor_ai_key() !== '' && function_exists('curl_init');
}

/* ---------- Prompt and tools (kept byte-identical between requests so they stay cached) ---------- */

function advisor_ai_system_prompt(): string {
    return 'You are the ZeroWaste AI Advisor for ' . STORE_NAME . ', a neighborhood grocery store in the Philippines. '
        . 'You help its admins and managers with every part of the store\'s ZeroWaste system: inventory and expiry dates, the archive, '
        . 'waste and reports, stock received, stock returned to suppliers, suppliers and what they deliver, deliveries and their payment, '
        . 'categories, product templates and user accounts, and you give practical advice for reducing food waste.

How to answer:
- Get every fact from the tools. Never guess or invent a number, item, supplier or date. If the tools have no data for something, say so.
- Call as many tools as you need. When lookups don\'t depend on each other, call them together.
- Lead with the direct answer, then the few details that matter. Keep it short: the reply shows in a small chat window.
- Format with short paragraphs, "-" bullet lists or numbered lists, and **bold** for key figures. No headings. Use a table only for a short comparison of 2 to 4 columns.
- Money is Philippine pesos, written like ₱1,250.00. Dates like "Fri, Oct 23".
- Reply in the language the user writes in (English, Filipino or Taglish).
- Each user message starts with today\'s date and time and who is asking.

Store vocabulary:
- Each row on the Food Items page is one batch of a product, with its own quantity and expiration date. When asked how many items there are, give the number of item records in stock and the total units, and mention low or out-of-stock items if relevant.
- Low stock means 1 to ' . LOW_STOCK_THRESHOLD . ' units; overstock means ' . OVERSTOCK_THRESHOLD . ' units or more; "expiring soon" means within ' . EXPIRING_SOON_DAYS . ' days.
- Waste value is quantity × purchase price. Expired stock is logged as "Expired" waste and archived automatically the day after its expiration date.
- Suggested markdowns: 20% off 2 days before expiry, 50% off on the last day.
- Selling price is the item\'s own price, or the purchase price plus a ' . DEFAULT_MARKUP_PERCENT . '% default markup.
- Returns are stock sent back to suppliers; credit is the money the supplier gave back.
- Archived items (expired, archived by staff, or removed while they have history) are hidden from inventory but kept for reports; they can be restored.
- User accounts: you can see usernames, roles, status and what each person recorded, never emails or passwords. Sign-in times are not recorded.
- The store records no sales, so you cannot report sales or profit. The "sales pace" from get_advisor_insights is an estimate: units that left stock without being wasted or returned.

You can only read data. To change something, tell the user where to do it: Food Items (add, edit, receive stock, print barcode labels), Expiring Soon, Low Stock, Archive, Log Waste, Returns, Waste History, Reports, Suppliers, Deliveries, and User Accounts.

If a question has nothing to do with this store, its stock, food waste or running a grocery, say in one sentence that you only help with the store, and suggest something you can answer.';
}

function advisor_ai_tools(): array {
    $date = fn (string $what) => ['type' => 'string', 'description' => $what . ' (YYYY-MM-DD).'];
    $limit = ['type' => 'integer', 'description' => 'Most rows to return, 1 to ' . ADVISOR_TOOL_MAX_ROWS . '. Default 50.'];
    $tools = [
        [
            'name' => 'get_store_overview',
            'description' => 'Store-wide totals right now: item records and units in stock, stock value at cost and at selling price, '
                . 'low, out-of-stock, overstocked and expiring-soon counts, archived items, counts per category, waste today, this month '
                . 'and last month, stock received this month and last month, supplier returns this month, number of suppliers and '
                . 'delivery status counts. Call this first for "how many", "how much" and "how are we doing" questions.',
            'input_schema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        [
            'name' => 'search_items',
            'description' => 'Find inventory items (batches) and their stock, unit, unit cost, selling price, stock value, expiration date, '
                . 'days left, freshness, stock status, suggested markdown, storage location and supplier. Use it for questions about '
                . 'specific products, categories, suppliers or storage places, or for lists such as low stock, out of stock or expiring soon.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Text to look for in the item name, barcode, category, supplier or storage location. Leave out to list everything.'],
                    'category' => ['type' => 'string', 'description' => 'Only items in a category whose name contains this text.'],
                    'status' => ['type' => 'string', 'enum' => ['any', 'in_stock', 'low_stock', 'out_of_stock', 'overstock', 'expiring_soon', 'fresh', 'expired'], 'description' => 'Stock or freshness filter. Default any.'],
                    'include_archived' => ['type' => 'boolean', 'description' => 'Also include archived items (expired, removed or archived by staff). Default false.'],
                    'sort' => ['type' => 'string', 'enum' => ['name', 'quantity_asc', 'quantity_desc', 'expiry', 'value_desc', 'recently_updated'], 'description' => 'Order of the results. Default expiry (soonest first).'],
                    'limit' => $limit,
                ],
            ],
        ],
        [
            'name' => 'get_waste',
            'description' => 'Waste that was logged in a date range: totals, plus either the individual entries or totals grouped by item, '
                . 'reason, category, supplier, day, month or staff member. Use it for questions about what was thrown away, why, '
                . 'how much it cost, and trends.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'from' => $date('First day to include. Default: the first day of this month'),
                    'to' => $date('Last day to include. Default: today'),
                    'group_by' => ['type' => 'string', 'enum' => ['none', 'item', 'reason', 'category', 'supplier', 'day', 'month', 'staff'], 'description' => 'none lists the entries, newest first. Default none.'],
                    'item' => ['type' => 'string', 'description' => 'Only items whose name contains this text.'],
                    'reason' => ['type' => 'string', 'description' => 'Only this waste reason, e.g. Expired, Damaged/Spoiled, Overstock, Spillage.'],
                    'limit' => $limit,
                ],
            ],
        ],
        [
            'name' => 'get_stock_received',
            'description' => 'Stock that came in during a date range (deliveries received, Quick Scan, new items and upward corrections), '
                . 'valued at purchase price: totals, plus the individual receipts or totals grouped by item, supplier, category, month or source. '
                . 'Use it for questions about purchases, spending on stock and how much of something came in.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'from' => $date('First day to include. Default: the first day of this month'),
                    'to' => $date('Last day to include. Default: today'),
                    'group_by' => ['type' => 'string', 'enum' => ['none', 'item', 'supplier', 'category', 'month', 'source'], 'description' => 'none lists the receipts, newest first. Default item.'],
                    'item' => ['type' => 'string', 'description' => 'Only items whose name contains this text.'],
                    'supplier' => ['type' => 'string', 'description' => 'Only items from suppliers whose name contains this text.'],
                    'limit' => $limit,
                ],
            ],
        ],
        [
            'name' => 'get_supplier_returns',
            'description' => 'Stock sent back to suppliers in a date range, with its value, the credit received and the reason. '
                . 'Use it for questions about returns, credits and money not recovered from suppliers.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'from' => $date('First day to include. Default: 90 days ago'),
                    'to' => $date('Last day to include. Default: today'),
                    'supplier' => ['type' => 'string', 'description' => 'Only suppliers whose name contains this text.'],
                    'item' => ['type' => 'string', 'description' => 'Only items whose name contains this text.'],
                    'limit' => $limit,
                ],
            ],
        ],
        [
            'name' => 'get_suppliers',
            'description' => 'Supplier records: contact person, phone, email and address, the products they deliver (with categories), '
                . 'how many active items and units come from each, their open deliveries and when they last delivered. '
                . 'Use it for supplier contact details, who supplies what, and supplier lists.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Text to look for in the supplier name, contact person or the products they deliver. Leave out to list all.'],
                ],
            ],
        ],
        [
            'name' => 'get_deliveries',
            'description' => 'Supplier deliveries with their reference (DLV-00012), items, amount, expected date and time, status '
                . '(scheduled, on the way, delivered, cancelled), payment status and notes, plus how much delivered stock is still unpaid. '
                . 'With a reference, returns that one delivery with its transaction log (who scheduled, shipped, delivered and paid it, and when). '
                . 'Use it for questions about upcoming, overdue or past deliveries and unpaid deliveries.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'reference' => ['type' => 'string', 'description' => 'One delivery, e.g. DLV-00012 or 12. The other filters are ignored.'],
                    'status' => ['type' => 'string', 'enum' => ['any', 'open', 'scheduled', 'on_the_way', 'delivered', 'cancelled', 'unpaid'], 'description' => 'open means scheduled or on the way. Default any.'],
                    'supplier' => ['type' => 'string', 'description' => 'Only suppliers whose name contains this text.'],
                    'from' => $date('Earliest expected date to include'),
                    'to' => $date('Latest expected date to include'),
                    'limit' => $limit,
                ],
            ],
        ],
        [
            'name' => 'get_report',
            'description' => 'The Reports page for a period: waste totals compared with the period before (with % change), stock cost received and '
                . 'waste as % of it, the top wasted items, waste by reason, category and supplier, supplier returns, and the last 12 months. '
                . 'Use it for report, comparison and trend questions ("how did we do last month", "compare with September").',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'period' => ['type' => 'string', 'enum' => array_keys(REPORT_PERIODS), 'description' => 'this_month, last_month, 30d, 90d or year. Default this_month.'],
                    'from' => $date('Start of a custom range instead of a period'),
                    'to' => $date('End of a custom range'),
                ],
            ],
        ],
        [
            'name' => 'get_users',
            'description' => 'User accounts: username, role (admin, manager, staff), active or inactive, date joined, and what each person '
                . 'recorded (waste entries and their value, supplier returns, stock receipts, deliveries scheduled) with their last activity. '
                . 'No emails or passwords. Use it for questions about staff, roles, pending accounts and who logged what.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'role' => ['type' => 'string', 'enum' => ['any', 'admin', 'manager', 'staff'], 'description' => 'Default any.'],
                    'status' => ['type' => 'string', 'enum' => ['any', 'active', 'inactive'], 'description' => 'inactive = waiting for approval or deactivated. Default any.'],
                    'query' => ['type' => 'string', 'description' => 'Only usernames containing this text.'],
                ],
            ],
        ],
        [
            'name' => 'get_archive',
            'description' => 'The Archive: items hidden from inventory because they expired, were archived by staff, or were removed while they '
                . 'had history, with the date archived, last expiry date, units left and lifetime waste. Use it for questions about '
                . 'archived, expired or removed items.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'reason' => ['type' => 'string', 'enum' => ['any', 'expired', 'manual', 'removed'], 'description' => 'manual = archived by staff. Default any.'],
                    'query' => ['type' => 'string', 'description' => 'Text to look for in the item name, barcode or category.'],
                    'limit' => $limit,
                ],
            ],
        ],
        [
            'name' => 'get_categories',
            'description' => 'Every product category with its active items, units and stock value, archived items, product templates, the '
                . 'suppliers who deliver it and its waste in the last 90 days, plus the list of waste reasons and how often each was used. '
                . 'Use it for category questions and to see which categories and waste reasons exist.',
            'input_schema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        [
            'name' => 'get_product_templates',
            'description' => 'Product templates (saved from Add Item for Quick Scan): barcode, name, category, unit, default purchase price, '
                . 'default shelf life, units in stock now and who created it. Use it for barcode and template questions.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Text to look for in the name, barcode or category.'],
                    'limit' => $limit,
                ],
            ],
        ],
        [
            'name' => 'get_advisor_insights',
            'description' => 'The built-in waste analysis of the last ' . ADVISOR_WINDOW_DAYS . ' days: the Zero-Waste Score and how it is made, '
                . 'ranked recommendations, the expiry forecast (stock that will expire unsold at its usual sales pace), safe order sizes, '
                . 'and per-product sales pace and waste. Use it for "what should I do", "what will be wasted", "how much should I order", '
                . 'slow movers and advice on reducing waste.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'section' => ['type' => 'string', 'enum' => ['all', 'summary', 'recommendations', 'expiry_forecast', 'order_plan'], 'description' => 'Part of the analysis to return. Default all.'],
                    'item' => ['type' => 'string', 'description' => 'Only products whose name contains this text; also adds their sales pace and waste details.'],
                ],
            ],
        ],
    ];
    usort($tools, fn ($a, $b) => strcmp($a['name'], $b['name']));
    return $tools;
}

/* ---------- Running a tool (the lookups themselves are in advisor_tools.php) ---------- */

function advisor_ai_run_tool(string $name, array $in): array {
    return match ($name) {
        'get_store_overview' => advisor_tool_overview(),
        'search_items' => advisor_tool_search_items($in),
        'get_waste' => advisor_tool_waste($in),
        'get_stock_received' => advisor_tool_stock_received($in),
        'get_supplier_returns' => advisor_tool_supplier_returns($in),
        'get_suppliers' => advisor_tool_suppliers($in),
        'get_deliveries' => advisor_tool_deliveries($in),
        'get_advisor_insights' => advisor_tool_insights($in),
        'get_report' => advisor_tool_report($in),
        'get_users' => advisor_tool_users($in),
        'get_archive' => advisor_tool_archive($in),
        'get_categories' => advisor_tool_categories(),
        'get_product_templates' => advisor_tool_product_templates($in),
        default => throw new InvalidArgumentException('There is no tool called ' . $name . '.'),
    };
}

/* ---------- Calling Claude ---------- */

// One Messages API request. Returns [decoded as arrays (for reading), decoded as objects (to send
// back unchanged)]. Retries rate limits, overloads and network errors twice.
function advisor_ai_request(array $body): array {
    static $withFallbacks = true; // turned off for the rest of the request if the account can't use them
    $request = $body;
    $headers = ['content-type: application/json', 'x-api-key: ' . advisor_ai_key(), 'anthropic-version: 2023-06-01'];
    if ($withFallbacks) {
        // If a safety classifier declines a benign question, the API retries it on another model.
        $request['fallbacks'] = 'default';
        $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
    }
    $payload = json_encode($request, ADVISOR_AI_JSON | JSON_THROW_ON_ERROR);
    $url = rtrim(config_value('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/') . '/v1/messages';

    for ($attempt = 0; ; $attempt++) {
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $networkError = $raw === false ? curl_error($ch) : '';
        unset($ch);

        if (($raw === false || in_array($status, [408, 409, 429, 500, 502, 503, 504, 529], true)) && $attempt < 2) {
            $wait = (float) ($responseHeaders['retry-after'] ?? 0) ?: 2 ** $attempt;
            usleep((int) (min($wait, 10) * 1000000));
            continue;
        }
        if ($raw === false) {
            throw new AdvisorAiException('Could not reach the AI service: ' . $networkError, 'network');
        }
        $data = json_decode($raw, true);
        if ($status === 200 && is_array($data)) {
            return [$data, json_decode($raw)];
        }
        $type = (string) ($data['error']['type'] ?? 'api_error');
        $message = (string) ($data['error']['message'] ?? 'HTTP ' . $status);
        if ($withFallbacks && $status === 400 && stripos($message, 'fallback') !== false) {
            $withFallbacks = false;
            return advisor_ai_request($body);
        }
        throw new AdvisorAiException($message, $type, $status);
    }
}

// The response content to send back on the next request. After a fallback, only the text the
// declined model wrote before the last "fallback" marker is kept (the API's echo rule).
function advisor_ai_content_to_echo(array $blocks): array {
    $lastFallback = -1;
    foreach ($blocks as $i => $block) {
        if (($block->type ?? '') === 'fallback') {
            $lastFallback = $i;
        }
    }
    $kept = [];
    foreach ($blocks as $i => $block) {
        $type = $block->type ?? '';
        if ($type === 'fallback' || ($i < $lastFallback && $type !== 'text')) {
            continue;
        }
        $kept[] = $block;
    }
    return $kept;
}

/**
 * Answers one question. $history holds the earlier turns of this chat as
 * ['prompt' => what was sent, 'question' => what the user typed, 'answer' => the reply text].
 * Earlier turns go back as plain text (no tool calls or thinking), so each request's prefix is
 * the previous request's prefix plus the new turn. That keeps the prompt cache warm and never
 * replays a thinking block outside the conversation that produced it.
 * Returns ['prompt' => ..., 'answer' => Markdown text, 'remember' => bool].
 */
function advisor_ai_answer(string $question, array $user, array $history): array {
    $messages = [];
    foreach (array_slice($history, -ADVISOR_AI_HISTORY_TURNS) as $turn) {
        $messages[] = ['role' => 'user', 'content' => $turn['prompt']];
        $messages[] = ['role' => 'assistant', 'content' => $turn['answer']];
    }
    $prompt = 'Today is ' . date('l, F j, Y, g:i A') . ' (Philippine time). Asked by ' . $user['username'] . ' (' . $user['role'] . ").\n\n" . $question;
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $base = [
        'model' => ADVISOR_AI_MODEL,
        'max_tokens' => 16000,
        // A chat answer needs quick lookups more than long deliberation.
        'output_config' => ['effort' => 'low'],
        // The marker on the system prompt caches tools + instructions; the top-level one caches the chat so far.
        'cache_control' => ['type' => 'ephemeral'],
        'system' => [['type' => 'text', 'text' => advisor_ai_system_prompt(), 'cache_control' => ['type' => 'ephemeral']]],
        'tools' => advisor_ai_tools(),
    ];

    for ($round = 0; $round <= ADVISOR_AI_MAX_TOOL_ROUNDS; $round++) {
        [$data, $raw] = advisor_ai_request($base + ['messages' => $messages]);
        $stop = (string) ($data['stop_reason'] ?? '');
        if ($stop === 'refusal') {
            return ['prompt' => $prompt, 'answer' => 'I can\'t help with that request. Ask me about the store\'s stock, waste, suppliers or deliveries.', 'remember' => false];
        }

        $content = advisor_ai_content_to_echo(is_array($raw->content ?? null) ? $raw->content : []);
        $text = trim(implode("\n\n", array_map(fn ($b) => (string) $b->text, array_filter($content, fn ($b) => ($b->type ?? '') === 'text'))));
        $calls = array_values(array_filter($content, fn ($b) => ($b->type ?? '') === 'tool_use'));

        if ($stop !== 'tool_use' || !$calls) {
            if ($stop === 'max_tokens') {
                $text .= "\n\n(The answer was cut short. Ask a narrower question for the rest.)";
            }
            return ['prompt' => $prompt, 'answer' => $text !== '' ? $text : 'I couldn\'t put an answer together. Please try asking another way.', 'remember' => $text !== ''];
        }

        $messages[] = ['role' => 'assistant', 'content' => $content];
        $results = [];
        foreach ($calls as $call) {
            try {
                $input = json_decode(json_encode($call->input ?? new stdClass()), true) ?: [];
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $call->id, 'content' => json_encode(advisor_ai_run_tool((string) $call->name, $input), ADVISOR_AI_JSON)];
            } catch (InvalidArgumentException $e) {
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $call->id, 'is_error' => true, 'content' => $e->getMessage()];
            } catch (Throwable $e) {
                error_log('ZeroWaste AI advisor tool ' . $call->name . ': ' . $e);
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $call->id, 'is_error' => true, 'content' => 'The lookup failed on the server.'];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $results];
    }

    return ['prompt' => $prompt, 'answer' => 'That question needed more lookups than I\'m allowed for one answer. Try asking something more specific.', 'remember' => false];
}

// What to tell staff when the AI could not answer (details go to the error log).
function advisor_ai_problem(AdvisorAiException $e): string {
    return match (true) {
        $e->kind === 'network' => 'The server couldn\'t reach the AI service.',
        $e->getCode() === 401 => 'The AI key in config.local.php was rejected.',
        $e->getCode() === 403 => 'This AI key isn\'t allowed to use the AI model.',
        stripos($e->getMessage(), 'credit balance') !== false => 'The Anthropic account has run out of credit.',
        $e->getCode() === 429 => 'The AI is getting too many questions right now.',
        $e->getCode() === 529 || $e->kind === 'overloaded_error' => 'The AI service is busy right now.',
        default => 'The AI service had a problem.',
    };
}

/* ---------- Showing the reply ---------- */

// Claude's Markdown reply as safe HTML for the chat bubble: paragraphs, bullet and numbered lists,
// **bold**, `code` and simple tables. Everything is escaped first.
function advisor_ai_render(string $markdown): string {
    $inline = function (string $text): string {
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', e($text));
        return preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
    };
    $lines = preg_split('/\R/u', trim($markdown));
    $html = '';
    $paragraph = [];
    $list = null;
    $items = [];
    $flush = function () use (&$html, &$paragraph, &$list, &$items, $inline): void {
        if ($paragraph) {
            $html .= '<p>' . implode('<br>', array_map($inline, $paragraph)) . '</p>';
        }
        if ($list !== null) {
            $html .= "<$list>" . implode('', array_map(fn ($li) => '<li>' . $inline($li) . '</li>', $items)) . "</$list>";
        }
        [$paragraph, $list, $items] = [[], null, []];
    };

    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $line = rtrim($lines[$i]);
        if (trim($line) === '' || preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
            $flush();
        } elseif (preg_match('/^\s*\|.*\|\s*$/', $line) && preg_match('/^\s*\|?\s*:?-{2,}/', $lines[$i + 1] ?? '')) {
            $flush();
            $rows = [];
            for (; $i < $n && preg_match('/^\s*\|.*\|\s*$/', $lines[$i]); $i++) {
                $rows[] = array_map('trim', explode('|', trim(trim($lines[$i]), '|')));
            }
            $i--;
            $cells = fn (array $row, string $tag) => implode('', array_map(fn ($c) => "<$tag>" . $inline($c) . "</$tag>", $row));
            $html .= '<div class="ask-table"><table><thead><tr>' . $cells($rows[0], 'th') . '</tr></thead><tbody>'
                . implode('', array_map(fn ($row) => '<tr>' . $cells($row, 'td') . '</tr>', array_slice($rows, 2)))
                . '</tbody></table></div>';
        } elseif (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) {
            $flush();
            $html .= '<p><strong>' . $inline(str_replace('**', '', $m[1])) . '</strong></p>';
        } elseif (preg_match('/^\s*(?:[-*+•])\s+(.+)$/u', $line, $m) || preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $m)) {
            $type = preg_match('/^\s*\d/', $line) ? 'ol' : 'ul';
            if ($list !== $type) {
                $flush();
                $list = $type;
            }
            $items[] = $m[1];
        } elseif ($list !== null && preg_match('/^\s{2,}\S/', $line)) {
            $items[count($items) - 1] .= ' ' . trim($line); // a list item that wraps onto the next line
        } else {
            if ($list !== null) {
                $flush();
            }
            $paragraph[] = $line;
        }
    }
    $flush();
    return $html;
}
