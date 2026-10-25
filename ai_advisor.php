<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/advisor_ai.php';

$user = require_login();
if (!can_manage($user) && is_ajax()) {
    json_response(['success' => false, 'message' => 'Only admins and managers can use the AI Waste Advisor.'], 403);
}
require_role($user, ['admin', 'manager']);
$aiOn = advisor_ai_enabled();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'new_chat') {
        unset($_SESSION[ADVISOR_AI_SESSION_KEY]);
        json_response(['success' => true]);
    }
    if ($action !== 'ask') {
        json_response(['success' => false, 'message' => 'Unknown action.'], 400);
    }
    $question = mb_substr(trim((string) ($_POST['question'] ?? '')), 0, 500);
    if (!$aiOn || $question === '') {
        json_response(['success' => true, 'html' => advisor_answer($question, advisor_analyze())]);
    }

    $history = $_SESSION[ADVISOR_AI_SESSION_KEY] ?? [];
    // An answer can take a while; don't keep the user's other pages waiting on the session lock.
    session_write_close();
    set_time_limit(300);
    try {
        $reply = advisor_ai_answer($question, $user, $history);
    } catch (AdvisorAiException $e) {
        error_log('ZeroWaste AI advisor: ' . $e->kind . ' (HTTP ' . $e->getCode() . '): ' . $e->getMessage());
        json_response(['success' => true, 'html' => '<p class="ask-note"><i class="fa-solid fa-triangle-exclamation"></i> '
            . e(advisor_ai_problem($e)) . ' Here is the built-in answer instead.</p>' . advisor_answer($question, advisor_analyze())]);
    }
    if ($reply['remember']) {
        session_start();
        $history[] = ['prompt' => $reply['prompt'], 'question' => $question, 'answer' => $reply['answer']];
        $_SESSION[ADVISOR_AI_SESSION_KEY] = array_slice($history, -ADVISOR_AI_HISTORY_TURNS);
    }
    json_response(['success' => true, 'html' => advisor_ai_render($reply['answer'])]);
}

$chat = $aiOn ? ($_SESSION[ADVISOR_AI_SESSION_KEY] ?? []) : [];
$a = advisor_analyze();
$t = $a['totals'];
$score = $a['score'];
$recs = $a['recommendations'];
$todo = count(array_filter($recs, fn ($r) => $r['priority'] !== 'good'));
$trendPct = $t['value_prev30'] > 0 ? round(($t['value30'] - $t['value_prev30']) / $t['value_prev30'] * 100, 1) : null;
$wasteEntries = (int) $t['entries90'];

function advisor_text(string $text): string {
    return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e($text));
}

render_shell_start($user, 'AI Waste Advisor', 'advisor');
page_header(
    'AI Waste Advisor',
    'What to do next to reduce waste, worked out from this store\'s own records · last ' . ADVISOR_WINDOW_DAYS . ' days · admins and managers',
    '<a class="btn" href="ai_advisor.php"><i class="fa-solid fa-rotate"></i> Re-analyse</a>'
);
?>

<div class="alert alert-info advisor-note">
    <i class="fa-solid fa-robot"></i>
    <span>
        The score, recommendations and forecasts are worked out on this server from ZeroWaste data only, here <?= advisor_plural(count($a['products']), 'product') ?>,
        <?= number_format($wasteEntries) . ($wasteEntries === 1 ? ' waste entry' : ' waste entries') ?>
        and <?= advisor_plural((int) $t['receipts90'], 'stock receipt') ?> from the last <?= ADVISOR_WINDOW_DAYS ?> days.
        <?php if ($aiOn): ?>
            The chat is answered by Claude, Anthropic's AI: your question and the records it looks up are sent to Anthropic.
        <?php else: ?>
            Nothing is sent outside the system.
        <?php endif; ?>
    </span>
</div>

