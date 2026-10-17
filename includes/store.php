<?php
// Customer storefront: settings, retail pricing, live catalog, pickup slots and reservations.
// Reservations hold stock; inventory is only deducted when staff mark an order as picked up.

const STORE_DEFAULTS = [
    'store_name' => 'Lisa Grocery Store',
    'tagline' => 'Your neighborhood store for fresh produce, daily essentials and friendly service.',
    'address' => '',
    'phone' => '',
    'email' => '',
    'facebook_url' => '',
    'open_time' => '07:00',
    'close_time' => '21:00',
    'default_markup' => '25',
    'pickup_enabled' => '1',
    'pickup_days' => '3',
    'prep_minutes' => '60',
    'announcement' => '',
    // JSON {"1": {"open": "07:00", "close": "21:00", "closed": 0}, ... "7": {...}} (1 = Monday).
    // Empty = every day uses open_time / close_time.
    'weekly_hours' => '',
    'store_status' => 'auto',
    'status_date' => '',
    'payment_online_details' => '',
    'payment_bank_details' => '',
];

const WEEK_DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

// Manual Open/Closed switch. The "now" options only last until midnight.
const STORE_STATUS_OPTIONS = [
    'auto' => 'Automatic: open and closed by the weekly schedule',
    'open_now' => 'Open now: ignore the schedule for the rest of today',
    'closed_now' => 'Closed now: ignore the schedule for the rest of today',
    'closed' => 'Closed until I reopen',
];

// [label, icon, short description] for how a customer pays for a reservation.
const PAYMENT_METHODS = [
    'cash' => ['Cash', 'fa-money-bill-wave', 'Pay at the counter when you pick up'],
    'card' => ['Card', 'fa-credit-card', 'Debit or credit card at the counter'],
    'online' => ['Online payment', 'fa-mobile-screen-button', 'GCash, Maya or another e-wallet'],
    'bank_transfer' => ['Bank transfer', 'fa-building-columns', 'Transfer to the store\'s bank account'],
];

const OPEN_ORDER_STATUSES = "('pending', 'ready')";
const MAX_ORDER_LINES = 25;
const MAX_LINE_QTY = 50;
const JUST_ARRIVED_DAYS = 3;

// Stock already promised to open reservations, per item.
const RESERVED_JOIN = "LEFT JOIN (SELECT coi.food_item_id, SUM(coi.quantity) AS reserved
                                  FROM customer_order_items coi
                                  JOIN customer_orders co ON co.id = coi.order_id
                                  WHERE co.status IN " . OPEN_ORDER_STATUSES . "
                                  GROUP BY coi.food_item_id) rsv ON rsv.food_item_id = fi.id";

/* ---------- Settings ---------- */

function store_settings(bool $reload = false): array {
    static $cache = null;
    if ($cache === null || $reload) {
        $saved = array_column(db_all('SELECT setting_key, setting_value FROM app_settings'), 'setting_value', 'setting_key');
        $cache = array_merge(STORE_DEFAULTS, array_intersect_key($saved, STORE_DEFAULTS));
    }
    return $cache;
}

function save_store_settings(array $values): void {
    foreach (array_intersect_key($values, STORE_DEFAULTS) as $key => $value) {
        db_exec(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            'ss',
            [$key, (string) $value]
        );
    }
    store_settings(true);
}

function pickup_enabled(): bool {
    return store_settings()['pickup_enabled'] === '1';
}

function format_time_12h(string $hhmm): string {
    return date('g:i A', strtotime('2000-01-01 ' . $hhmm));
}

function valid_hhmm(string $time): bool {
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
}

/* ---------- Store hours & Open/Closed status ---------- */

// The weekly schedule: [1..7 => ['open' => 'HH:MM', 'close' => 'HH:MM', 'closed' => bool]].
function store_week(): array {
    $s = store_settings();
    $saved = json_decode((string) $s['weekly_hours'], true);
    $saved = is_array($saved) ? $saved : [];
    $week = [];
    foreach (WEEK_DAYS as $n => $label) {
        $day = is_array($saved[$n] ?? null) ? $saved[$n] : [];
        $open = (string) ($day['open'] ?? '');
        $close = (string) ($day['close'] ?? '');
        $week[$n] = [
            'open' => valid_hhmm($open) ? $open : $s['open_time'],
            'close' => valid_hhmm($close) ? $close : $s['close_time'],
            'closed' => !empty($day['closed']),
        ];
    }
    return $week;
}

