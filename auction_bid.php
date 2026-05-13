<?php
declare(strict_types=1);

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

final class BvMobileAuctionApiException extends RuntimeException
{
    private int $statusCode;
    private string $publicMessage;

    public function __construct(string $code, string $message, int $statusCode = 400)
    {
        parent::__construct($code);
        $this->publicMessage = $message;
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function publicMessage(): string
    {
        return $this->publicMessage;
    }
}

function bv_mobile_auction_meta(): array
{
    return [
        'api_version' => 'mobile-v1',
        'generated_at' => gmdate('c'),
    ];
}

function bv_mobile_auction_json(int $statusCode, array $payload): void
{
    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        $payload['meta'] = bv_mobile_auction_meta();
    }

    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function bv_mobile_auction_error(string $code, string $message, int $statusCode = 400): void
{
    bv_mobile_auction_json($statusCode, [
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);
}

function bv_mobile_auction_public_root(): string
{
    $dir = __DIR__;
    for ($i = 0; $i < 5; $i++) {
        if (basename($dir) === 'public_html') {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    return dirname(__DIR__, 3);
}

function bv_mobile_auction_project_root(): string
{
    return dirname(bv_mobile_auction_public_root());
}

function bv_mobile_auction_log(string $message): void
{
    $safeMessage = str_replace(["\r", "\n"], ' ', $message);
    error_log('[BV Mobile Auction Bid] ' . $safeMessage);

    $logFile = bv_mobile_auction_public_root() . '/logs/mobile_auction_bid.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents($logFile, gmdate('[Y-m-d H:i:s] ') . $safeMessage . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function bv_mobile_auction_db(): ?PDO
{
    static $loaded = false;
    static $pdo = null;

    if ($loaded) {
        return $pdo;
    }
    $loaded = true;

    $publicRoot = bv_mobile_auction_public_root();
    $candidates = [
        $publicRoot . '/config/db.php',
        $publicRoot . '/includes/db.php',
    ];

    foreach ($candidates as $file) {
        if (!is_file($file)) {
            continue;
        }

        $loader = static function (string $path): array {
            $db_host = $db_user = $db_pass = $db_name = $db_port = null;
            $host = $user = $pass = $name = $port = null;
            $DB_HOST = $DB_USER = $DB_PASS = $DB_NAME = $DB_PORT = null;
            $dsn = null;
            $pdo = null;
            /** @noinspection PhpIncludeInspection */
            include $path;
            return compact(
                'db_host', 'db_user', 'db_pass', 'db_name', 'db_port',
                'host', 'user', 'pass', 'name', 'port',
                'DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DB_PORT',
                'dsn', 'pdo'
            );
        };

        $vars = $loader($file);
        if (($vars['pdo'] ?? null) instanceof PDO) {
            $pdo = $vars['pdo'];
            break;
        }
        if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
            break;
        }

        $dsn = $vars['dsn'] ?? null;
        $username = $vars['db_user'] ?? $vars['user'] ?? $vars['DB_USER'] ?? null;
        $password = $vars['db_pass'] ?? $vars['pass'] ?? $vars['DB_PASS'] ?? null;

        if (is_string($dsn) && $dsn !== '' && $username !== null) {
            $pdo = new PDO($dsn, (string) $username, (string) $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            break;
        }

        $host = $vars['db_host'] ?? $vars['host'] ?? $vars['DB_HOST'] ?? null;
        $dbName = $vars['db_name'] ?? $vars['name'] ?? $vars['DB_NAME'] ?? null;
        $port = (int) ($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);

        if ($host !== null && $username !== null && $dbName !== null) {
            $pdo = new PDO(
                'mysql:host=' . (string) $host . ';port=' . $port . ';dbname=' . (string) $dbName . ';charset=utf8mb4',
                (string) $username,
                (string) $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            break;
        }
    }

    if ($pdo instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    return $pdo;
}

function bv_mobile_auction_ident(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new BvMobileAuctionApiException('server_error', 'Service temporarily unavailable.', 500);
    }
    return '`' . $identifier . '`';
}

function bv_mobile_auction_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1'
    );
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function bv_mobile_auction_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute([':table' => $table]);

    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[(string) $column] = true;
    }

    $cache[$table] = $columns;
    return $columns;
}

function bv_mobile_auction_has_column(PDO $pdo, string $table, string $column): bool
{
    $columns = bv_mobile_auction_columns($pdo, $table);
    return isset($columns[$column]);
}

function bv_mobile_auction_first_column(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (bv_mobile_auction_has_column($pdo, $table, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function bv_mobile_auction_bearer_token(): string
{
    $header = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if ($header === '' || !preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
        return '';
    }

    return $matches[1];
}

function bv_mobile_auction_authenticate(PDO $pdo): array
{
    $token = bv_mobile_auction_bearer_token();
    if ($token === '') {
        throw new BvMobileAuctionApiException('token_missing', 'Authorization token is required.', 401);
    }

    foreach (['mobile_auth_tokens', 'users'] as $table) {
        if (!bv_mobile_auction_table_exists($pdo, $table)) {
            bv_mobile_auction_log($table . ' table not found.');
            throw new BvMobileAuctionApiException('db_unavailable', 'Service temporarily unavailable.', 503);
        }
    }

    foreach ([['mobile_auth_tokens', 'token_hash'], ['mobile_auth_tokens', 'user_id'], ['users', 'id']] as $required) {
        if (!bv_mobile_auction_has_column($pdo, $required[0], $required[1])) {
            bv_mobile_auction_log($required[0] . '.' . $required[1] . ' column not found.');
            throw new BvMobileAuctionApiException('db_unavailable', 'Service temporarily unavailable.', 503);
        }
    }

    $select = ['mat.`id` AS token_id', 'u.`id` AS user_id'];
    foreach (['email', 'role', 'account_status'] as $column) {
        if (bv_mobile_auction_has_column($pdo, 'users', $column)) {
            $select[] = 'u.' . bv_mobile_auction_ident($column) . ' AS ' . bv_mobile_auction_ident($column);
        }
    }

    $where = ['mat.`token_hash` = :token_hash'];
    if (bv_mobile_auction_has_column($pdo, 'mobile_auth_tokens', 'revoked_at')) {
        $where[] = 'mat.`revoked_at` IS NULL';
    }
    if (bv_mobile_auction_has_column($pdo, 'mobile_auth_tokens', 'expires_at')) {
        $where[] = 'mat.`expires_at` > NOW()';
    }
    if (bv_mobile_auction_has_column($pdo, 'users', 'account_status')) {
        $where[] = "u.`account_status` = 'active'";
    }

    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $select)
        . ' FROM `mobile_auth_tokens` mat INNER JOIN `users` u ON u.`id` = mat.`user_id`'
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY mat.`id` DESC LIMIT 1'
    );
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new BvMobileAuctionApiException('token_invalid', 'Token is invalid or has expired.', 401);
    }

    if (bv_mobile_auction_has_column($pdo, 'mobile_auth_tokens', 'last_used_at')) {
        $update = $pdo->prepare('UPDATE `mobile_auth_tokens` SET `last_used_at` = NOW() WHERE `id` = :id LIMIT 1');
        $update->execute([':id' => (int) $row['token_id']]);
    }

    return $row;
}

function bv_mobile_auction_parse_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        throw new BvMobileAuctionApiException('invalid_json', 'A JSON request body is required.', 400);
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        throw new BvMobileAuctionApiException('invalid_json', 'Request body must be valid JSON.', 400);
    }

    return $data;
}

function bv_mobile_auction_decimal($value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }
    if (is_string($value)) {
        $normalized = str_replace(',', '', trim($value));
        if ($normalized !== '' && is_numeric($normalized)) {
            return (float) $normalized;
        }
    }
    return null;
}

