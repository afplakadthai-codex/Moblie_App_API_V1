<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/buyer_offer_checkout.php
// Starts checkout handoff for an authenticated buyer's accepted offer. Mobile-only,
// JSON-only, bearer-token authenticated, and never uses website sessions.
// =============================================================================

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$GLOBALS['bvm_buyer_offer_checkout_request_id'] = bin2hex(random_bytes(8));
$GLOBALS['bvm_buyer_offer_checkout_log_context'] = [
    'request_id' => $GLOBALS['bvm_buyer_offer_checkout_request_id'],
    'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
];

function bvm_buyer_offer_checkout_public_root(): string
{
    return dirname(__DIR__, 3);
}

function bvm_buyer_offer_checkout_project_root(): string
{
    return dirname(bvm_buyer_offer_checkout_public_root());
}

function bvm_buyer_offer_checkout_request_id(): string
{
    return (string) ($GLOBALS['bvm_buyer_offer_checkout_request_id'] ?? '');
}

function bvm_buyer_offer_checkout_meta(): array
{
    return [
        'api_version' => 'mobile-v1',
        'generated_at' => gmdate('Y-m-d H:i:s'),
        'request_id' => bvm_buyer_offer_checkout_request_id(),
    ];
}

function bvm_buyer_offer_checkout_set_log_context(array $context): void
{
    $safeKeys = [
        'request_id' => true,
        'buyer_user_id' => true,
        'offer_id' => true,
        'listing_id' => true,
        'token_id' => true,
        'remote_ip' => true,
        'user_agent' => true,
    ];

    foreach ($context as $key => $value) {
        if (!isset($safeKeys[$key]) || $value === null || $value === '') {
            continue;
        }

        $GLOBALS['bvm_buyer_offer_checkout_log_context'][$key] = $value;
    }
}

function bvm_buyer_offer_checkout_log_event(string $event, array $context = []): void
{
    $blockedKeys = [
        'bearer_token' => true,
        'checkout_token' => true,
        'token' => true,
        'token_hash' => true,
        'password' => true,
        'db_pass' => true,
        'db_password' => true,
    ];

    $base = $GLOBALS['bvm_buyer_offer_checkout_log_context'] ?? [];
    $payload = ['event' => $event] + $base;

    foreach ($context as $key => $value) {
        if (isset($blockedKeys[$key])) {
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $payload[$key] = $value;
        }
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[BV Mobile Buyer Offer Checkout] ' . ($json === false ? $event : $json));
}

function bvm_buyer_offer_checkout_json(int $statusCode, array $payload): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function bvm_buyer_offer_checkout_error(string $code, string $message, int $statusCode): void
{
    bvm_buyer_offer_checkout_json($statusCode, [
        'ok' => false,
        'error' => ['code' => $code, 'message' => $message],
        'meta' => bvm_buyer_offer_checkout_meta(),
    ]);
}

function bvm_buyer_offer_checkout_base_url(): string
{
    if (defined('BETTAVARO_BASE_URL') && trim((string) constant('BETTAVARO_BASE_URL')) !== '') {
        return rtrim(trim((string) constant('BETTAVARO_BASE_URL')), '/');
    }

    foreach (['APP_URL', 'BETTAVARO_BASE_URL'] as $envName) {
        $value = getenv($envName);
        if (is_string($value) && trim($value) !== '') {
            return rtrim(trim($value), '/');
        }
    }

    return 'https://www.bettavaro.com';
}

bvm_buyer_offer_checkout_log_event('request_started');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bvm_buyer_offer_checkout_error('method_not_allowed', 'Only POST requests are accepted.', 405);
}