// The manual status that applies right now ('auto' once a "now" override has expired).
function store_override(): string {
    $s = store_settings();
    $status = array_key_exists($s['store_status'], STORE_STATUS_OPTIONS) ? $s['store_status'] : 'auto';
    if (in_array($status, ['open_now', 'closed_now'], true) && $s['status_date'] !== date('Y-m-d')) {
        return 'auto';
    }
    return $status;
}

// Opening hours for a calendar day, or null when the store is closed that day.
function hours_on(DateTimeInterface $day): ?array {
    $override = store_override();
    if ($override === 'closed' || ($override === 'closed_now' && $day->format('Y-m-d') === date('Y-m-d'))) {
        return null;
    }
    $hours = store_week()[(int) $day->format('N')];
    return $hours['closed'] ? null : ['open' => $hours['open'], 'close' => $hours['close']];
}

function store_open_now(): bool {
    $override = store_override();
    if ($override === 'open_now') {
        return true;
    }
    $hours = hours_on(new DateTimeImmutable('today'));
    $now = date('H:i');
    return $hours !== null && $now >= $hours['open'] && $now < $hours['close'];
}

// When the store next opens: "7:00 AM", "tomorrow 7:00 AM" or "Mon 7:00 AM".
function next_opening(): ?string {
    $now = new DateTimeImmutable('now');
    for ($d = 0; $d <= 7; $d++) {
        $day = $now->setTime(0, 0)->modify("+$d days");
        $hours = hours_on($day);
        if ($hours === null || new DateTimeImmutable($day->format('Y-m-d') . ' ' . $hours['open']) <= $now) {
            continue;
        }
        $time = format_time_12h($hours['open']);
        return match ($d) {
            0 => $time,
            1 => 'tomorrow ' . $time,
            default => $day->format('D') . ' ' . $time,
        };
    }
    return null;
}

// What the storefront says: ['open' => bool, 'label' => 'Open now', 'detail' => 'Closes 9:00 PM'].
function store_status(): array {
    $override = store_override();
    if (store_open_now()) {
        $hours = hours_on(new DateTimeImmutable('today'));
        $detail = $override === 'open_now' || $hours === null || date('H:i') >= $hours['close']
            ? 'Open today'
            : 'Closes ' . format_time_12h($hours['close']);
        return ['open' => true, 'label' => 'Open now', 'detail' => $detail];
    }
    if ($override === 'closed') {
        return ['open' => false, 'label' => 'Closed', 'detail' => 'Temporarily closed'];
    }
    $next = next_opening();
    return ['open' => false, 'label' => 'Closed now', 'detail' => $next ? 'Opens ' . $next : 'Check back soon'];
}

// Today's hours, e.g. "7:00 AM – 9:00 PM" or "Closed today".
function store_hours_label(): string {
    if (store_override() === 'open_now') {
        return 'Open today';
    }
    $hours = hours_on(new DateTimeImmutable('today'));
    return $hours ? format_time_12h($hours['open']) . ' – ' . format_time_12h($hours['close']) : 'Closed today';
}

// The weekly schedule with identical neighbouring days grouped: [['days' => 'Mon – Fri', 'hours' => '…', 'today' => bool]].
function store_week_summary(): array {
    $rows = [];
    foreach (store_week() as $n => $h) {
        $text = $h['closed'] ? 'Closed' : format_time_12h($h['open']) . ' – ' . format_time_12h($h['close']);
        $last = count($rows) - 1;
        if ($last >= 0 && $rows[$last]['hours'] === $text) {
            $rows[$last]['to'] = $n;
        } else {
            $rows[] = ['from' => $n, 'to' => $n, 'hours' => $text];
        }
    }
    $short = fn (int $n) => substr(WEEK_DAYS[$n], 0, 3);
    $today = (int) date('N');
    foreach ($rows as &$row) {
        $row['days'] = match (true) {
            $row['from'] === 1 && $row['to'] === 7 => 'Every day',
            $row['from'] === $row['to'] => $short($row['from']),
            default => $short($row['from']) . ' – ' . $short($row['to']),
        };
        $row['today'] = $today >= $row['from'] && $today <= $row['to'];
    }
    unset($row);
    return $rows;
}