<div class="stats">
    <div class="stat">
        <span class="stat-icon tone-<?= $score ? e($score['grade'][2]) : 'gray' ?>"><i class="fa-solid fa-leaf"></i></span>
        <span>
            <div class="stat-label">Zero-Waste Score</div>
            <div class="stat-value"><?= $score ? $score['score'] . '<small class="stat-of">/100</small>' : '—' ?></div>
            <div class="stat-sub"><?= $score ? 'Grade ' . e($score['grade'][0]) . ' · ' . e($score['grade'][1]) : 'Not enough data yet' ?></div>
        </span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-purple"><i class="fa-solid fa-percent"></i></span>
        <span>
            <div class="stat-label">Waste rate</div>
            <div class="stat-value"><?= $score ? number_format($score['rate'], 1) . '%' : '—' ?></div>
            <div class="stat-sub">of stock cost · last <?= $score ? $score['rate_span'] : 30 ?> days · target ≤ <?= ADVISOR_TARGET_RATE ?>%</div>
        </span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-amber"><i class="fa-solid fa-chart-line"></i></span>
        <span>
            <div class="stat-label">Forecast waste</div>
            <div class="stat-value"><?= peso($a['at_risk_value'], 0) ?></div>
            <div class="stat-sub">next <?= ADVISOR_HORIZON_DAYS ?> days if nothing changes · <?= number_format($a['at_risk_units']) ?> units</div>
        </span>
    </div>
    <div class="stat">
        <span class="stat-icon tone-red"><i class="fa-solid fa-peso-sign"></i></span>
        <span>
            <div class="stat-label">Wasted, last 30 days</div>
            <div class="stat-value"><?= peso($t['value30'], 0) ?></div>
            <div class="stat-sub">
                <?php if ($trendPct === null): ?>
                    <span class="trend flat">No earlier data</span>
                <?php elseif ($trendPct == 0): ?>
                    <span class="trend flat">No change</span>
                <?php else: ?>
                    <span class="trend <?= $trendPct > 0 ? 'up' : 'down' ?>"><i class="fa-solid fa-arrow-<?= $trendPct > 0 ? 'up' : 'down' ?>"></i> <?= abs($trendPct) ?>%</span> vs previous 30 days
                <?php endif; ?>
            </div>
        </span>
    </div>
</div>