function bv_mobile_auction_money(float $value): float
{
    return round($value, 2);
}

function bv_mobile_auction_value(array $row, ?string $column, $default = null)
{
    if ($column === null || !array_key_exists($column, $row)) {
        return $default;
    }
    return $row[$column];
}

function bv_mobile_auction_date_is_after_now(?string $value, int $nowTs): bool
{
    if ($value === null || trim($value) === '') {
        return true;
    }
    $ts = strtotime($value);
    return $ts === false || $ts > $nowTs;
}

function bv_mobile_auction_date_has_started(?string $value, int $nowTs): bool
{
    if ($value === null || trim($value) === '') {
        return true;
    }
    $ts = strtotime($value);
    return $ts !== false && $ts <= $nowTs;
}

function bv_mobile_auction_listing_public_enough(PDO $pdo, array $listing): bool
{
    foreach (['is_deleted', 'deleted'] as $column) {
        if (bv_mobile_auction_has_column($pdo, 'listings', $column) && (int) ($listing[$column] ?? 0) === 1) {
            return false;
        }
    }

    foreach (['is_active', 'active', 'is_published', 'published'] as $column) {
        if (bv_mobile_auction_has_column($pdo, 'listings', $column) && (int) ($listing[$column] ?? 0) !== 1) {
            return false;
        }
    }

    foreach (['status', 'listing_status', 'state'] as $column) {
        if (!bv_mobile_auction_has_column($pdo, 'listings', $column)) {
            continue;
        }
        $status = strtolower(trim((string) ($listing[$column] ?? '')));
        if ($status !== '' && in_array($status, ['deleted', 'disabled', 'inactive', 'draft', 'rejected', 'suspended', 'archived'], true)) {
            return false;
        }
    }

    foreach (['visibility', 'privacy'] as $column) {
        if (!bv_mobile_auction_has_column($pdo, 'listings', $column)) {
            continue;
        }
        $visibility = strtolower(trim((string) ($listing[$column] ?? '')));
        if ($visibility !== '' && !in_array($visibility, ['public', 'published', 'visible'], true)) {
            return false;
        }
    }

    return true;
}

function bv_mobile_auction_is_auction(PDO $pdo, array $listing): bool
{
    foreach (['sale_format', 'selling_method'] as $column) {
        if (bv_mobile_auction_has_column($pdo, 'listings', $column)) {
            $value = strtolower(trim((string) ($listing[$column] ?? '')));
            if ($value === 'auction' || str_contains($value, 'auction')) {
                return true;
            }
        }
    }

    if (bv_mobile_auction_has_column($pdo, 'listings', 'auction_enabled')) {
        return (int) ($listing['auction_enabled'] ?? 0) === 1;
    }

    return false;
}