/* ---------- Payment ---------- */

function payment_label(?string $method): string {
    return PAYMENT_METHODS[$method ?? ''][0] ?? 'Cash';
}

function payment_icon(?string $method): string {
    return PAYMENT_METHODS[$method ?? ''][1] ?? 'fa-money-bill-wave';
}

// What the customer needs to do to pay, shown after reserving and on the status page.
function payment_instructions(?string $method): string {
    $s = store_settings();
    return match ($method) {
        'online' => trim($s['payment_online_details']) !== ''
            ? 'Send your payment to: ' . trim($s['payment_online_details']) . '. Show the receipt when you pick up.'
            : 'The store will text you the e-wallet details for your payment.',
        'bank_transfer' => trim($s['payment_bank_details']) !== ''
            ? 'Transfer to: ' . trim($s['payment_bank_details']) . '. Show the receipt when you pick up.'
            : 'The store will text you the bank details for your transfer.',
        'card' => 'Pay by debit or credit card at the counter when you pick up.',
        default => 'Pay in cash at the counter when you pick up.',
    };
}

/* ---------- Pricing ---------- */

// Shelf price before any markdown. An explicit selling price wins; otherwise the
// store's default markup is applied to the purchase price. Null = not sellable online.
function retail_price($sellingPrice, $purchasePrice): ?float {
    if ($sellingPrice !== null && $sellingPrice !== '') {
        return round((float) $sellingPrice, 2);
    }
    if ($purchasePrice === null || $purchasePrice === '') {
        return null;
    }
    $markup = max(0, (float) store_settings()['default_markup']);
    return round((float) $purchasePrice * (1 + $markup / 100), 2);
}

function apply_discount(float $price, int $percent): float {
    return round($price * (100 - $percent) / 100, 2);
}

/* ---------- Catalog ---------- */

function category_style(string $category): array {
    $c = strtolower($category);
    return match (true) {
        str_contains($c, 'veg') => ['fa-carrot', 'green'],
        str_contains($c, 'fruit') => ['fa-apple-whole', 'orange'],
        str_contains($c, 'dairy'), str_contains($c, 'egg') => ['fa-cheese', 'blue'],
        str_contains($c, 'meat'), str_contains($c, 'sea'), str_contains($c, 'fish') => ['fa-fish', 'rose'],
        str_contains($c, 'bak'), str_contains($c, 'bread') => ['fa-bread-slice', 'amber'],
        str_contains($c, 'pantry'), str_contains($c, 'dry'), str_contains($c, 'can') => ['fa-jar', 'violet'],
        default => ['fa-basket-shopping', 'gray'],
    };
}