<?php if ($score): ?>
    <div class="card" style="margin-top:18px">
        <div class="card-head">
            <div>
                <div class="card-title"><i class="fa-solid fa-gauge-high"></i> How the score is made</div>
                <div class="card-sub"><?= $score['score'] ?> of 100 · <?= $score['score'] >= 85 ? 'near zero waste' : (85 - $score['score']) . ' points to near zero waste (85)' ?></div>
            </div>
        </div>
        <div class="card-body">
            <ul class="hbar-list score-parts">
                <?php foreach ($score['parts'] as $part): $pct = round($part['points'] / $part['max'] * 100); ?>
                    <li class="hbar-row">
                        <span class="name"><?= e($part['label']) ?></span>
                        <span class="val"><?= number_format($part['points'], 0) ?> / <?= $part['max'] ?></span>
                        <span class="hbar-track"><span class="hbar-fill" style="display:block;width:<?= $pct ?>%;background:var(--<?= $pct >= 80 ? 'g-500' : ($pct >= 50 ? 'c-waste' : 'c-loss') ?>)"></span></span>
                        <span class="hbar-note"><?= e($part['detail']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div class="card ask-card" style="margin-top:18px">
    <div class="card-head">
        <div>
            <div class="card-title"><i class="fa-solid fa-robot"></i> Ask the advisor</div>
            <div class="card-sub"><?= $aiOn ? 'Powered by Claude AI · answers from this store\'s records' : 'Built-in answers from this store\'s records' ?></div>
        </div>
        <?php if ($aiOn): ?>
            <button type="button" class="btn btn-sm btn-ghost" id="newChat" title="Start over: the advisor forgets this conversation"><i class="fa-solid fa-plus"></i> New chat</button>
        <?php endif; ?>
    </div>
    <div class="card-body ask-layout">
        <div class="ask-main">
            <div class="ask-log" id="askLog" aria-live="polite">
                <div class="ask-msg bot">
                    <p>Hi <?= e($user['username']) ?>. I've analysed your stock and waste.
                        <?php if ($score): ?>Your Zero-Waste Score is <strong><?= $score['score'] ?>/100</strong> (<?= e($score['grade'][1]) ?>).<?php endif; ?>
                    </p>
                    <?php if ($aiOn): ?>
                        <p>Ask me anything about the store in your own words: stock, expiry dates, waste and reports, suppliers, deliveries, returns, the archive, user accounts, or what to order.</p>
                    <?php else: ?>
                        <p>Ask me what's in stock, what to do today, what will expire unsold, how much to order, about deliveries, returns, the archive, users or a month's report, or about any item or supplier by name.</p>
                    <?php endif; ?>
                </div>
                <?php foreach ($chat as $turn): ?>
                    <div class="ask-msg user"><?= e($turn['question']) ?></div>
                    <div class="ask-msg bot"><?= advisor_ai_render($turn['answer']) ?></div>
                <?php endforeach; ?>
            </div>
            <form class="ask-form" id="askForm" autocomplete="off">
                <input class="input" type="text" id="askInput" maxlength="500" placeholder="e.g. How many items are in stock?" aria-label="Your question">
                <button class="btn btn-primary" type="submit" id="askBtn"><i class="fa-solid fa-paper-plane"></i><span class="hide-sm">Ask</span></button>
            </form>
        </div>
        <aside class="ask-side" aria-label="Suggested questions">
            <div class="ask-side-title">Try asking</div>
            <div class="ask-chips">
                <?php foreach (ADVISOR_SUGGESTIONS as $s): ?>
                    <button type="button" class="ask-chip" data-ask="<?= e($s) ?>"><?= e($s) ?></button>
                <?php endforeach; ?>
            </div>
            <p class="ask-tip"><i class="fa-regular fa-lightbulb"></i>
                <?= $aiOn ? 'Follow-up questions work too, for example "and last month?". You can write in English, Filipino or Taglish.' : 'Ask about any item by name, for example "How much milk is in stock?".' ?>
            </p>
        </aside>
    </div>
</div>

<div class="card" style="margin-top:18px">
    <div class="card-head">
        <div>
            <div class="card-title"><i class="fa-solid fa-list-check"></i> Recommendations</div>
            <div class="card-sub"><?= $todo ? advisor_plural($todo, 'action') . ', most important first' : 'Nothing to fix right now' ?></div>
        </div>
        <?php if ($recs): $byPriority = array_count_values(array_column($recs, 'priority')); ?>
            <div class="rec-counts">
                <?php foreach (ADVISOR_PRIORITIES as $key => [$label, $tone]): if (!empty($byPriority[$key])): ?>
                    <span class="chip chip-<?= e($tone) ?>"><?= (int) $byPriority[$key] ?> <?= e(strtolower(str_replace(' priority', '', $label))) ?></span>
                <?php endif; endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($recs): ?>
            <ol class="rec-list">
                <?php foreach ($recs as $r): [$label, $tone] = ADVISOR_PRIORITIES[$r['priority']]; ?>
                    <li class="rec rec-<?= e($r['priority']) ?>">
                        <div class="rec-head">
                            <span class="rec-icon tone-<?= e($tone) ?>"><i class="fa-solid <?= e($r['icon']) ?>"></i></span>
                            <div>
                                <h3 class="rec-title"><?= e($r['title']) ?></h3>
                                <div class="rec-meta">
                                    <span class="chip chip-<?= e($tone) ?>"><?= e($label) ?></span>
                                    <?php if ($r['impact']): ?><span class="chip chip-gray"><?= peso($r['impact'], 0) ?> <?= e($r['impact_label']) ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <p class="rec-why"><?= advisor_text($r['why']) ?></p>
                        <?php if ($r['actions']): ?>
                            <ul class="rec-actions">
                                <?php foreach ($r['actions'] as $action): ?><li><?= advisor_text($action) ?></li><?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($r['link']): ?>
                            <a class="btn btn-sm" href="<?= e($r['link'][1]) ?>"><?= e($r['link'][0]) ?> <i class="fa-solid fa-arrow-right"></i></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php elseif (!$a['products']): ?>
            <div class="empty"><i class="fa-solid fa-seedling"></i><strong>Nothing to learn from yet</strong>Add items, receive stock and log waste, and recommendations will appear here.</div>
        <?php else: ?>
            <div class="empty"><i class="fa-solid fa-circle-check"></i><strong>No recommendations</strong>Stock is on track to sell before it expires, and nothing stands out in the last <?= ADVISOR_WINDOW_DAYS ?> days.</div>
        <?php endif; ?>
    </div>
</div>

<div class="table-card" style="margin-top:18px">
    <div class="card-head" style="padding-bottom:14px">
        <div>
            <div class="card-title"><i class="fa-solid fa-chart-line"></i> Expiry forecast</div>
            <div class="card-sub">Stock that won't sell before it expires in the next <?= ADVISOR_HORIZON_DAYS ?> days at its usual pace, oldest batch first</div>
        </div>
    </div>
    <?php if ($a['forecast']): ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Item</th><th class="center">In stock</th><th>Sells</th><th>Expires</th><th class="center">Left over</th><th class="text-right">Value at risk</th><th>What to do</th></tr></thead>
                <tbody>
                <?php foreach ($a['forecast'] as $r): [$freshLabel, $freshTone] = freshness_status($r['days_left']); ?>
                    <tr>
                        <td class="cell-title"><?= e($r['name']) ?></td>
                        <td class="center qty-big"><?= (int) $r['quantity'] ?></td>
                        <td class="nowrap"><?= e(advisor_pace($r['pace'], $r['unit'])) ?><div class="cell-sub"><?= $r['confidence'] === 'none' ? 'new item' : e($r['confidence']) . ' confidence' ?></div></td>
                        <td class="nowrap"><span class="chip chip-<?= e($freshTone) ?>"><?= e($freshLabel) ?></span><div class="cell-sub"><?= date('M j', strtotime($r['expires'])) ?></div></td>
                        <td class="center strong"><?= (int) $r['leftover'] ?> <span class="muted"><?= e($r['unit']) ?></span></td>
                        <td class="text-right strong num"><?= peso($r['value']) ?></td>
                        <td class="advisor-action"><?= e($r['action']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty"><i class="fa-solid fa-circle-check"></i><strong>Everything should sell in time</strong>At the current pace, no stock is forecast to expire unsold in the next <?= ADVISOR_HORIZON_DAYS ?> days.</div>
    <?php endif; ?>
</div>

<div class="table-card" style="margin-top:18px">
    <div class="card-head" style="padding-bottom:14px">
        <div>
            <div class="card-title"><i class="fa-solid fa-cart-shopping"></i> Smart order sizes</div>
            <div class="card-sub">The most to order at once: what sells within one shelf life (30 days at most), minus stock that will still sell</div>
        </div>
    </div>
    <?php if ($a['order_plan']): ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>Item</th><th>Sells</th><th class="center">Keeps</th><th class="center">Will still sell</th><th class="center">Wasted</th><th class="center">Order at most</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($a['order_plan'], 0, 15) as $o): ?>
                    <tr>
                        <td class="cell-title"><?= e($o['name']) ?></td>
                        <td class="nowrap"><?= e(advisor_pace($o['pace'], $o['unit'])) ?><div class="cell-sub"><?= e($o['confidence']) ?> confidence</div></td>
                        <td class="center nowrap"><?= advisor_plural($o['shelf'], 'day') ?></td>
                        <td class="center"><?= $o['sellable'] ?> <span class="muted"><?= e($o['unit']) ?></span><div class="cell-sub">~<?= advisor_plural((int) floor($o['cover']), 'day') ?></div></td>
                        <td class="center"><?= $o['waste_share'] !== null ? number_format($o['waste_share'] * 100, 0) . '%' : '—' ?></td>
                        <td class="center qty-big"><?= $o['cap'] ?> <span class="muted"><?= e($o['unit']) ?></span></td>
                        <td><span class="chip chip-<?= e($o['status'][1]) ?>"><?= e($o['status'][0]) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($a['order_plan']) > 15): ?>
            <div class="table-foot"><span><?= count($a['order_plan']) - 15 ?> more items. Ask the advisor about any of them by name.</span></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty"><i class="fa-solid fa-hourglass-start"></i><strong>Not enough sales history yet</strong>Once items have sold for a week or two, safe order sizes appear here.</div>
    <?php endif; ?>
