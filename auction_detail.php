<?php
declare(strict_types=1);

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

final class BvMobileAuctionDetailException extends RuntimeException
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

function bv_mobile_auction_detail_meta(): array
{
    return [
        'api_version' => 'mobile-v1',
        'generated_at' => gmdate('c'),
    ];
}

function bv_mobile_auction_detail_json(int $statusCode, array $payload): void
{
    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        $payload['meta'] = bv_mobile_auction_detail_meta();
    }

    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function bv_mobile_auction_detail_error(string $code, string $message, int $statusCode = 400): void
{
    bv_mobile_auction_detail_json($statusCode, [
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);
}

function bv_mobile_auction_detail_public_root(): string
{
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
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

function bv_mobile_auction_detail_project_root(): string
{
    return dirname(bv_mobile_auction_detail_public_root());
}

function bv_mobile_auction_detail_log(string $message): void
{
    $safeMessage = str_replace(["\r", "\n"], ' ', $message);
    error_log('[BV Mobile Auction Detail] ' . $safeMessage);

    $logFile = bv_mobile_auction_detail_public_root() . '/logs/mobile_auction_detail.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents($logFile, gmdate('[Y-m-d H:i:s] ') . $safeMessage . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function bv_mobile_auction_detail_db(): PDO
{
    static $loaded = false;
    static $connection = null;

    if ($loaded && $connection instanceof PDO) {
        return $connection;
    }
    $loaded = true;

    if (($GLOBALS['pdo'] ?? null) instanceof PDO) {
        $connection = $GLOBALS['pdo'];
        bv_mobile_auction_detail_prepare_pdo($connection);
        return $connection;
    }

    $publicRoot = bv_mobile_auction_detail_public_root();
    $projectRoot = bv_mobile_auction_detail_project_root();
    $candidates = [
        $publicRoot . '/config/db.php',
        $publicRoot . '/includes/db.php',
        $projectRoot . '/config/db.php',
        $projectRoot . '/includes/db.php',
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
            $pdo = $PDO = $db = $conn = null;
            include $path;
            return compact(
                'db_host', 'db_user', 'db_pass', 'db_name', 'db_port',
                'host', 'user', 'pass', 'name', 'port',
                'DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DB_PORT',
                'dsn', 'pdo', 'PDO', 'db', 'conn'
            );
        };

        $vars = $loader($file);
        foreach (['pdo', 'PDO', 'db', 'conn'] as $name) {
            if (($vars[$name] ?? null) instanceof PDO) {
                $connection = $vars[$name];
                bv_mobile_auction_detail_prepare_pdo($connection);
                return $connection;
            }
        }
        foreach (['pdo', 'PDO', 'db', 'conn'] as $name) {
            if (($GLOBALS[$name] ?? null) instanceof PDO) {
                $connection = $GLOBALS[$name];
                bv_mobile_auction_detail_prepare_pdo($connection);
                return $connection;
            }
        }

        $dsn = $vars['dsn'] ?? null;
        $username = $vars['db_user'] ?? $vars['user'] ?? $vars['DB_USER'] ?? null;
        $password = $vars['db_pass'] ?? $vars['pass'] ?? $vars['DB_PASS'] ?? null;

        if (is_string($dsn) && $dsn !== '' && $username !== null) {
            $connection = new PDO($dsn, (string) $username, (string) $password, bv_mobile_auction_detail_pdo_options());
            bv_mobile_auction_detail_prepare_pdo($connection);
            return $connection;
        }

        $host = $vars['db_host'] ?? $vars['host'] ?? $vars['DB_HOST'] ?? null;
        $dbName = $vars['db_name'] ?? $vars['name'] ?? $vars['DB_NAME'] ?? null;
        $port = (int) ($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);

        if ($host !== null && $username !== null && $dbName !== null) {
            $connection = new PDO(
                'mysql:host=' . (string) $host . ';port=' . $port . ';dbname=' . (string) $dbName . ';charset=utf8mb4',
                (string) $username,
                (string) $password,
                bv_mobile_auction_detail_pdo_options()
            );
            bv_mobile_auction_detail_prepare_pdo($connection);
            return $connection;
        }
    }

    throw new BvMobileAuctionDetailException('server_error', 'Database connection is unavailable.', 500);
}

function bv_mobile_auction_detail_pdo_options(): array
{
    return [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
}

function bv_mobile_auction_detail_prepare_pdo(PDO $pdo): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
}

function bv_mobile_auction_detail_ident(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new BvMobileAuctionDetailException('server_error', 'Invalid database identifier.', 500);
    }

    return '`' . $identifier . '`';
}

function bv_mobile_auction_detail_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM ' . bv_mobile_auction_detail_ident($table));
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['Field'])) {
                $columns[(string) $row['Field']] = true;
            }
        }
        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        bv_mobile_auction_detail_log('Column lookup failed for ' . $table . ': ' . $e->getMessage());
        $cache[$table] = [];
        return [];
    }
}