// Picture for a product, matched on words at the start of its name (English and Filipino).
// Order matters: look-alikes come first, so "egg noodles" is not an egg and "sweet potato" is not a potato.
const ITEM_EMOJI = [
    'peanut|mani\b' => '🥜',
    'milk ?tea|bubble tea|boba' => '🧋',
    'ice ?cream|sorbet|gelato' => '🍨',
    'chocolate|choco|cocoa|tsokolate' => '🍫',
    'noodle|pancit|ramen|mami\b|lomi\b|bihon|canton|misua|miswa|sotanghon' => '🍜',
    'spaghetti|pasta|macaroni|penne|lasagna|fettuccine' => '🍝',
    'corned|luncheon|spam\b|vienna|sardin|canned|de lata' => '🥫',
    'popcorn' => '🍿',
    'french fries|fries\b' => '🍟',
    'eggplant|talong|aubergine' => '🍆',
    'sweet potato|kamote|camote|yam\b|ube\b|gabi\b|taro\b' => '🍠',
    'pineapple|pinya' => '🍍',
    'watermelon|pakwan' => '🍉',
    'melon|cantaloupe' => '🍈',
    'banana|saging|saba\b|lakatan|latundan' => '🍌',
    'apple|mansanas' => '🍎',
    'orange|dalandan|dalanghita|mandarin|ponkan|tangerine|calamansi|kalamansi' => '🍊',
    'lemon|limes?\b|dayap' => '🍋',
    'grape|ubas\b' => '🍇',
    'strawberr' => '🍓',
    'blueberr|blackberr|raspberr' => '🫐',
    'cherr' => '🍒',
    'peach' => '🍑',
    'pears?\b|peras\b' => '🍐',
    'mango|mangga' => '🥭',
    'kiwi' => '🥝',
    'coconut|niyog|buko\b' => '🥥',
    'avocado|abokado' => '🥑',
    'tomato|kamatis' => '🍅',
    'potato|patatas' => '🥔',
    'carrot|karot' => '🥕',
    'corns?\b|mais\b' => '🌽',
    'broccoli|cauliflower' => '🥦',
    'cabbage|repolyo|lettuce|litsugas|pechay|petsay|bok ?choy|pak ?choi|kangkong|spinach|alugbati|malunggay|kale\b|mustasa|celery|kintsay|wombok' => '🥬',
    'cucumber|pipino|zucchini|upo\b|patola|ampalaya|bitter gourd|okra|sitaw|string beans?|green beans?|baguio beans?' => '🥒',
    'bell pepper|capsicum' => '🫑',
    'chil+i|sili\b|siling|labuyo|jalape' => '🌶️',
    'garlic|bawang' => '🧄',
    'onion|sibuyas|shallot|scallion|leeks?\b' => '🧅',
    'mushroom|kabute' => '🍄',
    'basil|parsley|cilantro|wansoy|coriander|lemongrass|tanglad|mint\b|oregano|pandan|herb' => '🌿',
    'cheese|keso|queso' => '🧀',
    'butter|margarine|mantikilya' => '🧈',
    'milk|gatas|cream|creamer|evap|condensed|yogurt|yoghurt|yakult' => '🥛',
    'egg|itlog|balut|penoy' => '🥚',
    'shrimp|prawn|hipon|sugpo' => '🦐',
    'squid|pusit|calamari|octopus|pugita' => '🦑',
    'crab|alimango|alimasag|talangka' => '🦀',
    'lobster|ulang\b' => '🦞',
    'oyster|talaba|clams?\b|halaan|tahong|mussel|scallop' => '🦪',
    'fish|isda|tilapia|bangus|milkfish|galunggong|tuna\b|salmon|lapu|dilis|tuyo\b|daing|tamban|mackerel|alumahan|tulingan|maya-maya' => '🐟',
    'chicken|manok|poultry|drumstick|wings?\b|thighs?\b' => '🍗',
    'bacon' => '🥓',
    'hot ?dogs?\b|sausage|longganisa|longanisa|franks?\b' => '🌭',
    'burger|patty|patties' => '🍔',
    'hams?\b|hamon|tocino|pork|baboy|liempo|kasim|pigue|lechon|ribs\b' => '🍖',
    'beef|baka\b|steak|sirloin|brisket|giniling|ground meat|meat' => '🥩',
    'siomai|siopao|shumai|dumpling|gyoza|wonton|lumpia|spring roll' => '🥟',
    'pizza' => '🍕',
    'sandwich' => '🥪',
    'sushi|maki\b' => '🍣',
    'croissant' => '🥐',
    'baguette' => '🥖',
    'pandesal|pan de sal|bread|tinapay|loaf|buns?\b|toast|ensaymada|monay' => '🍞',
    'cake|mamon|cupcake|muffin|brownie' => '🍰',
    'donut|doughnut' => '🍩',
    'cookie|biscuit|cracker|galletas|wafer' => '🍪',
    'pies?\b|tarts?\b' => '🥧',
    'rice|bigas|kanin|dinorado|jasmine' => '🍚',
    'flour|harina|oats?\b|oatmeal|cereal|granola|wheat' => '🌾',
    'coffee|kape\b|espresso|nescafe|3-in-1|3 in 1' => '☕',
    'teas?\b|tsaa|matcha' => '🍵',
    'juice|nectar|zest-o|tang\b' => '🧃',
    'soda|soft ?drinks?|coke|cola\b|sprite|pepsi|7 ?up|mountain dew|royal|energy drink|sparkling' => '🥤',
    'water|mineral|distilled|tubig' => '💧',
    'beer|serbesa|red horse' => '🍺',
    'wine' => '🍷',
    'honey|pulot|jam\b|jelly|marmalade' => '🍯',
    'candy|candies|kendi|lollipop|gumm|marshmallow|sugar|asukal' => '🍬',
    'salt|asin\b|pepper\b|paminta|seasoning|magic sarap|bouillon' => '🧂',
    'oil\b|mantika|olive' => '🫒',
    'soy sauce|toyo\b|vinegar|suka\b|patis|fish sauce|ketchup|catsup|sauce|mayonnaise|mayo\b|dressing|bagoong' => '🍶',
    'chips|chippy|crisps|snack|nachos' => '🍿',
    'nuts?\b|almond|cashew|kasoy|pili\b|walnut|pistachio|chestnut|kastanyas' => '🌰',
    'frozen|ice\b' => '🧊',
];