function bvm_buyer_offer_checkout_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $publicRoot = bvm_buyer_offer_checkout_public_root();
    $projectRoot = bvm_buyer_offer_checkout_project_root();
    $candidates = [
        $publicRoot . '/config/db.php',
        $publicRoot . '/includes/db.php',
        $projectRoot . '/config/db.php',
        $projectRoot . '/includes/db.php',
    ];

    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }

        $loader = static function (string $includePath): array {
            $db_host = $db_user = $db_pass = $db_name = $db_port = null;
            $host = $user = $pass = $name = $port = null;
            $DB_HOST = $DB_USER = $DB_PASS = $DB_NAME = $DB_PORT = null;
            $dsn = null;
            $pdo = $db = $conn = null;

            ob_start();
            /** @noinspection PhpIncludeInspection */
            include $includePath;
            @ob_end_clean();

            return compact(
                'db_host', 'db_user', 'db_pass', 'db_name', 'db_port',
                'host', 'user', 'pass', 'name', 'port',
                'DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DB_PORT',
                'dsn', 'pdo', 'db', 'conn'
            );
        };

        $vars = $loader($path);

        foreach (['pdo', 'db', 'conn'] as $name) {
            if (($vars[$name] ?? null) instanceof PDO) {
                $pdo = $vars[$name];
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                return $pdo;
            }
        }

        foreach (['pdo', 'db', 'conn'] as $name) {
            if (($GLOBALS[$name] ?? null) instanceof PDO) {
                $pdo = $GLOBALS[$name];
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                return $pdo;
            }
        }

        $dsn = $vars['dsn'] ?? null;
        $dbUser = $vars['db_user'] ?? $vars['user'] ?? $vars['DB_USER'] ?? null;
        $dbPass = $vars['db_pass'] ?? $vars['pass'] ?? $vars['DB_PASS'] ?? '';
        if (is_string($dsn) && $dsn !== '' && $dbUser !== null) {
            $pdo = new PDO($dsn, (string) $dbUser, (string) $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return $pdo;
        }

        $host = $vars['db_host'] ?? $vars['host'] ?? $vars['DB_HOST'] ?? null;
        $dbName = $vars['db_name'] ?? $vars['name'] ?? $vars['DB_NAME'] ?? null;
        $port = (int) ($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);
        if ($host !== null && $dbUser !== null && $dbName !== null) {
            $pdo = new PDO(
                'mysql:host=' . (string) $host . ';port=' . $port . ';dbname=' . (string) $dbName . ';charset=utf8mb4',
                (string) $dbUser,
                (string) $dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            return $pdo;
        }
    }

    throw new RuntimeException('Unable to locate a PDO database connection.');
}

function bvm_buyer_offer_checkout_bearer_token(): ?string
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    }

    $authorization = '';
    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'authorization') {
            $authorization = trim((string) $value);
            break;
        }
    }

    if ($authorization === '') {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return null;
    }

    $token = trim($matches[1]);
    return $token === '' ? null : $token;
}

function bvm_buyer_offer_checkout_authenticate(PDO $pdo): int
{
    $token = bvm_buyer_offer_checkout_bearer_token();
    if ($token === null) {
        bvm_buyer_offer_checkout_error('unauthorized', 'Missing bearer token.', 401);
    }

    $stmt = $pdo->prepare(
        'SELECT id, user_id FROM mobile_auth_tokens '
        . 'WHERE token_hash = :token_hash '
        . 'AND revoked_at IS NULL '
        . 'AND expires_at > UTC_TIMESTAMP() '
        . 'LIMIT 1'
    );
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $row = $stmt->fetch();

    if (!$row) {
        bvm_buyer_offer_checkout_error('unauthorized', 'Invalid or expired bearer token.', 401);
    }

    try {
        $update = $pdo->prepare('UPDATE mobile_auth_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = :id LIMIT 1');
        $update->execute([':id' => (int) $row['id']]);
    } catch (Throwable $e) {
        bvm_buyer_offer_checkout_log_event('auth_last_used_update_failed', ['message' => $e->getMessage()]);
    }

    return (int) $row['user_id'];
}