function bv_mobile_auction_detail_has_column(PDO $pdo, string $table, string $column): bool
{
    $columns = bv_mobile_auction_detail_columns($pdo, $table);
    return isset($columns[$column]);
}

function bv_mobile_auction_detail_first_column(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $column) {
        if (bv_mobile_auction_detail_has_column($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function bv_mobile_auction_detail_select_expr(PDO $pdo, string $table, string $column, string $alias, string $fallback = 'NULL'): string
{
    if (bv_mobile_auction_detail_has_column($pdo, $table, $column)) {
        return bv_mobile_auction_detail_ident($column) . ' AS ' . bv_mobile_auction_detail_ident($alias);
    }

    return $fallback . ' AS ' . bv_mobile_auction_detail_ident($alias);
}

function bv_mobile_auction_detail_require_columns(PDO $pdo, string $table, array $columns): void
{
    foreach ($columns as $column) {
        if (!bv_mobile_auction_detail_has_column($pdo, $table, $column)) {
            throw new BvMobileAuctionDetailException('server_error', 'Required database column is unavailable.', 500);
        }
    }
}

function bv_mobile_auction_detail_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if (!is_string($header) || !preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
        throw new BvMobileAuctionDetailException('unauthorized', 'Bearer token is required.', 401);
    }

    $token = trim($matches[1]);
    if ($token === '') {
        throw new BvMobileAuctionDetailException('unauthorized', 'Bearer token is required.', 401);
    }

    return $token;
}

function bv_mobile_auction_detail_authenticate(PDO $pdo): array
{
    bv_mobile_auction_detail_require_columns($pdo, 'mobile_auth_tokens', ['user_id', 'token_hash', 'expires_at']);
    bv_mobile_auction_detail_require_columns($pdo, 'users', ['id']);

    $tokenHash = hash('sha256', bv_mobile_auction_detail_bearer_token());
    $userSelect = [
        'u.' . bv_mobile_auction_detail_ident('id') . ' AS user_id',
    ];
    foreach (['account_status', 'status', 'is_active'] as $column) {
        if (bv_mobile_auction_detail_has_column($pdo, 'users', $column)) {
            $userSelect[] = 'u.' . bv_mobile_auction_detail_ident($column) . ' AS ' . bv_mobile_auction_detail_ident($column);
        }
    }

    $revokedSql = bv_mobile_auction_detail_has_column($pdo, 'mobile_auth_tokens', 'revoked_at')
        ? ' AND mat.' . bv_mobile_auction_detail_ident('revoked_at') . ' IS NULL'
        : '';

    $sql = 'SELECT ' . implode(', ', $userSelect)
        . ' FROM ' . bv_mobile_auction_detail_ident('mobile_auth_tokens') . ' mat'
        . ' INNER JOIN ' . bv_mobile_auction_detail_ident('users') . ' u ON u.' . bv_mobile_auction_detail_ident('id') . ' = mat.' . bv_mobile_auction_detail_ident('user_id')
        . ' WHERE mat.' . bv_mobile_auction_detail_ident('token_hash') . ' = :token_hash'
        . ' AND mat.' . bv_mobile_auction_detail_ident('expires_at') . ' > UTC_TIMESTAMP()'
        . $revokedSql
        . ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token_hash' => $tokenHash]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new BvMobileAuctionDetailException('unauthorized', 'Invalid or expired bearer token.', 401);
    }

    if (bv_mobile_auction_detail_has_column($pdo, 'mobile_auth_tokens', 'last_used_at')) {
        try {
            $update = $pdo->prepare(
                'UPDATE ' . bv_mobile_auction_detail_ident('mobile_auth_tokens')
                . ' SET ' . bv_mobile_auction_detail_ident('last_used_at') . ' = UTC_TIMESTAMP()'
                . ' WHERE ' . bv_mobile_auction_detail_ident('token_hash') . ' = :token_hash LIMIT 1'
            );
            $update->execute([':token_hash' => $tokenHash]);
        } catch (Throwable $e) {
            bv_mobile_auction_detail_log('Unable to update token last_used_at: ' . $e->getMessage());
        }
    }

    return $user;
}

function bv_mobile_auction_detail_user_active(array $user): bool
{
    if (array_key_exists('account_status', $user)) {
        return strtolower((string) $user['account_status']) === 'active';
    }
    if (array_key_exists('status', $user)) {
        return strtolower((string) $user['status']) === 'active';
    }
    if (array_key_exists('is_active', $user)) {
        return (int) $user['is_active'] === 1;
    }

    return true;
}

function bv_mobile_auction_detail_listing(PDO $pdo, int $listingId): array
{
    bv_mobile_auction_detail_require_columns($pdo, 'listings', ['id']);

    $select = [
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'id', 'id'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'title', 'title', "''"),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'seller_id', 'seller_id'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'currency', 'currency', "'USD'"),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'cover_image', 'cover_image'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'image_url', 'direct_image_url'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'status', 'status'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'sale_status', 'sale_status'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'sale_format', 'sale_format'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'selling_method', 'selling_method'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'auction_enabled', 'auction_enabled', '0'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'auction_status', 'auction_status'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'auction_start_at', 'auction_start_at'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'auction_starts_at', 'auction_starts_at'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'auction_end_at', 'auction_end_at'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'auction_ends_at', 'auction_ends_at'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'auction_current_bid', 'auction_current_bid'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'auction_bid_count', 'auction_bid_count'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'auction_winner_user_id', 'auction_winner_user_id'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'auction_start_price', 'auction_start_price'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'auction_min_increment', 'auction_min_increment'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'price', 'price'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'is_active', 'is_active'),
        bv_mobile_auction_detail_select_expr($pdo, 'listings', 'disabled_at', 'disabled_at'),
    ];

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM ' . bv_mobile_auction_detail_ident('listings') . ' WHERE ' . bv_mobile_auction_detail_ident('id') . ' = :listing_id LIMIT 1');
    $stmt->execute([':listing_id' => $listingId]);
    $listing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$listing) {
        throw new BvMobileAuctionDetailException('not_found', 'Listing was not found.', 404);
    }

    return $listing;
}