function category_emoji(string $category): ?string {
    $c = strtolower($category);
    return match (true) {
        str_contains($c, 'veg') => '🥬',
        str_contains($c, 'fruit') => '🍎',
        str_contains($c, 'egg') => '🥚',
        str_contains($c, 'dairy'), str_contains($c, 'milk') => '🥛',
        str_contains($c, 'meat'), str_contains($c, 'poultry') => '🥩',
        str_contains($c, 'sea'), str_contains($c, 'fish') => '🐟',
        str_contains($c, 'bak'), str_contains($c, 'bread') => '🍞',
        str_contains($c, 'bever'), str_contains($c, 'drink') => '🥤',
        str_contains($c, 'snack'), str_contains($c, 'candy') => '🍪',
        str_contains($c, 'frozen') => '🧊',
        str_contains($c, 'can') => '🥫',
        str_contains($c, 'condiment'), str_contains($c, 'spice'), str_contains($c, 'sauce') => '🧂',
        str_contains($c, 'pantry'), str_contains($c, 'dry'), str_contains($c, 'grain'), str_contains($c, 'rice') => '🍚',
        default => null,
    };
}

// The picture for the actual item, falling back to its category. Null = use the category icon.
function item_emoji(string $name, string $category = ''): ?string {
    foreach (ITEM_EMOJI as $pattern => $emoji) {
        if (preg_match('/\b(?:' . $pattern . ')/iu', $name)) {
            return $emoji;
        }
    }
    return category_emoji($category);
}

// Everything customers can see: listed, not archived, not expired and priced.
function store_catalog(): array {
    $rows = db_all(
        "SELECT fi.id, fi.name, fi.unit, fi.quantity, fi.selling_price, fi.purchase_price, fi.expiration_date,
                DATEDIFF(fi.expiration_date, CURDATE()) AS days_left, fc.name AS category,
                COALESCE(rsv.reserved, 0) AS reserved, lr.last_received
         FROM food_items fi
         JOIN food_categories fc ON fc.id = fi.category_id
         " . RESERVED_JOIN . "
         LEFT JOIN (SELECT food_item_id, MAX(received_at) AS last_received FROM stock_receipts GROUP BY food_item_id) lr
                ON lr.food_item_id = fi.id
         WHERE fi.archived_at IS NULL AND fi.is_listed = 1 AND fi.expiration_date >= CURDATE()
         ORDER BY fc.name, fi.name"
    );

    $catalog = [];
    $arrivedSince = date('Y-m-d H:i:s', strtotime('-' . JUST_ARRIVED_DAYS . ' days'));
    foreach ($rows as $r) {
        $price = retail_price($r['selling_price'], $r['purchase_price']);
        if ($price === null) {
            continue;
        }
        $days = (int) $r['days_left'];
        $available = max(0, (int) $r['quantity'] - (int) $r['reserved']);
        $discount = $available > 0 ? discount_percent($days) : 0;
        $catalog[] = [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'unit' => $r['unit'] ?: 'pc',
            'category' => $r['category'],
            'emoji' => item_emoji($r['name'], $r['category']),
            'price' => $price,
            'discount' => $discount,
            'sale_price' => $discount ? apply_discount($price, $discount) : $price,
            'available' => $available,
            'days_left' => $days,
            'expiration_date' => $r['expiration_date'],
            'just_arrived' => $r['last_received'] !== null && $r['last_received'] >= $arrivedSince,
        ];
    }
    return $catalog;
}

function best_before_label(int $daysLeft, string $date): string {
    return match (true) {
        $daysLeft <= 0 => 'Best before today',
        $daysLeft === 1 => 'Best before tomorrow',
        $daysLeft <= 7 => 'Best before ' . date('D, M j', strtotime($date)),
        default => 'Best before ' . date('M j', strtotime($date)),
    };
}

