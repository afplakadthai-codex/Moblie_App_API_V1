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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => ['code' => 'method_not_allowed', 'message' => 'Only POST requests are accepted.'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bvm_buyer_offer_checkout_public_root(): string
{
    return dirname(__DIR__, 3);
}

function bvm_buyer_offer_checkout_project_root(): string
{
    return dirname(bvm_buyer_offer_checkout_public_root());
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
    ]);
}

function bvm_buyer_offer_checkout_log(string $message): void
{
    error_log('[BV Mobile Buyer Offer Checkout] ' . $message);
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
        bvm_buyer_offer_checkout_log('Unable to update token last_used_at: ' . $e->getMessage());
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
        bvm_buyer_offer_checkout_error('missing_offer_id', 'Missing offer_id.', 400);
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
    $select = ['id', 'expires_at'];
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'token')) {
        $select[] = 'token';
    }
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'token_hash')) {
        $select[] = 'token_hash';
    }

    $where = [
        'offer_id = :offer_id',
        "status = 'active'",
        'expires_at >= UTC_TIMESTAMP()',
    ];
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'used_at')) {
        $where[] = 'used_at IS NULL';
    }

    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $select) . ' '
        . 'FROM listing_offer_checkout_tokens '
        . 'WHERE ' . implode(' AND ', $where) . ' '
        . 'ORDER BY expires_at DESC, id DESC '
        . 'LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':offer_id' => $offerId]);
    $row = $stmt->fetch();

    return $row ?: null;
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
        'status' => 'active',
        'expires_at' => (string) $expiresAt,
    ];

    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'token')) {
        $insertValues['token'] = $token;
    }
    if (bvm_buyer_offer_checkout_has_column($tokenColumns, 'token_hash')) {
        $insertValues['token_hash'] = $tokenHash;
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
        'token' => $token,
        'expires_at' => (string) $expiresAt,
    ];
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
    $requestData = bvm_buyer_offer_checkout_request_data();
    $offerId = bvm_buyer_offer_checkout_required_offer_id($requestData);

    $pdo->beginTransaction();

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

    $listingStmt = $pdo->prepare(
        'SELECT ' . implode(', ', $listingSelect) . ' '
        . 'FROM listings '
        . 'WHERE id = :listing_id '
        . 'LIMIT 1 FOR UPDATE'
    );
    $listingStmt->execute([':listing_id' => (int) $offer['listing_id']]);
    $listing = $listingStmt->fetch();

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
        if ($plainToken === null) {
            $pdo->rollBack();
            bvm_buyer_offer_checkout_error('checkout_token_unavailable', 'Active checkout token cannot be returned.', 500);
        }

        $checkoutToken = $plainToken;
        $tokenExpiresAt = (string) $activeToken['expires_at'];
    } else {
        $createdToken = bvm_buyer_offer_checkout_create_token($pdo, $offer, $tokenColumns);
        $checkoutToken = $createdToken['token'];
        $tokenExpiresAt = $createdToken['expires_at'];
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

    $checkoutUrl = 'https://www.bettavaro.com/offer_accept_checkout.php?offer_id='
        . (int) $freshOffer['id']
        . '&token='
        . rawurlencode($checkoutToken);

    $pdo->commit();

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
        'meta' => [
            'api_version' => 'mobile-v1',
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    bvm_buyer_offer_checkout_log('Database/server error: ' . $e->getMessage());
    bvm_buyer_offer_checkout_error('server_error', 'A server error occurred.', 500);
}
