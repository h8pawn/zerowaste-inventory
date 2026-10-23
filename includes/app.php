<?php
// Shared bootstrap for every page: settings, database, session, helpers, auth and CSRF.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/schema.php';

const LOW_STOCK_THRESHOLD = 10;
const OVERSTOCK_THRESHOLD = 75;
const EXPIRING_SOON_DAYS = 3;

// Details go to the server's error log; visitors only see them in development.
set_exception_handler(function (Throwable $e): void {
    error_log('ZeroWaste: ' . $e);
    http_response_code(500);
    $message = APP_DEBUG ? $e->getMessage() : 'Please try again. If it keeps happening, tell your administrator.';
    $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Something went wrong. ' . $message]);
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:640px;margin:10vh auto;padding:24px;border:1px solid #f5b5b5;border-radius:14px;background:#fff5f5;color:#7f1d1d">'
        . '<h2 style="margin-top:0">Something went wrong</h2><p>' . e($message) . '</p>'
        . '<p><a href="home_page.php">Back to dashboard</a></p></div>';
});

/* ---------- Security headers & session ---------- */

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
    session_start();
}

ensure_schema($conn);
autoMoveExpiredItemsToWasteHistory($conn);

/* ---------- Output ---------- */

function e($value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function peso($amount, int $decimals = 2): string {
    return '₱' . number_format((float) $amount, $decimals);
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit();
}

// A real calendar date in Y-m-d form (rejects 2026-02-30).
function valid_date(string $value): bool {
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

function json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

/* ---------- Database ---------- */

function db_query(string $sql, string $types = '', array $params = []): mysqli_stmt {
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Database error: ' . $conn->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Database error: ' . $stmt->error);
    }
    return $stmt;
}