/* ---------- Pickup scheduling ---------- */

// Selectable pickup days and hourly times, honoring store hours and preparation time.
// Returns ['Y-m-d' => ['label' => 'Today · Wed, Sep 30', 'times' => ['15:00' => '3:00 PM', ...]]].
function pickup_slots(?DateTimeImmutable $now = null): array {
    $s = store_settings();
    $now ??= new DateTimeImmutable('now');
    $earliest = $now->modify('+' . max(0, (int) $s['prep_minutes']) . ' minutes');
    $days = max(1, min(7, (int) $s['pickup_days']));
    $slots = [];

    for ($d = 0; $d < $days; $d++) {
        $day = $now->setTime(0, 0)->modify("+$d days");
        $hours = hours_on($day);
        if ($hours === null) {
            continue; // closed that day (schedule or manual status)
        }
        $open = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $hours['open']);
        $lastStart = (new DateTimeImmutable($day->format('Y-m-d') . ' ' . $hours['close']))->modify('-30 minutes');
        $times = [];
        for ($t = $open; $t <= $lastStart; $t = $t->modify('+1 hour')) {
            if ($t >= $earliest) {
                $times[$t->format('H:i')] = $t->format('g:i A');
            }
        }
        if ($times) {
            $prefix = $d === 0 ? 'Today · ' : ($d === 1 ? 'Tomorrow · ' : '');
            $slots[$day->format('Y-m-d')] = ['label' => $prefix . $day->format('D, M j'), 'times' => $times];
        }
    }
    return $slots;
}

function pickup_label(string $date, string $time): string {
    $today = date('Y-m-d');
    $prefix = $date === $today ? 'Today' : ($date === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : date('D, M j', strtotime($date)));
    return $prefix . ', ' . format_time_12h($time);
}

/* ---------- Customers & orders ---------- */

// Philippine mobile numbers only (09XXXXXXXXX), so staff can always text or call back.
function normalize_phone(string $raw): ?string {
    $digits = preg_replace('/\D+/', '', $raw);
    if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
        $digits = '0' . substr($digits, 2);
    } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
        $digits = '0' . $digits;
    }
    return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
}

function format_phone(string $phone): string {
    return preg_match('/^(\d{4})(\d{3})(\d{4})$/', $phone, $m) ? "$m[1] $m[2] $m[3]" : $phone;
}

function new_order_code(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = 'LG-';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
    } while (db_value('SELECT COUNT(*) FROM customer_orders WHERE code = ?', 's', [$code]));
    return $code;
}

function order_status_meta(string $status): array {
    return match ($status) {
        'pending' => ['Received', 'amber', 'fa-inbox'],
        'ready' => ['Ready for pickup', 'blue', 'fa-bag-shopping'],
        'completed' => ['Picked up', 'green', 'fa-circle-check'],
        'cancelled' => ['Cancelled', 'red', 'fa-ban'],
        default => [ucfirst($status), 'gray', 'fa-circle'],
    };
}

/**
 * Validates and saves a pickup reservation.
 * $cart = [foodItemId => quantity]; $customer = name, phone, email, pickup_date, pickup_time, notes.
 * Returns ['ok' => true, 'order' => [...]] or ['ok' => false, 'message' => ..., 'item_errors' => [id => msg]].
 */