function bv_mobile_auction_detail_is_auction_listing(array $listing): bool
{
    if ((int) ($listing['auction_enabled'] ?? 0) === 1) {
        return true;
    }

    foreach (['sale_format', 'selling_method'] as $column) {
        if (strtolower((string) ($listing[$column] ?? '')) === 'auction') {
            return true;
        }
    }

    return false;
}

function bv_mobile_auction_detail_viewable(array $listing): bool
{
    $allowed = ['active' => true, 'available' => true, 'published' => true, 'reserved' => true, 'sold' => true];
    $status = strtolower((string) ($listing['status'] ?? ''));
    if ($status !== '') {
        return isset($allowed[$status]);
    }

    $saleStatus = strtolower((string) ($listing['sale_status'] ?? ''));
    if ($saleStatus !== '') {
        return isset($allowed[$saleStatus]);
    }

    return false;
}

function bv_mobile_auction_detail_listing_enabled_for_bidding(array $listing): bool
{
    if (array_key_exists('is_active', $listing) && $listing['is_active'] !== null && (int) $listing['is_active'] !== 1) {
        return false;
    }
    if (!empty($listing['disabled_at'])) {
        return false;
    }

    $status = strtolower((string) ($listing['status'] ?? ''));
    if ($status !== '' && !in_array($status, ['active', 'available', 'published'], true)) {
        return false;
    }

    $saleStatus = strtolower((string) ($listing['sale_status'] ?? ''));
    if ($saleStatus !== '' && !in_array($saleStatus, ['available', 'active'], true)) {
        return false;
    }

    return true;
}

function bv_mobile_auction_detail_money($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return round((float) $value, 2);
}

function bv_mobile_auction_detail_datetime(?string $value): ?DateTimeImmutable
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        bv_mobile_auction_detail_log('Invalid datetime value: ' . $value);
        return null;
    }
}