function bvm_buyer_offer_checkout_request_data(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $rawBody = file_get_contents('php://input');
        $decoded = json_decode($rawBody === false ? '' : $rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function bvm_buyer_offer_checkout_required_offer_id(array $data): int
{
    if (!array_key_exists('offer_id', $data) || trim((string) $data['offer_id']) === '') {
        bvm_buyer_offer_checkout_error('missing_offer_id', 'Missing offer_id.', 400);
    }

    $offerId = filter_var($data['offer_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($offerId === false) {
        bvm_buyer_offer_checkout_error('invalid_offer_id', 'Invalid offer_id.', 400);
    }

    return (int) $offerId;
}

function bvm_buyer_offer_checkout_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS table_count '
        . 'FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute([':table_name' => $table]);
    return (int) ($stmt->fetch()['table_count'] ?? 0) > 0;
}

function bvm_buyer_offer_checkout_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME '
        . 'FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute([':table_name' => $table]);

    $columns = [];
    foreach ($stmt->fetchAll() as $row) {
        $columns[(string) $row['COLUMN_NAME']] = true;
    }

    $cache[$table] = $columns;
    return $columns;
}

function bvm_buyer_offer_checkout_has_column(array $columns, string $column): bool
{
    return isset($columns[$column]);
}

function bvm_buyer_offer_checkout_nullable_float($value): ?float
{
    return $value === null ? null : (float) $value;
}

function bvm_buyer_offer_checkout_rate_limit_guard(PDO $pdo, int $buyerUserId): void
{
    unset($pdo);

    // No existing reliable request-log table is guaranteed in this deployment package.
    // Keep this guard as a production-safe no-op rather than introducing a hard dependency
    // that could break mobile checkout during rollout.
    bvm_buyer_offer_checkout_log_event('rate_limit_skipped', [
        'buyer_user_id' => $buyerUserId,
        'reason' => 'no_reliable_existing_rate_limit_table',
    ]);
}

function bvm_buyer_offer_checkout_plain_token_from_row(array $row, array $tokenColumns): ?string
{
    if (!bvm_buyer_offer_checkout_has_column($tokenColumns, 'token')) {
        return null;
    }

    $token = trim((string) ($row['token'] ?? ''));
    return $token === '' ? null : $token;
}

function bvm_buyer_offer_checkout_find_active_token(PDO $pdo, int $offerId, array $tokenColumns): ?array
{
    $select = ['id'];
    foreach (['expires_at', 'token', 'token_hash', 'status', 'used_at'] as $column) {
        if (bvm_buyer_offer_checkout_has_column($tokenColumns, $column)) {
            $select[] = $column;
        }
    }

    $where = ['offer_id = :offer_id'];
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'status')) {
        $where[] = "status = 'active'";
    }
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'expires_at')) {
        $where[] = 'expires_at >= UTC_TIMESTAMP()';
    }
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'used_at')) {
        $where[] = 'used_at IS NULL';
    }

    // Transaction locks prevent double taps/concurrent requests from creating multiple active tokens.
    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $select) . ' '
        . 'FROM listing_offer_checkout_tokens '
        . 'WHERE ' . implode(' AND ', $where) . ' '
        . 'ORDER BY ' . (bvm_buyer_offer_checkout_has_column($tokenColumns, 'expires_at') ? 'expires_at DESC, ' : '') . 'id DESC '
        . 'LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':offer_id' => $offerId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function bvm_buyer_offer_checkout_invalidate_active_tokens(PDO $pdo, int $offerId, array $tokenColumns): int
{
    $set = [];
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'status')) {
        $set[] = "status = 'expired'";
    }
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'expires_at')) {
        $set[] = 'expires_at = UTC_TIMESTAMP()';
    }
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'updated_at')) {
        $set[] = 'updated_at = UTC_TIMESTAMP()';
    }

    if ($set === []) {
        return 0;
    }

    $where = ['offer_id = :offer_id'];
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'status')) {
        $where[] = "status = 'active'";
    }
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'expires_at')) {
        $where[] = 'expires_at >= UTC_TIMESTAMP()';
    }
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'used_at')) {
        $where[] = 'used_at IS NULL';
    }

    // Old active tokens are revoked/expired before issuing a fresh one so only the just-returned
    // plain token can be used by the mobile handoff. Do not mark used_at unless actually consumed.
    $stmt = $pdo->prepare(
        'UPDATE listing_offer_checkout_tokens '
        . 'SET ' . implode(', ', $set) . ' '
        . 'WHERE ' . implode(' AND ', $where)
    );
    $stmt->execute([':offer_id' => $offerId]);

    return $stmt->rowCount();
}