function place_order(array $cart, array $customer, string $ip): array {
    if (!pickup_enabled()) {
        return ['ok' => false, 'message' => 'Online reservations are paused right now. Please visit the store.'];
    }

    $lines = [];
    foreach ($cart as $id => $qty) {
        $id = (int) $id;
        $qty = (int) $qty;
        if ($id > 0 && $qty > 0) {
            $lines[$id] = min($qty, MAX_LINE_QTY);
        }
    }
    if (!$lines) {
        return ['ok' => false, 'message' => 'Your basket is empty.'];
    }
    if (count($lines) > MAX_ORDER_LINES) {
        return ['ok' => false, 'message' => 'Please reserve at most ' . MAX_ORDER_LINES . ' different items per order.'];
    }

    $name = trim(preg_replace('/\s+/', ' ', (string) ($customer['name'] ?? '')));
    $phone = normalize_phone((string) ($customer['phone'] ?? ''));
    $email = trim((string) ($customer['email'] ?? ''));
    $notes = trim((string) ($customer['notes'] ?? ''));
    $date = (string) ($customer['pickup_date'] ?? '');
    $time = (string) ($customer['pickup_time'] ?? '');
    $payment = (string) ($customer['payment_method'] ?? '');

    if (!isset(PAYMENT_METHODS[$payment])) {
        return ['ok' => false, 'message' => 'Please choose how you will pay.'];
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        return ['ok' => false, 'message' => 'Please enter your name.'];
    }
    if ($phone === null) {
        return ['ok' => false, 'message' => 'Please enter a valid mobile number, like 0917 123 4567.'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'That email address does not look right.'];
    }
    if (mb_strlen($notes) > 500) {
        return ['ok' => false, 'message' => 'Notes can be at most 500 characters.'];
    }
    $slots = pickup_slots();
    if (!isset($slots[$date]['times'][$time])) {
        return ['ok' => false, 'message' => 'That pickup time is no longer available. Please choose another.'];
    }

    // Light abuse protection for a public form.
    if ((int) db_value('SELECT COUNT(*) FROM customer_orders WHERE ip_address = ? AND created_at > NOW() - INTERVAL 15 MINUTE', 's', [$ip]) >= 3) {
        return ['ok' => false, 'message' => 'Too many reservations from this device. Please wait a few minutes.'];
    }
    if ((int) db_value('SELECT COUNT(*) FROM customer_orders WHERE phone = ? AND status IN ' . OPEN_ORDER_STATUSES, 's', [$phone]) >= 3) {
        return ['ok' => false, 'message' => 'This number already has 3 open reservations. Please pick those up first or call the store.'];
    }

    return db_transaction(function () use ($lines, $name, $phone, $email, $notes, $date, $time, $payment, $ip) {
        $ids = array_keys($lines);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // Lock the item rows so two customers can't reserve the same last units at once.
        $items = array_column(db_all(
            "SELECT id, name, unit, quantity, selling_price, purchase_price, expiration_date, is_listed, archived_at,
                    DATEDIFF(expiration_date, CURDATE()) AS days_left
             FROM food_items WHERE id IN ($placeholders) FOR UPDATE",
            str_repeat('i', count($ids)),
            $ids
        ), null, 'id');
        $reserved = array_column(db_all(
            "SELECT coi.food_item_id, SUM(coi.quantity) AS reserved
             FROM customer_order_items coi JOIN customer_orders co ON co.id = coi.order_id
             WHERE co.status IN " . OPEN_ORDER_STATUSES . " AND coi.food_item_id IN ($placeholders)
             GROUP BY coi.food_item_id",
            str_repeat('i', count($ids)),
            $ids
        ), 'reserved', 'food_item_id');

        $errors = [];
        $orderLines = [];
        $total = 0.0;
        $savings = 0.0;
        foreach ($lines as $id => $qty) {
            $item = $items[$id] ?? null;
            $price = $item ? retail_price($item['selling_price'], $item['purchase_price']) : null;
            if (!$item || $item['archived_at'] !== null || !(int) $item['is_listed'] || $price === null) {
                $errors[$id] = 'No longer available online.';
                continue;
            }
            if ($item['expiration_date'] < $date) {
                $errors[$id] = 'Best before ' . date('M j', strtotime($item['expiration_date'])) . ', which is before your pickup day.';
                continue;
            }
            $available = (int) $item['quantity'] - (int) ($reserved[$id] ?? 0);
            if ($available < $qty) {
                $errors[$id] = $available > 0 ? "Only $available left." : 'Sold out.';
                continue;
            }
            $discount = discount_percent((int) $item['days_left']);
            $unitPrice = $discount ? apply_discount($price, $discount) : $price;
            $lineTotal = round($unitPrice * $qty, 2);
            $total += $lineTotal;
            $savings += round(($price - $unitPrice) * $qty, 2);
            $orderLines[] = [$id, $item['name'], $item['unit'], $qty, $price, $discount, $unitPrice, $lineTotal];
        }

        if ($errors) {
            // Returning (not throwing) commits an empty transaction, which only releases the locks.
            return ['ok' => false, 'message' => 'Some items in your basket changed. Please review them.', 'item_errors' => $errors];
        }

        $code = new_order_code();
        db_exec(
            'INSERT INTO customer_orders (code, customer_name, phone, email, pickup_date, pickup_time, notes, payment_method, total, savings, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'ssssssssdds',
            [$code, $name, $phone, $email !== '' ? $email : null, $date, $time, $notes !== '' ? $notes : null, $payment, round($total, 2), round($savings, 2), $ip]
        );
        $orderId = db_last_id();
        foreach ($orderLines as $l) {
            db_exec(
                'INSERT INTO customer_order_items (order_id, food_item_id, item_name, unit, quantity, regular_price, discount_percent, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'iissididd',
                [$orderId, ...$l]
            );
        }

        return ['ok' => true, 'order' => [
            'code' => $code,
            'total' => round($total, 2),
            'savings' => round($savings, 2),
            'pickup' => pickup_label($date, $time),
            'phone' => $phone,
            'payment' => payment_label($payment),
            'payment_note' => payment_instructions($payment),
        ]];
    });
}