function bv_mobile_auction_detail_datetime_out(?string $value): ?string
{
    $dt = bv_mobile_auction_detail_datetime($value);
    return $dt instanceof DateTimeImmutable ? $dt->format('c') : null;
}

function bv_mobile_auction_detail_derive_status(array $listing, ?DateTimeImmutable $startsAt, ?DateTimeImmutable $endsAt, DateTimeImmutable $now): string
{
    $storedStatus = strtolower((string) ($listing['auction_status'] ?? ''));
    if (in_array($storedStatus, ['ended', 'closed', 'cancelled'], true)) {
        return 'closed';
    }

    if ($startsAt instanceof DateTimeImmutable && $startsAt > $now) {
        return 'scheduled';
    }

    if ($endsAt instanceof DateTimeImmutable && $endsAt < $now) {
        return 'ended';
    }

    if ($endsAt instanceof DateTimeImmutable && $now <= $endsAt && (!$startsAt instanceof DateTimeImmutable || $now >= $startsAt)) {
        return 'live';
    }

    if ($startsAt instanceof DateTimeImmutable && !$endsAt instanceof DateTimeImmutable && $now >= $startsAt) {
        return 'live';
    }

    return 'unknown';
}

function bv_mobile_auction_detail_bid_stats(PDO $pdo, int $listingId): array
{
    $stats = ['max_bid' => null, 'bid_count' => null, 'highest_bidder_user_id' => null];
    if (!bv_mobile_auction_detail_has_column($pdo, 'listing_auction_bids', 'listing_id')) {
        return $stats;
    }

    $amountExpr = bv_mobile_auction_detail_has_column($pdo, 'listing_auction_bids', 'bid_amount') ? 'MAX(' . bv_mobile_auction_detail_ident('bid_amount') . ')' : 'NULL';
    $countExpr = bv_mobile_auction_detail_has_column($pdo, 'listing_auction_bids', 'id') ? 'COUNT(*)' : '0';
    $stmt = $pdo->prepare('SELECT ' . $amountExpr . ' AS max_bid, ' . $countExpr . ' AS bid_count FROM ' . bv_mobile_auction_detail_ident('listing_auction_bids') . ' WHERE ' . bv_mobile_auction_detail_ident('listing_id') . ' = :listing_id');
    $stmt->execute([':listing_id' => $listingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['max_bid'] = $row['max_bid'] ?? null;
    $stats['bid_count'] = isset($row['bid_count']) ? (int) $row['bid_count'] : null;

    if (bv_mobile_auction_detail_has_column($pdo, 'listing_auction_bids', 'bidder_user_id') && bv_mobile_auction_detail_has_column($pdo, 'listing_auction_bids', 'bid_amount')) {
        $stmt = $pdo->prepare(
            'SELECT ' . bv_mobile_auction_detail_ident('bidder_user_id') . ' AS bidder_user_id'
            . ' FROM ' . bv_mobile_auction_detail_ident('listing_auction_bids')
            . ' WHERE ' . bv_mobile_auction_detail_ident('listing_id') . ' = :listing_id'
            . ' ORDER BY ' . bv_mobile_auction_detail_ident('bid_amount') . ' DESC, ' . (bv_mobile_auction_detail_has_column($pdo, 'listing_auction_bids', 'created_at') ? bv_mobile_auction_detail_ident('created_at') : bv_mobile_auction_detail_ident('id')) . ' ASC'
            . ' LIMIT 1'
        );
        $stmt->execute([':listing_id' => $listingId]);
        $highest = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($highest && $highest['bidder_user_id'] !== null) {
            $stats['highest_bidder_user_id'] = (int) $highest['bidder_user_id'];
        }
    }

    return $stats;
}

function bv_mobile_auction_detail_recent_bids(PDO $pdo, int $listingId): array
{
    if (!bv_mobile_auction_detail_has_column($pdo, 'listing_auction_bids', 'listing_id')) {
        return [];
    }

    $select = [
        bv_mobile_auction_detail_select_expr($pdo, 'listing_auction_bids', 'id', 'id'),
        bv_mobile_auction_detail_select_expr($pdo, 'listing_auction_bids', 'bidder_user_id', 'bidder_user_id'),
        bv_mobile_auction_detail_select_expr($pdo, 'listing_auction_bids', 'bid_amount', 'bid_amount'),
        bv_mobile_auction_detail_select_expr($pdo, 'listing_auction_bids', 'bid_status', 'bid_status', "'active'"),
        bv_mobile_auction_detail_select_expr($pdo, 'listing_auction_bids', 'is_auto_selected', 'is_auto_selected', '0'),
        bv_mobile_auction_detail_select_expr($pdo, 'listing_auction_bids', 'created_at', 'created_at'),
    ];
    $orderColumn = bv_mobile_auction_detail_first_column($pdo, 'listing_auction_bids', ['created_at', 'id']) ?? 'listing_id';
    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $select)
        . ' FROM ' . bv_mobile_auction_detail_ident('listing_auction_bids')
        . ' WHERE ' . bv_mobile_auction_detail_ident('listing_id') . ' = :listing_id'
        . ' ORDER BY ' . bv_mobile_auction_detail_ident($orderColumn) . ' DESC'
        . ' LIMIT 10'
    );
    $stmt->execute([':listing_id' => $listingId]);

    $bids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $bids[] = [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'bidder_user_id' => isset($row['bidder_user_id']) ? (int) $row['bidder_user_id'] : null,
            'bid_amount' => bv_mobile_auction_detail_money($row['bid_amount'] ?? null),
            'bid_status' => (string) ($row['bid_status'] ?? 'active'),
            'is_auto_selected' => ((int) ($row['is_auto_selected'] ?? 0)) === 1,
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
        ];
    }

    return $bids;
}