function db_all(string $sql, string $types = '', array $params = []): array {
    $result = db_query($sql, $types, $params)->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function db_one(string $sql, string $types = '', array $params = []): ?array {
    $rows = db_all($sql, $types, $params);
    return $rows[0] ?? null;
}

function db_value(string $sql, string $types = '', array $params = []) {
    $row = db_one($sql, $types, $params);
    return $row ? reset($row) : null;
}

function db_exec(string $sql, string $types = '', array $params = []): int {
    return db_query($sql, $types, $params)->affected_rows;
}

// Runs $work inside a transaction; rolls back and rethrows if anything fails.
function db_transaction(callable $work) {
    global $conn;
    $conn->begin_transaction();
    try {
        $result = $work();
        $conn->commit();
        return $result;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function db_last_id(): int {
    global $conn;
    return (int) $conn->insert_id;
}

function db_table_exists(string $table): bool {
    // SHOW TABLES can't take a bound parameter in MariaDB, so ask information_schema.
    return (bool) db_value(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        's',
        [$table]
    );
}

/* ---------- Inventory ledger ---------- */

// Every unit that comes into stock is recorded here so dashboards can report what stock cost.
function record_receipt(int $itemId, int $quantity, $unitCost, string $source, ?int $userId): void {
    if ($quantity <= 0) {
        return;
    }
    db_exec(
        'INSERT INTO stock_receipts (food_item_id, quantity, unit_cost, source, user_id) VALUES (?, ?, ?, ?, ?)',
        'iidsi',
        [$itemId, $quantity, (float) ($unitCost ?? 0), $source, $userId]
    );
}

// Items with waste or return history can't be hard-deleted without losing that history.
function item_has_history(int $itemId): bool {
    return (bool) db_value(
        'SELECT (SELECT COUNT(*) FROM waste_logs WHERE food_item_id = ?)
              + (SELECT COUNT(*) FROM item_returns WHERE food_item_id = ?)',
        'ii',
        [$itemId, $itemId]
    );
}

function archive_item(int $itemId, string $reason): void {
    db_exec('UPDATE food_items SET archived_at = NOW(), archive_reason = ? WHERE id = ? AND archived_at IS NULL', 'si', [$reason, $itemId]);
}

function archive_reason_label(?string $reason): array {
    return match ($reason) {
        'expired' => ['Expired', 'red'],
        'manual' => ['Archived by staff', 'gray'],
        'removed' => ['Removed (has history)', 'amber'],
        default => ['Archived', 'gray'],
    };
}

// Logs the remaining stock of every expired item as "Expired" waste, zeroes it and archives it.
// Items that were partially wasted earlier are included, so leftover stock is never missed.
function autoMoveExpiredItemsToWasteHistory(mysqli $conn): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $reasonResult = $conn->query("SELECT id FROM waste_reasons WHERE reason = 'Expired' LIMIT 1");
    if (!$reasonResult || $reasonResult->num_rows === 0) {
        return;
    }
    $expiredReasonId = (int) $reasonResult->fetch_assoc()['id'];

    $actorUserId = 0;
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
        $actorUserId = (int) $_SESSION['user_id'];
    }

    if ($actorUserId <= 0) {
        $userResult = $conn->query("SELECT id FROM users WHERE is_active = 1 ORDER BY (role = 'admin') DESC, id ASC LIMIT 1");
        if (!$userResult || $userResult->num_rows === 0) {
            return;
        }
        $actorUserId = (int) $userResult->fetch_assoc()['id'];
    }

    // Expired items that are already empty just need archiving.
    $conn->query("UPDATE food_items SET archived_at = NOW(), archive_reason = 'expired'
                  WHERE archived_at IS NULL AND quantity = 0 AND expiration_date < CURDATE()");

    $expiredItems = $conn->query(
        "SELECT id, quantity, purchase_price, expiration_date
         FROM food_items
         WHERE expiration_date < CURDATE() AND quantity > 0"
    );
    if (!$expiredItems || $expiredItems->num_rows === 0) {
        return;
    }

    $conn->begin_transaction();
    try {
        $insertLog = $conn->prepare(
            "INSERT INTO waste_logs
            (food_item_id, quantity_wasted, waste_reason_id, waste_date, user_id, notes, estimated_value)
            VALUES (?, ?, ?, CURDATE(), ?, ?, ?)"
        );
        $updateItem = $conn->prepare(
            "UPDATE food_items
             SET quantity = 0, archived_at = COALESCE(archived_at, NOW()), archive_reason = COALESCE(archive_reason, 'expired')
             WHERE id = ?"
        );

        while ($item = $expiredItems->fetch_assoc()) {
            $itemId = (int) $item['id'];
            $qty = (int) $item['quantity'];

            $purchasePrice = $item['purchase_price'] !== null ? (float) $item['purchase_price'] : 0.0;
            $estimatedValue = $qty * $purchasePrice;
            $notes = "Auto-moved by system after expiration date (" . $item['expiration_date'] . ").";

            $insertLog->bind_param("iiiisd", $itemId, $qty, $expiredReasonId, $actorUserId, $notes, $estimatedValue);
            if (!$insertLog->execute()) {
                throw new Exception("Failed to auto-log expired item #{$itemId}: " . $insertLog->error);
            }

            $updateItem->bind_param("i", $itemId);
            if (!$updateItem->execute()) {
                throw new Exception("Failed to update expired item #{$itemId}: " . $updateItem->error);
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('ZeroWaste: ' . $e->getMessage());
    }
}

/* ---------- Flash messages ---------- */

// $link = ['label' => ..., 'href' => ..., 'new_tab' => bool] adds a button to the message.
function flash(string $type, string $message, ?array $link = null): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message, 'link' => $link];
}

function pull_flashes(): array {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/* ---------- CSRF ---------- */

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool {
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

function is_ajax(): bool {
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

// Rejects any POST without a valid token. Pages call this through require_login().
function enforce_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || csrf_valid()) {
        return;
    }
    if (is_ajax()) {
        json_response(['success' => false, 'message' => 'Your session expired. Reload the page and try again.'], 403);
    }
    flash('error', 'Your session expired. Please try again.');
    redirect($_SERVER['REQUEST_URI'] ?? 'home_page.php');
}

/* ---------- Auth ---------- */

function normalizeUserRole($role): string {
    $normalized = strtolower(trim((string) $role));
    $allowed = ['admin', 'manager', 'staff'];

    if (!in_array($normalized, $allowed, true)) {
        return 'staff';
    }

    return $normalized;
}

// Reloads the user from the database on every request so deactivation and
// role changes take effect immediately, not only after the next login.
function require_login(): array {
    if (empty($_SESSION['user_id'])) {
        redirect('index.php');
    }

    $user = db_one('SELECT id, username, email, role, is_active FROM users WHERE id = ?', 'i', [(int) $_SESSION['user_id']]);
    if (!$user || !$user['is_active']) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('error', 'Your account is no longer active. Please contact an admin.');
        redirect('index.php');
    }

    $user['id'] = (int) $user['id'];
    $user['role'] = normalizeUserRole($user['role']);
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];

    enforce_csrf();
    enforce_read_only($user);
    return $user;
}

// Lookups that use POST but change nothing (barcode preview, expiry prediction, loading an item).
const READ_ONLY_POST_ACTIONS = ['item_details', 'barcode_preview', 'predict'];

// Staff accounts are view-and-print only: they can open pages, print labels and export,
// but any request that would change data is refused here, whatever page it targets.
function enforce_read_only(array $user): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || can_manage($user)
        || in_array($_POST['action'] ?? '', READ_ONLY_POST_ACTIONS, true)) {
        return;
    }
    $message = 'Your account is view and print only. Ask a manager or admin to make this change.';
    if (is_ajax()) {
        json_response(['success' => false, 'message' => $message], 403);
    }
    flash('error', $message);
    redirect($_SERVER['REQUEST_URI'] ?? 'home_page.php');
}

function require_role(array $user, array $roles): void {
    if (!in_array($user['role'], $roles, true)) {
        flash('error', 'You do not have permission to open that page.');
        redirect('home_page.php');
    }
}

// Admins and managers add and change items, suppliers, stock and user accounts; staff only view and print.
function can_manage(array $user): bool {
    return in_array($user['role'], ['admin', 'manager'], true);
}

function is_admin(array $user): bool {
    return $user['role'] === 'admin';
}