function bv_mobile_auction_max_existing_bid(PDO $pdo, int $listingId): ?float
{
    if (!bv_mobile_auction_table_exists($pdo, 'listing_auction_bids')) {
        bv_mobile_auction_log('listing_auction_bids table not found.');
        throw new BvMobileAuctionApiException('db_unavailable', 'Service temporarily unavailable.', 503);
    }
    if (!bv_mobile_auction_has_column($pdo, 'listing_auction_bids', 'listing_id') || !bv_mobile_auction_has_column($pdo, 'listing_auction_bids', 'bid_amount')) {
        bv_mobile_auction_log('listing_auction_bids required columns not found.');
        throw new BvMobileAuctionApiException('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    $where = ['`listing_id` = :listing_id'];
    if (bv_mobile_auction_has_column($pdo, 'listing_auction_bids', 'bid_status')) {
        $where[] = "(`bid_status` IS NULL OR `bid_status` NOT IN ('rejected', 'cancelled', 'canceled', 'void'))";
    }

    $stmt = $pdo->prepare('SELECT MAX(`bid_amount`) FROM `listing_auction_bids` WHERE ' . implode(' AND ', $where));
    $stmt->execute([':listing_id' => $listingId]);
    $value = $stmt->fetchColumn();

    return $value === null ? null : bv_mobile_auction_decimal($value);
}

function bv_mobile_auction_count_existing_bids(PDO $pdo, int $listingId): int
{
    $where = ['`listing_id` = :listing_id'];
    if (bv_mobile_auction_has_column($pdo, 'listing_auction_bids', 'bid_status')) {
        $where[] = "(`bid_status` IS NULL OR `bid_status` NOT IN ('rejected', 'cancelled', 'canceled', 'void'))";
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `listing_auction_bids` WHERE ' . implode(' AND ', $where));
    $stmt->execute([':listing_id' => $listingId]);
    return (int) $stmt->fetchColumn();
}


function bv_mobile_auction_previous_highest_bid(PDO $pdo, int $listingId, array $listing): array
{
    $previousBidderUserId = null;
    $previousBidAmount = null;
    $previousBidId = null;

    if (bv_mobile_auction_has_column($pdo, 'listings', 'auction_winner_user_id')) {
        $winnerId = (int) ($listing['auction_winner_user_id'] ?? 0);
        if ($winnerId > 0) {
            $amount = bv_mobile_auction_decimal($listing['auction_current_bid'] ?? null);
            if ($amount !== null && $amount > 0) {
                $previousBidderUserId = $winnerId;
                $previousBidAmount = bv_mobile_auction_money($amount);
            }
        }
    }

    $bidColumns = bv_mobile_auction_columns($pdo, 'listing_auction_bids');
    $bidderColumn = isset($bidColumns['bidder_user_id']) ? 'bidder_user_id' : (isset($bidColumns['user_id']) ? 'user_id' : null);
    if ($bidderColumn === null) {
        return [
            'previous_highest_bidder_user_id' => $previousBidderUserId,
            'previous_highest_bid_amount' => $previousBidAmount,
            'previous_bid_id' => $previousBidId,
        ];
    }

    $select = [];
    if (isset($bidColumns['id'])) {
        $select[] = '`id`';
    }
    $select[] = '`' . $bidderColumn . '` AS `bidder_user_id`';
    $select[] = '`bid_amount`';

    $where = ['`listing_id` = :listing_id'];
    if (isset($bidColumns['bid_status'])) {
        $where[] = "(`bid_status` IS NULL OR `bid_status` NOT IN ('rejected', 'cancelled', 'canceled', 'void'))";
    }

    $order = ['`bid_amount` DESC'];
    if (isset($bidColumns['created_at'])) {
        $order[] = '`created_at` DESC';
    }
    if (isset($bidColumns['id'])) {
        $order[] = '`id` DESC';
    }

    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $select)
        . ' FROM `listing_auction_bids` WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . implode(', ', $order) . ' LIMIT 1'
    );
    $stmt->execute([':listing_id' => $listingId]);
    $row = $stmt->fetch();

    if ($row) {
        $rowUserId = (int) ($row['bidder_user_id'] ?? 0);
        $rowAmount = bv_mobile_auction_decimal($row['bid_amount'] ?? null);
        if ($rowUserId > 0 && $rowAmount !== null && $rowAmount > 0) {
            if ($previousBidderUserId === null || $previousBidAmount === null || $rowAmount >= $previousBidAmount - 0.00001) {
                $previousBidderUserId = $rowUserId;
                $previousBidAmount = bv_mobile_auction_money($rowAmount);
                $previousBidId = isset($row['id']) ? (int) $row['id'] : null;
            }
        }
    }

    if ($previousBidderUserId !== null && $previousBidAmount !== null && $previousBidId === null && isset($bidColumns['id'])) {
        $matchWhere = [
            '`listing_id` = :listing_id',
            '`' . $bidderColumn . '` = :bidder_user_id',
            '`bid_amount` = :bid_amount',
        ];
        if (isset($bidColumns['bid_status'])) {
            $matchWhere[] = "(`bid_status` IS NULL OR `bid_status` NOT IN ('rejected', 'cancelled', 'canceled', 'void'))";
        }
        $matchOrder = [];
        if (isset($bidColumns['created_at'])) {
            $matchOrder[] = '`created_at` DESC';
        }
        $matchOrder[] = '`id` DESC';
        $stmt = $pdo->prepare(
            'SELECT `id` FROM `listing_auction_bids` WHERE ' . implode(' AND ', $matchWhere)
            . ' ORDER BY ' . implode(', ', $matchOrder) . ' LIMIT 1'
        );
        $stmt->execute([
            ':listing_id' => $listingId,
            ':bidder_user_id' => $previousBidderUserId,
            ':bid_amount' => $previousBidAmount,
        ]);
        $matchedBidId = $stmt->fetchColumn();
        if ($matchedBidId !== false && $matchedBidId !== null) {
            $previousBidId = (int) $matchedBidId;
        }
    }

    return [
        'previous_highest_bidder_user_id' => $previousBidderUserId,
        'previous_highest_bid_amount' => $previousBidAmount,
        'previous_bid_id' => $previousBidId,
    ];
}

function bv_mobile_auction_base_url(): string
{
    foreach (['BETTAVARO_BASE_URL', 'APP_URL', 'BASE_URL'] as $constant) {
        if (defined($constant) && is_string(constant($constant)) && trim((string) constant($constant)) !== '') {
            return rtrim(trim((string) constant($constant)), '/');
        }
    }

    return 'https://www.bettavaro.com';
}

function bv_mobile_auction_listing_url(int $listingId, ?string $slug): string
{
    $base = bv_mobile_auction_base_url();
    $cleanSlug = trim((string) $slug);
    if ($cleanSlug !== '') {
        return $base . '/listing.php?slug=' . urlencode($cleanSlug);
    }
    if ($listingId > 0) {
        return $base . '/listing.php?id=' . $listingId;
    }
    return $base . '/listing.php';
}

function bv_mobile_auction_escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bv_mobile_auction_listing_path(int $listingId, ?string $slug): string
{
    $cleanSlug = trim((string) $slug);
    if ($cleanSlug !== '') {
        return '/listing.php?slug=' . urlencode($cleanSlug);
    }
    if ($listingId > 0) {
        return '/listing.php?id=' . $listingId;
    }
    return '/listing.php';
}

function bv_mobile_auction_notification_log(string $event, array $context = []): void
{
    $safeEvent = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $event) ?: 'notification_event';
    $parts = [$safeEvent];
    foreach ($context as $key => $value) {
        if (is_array($value) || is_object($value)) {
            continue;
        }
        $safeKey = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) $key) ?: 'context';
        $safeValue = str_replace(["\r", "\n"], ' ', (string) $value);
        $parts[] = $safeKey . '=' . $safeValue;
    }
    $message = implode(' ', $parts);

    error_log('[BV Mobile Auction Notifications] ' . $message);

    $logFile = bv_mobile_auction_public_root() . '/logs/mobile_auction_notifications.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents($logFile, gmdate('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function bv_mobile_auction_create_notification(PDO $pdo, int $userId, string $type, string $title, string $message, string $url): bool
{
    try {
        if ($userId <= 0) {
            bv_mobile_auction_notification_log('notification_skipped', [
                'reason' => 'invalid_recipient',
                'type' => $type,
            ]);
            return false;
        }

        if (!bv_mobile_auction_table_exists($pdo, 'notifications')) {
            bv_mobile_auction_notification_log('notification_skipped', [
                'reason' => 'notifications_table_missing',
                'user_id' => $userId,
                'type' => $type,
            ]);
            return false;
        }

        $columns = bv_mobile_auction_columns($pdo, 'notifications');
        foreach (['user_id', 'type', 'title', 'message', 'url', 'is_read', 'created_at'] as $requiredColumn) {
            if (!isset($columns[$requiredColumn])) {
                bv_mobile_auction_notification_log('notification_skipped', [
                    'reason' => 'notifications_' . $requiredColumn . '_column_missing',
                    'user_id' => $userId,
                    'type' => $type,
                ]);
                return false;
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO `notifications` (`user_id`, `type`, `title`, `message`, `url`, `is_read`, `created_at`)'
            . ' VALUES (:user_id, :type, :title, :message, :url, :is_read, NOW())'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':type' => $type,
            ':title' => $title,
            ':message' => $message,
            ':url' => $url,
            ':is_read' => 0,
        ]);

        return true;
    } catch (Throwable $e) {
        bv_mobile_auction_notification_log('notification_failed', [
            'user_id' => $userId,
            'type' => $type,
            'error' => $e->getMessage(),
        ]);
        return false;
    }
}