function bv_mobile_auction_detail_image_url(?string $path): ?string
{
    if ($path === null || trim($path) === '') {
        return null;
    }

    $path = trim($path);
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return $path;
    }

    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function bv_mobile_auction_detail_cannot_bid_reason(array $listing, array $user, string $auctionStatus, int $currentUserId): ?string
{
    if ((int) ($listing['seller_id'] ?? 0) === $currentUserId) {
        return 'current_user_is_seller';
    }
    if (!bv_mobile_auction_detail_user_active($user)) {
        return 'user_inactive';
    }
    if (!bv_mobile_auction_detail_listing_enabled_for_bidding($listing)) {
        return 'listing_disabled';
    }
    if ($auctionStatus === 'ended' || $auctionStatus === 'closed') {
        return 'auction_ended';
    }
    if ($auctionStatus !== 'live') {
        return 'auction_not_live';
    }

    return null;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        bv_mobile_auction_detail_error('method_not_allowed', 'Only GET requests are allowed.', 405);
    }

    $listingIdRaw = $_GET['listing_id'] ?? null;
    if (!is_string($listingIdRaw) || !preg_match('/^[1-9][0-9]*$/', $listingIdRaw)) {
        bv_mobile_auction_detail_error('invalid_request', 'listing_id is required.', 400);
    }
    $listingId = (int) $listingIdRaw;

    $pdo = bv_mobile_auction_detail_db();
    $user = bv_mobile_auction_detail_authenticate($pdo);
    $currentUserId = (int) $user['user_id'];

    $listing = bv_mobile_auction_detail_listing($pdo, $listingId);
    if (!bv_mobile_auction_detail_is_auction_listing($listing)) {
        throw new BvMobileAuctionDetailException('not_found', 'Auction listing was not found.', 404);
    }
    if (!bv_mobile_auction_detail_viewable($listing)) {
        throw new BvMobileAuctionDetailException('not_found', 'Listing was not found.', 404);
    }

    $startsAtRaw = $listing['auction_start_at'] ?? $listing['auction_starts_at'] ?? null;
    if (($startsAtRaw === null || $startsAtRaw === '') && !empty($listing['auction_starts_at'])) {
        $startsAtRaw = $listing['auction_starts_at'];
    }
    $endsAtRaw = $listing['auction_end_at'] ?? $listing['auction_ends_at'] ?? null;
    if (($endsAtRaw === null || $endsAtRaw === '') && !empty($listing['auction_ends_at'])) {
        $endsAtRaw = $listing['auction_ends_at'];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $startsAt = bv_mobile_auction_detail_datetime(is_string($startsAtRaw) ? $startsAtRaw : null);
    $endsAt = bv_mobile_auction_detail_datetime(is_string($endsAtRaw) ? $endsAtRaw : null);
    $auctionStatus = bv_mobile_auction_detail_derive_status($listing, $startsAt, $endsAt, $now);

    $stats = bv_mobile_auction_detail_bid_stats($pdo, $listingId);
    $currentBid = bv_mobile_auction_detail_money($listing['auction_current_bid'] ?? null);
    if ($currentBid === null) {
        $currentBid = bv_mobile_auction_detail_money($stats['max_bid'] ?? null);
    }
    if ($currentBid === null) {
        $currentBid = bv_mobile_auction_detail_money($listing['auction_start_price'] ?? null);
    }
    if ($currentBid === null) {
        $currentBid = bv_mobile_auction_detail_money($listing['price'] ?? null) ?? 0.00;
    }

    $startPrice = bv_mobile_auction_detail_money($listing['auction_start_price'] ?? null) ?? bv_mobile_auction_detail_money($listing['price'] ?? null) ?? 0.00;
    $minIncrement = bv_mobile_auction_detail_money($listing['auction_min_increment'] ?? null) ?? 1.00;
    if ($minIncrement <= 0) {
        $minIncrement = 1.00;
    }

    $bidCount = $listing['auction_bid_count'] !== null && $listing['auction_bid_count'] !== '' ? (int) $listing['auction_bid_count'] : null;
    if ($bidCount === null) {
        $bidCount = (int) ($stats['bid_count'] ?? 0);
    }

    $highestBidderUserId = $stats['highest_bidder_user_id'] !== null ? (int) $stats['highest_bidder_user_id'] : null;
    if ($highestBidderUserId === null && $listing['auction_winner_user_id'] !== null && $listing['auction_winner_user_id'] !== '') {
        $highestBidderUserId = (int) $listing['auction_winner_user_id'];
    }

    $cannotBidReason = bv_mobile_auction_detail_cannot_bid_reason($listing, $user, $auctionStatus, $currentUserId);
    $secondsRemaining = $endsAt instanceof DateTimeImmutable ? max(0, $endsAt->getTimestamp() - $now->getTimestamp()) : null;
    $imagePath = !empty($listing['direct_image_url']) ? (string) $listing['direct_image_url'] : ($listing['cover_image'] !== null ? (string) $listing['cover_image'] : null);

    bv_mobile_auction_detail_json(200, [
        'ok' => true,
        'data' => [
            'listing' => [
                'id' => (int) $listing['id'],
                'title' => (string) ($listing['title'] ?? ''),
                'seller_id' => isset($listing['seller_id']) ? (int) $listing['seller_id'] : null,
                'currency' => (string) ($listing['currency'] ?? 'USD'),
                'image_url' => bv_mobile_auction_detail_image_url($imagePath),
                'status' => (string) ($listing['status'] ?? ($listing['sale_status'] ?? '')),
            ],
            'auction' => [
                'status' => $auctionStatus,
                'auction_enabled' => bv_mobile_auction_detail_is_auction_listing($listing),
                'current_bid' => $currentBid,
                'start_price' => $startPrice,
                'min_increment' => $minIncrement,
                'next_min_bid' => bv_mobile_auction_detail_money($currentBid + $minIncrement),
                'bid_count' => $bidCount,
                'highest_bidder_user_id' => $highestBidderUserId,
                'is_current_user_highest' => $highestBidderUserId !== null && $highestBidderUserId === $currentUserId,
                'current_user_id' => $currentUserId,
                'can_bid' => $cannotBidReason === null,
                'cannot_bid_reason' => $cannotBidReason,
                'starts_at' => is_string($startsAtRaw) ? bv_mobile_auction_detail_datetime_out($startsAtRaw) : null,
                'ends_at' => is_string($endsAtRaw) ? bv_mobile_auction_detail_datetime_out($endsAtRaw) : null,
                'seconds_remaining' => $secondsRemaining,
            ],
            'recent_bids' => bv_mobile_auction_detail_recent_bids($pdo, $listingId),
        ],
    ]);
} catch (BvMobileAuctionDetailException $e) {
    bv_mobile_auction_detail_error($e->getMessage(), $e->publicMessage(), $e->statusCode());
} catch (Throwable $e) {
    bv_mobile_auction_detail_log('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_mobile_auction_detail_error('server_error', 'Something went wrong.', 500);
}