function bvm_buyer_offer_checkout_create_token(PDO $pdo, array $offer, array $tokenColumns): array
{
    if (!bvm_buyer_offer_checkout_has_column($tokenColumns, 'token') && !bvm_buyer_offer_checkout_has_column($tokenColumns, 'token_hash')) {
        bvm_buyer_offer_checkout_error('checkout_token_unavailable', 'Checkout token storage is unavailable.', 500);
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = $offer['expires_at'];

    if ($expiresAt === null || (string) $expiresAt === '') {
        $stmt = $pdo->query('SELECT DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR) AS token_expires_at');
        $expiresAt = (string) (($stmt->fetch()['token_expires_at'] ?? null) ?: gmdate('Y-m-d H:i:s', time() + 86400));
    }

    $insertValues = [
        'offer_id' => (int) $offer['id'],
        'listing_id' => (int) $offer['listing_id'],
        'buyer_user_id' => (int) $offer['buyer_user_id'],
        'seller_user_id' => (int) $offer['seller_user_id'],
        'currency' => (string) $offer['currency'],
        'agreed_price' => number_format((float) $offer['agreed_price'], 2, '.', ''),
    ];

    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'status')) {
        $insertValues['status'] = 'active';
    }
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'expires_at')) {
        $insertValues['expires_at'] = (string) $expiresAt;
    }

    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'token_hash')) {
       // token_hash is the preferred production validation path for new checkout tokens.		
        $insertValues['token_hash'] = $tokenHash;
   }
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'token')) {
        // Temporary legacy compatibility: persist the plain token only while the website
        // cart/checkout bridge still requires offer_token. Remove this persistence after
        // cart/checkout can rely exclusively on token_id and offer_id. 
        $insertValues['token'] = $token;
    }

    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'used_at')) {
        $insertValues['used_at'] = null;
    }
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'created_at')) {
        $insertValues['created_at'] = null;
    }
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'updated_at')) {
        $insertValues['updated_at'] = null;
    }

    $columns = [];
    $placeholders = [];
    $params = [];
    foreach ($insertValues as $column => $value) {
        $columns[] = '`' . $column . '`';
        if ($column === 'created_at' || $column === 'updated_at') {
            $placeholders[] = 'UTC_TIMESTAMP()';
            continue;
        }

        $placeholder = ':' . $column;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $value;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO listing_offer_checkout_tokens (' . implode(', ', $columns) . ') '
        . 'VALUES (' . implode(', ', $placeholders) . ')'
    );
    $stmt->execute($params);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'token' => $token,
        'expires_at' => (string) $expiresAt,
    ];
}

function bvm_buyer_offer_checkout_is_duplicate_key(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }

    $errorInfo = $e->errorInfo;
    return (($errorInfo[0] ?? null) === '23000') || ((int) ($errorInfo[1] ?? 0) === 1062);
}

function bvm_buyer_offer_checkout_offer_payload(array $offer): array
{
    return [
        'id' => (int) $offer['id'],
        'status' => (string) $offer['status'],
        'agreed_price' => bvm_buyer_offer_checkout_nullable_float($offer['agreed_price']),
        'currency' => (string) $offer['currency'],
        'expires_at' => $offer['expires_at'],
    ];
}