function bv_mobile_auction_notify_bid_events(PDO $pdo, array $context): void
{
    try {
        $listingId = (int) ($context['listing_id'] ?? 0);
        $listingTitle = trim((string) ($context['listing_title'] ?? ''));
        if ($listingTitle === '') {
            $listingTitle = 'your auction listing';
        }
        $url = $listingId > 0 ? '/listing.php?id=' . $listingId : '/listing.php';
        $bidderUserId = (int) ($context['bidder_user_id'] ?? 0);
        $previousBidderUserId = (int) ($context['previous_bidder_user_id'] ?? 0);
        $sellerId = (int) ($context['seller_id'] ?? 0);

        if ($previousBidderUserId > 0 && $previousBidderUserId !== $bidderUserId) {
            $created = bv_mobile_auction_create_notification(
                $pdo,
                $previousBidderUserId,
                'auction.outbid',
                'You have been outbid',
                'Someone placed a higher bid on ' . $listingTitle . '.',
                $url
            );
            bv_mobile_auction_notification_log($created ? 'notification_outbid_created' : 'notification_failed', [
                'listing_id' => $listingId,
                'user_id' => $previousBidderUserId,
                'bidder_user_id' => $bidderUserId,
            ]);
        } else {
            bv_mobile_auction_notification_log('notification_skipped', [
                'reason' => 'outbid_recipient_unavailable_or_same_bidder',
                'listing_id' => $listingId,
                'previous_bidder_user_id' => $previousBidderUserId,
                'bidder_user_id' => $bidderUserId,
            ]);
        }

       $sellerNotificationContext = [
            'listing_id' => $listingId,
            'seller_id' => $sellerId,
            'bidder_user_id' => $bidderUserId,
            'bid_amount' => number_format((float) ($context['bid_amount'] ?? 0), 2, '.', ''),
            'currency' => trim((string) ($context['currency'] ?? '')),
        ];
        if ($sellerNotificationContext['currency'] === '') {
            $sellerNotificationContext['currency'] = 'USD';
        }
        bv_mobile_auction_notification_log('seller_notification_context', $sellerNotificationContext);

        if ($sellerId > 0 && $sellerId !== $bidderUserId) {
            $bidderName = trim((string) ($context['bidder_name'] ?? ''));
            if ($bidderName === '') {
                $bidderName = 'A buyer';
            }
            $created = bv_mobile_auction_create_notification(
                $pdo,
                $sellerId,
                'auction.new_bid',
                'New auction bid received',
                $bidderName . ' placed a bid of ' . $sellerNotificationContext['currency'] . ' ' . $sellerNotificationContext['bid_amount'] . ' on ' . $listingTitle . '.',
                $url
            );
            bv_mobile_auction_notification_log($created ? 'notification_seller_bid_created' : 'notification_failed', [
                'listing_id' => $listingId,
                'user_id' => $sellerId,
                'bidder_user_id' => $bidderUserId,
            ]);
        } else {
 
            $sellerNotificationContext['reason'] = $sellerId <= 0 ? 'seller_unavailable' : 'seller_is_bidder';
            bv_mobile_auction_notification_log('seller_notification_skipped', $sellerNotificationContext); 
        }
    } catch (Throwable $e) {
        bv_mobile_auction_notification_log('notification_failed', [
            'listing_id' => (int) ($context['listing_id'] ?? 0),
            'error' => $e->getMessage(),
        ]);
    }
}