/* ---------- Public page chrome ---------- */

// $bodyClass: 'page-quiet' dims the wallpaper on pages that are mostly text.
function render_store_head(string $title, string $bodyClass = ''): void {
    $s = store_settings();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="description" content="<?= e($s['tagline']) ?>">
    <meta name="color-scheme" content="light dark">
    <title><?= e($title) ?></title>
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
    <link rel="preload" as="image" href="shutterstock_373602469.webp">
    <link rel="stylesheet" href="<?= asset('store.css') ?>">
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
    <?php if (trim($s['announcement']) !== ''): ?>
        <div class="announce"><i class="fa-solid fa-bullhorn"></i> <?= e($s['announcement']) ?></div>
    <?php endif; ?>
    <?php
}

function render_store_header(bool $withBasket = true): void {
    $s = store_settings();
    $status = store_status();
    ?>
    <header class="site-header">
        <div class="wrap header-row">
            <a class="logo" href="front_page.php">
                <span class="logo-mark">LG</span>
                <span class="logo-text"><?= e(strtoupper($s['store_name'])) ?></span>
            </a>
            <nav class="site-nav" aria-label="Main">
                <a href="front_page.php#deals">Deals</a>
                <a href="front_page.php#shop">Shop</a>
                <a href="front_page.php#how">How it works</a>
                <a href="front_page.php#visit">Visit us</a>
            </nav>
            <div class="header-actions">
                <a class="open-pill <?= $status['open'] ? 'is-open' : '' ?>" href="front_page.php#visit" title="Today: <?= e(store_hours_label()) ?>">
                    <?= e($status['label']) ?><span class="pill-detail"> · <?= e($status['detail']) ?></span>
                </a>
                <button type="button" class="hbtn hbtn-ghost hbtn-icon" data-theme-toggle aria-label="Switch between light and dark mode" title="Dark mode"><i class="fa-solid fa-moon"></i></button>
                <a class="hbtn" href="order_status.php"><i class="fa-solid fa-receipt"></i><span>Track order</span></a>
                <?php if ($withBasket && pickup_enabled()): ?>
                    <button type="button" class="hbtn hbtn-primary" data-basket-open aria-label="Open basket">
                        <i class="fa-solid fa-basket-shopping"></i><span>Basket</span><b class="basket-count" data-basket-count hidden>0</b>
                    </button>
                <?php endif; ?>
                <a class="hbtn hbtn-ghost" href="index.php" title="Log in"><i class="fa-solid fa-right-to-bracket"></i><span>Log in</span></a>
            </div>
        </div>
    </header>
    <?php
}

function render_store_footer(): void {
    $s = store_settings();
    ?>
    <footer class="site-footer">
        <div class="wrap footer-row">
            <div>
                <strong><?= e($s['store_name']) ?></strong>
                <p><?= e($s['tagline']) ?></p>
            </div>
            <div class="footer-meta">
                <span><i class="fa-regular fa-clock"></i> Today: <?= e(store_hours_label()) ?> · <?= e(store_status()['label']) ?></span>
                <?php if ($s['phone'] !== ''): ?><span><i class="fa-solid fa-phone"></i> <?= e($s['phone']) ?></span><?php endif; ?>
                <span><i class="fa-solid fa-leaf"></i> Inventory powered by ZeroWaste</span>
            </div>
        </div>
    </footer>
    <?php
}