</div>

<details class="card advisor-method" style="margin-top:18px">
    <summary class="card-body"><i class="fa-solid fa-circle-info"></i> How the advisor works</summary>
    <div class="card-body" style="padding-top:0">
        <ul>
            <li><strong>Only your data.</strong> The score, recommendations, forecast and order sizes are worked out on this server from stock received, waste logs, supplier returns, suppliers and storage locations. They call no outside service.</li>
            <?php if ($aiOn): ?>
                <li><strong>Ask the advisor.</strong> Questions are answered by Claude, Anthropic's AI. It looks up what it needs through read-only lookups (stock, waste, stock received, supplier returns, suppliers, deliveries and this analysis), so it can't change anything. Your question and the records it looks up are sent to Anthropic. It remembers the last <?= ADVISOR_AI_HISTORY_TURNS ?> questions until you start a new chat.</li>
            <?php else: ?>
                <li><strong>Ask the advisor.</strong> Built-in answers matched to your question. Add an Anthropic API key (ANTHROPIC_API_KEY in config.local.php) to let Claude answer any question about the store in plain language.</li>
            <?php endif; ?>
            <li><strong>Sales pace.</strong> Units that left stock without being wasted or sent back (received − wasted − sent back − in stock now), divided by the days since the item was first stocked. In-store sales are counted too, because they lower the stock.</li>
            <li><strong>Expiry forecast.</strong> Batches sell oldest-expiry first at that pace. Whatever can't sell by the end of its best-before date is predicted waste.</li>
            <li><strong>Smart order sizes.</strong> Pace × shelf life, capped at 30 days, minus the stock that will still sell. Shelf life is how long this store's own batches lasted from delivery to best-before, or the built-in shelf-life profiles for new items.</li>
            <li><strong>Recommendations.</strong> Waste reasons, suppliers, storage and the 30-day trend are checked against fixed rules, then ranked by urgency and money at stake.</li>
            <li><strong>Zero-Waste Score.</strong> Waste as a share of stock cost (50 points), stock forecast to expire unsold (25), the 30-day trend (15), and how much expired stock staff caught before the system had to clear it (10).</li>
            <li><strong>Confidence.</strong> Low with under a week of history or very few sales; it improves as more stock is received, sold and logged.</li>
        </ul>
    </div>
