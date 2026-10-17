<?php
// Supplier transactions (TPS): deliveries scheduled with a supplier and tracked
// Scheduled → On the way → Delivered, with a timestamped event log and notifications.

const DELIVERY_STATUSES = [
    'scheduled' => ['Scheduled', 'amber', 'fa-calendar-check'],
    'on_the_way' => ['On the way', 'blue', 'fa-truck-fast'],
    'delivered' => ['Delivered', 'green', 'fa-circle-check'],
    'cancelled' => ['Cancelled', 'red', 'fa-ban'],
];

// Labels for the event log (timeline) of one delivery.
const DELIVERY_EVENTS = [
    'scheduled' => ['Scheduled', 'fa-calendar-plus'],
    'rescheduled' => ['Details changed', 'fa-pen'],
    'on_the_way' => ['On the way', 'fa-truck-fast'],
    'delivered' => ['Delivered', 'fa-circle-check'],
    'cancelled' => ['Cancelled', 'fa-ban'],
    'paid' => ['Marked as paid', 'fa-peso-sign'],
    'unpaid' => ['Marked as unpaid', 'fa-rotate-left'],
];

function delivery_status_meta(string $status): array {
    return DELIVERY_STATUSES[$status] ?? [ucfirst($status), 'gray', 'fa-circle'];
}

function delivery_ref(int $id): string {
    return 'DLV-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

// "Today, 9:00 AM", "Tomorrow", "Mon, Oct 5". $relative = false always gives the date (for the saved log).
function delivery_when(string $date, ?string $time = null, bool $relative = true): string {
    $label = match (true) {
        $relative && $date === date('Y-m-d') => 'Today',
        $relative && $date === date('Y-m-d', strtotime('+1 day')) => 'Tomorrow',
        $relative && $date === date('Y-m-d', strtotime('-1 day')) => 'Yesterday',
        default => date('D, M j', strtotime($date)),
    };
    return $time ? $label . ', ' . date('g:i A', strtotime('2000-01-01 ' . $time)) : $label;
}

function log_delivery_event(int $deliveryId, string $event, ?string $note, ?int $userId): void {
    db_exec(
        'INSERT INTO supplier_delivery_events (delivery_id, event, note, user_id) VALUES (?, ?, ?, ?)',
        'issi',
        [$deliveryId, $event, $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null, $userId]
    );
}

// Numbers for the sidebar badge, dashboard and Deliveries page.
function delivery_counts(): array {
    $row = db_one(
        "SELECT COALESCE(SUM(status = 'scheduled' AND expected_date < CURDATE()), 0) AS overdue,
                COALESCE(SUM(status = 'scheduled' AND expected_date = CURDATE()), 0) AS due_today,
                COALESCE(SUM(status = 'scheduled' AND expected_date = CURDATE() + INTERVAL 1 DAY), 0) AS due_tomorrow,
                COALESCE(SUM(status = 'scheduled'), 0) AS scheduled,
                COALESCE(SUM(status = 'on_the_way'), 0) AS on_the_way,
                COALESCE(SUM(status = 'delivered' AND delivered_at >= NOW() - INTERVAL 1 DAY), 0) AS delivered_recent
         FROM supplier_deliveries"
    ) ?? [];
    return array_map('intval', $row);
}

/**
 * Bell notifications about deliveries: overdue, due today, on the way, due tomorrow, delivered in the last day.
 * Each has a key that changes when the delivery's state (or date) changes, so the browser can tell
 * which ones the user has not seen yet.
 */
function delivery_notifications(int $limit = 12): array {
    $rows = db_all(
        "SELECT id, supplier_name, items, expected_date, expected_time, status, shipped_at, delivered_at
         FROM supplier_deliveries
         WHERE (status = 'scheduled' AND expected_date <= CURDATE() + INTERVAL 1 DAY)
            OR status = 'on_the_way'
            OR (status = 'delivered' AND delivered_at >= NOW() - INTERVAL 1 DAY)
         ORDER BY expected_date, expected_time
         LIMIT 50"
    );

    $today = date('Y-m-d');
    $notes = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $supplier = $r['supplier_name'];
        $what = mb_strimwidth(preg_replace('/\s+/', ' ', $r['items']), 0, 60, '…');
        $when = delivery_when($r['expected_date'], $r['expected_time']);
        [$priority, $tone, $icon, $title, $text, $key] = match (true) {
            $r['status'] === 'on_the_way' => [2, 'blue', 'fa-truck-fast', "$supplier is on the way", "$what · expected $when", "d$id-way"],
            $r['status'] === 'delivered' => [4, 'green', 'fa-circle-check', "Delivered: $supplier", $what . ' · arrived ' . date('g:i A', strtotime($r['delivered_at'])), "d$id-done"],
            $r['expected_date'] < $today => [0, 'red', 'fa-triangle-exclamation', "Overdue: $supplier", "Was expected $when · $what", "d$id-late-{$r['expected_date']}"],
            $r['expected_date'] === $today => [1, 'amber', 'fa-calendar-day', "Delivery today: $supplier", "$when · $what", "d$id-today-{$r['expected_date']}"],
            default => [3, 'gray', 'fa-calendar', "Tomorrow: $supplier", "$when · $what", "d$id-soon-{$r['expected_date']}"],
        };
        $notes[] = compact('priority', 'tone', 'icon', 'title', 'text', 'key') + ['href' => 'deliveries.php?view=' . $id];
    }
    usort($notes, fn ($a, $b) => $a['priority'] <=> $b['priority']);
    return array_slice($notes, 0, $limit);
}