function bv_mobile_auction_bidder_display_name(PDO $pdo, int $userId): string
{
    try {
        if ($userId <= 0 || !bv_mobile_auction_table_exists($pdo, 'users') || !bv_mobile_auction_has_column($pdo, 'users', 'id')) {
            return 'A buyer';
        }

        $columns = bv_mobile_auction_columns($pdo, 'users');
        $select = ['`id`'];
         foreach (['first_name', 'last_name', 'email', 'username'] as $column) { 
            if (isset($columns[$column])) {
                $select[] = bv_mobile_auction_ident($column);
            }
        }
        if (count($select) === 1) {
            return 'A buyer';
        }

        $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM `users` WHERE `id` = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return 'A buyer';
        }

        foreach (['display_name', 'name', 'full_name', 'username'] as $column) {
            $value = trim((string) ($user[$column] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'A buyer';
    } catch (Throwable $e) {
        bv_mobile_auction_notification_log('notification_skipped', [
            'reason' => 'bidder_name_unavailable',
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);
        return 'A buyer';
    }
}

function bv_mobile_auction_create_outbid_in_app_notification(PDO $pdo, array $context): array
{
    $listingId = (int) ($context['listing_id'] ?? 0);
    $userId = (int) ($context['previous_bidder_user_id'] ?? 0);
    $message = 'Someone placed a higher bid on your auction item.';
    $url = $listingId > 0 ? '/listing.php?id=' . $listingId : '/listing.php';
	
    try {
        if (!bv_mobile_auction_table_exists($pdo, 'notifications')) {
           bv_mobile_auction_log('outbid_in_app_notification_skipped notifications_table_missing listing_id=' . $listingId);
            return ['created' => false, 'error' => 'notifications unavailable'];
        }

        $columns = bv_mobile_auction_columns($pdo, 'notifications');
        foreach (['user_id', 'type', 'title', 'message', 'url', 'is_read', 'created_at'] as $requiredColumn) {
            if (!isset($columns[$requiredColumn])) {
                 bv_mobile_auction_log('outbid_in_app_notification_skipped notifications_' . $requiredColumn . '_column_missing listing_id=' . $listingId);
                return ['created' => false, 'error' => 'notifications unavailable'];
            }
        }

        if ($userId <= 0) {
             bv_mobile_auction_log('outbid_in_app_notification_skipped recipient_unavailable listing_id=' . $listingId);
            return ['created' => false, 'error' => 'recipient unavailable'];
        }

        $dedupe = $pdo->prepare(
            'SELECT `id` FROM `notifications`'
            . ' WHERE `user_id` = :user_id'
            . ' AND `type` = :type'
            . ' AND `url` = :url'
            . ' AND `created_at` >= (NOW() - INTERVAL 2 MINUTE)'
            . ' AND `message` LIKE :message'
            . ' ORDER BY `id` DESC LIMIT 1'
        );
        $dedupe->execute([
            ':user_id' => $userId,
            ':type' => 'auction.outbid',
            ':url' => $url,
            ':message' => $message,
        ]);
        if ($dedupe->fetchColumn() !== false) {
            bv_mobile_auction_log('outbid_in_app_notification_skipped duplicate_recent listing_id=' . $listingId . ' previous_bidder_user_id=' . $userId . ' bid_id=' . (int) ($context['bid_id'] ?? 0));
            return ['created' => true, 'error' => null];
        }
 

        $stmt = $pdo->prepare(
            'INSERT INTO `notifications` (`user_id`, `type`, `title`, `message`, `url`, `is_read`, `created_at`)'
            . ' VALUES (:user_id, :type, :title, :message, :url, :is_read, NOW())'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':type' => 'auction.outbid',
            ':title' => 'You have been outbid',
            ':message' => $message,
            ':url' => $url, 
            ':is_read' => 0,
        ]);

        bv_mobile_auction_log('outbid_in_app_notification_created listing_id=' . $listingId . ' previous_bidder_user_id=' . $userId . ' bid_id=' . (int) ($context['bid_id'] ?? 0));
         return ['created' => true, 'error' => null]; 
    } catch (Throwable $e) {
       bv_mobile_auction_log('outbid_in_app_notification_failed ' . $e->getMessage() . ' listing_id=' . $listingId);
        return ['created' => false, 'error' => 'notification failed']; 
    }
}


function bv_mobile_auction_outbid_recipient(PDO $pdo, int $userId): ?array
{
    if (!bv_mobile_auction_table_exists($pdo, 'users') || !bv_mobile_auction_has_column($pdo, 'users', 'id') || !bv_mobile_auction_has_column($pdo, 'users', 'email')) {
        return null;
    }

    $columns = bv_mobile_auction_columns($pdo, 'users');
    $select = ['`id`', '`email`'];
    foreach (['account_status', 'status', 'is_active', 'active'] as $column) {
        if (isset($columns[$column])) {
            $select[] = bv_mobile_auction_ident($column);
        }
    }

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM `users` WHERE `id` = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }

    $email = trim((string) ($user['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    foreach (['is_active', 'active'] as $column) {
        if (array_key_exists($column, $user) && (int) $user[$column] !== 1) {
            return null;
        }
    }

    foreach (['account_status', 'status'] as $column) {
        if (!array_key_exists($column, $user)) {
            continue;
        }
        $status = strtolower(trim((string) $user[$column]));
        if ($status !== '' && !in_array($status, ['active', 'verified', 'enabled'], true)) {
            return null;
        }
    }

    return ['id' => $userId, 'email' => $email];
}

function bv_mobile_auction_load_mail_queue_helper(): void
{
    $publicRoot = bv_mobile_auction_public_root();
    $candidates = [
        $publicRoot . '/includes/mail_queue.php',
        $publicRoot . '/includes/mailer.php',
        __DIR__ . '/mail_queue.php',
        __DIR__ . '/mailer.php',
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
        }
    }
}

function bv_mobile_auction_queue_outbid_email(PDO $pdo, array $context): bool
{
    try {
        bv_mobile_auction_load_mail_queue_helper();

        if (!function_exists('bv_queue_mail')) {
            bv_mobile_auction_log('outbid_notification_skipped helper_unavailable listing_id=' . (int) ($context['listing_id'] ?? 0));
            return false;
        }

        $recipient = bv_mobile_auction_outbid_recipient($pdo, (int) ($context['previous_bidder_user_id'] ?? 0));
        if ($recipient === null) {
            bv_mobile_auction_log('outbid_notification_skipped recipient_unavailable listing_id=' . (int) ($context['listing_id'] ?? 0));
            return false;
        }

        $currency = (string) ($context['currency'] ?? 'USD');
        $previousAmount = number_format((float) ($context['previous_bid_amount'] ?? 0), 2, '.', '');
        $newAmount = number_format((float) ($context['new_bid_amount'] ?? 0), 2, '.', '');
        $listingTitle = trim((string) ($context['listing_title'] ?? 'Auction listing'));
        if ($listingTitle === '') {
            $listingTitle = 'Auction listing';
        }
        $listingUrl = bv_mobile_auction_listing_url((int) ($context['listing_id'] ?? 0), isset($context['listing_slug']) ? (string) $context['listing_slug'] : null);

        $subject = 'You have been outbid on Bettavaro';
        $safeTitle = bv_mobile_auction_escape_html($listingTitle);
        $safeCurrency = bv_mobile_auction_escape_html($currency);
        $safeListingUrl = bv_mobile_auction_escape_html($listingUrl);

        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f6f7f9;font-family:Arial,sans-serif;color:#111;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f9;padding:20px 0;"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:24px;">'
            . '<tr><td style="font-size:20px;font-weight:bold;color:#111;padding-bottom:12px;">You have been outbid</td></tr>'
            . '<tr><td style="font-size:14px;line-height:1.5;color:#333;padding-bottom:12px;">Another bidder placed a higher bid on your Bettavaro auction.</td></tr>'
            . '<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td style="padding:8px 0;color:#666;width:160px;">Listing</td><td style="padding:8px 0;color:#111;">' . $safeTitle . '</td></tr>'
            . '<tr><td style="padding:8px 0;color:#666;width:160px;">Your previous bid</td><td style="padding:8px 0;color:#111;">' . $safeCurrency . ' ' . $previousAmount . '</td></tr>'
            . '<tr><td style="padding:8px 0;color:#666;width:160px;">New current bid</td><td style="padding:8px 0;color:#111;">' . $safeCurrency . ' ' . $newAmount . '</td></tr>'
            . '</table></td></tr>'
            . '<tr><td style="padding-top:20px;"><a href="' . $safeListingUrl . '" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:10px 16px;border-radius:4px;font-size:14px;">Open auction and place a new bid</a></td></tr>'
            . '</table></td></tr></table></body></html>';

        $text = "You have been outbid on Bettavaro\n\n"
            . 'Listing: ' . $listingTitle . "\n"
            . 'Your previous bid: ' . $currency . ' ' . $previousAmount . "\n"
            . 'New current bid: ' . $currency . ' ' . $newAmount . "\n"
            . 'Open auction and place a new bid: ' . $listingUrl . "\n";

        $payload = [
            'queue_key' => 'auction_outbid_' . (int) ($context['listing_id'] ?? 0) . '_' . (int) ($context['bid_id'] ?? 0) . '_' . (int) ($context['previous_bidder_user_id'] ?? 0),
            'to' => $recipient['email'],
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'html_body' => $html,
            'text_body' => $text,
            'meta' => [
                'event' => 'auction.outbid',
                'listing_id' => (int) ($context['listing_id'] ?? 0),
                'previous_bidder_user_id' => (int) ($context['previous_bidder_user_id'] ?? 0),
                'new_bidder_user_id' => (int) ($context['new_bidder_user_id'] ?? 0),
                'bid_id' => (int) ($context['bid_id'] ?? 0),
            ],
        ];

        $result = bv_queue_mail($payload);
        $queued = false;
        if (is_bool($result)) {
            $queued = $result;
        } elseif (is_numeric($result)) {
            $queued = ((int) $result) > 0;
        } elseif (is_array($result)) {
            $queued = (bool) ($result['ok'] ?? $result['success'] ?? $result['queued'] ?? false);
        }

        if ($queued) {
            bv_mobile_auction_log('outbid_notification_queued listing_id=' . (int) ($context['listing_id'] ?? 0) . ' previous_bidder_user_id=' . (int) ($context['previous_bidder_user_id'] ?? 0) . ' bid_id=' . (int) ($context['bid_id'] ?? 0));
            return true;
        }

        bv_mobile_auction_log('outbid_notification_failed queue_returned_false listing_id=' . (int) ($context['listing_id'] ?? 0) . ' previous_bidder_user_id=' . (int) ($context['previous_bidder_user_id'] ?? 0));
        return false;
    } catch (Throwable $e) {
        bv_mobile_auction_log('outbid_notification_failed ' . $e->getMessage() . ' listing_id=' . (int) ($context['listing_id'] ?? 0));
        return false;
    }
}


try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        bv_mobile_auction_error('method_not_allowed', 'Only POST requests are accepted.', 405);
    }

    $pdo = bv_mobile_auction_db();
    if (!$pdo instanceof PDO) {
        bv_mobile_auction_log('No PDO database connection available.');
        bv_mobile_auction_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    $auth = bv_mobile_auction_authenticate($pdo);
    $userId = (int) $auth['user_id'];

    $body = bv_mobile_auction_parse_body();
    $listingId = isset($body['listing_id']) ? (int) $body['listing_id'] : 0;
    $bidAmount = bv_mobile_auction_decimal($body['bid_amount'] ?? null);

    if ($listingId <= 0) {
        throw new BvMobileAuctionApiException('listing_id_required', 'A valid listing_id is required.', 422);
    }
    if ($bidAmount === null || $bidAmount <= 0) {
        throw new BvMobileAuctionApiException('bid_amount_required', 'A valid bid_amount is required.', 422);
    }
    $bidAmount = bv_mobile_auction_money($bidAmount);

    foreach (['listings', 'listing_auction_bids'] as $table) {
        if (!bv_mobile_auction_table_exists($pdo, $table)) {
            bv_mobile_auction_log($table . ' table not found.');
            throw new BvMobileAuctionApiException('db_unavailable', 'Service temporarily unavailable.', 503);
        }
    }

    if (!bv_mobile_auction_has_column($pdo, 'listings', 'id')) {
        bv_mobile_auction_log('listings.id column not found.');
        throw new BvMobileAuctionApiException('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM `listings` WHERE `id` = :id LIMIT 1 FOR UPDATE');
    $stmt->execute([':id' => $listingId]);
    $listing = $stmt->fetch();

    if (!$listing) {
        throw new BvMobileAuctionApiException('listing_not_found', 'Listing was not found.', 404);
    }

    if (!bv_mobile_auction_listing_public_enough($pdo, $listing)) {
        throw new BvMobileAuctionApiException('listing_unavailable', 'Listing is not available for bidding.', 409);
    }

    if (!bv_mobile_auction_is_auction($pdo, $listing)) {
        throw new BvMobileAuctionApiException('not_auction', 'This listing is not accepting auction bids.', 409);
    }

    $sellerColumn = bv_mobile_auction_first_column($pdo, 'listings', ['seller_id', 'user_id', 'owner_user_id', 'vendor_id']);
    $sellerId = (int) bv_mobile_auction_value($listing, $sellerColumn, 0);
    if ($sellerId > 0 && $sellerId === $userId) {
        throw new BvMobileAuctionApiException('own_listing', 'You cannot bid on your own listing.', 409);
    }

    if (bv_mobile_auction_has_column($pdo, 'listings', 'auction_status')) {
        $auctionStatus = strtolower(trim((string) ($listing['auction_status'] ?? '')));
        if ($auctionStatus !== '' && in_array($auctionStatus, ['ended', 'closed', 'paid', 'cancelled', 'canceled'], true)) {
            throw new BvMobileAuctionApiException('auction_ended', 'This auction is no longer accepting bids.', 409);
        }
    }

    $nowStmt = $pdo->query('SELECT NOW()');
    $nowValue = (string) $nowStmt->fetchColumn();
    $nowTs = strtotime($nowValue) ?: time();

    $startColumn = bv_mobile_auction_first_column($pdo, 'listings', ['auction_start_at', 'auction_starts_at']);
    $endColumn = bv_mobile_auction_first_column($pdo, 'listings', ['auction_end_at', 'auction_ends_at']);
    $startsAt = bv_mobile_auction_value($listing, $startColumn, null);
    $endsAt = bv_mobile_auction_value($listing, $endColumn, null);

    if (!bv_mobile_auction_date_has_started(is_string($startsAt) ? $startsAt : null, $nowTs)) {
        throw new BvMobileAuctionApiException('auction_not_started', 'This auction has not started yet.', 409);
    }
    if (!bv_mobile_auction_date_is_after_now(is_string($endsAt) ? $endsAt : null, $nowTs)) {
        throw new BvMobileAuctionApiException('auction_ended', 'This auction has ended.', 409);
    }

    $startPriceColumn = bv_mobile_auction_first_column($pdo, 'listings', ['auction_start_price', 'starting_bid', 'start_price']);
    $currentBidColumn = bv_mobile_auction_first_column($pdo, 'listings', ['auction_current_bid', 'current_bid']);
    $priceColumn = bv_mobile_auction_first_column($pdo, 'listings', ['price', 'listing_price', 'amount']);
    $incrementColumn = bv_mobile_auction_first_column($pdo, 'listings', ['auction_min_increment', 'auction_increment']);
    $currencyColumn = bv_mobile_auction_first_column($pdo, 'listings', ['currency', 'currency_code']);
    $bidCountColumn = bv_mobile_auction_first_column($pdo, 'listings', ['auction_bid_count', 'bid_count']);

    $maxBid = bv_mobile_auction_max_existing_bid($pdo, $listingId);
    $listingCurrentBid = bv_mobile_auction_decimal(bv_mobile_auction_value($listing, $currentBidColumn, null));
    $startPrice = bv_mobile_auction_decimal(bv_mobile_auction_value($listing, $startPriceColumn, null));
    $listingPrice = bv_mobile_auction_decimal(bv_mobile_auction_value($listing, $priceColumn, null));
    $increment = bv_mobile_auction_decimal(bv_mobile_auction_value($listing, $incrementColumn, null));

    if ($startPrice === null || $startPrice <= 0) {
        $startPrice = $listingPrice !== null && $listingPrice > 0 ? $listingPrice : 0.0;
    }
    if ($increment === null || $increment <= 0) {
        $increment = 1.00;
    }
    $increment = bv_mobile_auction_money($increment);

    $previousBid = null;
    if ($maxBid !== null && $maxBid > 0) {
        $previousBid = $maxBid;
    } elseif ($listingCurrentBid !== null && $listingCurrentBid > 0 && $listingCurrentBid > $startPrice) {
        $previousBid = $listingCurrentBid;
    }

    $currentPrice = $previousBid;
    if ($currentPrice === null && $listingCurrentBid !== null && $listingCurrentBid > 0) {
        $currentPrice = $listingCurrentBid;
    }
    if ($currentPrice === null && $startPrice > 0) {
        $currentPrice = $startPrice;
    }
    if ($currentPrice === null && $listingPrice !== null && $listingPrice > 0) {
        $currentPrice = $listingPrice;
    }
    if ($currentPrice === null) {
        $currentPrice = 0.0;
    }

    $minimumBid = $previousBid === null ? $startPrice : ($previousBid + $increment);
    $minimumBid = bv_mobile_auction_money($minimumBid);

    if ($bidAmount + 0.00001 < $minimumBid) {
        throw new BvMobileAuctionApiException(
            'bid_too_low',
            'Bid amount must be at least ' . number_format($minimumBid, 2, '.', '') . '.',
            422
        );
    }
	
   $previousHighest = bv_mobile_auction_previous_highest_bid($pdo, $listingId, $listing);
    $previousHighestBidderUserId = $previousHighest['previous_highest_bidder_user_id'];
    $previousHighestBidAmount = $previousHighest['previous_highest_bid_amount'];
    $previousBidId = $previousHighest['previous_bid_id'];
    $wasOutbid = $previousHighestBidderUserId !== null && (int) $previousHighestBidderUserId > 0 && (int) $previousHighestBidderUserId !== $userId;
    if ($wasOutbid) {
        bv_mobile_auction_log('outbid_detected listing_id=' . $listingId . ' previous_bidder_user_id=' . (int) $previousHighestBidderUserId . ' new_bidder_user_id=' . $userId);
    }
	

    $bidColumns = bv_mobile_auction_columns($pdo, 'listing_auction_bids');
    foreach (['listing_id', 'bid_amount'] as $requiredColumn) {
        if (!isset($bidColumns[$requiredColumn])) {
            bv_mobile_auction_log('listing_auction_bids.' . $requiredColumn . ' column not found.');
            throw new BvMobileAuctionApiException('db_unavailable', 'Service temporarily unavailable.', 503);
        }
    }

    $insert = [
        'listing_id' => $listingId,
        'bid_amount' => $bidAmount,
    ];
    if (isset($bidColumns['bidder_user_id'])) {
        $insert['bidder_user_id'] = $userId;
    } elseif (isset($bidColumns['user_id'])) {
        $insert['user_id'] = $userId;
    }
    if (isset($bidColumns['currency'])) {
        $insert['currency'] = (string) bv_mobile_auction_value($listing, $currencyColumn, 'USD');
    }
    if (isset($bidColumns['bid_status'])) {
        $insert['bid_status'] = 'active';
    }
    if (isset($bidColumns['ip_address'])) {
        $insert['ip_address'] = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }
    if (isset($bidColumns['user_agent'])) {
        $insert['user_agent'] = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
    if (isset($bidColumns['created_at'])) {
        $insert['created_at'] = $nowValue;
    }
    if (isset($bidColumns['updated_at'])) {
        $insert['updated_at'] = $nowValue;
    }

    $insertColumns = array_keys($insert);
    $placeholders = array_map(static fn(string $column): string => ':' . $column, $insertColumns);
    $sql = 'INSERT INTO `listing_auction_bids` ('
        . implode(', ', array_map('bv_mobile_auction_ident', $insertColumns))
        . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    foreach ($insert as $column => $value) {
        $stmt->bindValue(':' . $column, $value);
    }
    $stmt->execute();
    $bidId = (int) $pdo->lastInsertId();

    $listingUpdates = [];
    $listingParams = [':listing_id' => $listingId];
    if (bv_mobile_auction_has_column($pdo, 'listings', 'auction_current_bid')) {
        $listingUpdates[] = '`auction_current_bid` = :bid_amount';
    }
    if (bv_mobile_auction_has_column($pdo, 'listings', 'current_bid')) {
        $listingUpdates[] = '`current_bid` = :bid_amount';
    }
    if (bv_mobile_auction_has_column($pdo, 'listings', 'auction_bid_count')) {
        $listingUpdates[] = '`auction_bid_count` = COALESCE(`auction_bid_count`, 0) + 1';
    }
    if (bv_mobile_auction_has_column($pdo, 'listings', 'bid_count')) {
        $listingUpdates[] = '`bid_count` = COALESCE(`bid_count`, 0) + 1';
    }
    if (bv_mobile_auction_has_column($pdo, 'listings', 'last_bid_at')) {
        $listingUpdates[] = '`last_bid_at` = NOW()';
    }
    if (bv_mobile_auction_has_column($pdo, 'listings', 'auction_winner_user_id')) {
        $listingUpdates[] = '`auction_winner_user_id` = :winner_user_id';
        $listingParams[':winner_user_id'] = $userId;
    }
    if (bv_mobile_auction_has_column($pdo, 'listings', 'updated_at')) {
        $listingUpdates[] = '`updated_at` = NOW()';
    }

    if ($listingUpdates !== []) {
        $listingParams[':bid_amount'] = $bidAmount;
        $stmt = $pdo->prepare('UPDATE `listings` SET ' . implode(', ', $listingUpdates) . ' WHERE `id` = :listing_id LIMIT 1');
        $stmt->execute($listingParams);
    }

    $bidCount = bv_mobile_auction_count_existing_bids($pdo, $listingId);
    $currency = (string) bv_mobile_auction_value($listing, $currencyColumn, 'USD');
    $nextMinBid = bv_mobile_auction_money($bidAmount + $increment);
    $titleColumn = bv_mobile_auction_first_column($pdo, 'listings', ['title', 'name', 'listing_title']);  
    $slugColumn = bv_mobile_auction_first_column($pdo, 'listings', ['slug']);
    $listingTitle = (string) bv_mobile_auction_value($listing, $titleColumn, 'Auction listing');
    $listingSlug = $slugColumn !== null ? (string) bv_mobile_auction_value($listing, $slugColumn, '') : '';
    $notificationListingTitle = trim($listingTitle);
    if ($notificationListingTitle === '') {
        $notificationListingTitle = 'your auction listing';
    }
    $bidNotificationContext = [
        'listing_id' => $listingId,
        'listing_title' => $notificationListingTitle,
        'seller_id' => $sellerId,
        'bidder_user_id' => $userId,
        'bidder_name' => bv_mobile_auction_bidder_display_name($pdo, $userId),
        'bid_amount' => $bidAmount,
        'currency' => $currency,
        'previous_bidder_user_id' => $previousHighestBidderUserId !== null ? (int) $previousHighestBidderUserId : 0,
        'previous_bid_amount' => $previousHighestBidAmount !== null ? (float) $previousHighestBidAmount : 0.0,
    ];	
    $outbid = [
        'was_outbid' => $wasOutbid,
        'previous_highest_bidder_user_id' => $wasOutbid ? (int) $previousHighestBidderUserId : null,
        'previous_highest_bid_amount' => $wasOutbid && $previousHighestBidAmount !== null ? bv_mobile_auction_money((float) $previousHighestBidAmount) : null,
        'new_highest_bidder_user_id' => $userId,
        'new_highest_bid_amount' => $bidAmount,
        'notification_queued' => false,
        'notification_in_app_created' => false,
        'notification_in_app_error' => null,
    ];
    $outbidNotificationContext = null;
    if ($wasOutbid) {
        $outbidNotificationContext = [
            'listing_id' => $listingId,
            'listing_title' => $notificationListingTitle,
            'listing_slug' => $listingSlug,
            'currency' => $currency,
            'previous_bidder_user_id' => (int) $previousHighestBidderUserId,
            'previous_bid_amount' => $previousHighestBidAmount !== null ? (float) $previousHighestBidAmount : 0.0,
            'previous_bid_id' => $previousBidId,
            'new_bidder_user_id' => $userId,
            'new_bid_amount' => $bidAmount,
            'bidder_user_id' => $userId,
            'bid_amount' => $bidAmount,
            'seller_id' => $sellerId,
            'bidder_name' => $bidNotificationContext['bidder_name'],			
            'bid_id' => $bidId,
        ];
    }

    $pdo->commit();

    if ($outbidNotificationContext !== null) {
        $outbid['notification_queued'] = bv_mobile_auction_queue_outbid_email($pdo, $outbidNotificationContext);

    } else {
        bv_mobile_auction_notification_log('notification_skipped', [
            'reason' => 'no_previous_outbid',
            'listing_id' => $listingId,
            'bidder_user_id' => $userId,
        ]); 
    }


    bv_mobile_auction_notify_bid_events($pdo, $bidNotificationContext);
	
	
   $outbidResponse = [
        'was_outbid' => (bool) ($outbid['was_outbid'] ?? false),
        'previous_highest_bidder_user_id' => isset($outbid['previous_highest_bidder_user_id']) ? (int) $outbid['previous_highest_bidder_user_id'] : null,
        'previous_highest_bid_amount' => isset($outbid['previous_highest_bid_amount']) ? (float) $outbid['previous_highest_bid_amount'] : null,
        'new_highest_bidder_user_id' => (int) ($outbid['new_highest_bidder_user_id'] ?? $userId),
        'new_highest_bid_amount' => (float) ($outbid['new_highest_bid_amount'] ?? $bidAmount),
        'notification_queued' => (bool) ($outbid['notification_queued'] ?? false),
         'notification_in_app_created' => (bool) ($outbid['notification_in_app_created'] ?? false),
    ];
    if (!empty($outbid['notification_in_app_error'])) {
        $outbidResponse['notification_in_app_error'] = (string) $outbid['notification_in_app_error'];
    }

    bv_mobile_auction_json(200, [
        'ok' => true,
        'data' => [
            'bid' => [
                'id' => $bidId,
                'listing_id' => $listingId,
                'bid_amount' => $bidAmount,
                'currency' => $currency,
                'is_highest' => true,
                'created_at' => gmdate('c'),
            ],
            'auction' => [
                'current_bid' => $bidAmount,
                'next_min_bid' => $nextMinBid,
                'bid_count' => $bidCount,
                'ends_at' => is_string($endsAt) && trim($endsAt) !== '' ? $endsAt : null,
            ],
            'outbid' => $outbidResponse,		
        ],
    ]);
} catch (BvMobileAuctionApiException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    bv_mobile_auction_error($e->getMessage(), $e->publicMessage(), $e->statusCode());
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    bv_mobile_auction_log('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_mobile_auction_error('server_error', 'Something went wrong.', 500);
}