</details>

<?php ob_start(); ?>
<script>
(function () {
    const log = document.getElementById('askLog');
    const form = document.getElementById('askForm');
    const input = document.getElementById('askInput');
    const btn = document.getElementById('askBtn');

    function addMessage(role, html) {
        const div = document.createElement('div');
        div.className = 'ask-msg ' + role;
        div.innerHTML = html;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        return div;
    }

    function ask(question) {
        question = question.trim();
        if (!question || btn.disabled) return;
        addMessage('user', escapeHtml(question));
        input.value = '';
        const reply = addMessage('bot', '<span class="ask-typing">Analysing your records…</span>');
        btn.disabled = true;
        postForm('ai_advisor.php', { action: 'ask', question })
            .then((d) => { reply.innerHTML = d.success ? d.html : '<p>' + escapeHtml(d.message || 'Something went wrong.') + '</p>'; })
            .catch(() => { reply.innerHTML = '<p>The advisor could not be reached. Reload the page and try again.</p>'; })
            .finally(() => { btn.disabled = false; input.focus(); log.scrollTop = log.scrollHeight; });
    }

    form.addEventListener('submit', (e) => { e.preventDefault(); ask(input.value); });
    document.querySelectorAll('[data-ask]').forEach((chip) => chip.addEventListener('click', () => ask(chip.dataset.ask)));

    // Earlier questions from this chat are shown on load; New chat makes the advisor forget them.
    log.scrollTop = log.scrollHeight;
    document.getElementById('newChat')?.addEventListener('click', () => {
        if (btn.disabled) return;
        postForm('ai_advisor.php', { action: 'new_chat' })
            .then(() => { log.querySelectorAll('.ask-msg:not(:first-child)').forEach((m) => m.remove()); input.focus(); })
            .catch(() => {});
    });
})();
</script>
<?php render_shell_end(ob_get_clean()); ?>