try {
    $pdo = bvm_buyer_offer_checkout_pdo();
    $buyerUserId = bvm_buyer_offer_checkout_authenticate($pdo);
    bvm_buyer_offer_checkout_set_log_context(['buyer_user_id' => $buyerUserId]);
    bvm_buyer_offer_checkout_log_event('auth_ok');

    bvm_buyer_offer_checkout_rate_limit_guard($pdo, $buyerUserId);

    $requestData = bvm_buyer_offer_checkout_request_data();
    $offerId = bvm_buyer_offer_checkout_required_offer_id($requestData);
    bvm_buyer_offer_checkout_set_log_context(['offer_id' => $offerId]);

    $pdo->beginTransaction();

    // The offer row is locked so concurrent double taps observe one serialized checkout-token decision.
    $offerStmt = $pdo->prepare(
        'SELECT id, listing_id, buyer_user_id, seller_user_id, status, currency, agreed_price, expires_at, completed_order_id '
        . 'FROM listing_offers '
        . 'WHERE id = :offer_id AND buyer_user_id = :buyer_user_id '
        . 'LIMIT 1 FOR UPDATE'
    );
    $offerStmt->execute([
        ':offer_id' => $offerId,
        ':buyer_user_id' => $buyerUserId,
    ]);
    $offer = $offerStmt->fetch();

    if (!$offer) {
        $pdo->rollBack();
        bvm_buyer_offer_checkout_error('offer_not_found', 'Offer not found.', 404);
    }

    bvm_buyer_offer_checkout_set_log_context([
        'offer_id' => (int) $offer['id'],
        'listing_id' => (int) $offer['listing_id'],
    ]);
    bvm_buyer_offer_checkout_log_event('offer_locked');

    $status = (string) $offer['status'];
    if (!in_array($status, ['seller_accepted', 'buyer_checkout_ready'], true)) {
        $pdo->rollBack();
        bvm_buyer_offer_checkout_error('offer_not_accepted', 'Offer is not ready for checkout.', 409);
    }

    if ($offer['completed_order_id'] !== null) {
        $pdo->rollBack();
        bvm_buyer_offer_checkout_error('offer_completed', 'Offer checkout has already been completed.', 409);
    }

    if ($offer['agreed_price'] === null || (float) $offer['agreed_price'] <= 0) {
        $pdo->rollBack();
        bvm_buyer_offer_checkout_error('offer_not_accepted', 'Offer is not ready for checkout.', 409);
    }

    if ($offer['expires_at'] !== null && (string) $offer['expires_at'] !== '') {
        $expiryStmt = $pdo->prepare('SELECT CASE WHEN :expires_at < UTC_TIMESTAMP() THEN 1 ELSE 0 END AS is_expired');
        $expiryStmt->execute([':expires_at' => (string) $offer['expires_at']]);
        if ((int) ($expiryStmt->fetch()['is_expired'] ?? 0) === 1) {
            $pdo->rollBack();
            bvm_buyer_offer_checkout_error('offer_expired', 'Offer has expired.', 409);
        }
    }

    $listingColumns = bvm_buyer_offer_checkout_columns($pdo, 'listings');
    $listingSelect = ['id', 'status'];
    foreach (['sale_status', 'stock_available', 'deleted_at'] as $column) {
        if (bvm_buyer_offer_checkout_has_column($listingColumns, $column)) {
            $listingSelect[] = $column;
        }
    }

    // Listing is also locked to keep availability consistent with the accepted offer during handoff.
    $listingStmt = $pdo->prepare(
        'SELECT ' . implode(', ', $listingSelect) . ' '
        . 'FROM listings '
        . 'WHERE id = :listing_id '
        . 'LIMIT 1 FOR UPDATE'
    );
    $listingStmt->execute([':listing_id' => (int) $offer['listing_id']]);
    $listing = $listingStmt->fetch();
    bvm_buyer_offer_checkout_log_event('listing_locked');

    $listingStatus = strtolower((string) ($listing['status'] ?? ''));
    $saleStatus = strtolower((string) ($listing['sale_status'] ?? 'available'));
    $stockAvailable = (int) ($listing['stock_available'] ?? 1);
    $deletedAt = $listing['deleted_at'] ?? null;

    if (!$listing || $listingStatus !== 'active' || $saleStatus !== 'available' || $stockAvailable < 1 || $deletedAt !== null) {
        $pdo->rollBack();
        bvm_buyer_offer_checkout_error('listing_not_available', 'Listing is not available for checkout.', 409);
    }

    if (!bvm_buyer_offer_checkout_table_exists($pdo, 'listing_offer_checkout_tokens')) {
        $pdo->rollBack();
        bvm_buyer_offer_checkout_error('checkout_token_unavailable', 'Checkout token storage is unavailable.', 500);
    }

    $tokenColumns = bvm_buyer_offer_checkout_columns($pdo, 'listing_offer_checkout_tokens');
    $activeToken = bvm_buyer_offer_checkout_find_active_token($pdo, (int) $offer['id'], $tokenColumns);

    if ($activeToken !== null) {
        $plainToken = bvm_buyer_offer_checkout_plain_token_from_row($activeToken, $tokenColumns);
        if ($plainToken !== null) {
            $checkoutToken = $plainToken;
            $tokenExpiresAt = (string) ($activeToken['expires_at'] ?? $offer['expires_at'] ?? '');
            bvm_buyer_offer_checkout_set_log_context(['token_id' => (int) $activeToken['id']]);
            bvm_buyer_offer_checkout_log_event('active_token_reused');
        } else {
            bvm_buyer_offer_checkout_set_log_context(['token_id' => (int) $activeToken['id']]);
            $invalidatedRows = bvm_buyer_offer_checkout_invalidate_active_tokens($pdo, (int) $offer['id'], $tokenColumns);
            bvm_buyer_offer_checkout_log_event('active_token_invalidated', ['invalidated_rows' => $invalidatedRows]);
            $activeToken = null;
        }
    }

    if ($activeToken === null) {
        $tokenWasCreated = true;
        try {
            $createdToken = bvm_buyer_offer_checkout_create_token($pdo, $offer, $tokenColumns);
        } catch (Throwable $e) {
            if (!bvm_buyer_offer_checkout_is_duplicate_key($e)) {
                throw $e;
            }

            $duplicateToken = bvm_buyer_offer_checkout_find_active_token($pdo, (int) $offer['id'], $tokenColumns);
            $duplicatePlainToken = $duplicateToken === null ? null : bvm_buyer_offer_checkout_plain_token_from_row($duplicateToken, $tokenColumns);
            if ($duplicateToken !== null && $duplicatePlainToken !== null) {
                $createdToken = [
                    'id' => (int) $duplicateToken['id'],
                    'token' => $duplicatePlainToken,
                    'expires_at' => (string) ($duplicateToken['expires_at'] ?? $offer['expires_at'] ?? ''),
                ];
                $tokenWasCreated = false;
                bvm_buyer_offer_checkout_set_log_context(['token_id' => (int) $duplicateToken['id']]);
                bvm_buyer_offer_checkout_log_event('active_token_reused', ['duplicate_key_recovered' => true]);
            } else {
                if ($duplicateToken !== null) {
                    bvm_buyer_offer_checkout_set_log_context(['token_id' => (int) $duplicateToken['id']]);
                }
                $invalidatedRows = bvm_buyer_offer_checkout_invalidate_active_tokens($pdo, (int) $offer['id'], $tokenColumns);
                bvm_buyer_offer_checkout_log_event('active_token_invalidated', [
                    'invalidated_rows' => $invalidatedRows,
                    'duplicate_key_recovered' => true,
                ]);
                $createdToken = bvm_buyer_offer_checkout_create_token($pdo, $offer, $tokenColumns);
            }
        }

        $checkoutToken = $createdToken['token'];
        $tokenExpiresAt = $createdToken['expires_at'];
        bvm_buyer_offer_checkout_set_log_context(['token_id' => (int) $createdToken['id']]);
        if ($tokenWasCreated) {
            bvm_buyer_offer_checkout_log_event('token_created');
        }
    }

    $freshOfferStmt = $pdo->prepare(
        'SELECT id, listing_id, buyer_user_id, seller_user_id, status, currency, agreed_price, expires_at, completed_order_id '
        . 'FROM listing_offers '
        . 'WHERE id = :offer_id AND buyer_user_id = :buyer_user_id '
        . 'LIMIT 1 FOR UPDATE'
    );
    $freshOfferStmt->execute([
        ':offer_id' => $offerId,
        ':buyer_user_id' => $buyerUserId,
    ]);
    $freshOffer = $freshOfferStmt->fetch();

    if (!$freshOffer) {
        $pdo->rollBack();
        bvm_buyer_offer_checkout_error('offer_not_found', 'Offer not found.', 404);
    }

    $checkoutUrl = bvm_buyer_offer_checkout_base_url()
        . '/offer_accept_checkout.php?offer_id='
        . (int) $freshOffer['id']
        . '&token='
        . rawurlencode($checkoutToken);

    $pdo->commit();

    bvm_buyer_offer_checkout_log_event('checkout_ready');

    bvm_buyer_offer_checkout_json(200, [
        'ok' => true,
        'data' => [
            'offer' => bvm_buyer_offer_checkout_offer_payload($freshOffer),
            'checkout' => [
                'can_checkout' => true,
                'checkout_url' => $checkoutUrl,
                'token_expires_at' => $tokenExpiresAt,
            ],
        ],
        'meta' => bvm_buyer_offer_checkout_meta(),
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    bvm_buyer_offer_checkout_log_event('server_error', [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
    ]);
    bvm_buyer_offer_checkout_error('server_error', 'A server error occurred.', 500);
}
