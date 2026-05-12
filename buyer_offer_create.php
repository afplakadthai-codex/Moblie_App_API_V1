<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/buyer_offer_create.php
// Creates an authenticated buyer offer for a listing. Mobile-only, JSON-only,
// bearer-token authenticated, and never uses website sessions.
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

function bvm_buyer_offer_create_public_root(): string
{
    return dirname(__DIR__, 3);
}

function bvm_buyer_offer_create_project_root(): string
{
    return dirname(bvm_buyer_offer_create_public_root());
}

function bvm_buyer_offer_create_json(int $statusCode, array $payload): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function bvm_buyer_offer_create_error(string $code, string $message, int $statusCode): void
{
    bvm_buyer_offer_create_json($statusCode, [
        'ok' => false,
        'error' => ['code' => $code, 'message' => $message],
    ]);
}

function bvm_buyer_offer_create_log(string $message): void
{
    error_log('[BV Mobile Buyer Offer Create] ' . $message);
}

function bvm_buyer_offer_create_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $publicRoot = bvm_buyer_offer_create_public_root();
    $projectRoot = bvm_buyer_offer_create_project_root();
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

function bvm_buyer_offer_create_bearer_token(): ?string
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

function bvm_buyer_offer_create_authenticate(PDO $pdo): int
{
    $token = bvm_buyer_offer_create_bearer_token();
    if ($token === null) {
        bvm_buyer_offer_create_error('unauthorized', 'Missing bearer token.', 401);
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
        bvm_buyer_offer_create_error('unauthorized', 'Invalid or expired bearer token.', 401);
    }

    try {
        $update = $pdo->prepare('UPDATE mobile_auth_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = :id LIMIT 1');
        $update->execute([':id' => (int) $row['id']]);
    } catch (Throwable $e) {
        bvm_buyer_offer_create_log('Unable to update token last_used_at: ' . $e->getMessage());
    }

    return (int) $row['user_id'];
}

function bvm_buyer_offer_create_request_data(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $rawBody = file_get_contents('php://input');
        $decoded = json_decode($rawBody === false ? '' : $rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function bvm_buyer_offer_create_required_listing_id(array $data): int
{
    if (!array_key_exists('listing_id', $data) || trim((string) $data['listing_id']) === '') {
        bvm_buyer_offer_create_error('missing_listing_id', 'Missing listing_id.', 400);
    }

    $listingId = filter_var($data['listing_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($listingId === false) {
        bvm_buyer_offer_create_error('missing_listing_id', 'Missing listing_id.', 400);
    }

    return (int) $listingId;
}

function bvm_buyer_offer_create_parse_offer_price(array $data): string
{
    if (!array_key_exists('offer_price', $data) || $data['offer_price'] === null || trim((string) $data['offer_price']) === '') {
        bvm_buyer_offer_create_error('invalid_offer_price', 'offer_price is required and must be greater than 0.', 400);
    }

    $rawPrice = trim((string) $data['offer_price']);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $rawPrice)) {
        bvm_buyer_offer_create_error('invalid_offer_price', 'offer_price must be a positive decimal with up to 2 decimal places.', 400);
    }

    if ((float) $rawPrice <= 0) {
        bvm_buyer_offer_create_error('invalid_offer_price', 'offer_price must be greater than 0.', 400);
    }

    return number_format((float) $rawPrice, 2, '.', '');
}

function bvm_buyer_offer_create_string_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function bvm_buyer_offer_create_parse_message_text(array $data): ?string
{
    if (!array_key_exists('message_text', $data) || $data['message_text'] === null) {
        return null;
    }

    $messageText = trim((string) $data['message_text']);
    if ($messageText === '') {
        return null;
    }

    if (bvm_buyer_offer_create_string_length($messageText) > 2000) {
        bvm_buyer_offer_create_error('invalid_message_text', 'message_text must be 2000 characters or fewer.', 400);
    }

    return $messageText;
}

function bvm_buyer_offer_create_nullable_float($value): ?float
{
    return $value === null ? null : (float) $value;
}

function bvm_buyer_offer_create_offer_payload(array $offer): array
{
    return [
        'id' => (int) $offer['id'],
        'listing_id' => (int) $offer['listing_id'],
        'status' => (string) $offer['status'],
        'currency' => (string) $offer['currency'],
        'listing_price_snapshot' => (float) $offer['listing_price_snapshot'],
        'latest_offer_price' => bvm_buyer_offer_create_nullable_float($offer['latest_offer_price']),
        'agreed_price' => bvm_buyer_offer_create_nullable_float($offer['agreed_price']),
        'last_message_at' => $offer['last_message_at'],
        'created_at' => $offer['created_at'],
    ];
}

try {
    $pdo = bvm_buyer_offer_create_pdo();
    $buyerUserId = bvm_buyer_offer_create_authenticate($pdo);
    $requestData = bvm_buyer_offer_create_request_data();
    $listingId = bvm_buyer_offer_create_required_listing_id($requestData);
    $offerPrice = bvm_buyer_offer_create_parse_offer_price($requestData);
    $messageText = bvm_buyer_offer_create_parse_message_text($requestData);

    $pdo->beginTransaction();

    $listingStmt = $pdo->prepare(
        'SELECT id, seller_id, price, currency, status, sale_status, stock_available '
        . 'FROM listings '
        . 'WHERE id = :listing_id '
        . 'LIMIT 1 FOR UPDATE'
    );
    $listingStmt->execute([':listing_id' => $listingId]);
    $listing = $listingStmt->fetch();

    if (!$listing) {
        $pdo->rollBack();
        bvm_buyer_offer_create_error('listing_not_found', 'Listing not found.', 404);
    }

    if ((int) $listing['seller_id'] === $buyerUserId) {
        $pdo->rollBack();
        bvm_buyer_offer_create_error('own_listing_not_allowed', 'Buyer cannot create an offer on their own listing.', 403);
    }

    $listingStatus = (string) $listing['status'];
    $saleStatus = (string) ($listing['sale_status'] ?? 'available');
    $stockAvailable = (int) ($listing['stock_available'] ?? 1);
    if ($listingStatus !== 'active' || $saleStatus !== 'available' || $stockAvailable < 1) {
        $pdo->rollBack();
        bvm_buyer_offer_create_error('listing_not_available', 'Listing is not available for offers.', 409);
    }

    if ((float) $offerPrice >= (float) $listing['price']) {
        $pdo->rollBack();
        bvm_buyer_offer_create_error('offer_price_too_high', 'offer_price must be lower than the listing price.', 400);
    }

    $activeStatuses = ['open', 'seller_accepted', 'buyer_checkout_ready'];
    $existingStmt = $pdo->prepare(
        'SELECT id, listing_id, status, currency, listing_price_snapshot, latest_offer_price, agreed_price, last_message_at, created_at '
        . 'FROM listing_offers '
        . 'WHERE listing_id = :listing_id '
        . 'AND buyer_user_id = :buyer_user_id '
        . "AND status IN ('open', 'seller_accepted', 'buyer_checkout_ready') "
        . 'ORDER BY id DESC '
        . 'LIMIT 1 FOR UPDATE'
    );
    $existingStmt->execute([
        ':listing_id' => $listingId,
        ':buyer_user_id' => $buyerUserId,
    ]);
    $existingOffer = $existingStmt->fetch();

    if ($existingOffer && in_array((string) $existingOffer['status'], $activeStatuses, true)) {
        $pdo->commit();
        bvm_buyer_offer_create_json(200, [
            'ok' => true,
            'data' => [
                'created' => false,
                'existing' => true,
                'offer' => bvm_buyer_offer_create_offer_payload($existingOffer),
                'message' => null,
            ],
            'meta' => [
                'api_version' => 'mobile-v1',
                'generated_at' => gmdate('Y-m-d H:i:s'),
            ],
        ]);
    }

    $nowStmt = $pdo->query('SELECT UTC_TIMESTAMP() AS now_at');
    $nowRow = $nowStmt->fetch();
    $now = (string) ($nowRow['now_at'] ?? gmdate('Y-m-d H:i:s'));

    $offerInsertStmt = $pdo->prepare(
        'INSERT INTO listing_offers '
        . '(listing_id, buyer_user_id, seller_user_id, status, currency, listing_price_snapshot, latest_offer_price, agreed_price, last_message_at, created_at, updated_at) '
        . 'VALUES (:listing_id, :buyer_user_id, :seller_user_id, :status, :currency, :listing_price_snapshot, :latest_offer_price, NULL, :last_message_at, :created_at, :updated_at)'
    );
    $offerInsertStmt->execute([
        ':listing_id' => $listingId,
        ':buyer_user_id' => $buyerUserId,
        ':seller_user_id' => (int) $listing['seller_id'],
        ':status' => 'open',
        ':currency' => (string) $listing['currency'],
        ':listing_price_snapshot' => number_format((float) $listing['price'], 2, '.', ''),
        ':latest_offer_price' => $offerPrice,
        ':last_message_at' => $now,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $offerId = (int) $pdo->lastInsertId();

    $messageInsertStmt = $pdo->prepare(
        'INSERT INTO listing_offer_messages '
        . '(offer_id, sender_user_id, sender_role, message_type, offer_price, message_text, created_at) '
        . 'VALUES (:offer_id, :sender_user_id, :sender_role, :message_type, :offer_price, :message_text, :created_at)'
    );
    $messageInsertStmt->execute([
        ':offer_id' => $offerId,
        ':sender_user_id' => $buyerUserId,
        ':sender_role' => 'buyer',
        ':message_type' => 'offer',
        ':offer_price' => $offerPrice,
        ':message_text' => $messageText,
        ':created_at' => $now,
    ]);
    $messageId = (int) $pdo->lastInsertId();

    $freshOfferStmt = $pdo->prepare(
        'SELECT id, listing_id, status, currency, listing_price_snapshot, latest_offer_price, agreed_price, last_message_at, created_at '
        . 'FROM listing_offers '
        . 'WHERE id = :offer_id '
        . 'LIMIT 1'
    );
    $freshOfferStmt->execute([':offer_id' => $offerId]);
    $freshOffer = $freshOfferStmt->fetch();

    $pdo->commit();

    bvm_buyer_offer_create_json(201, [
        'ok' => true,
        'data' => [
            'created' => true,
            'offer' => bvm_buyer_offer_create_offer_payload($freshOffer),
            'message' => [
                'id' => $messageId,
                'message_type' => 'offer',
                'offer_price' => (float) $offerPrice,
                'message_text' => $messageText,
                'created_at' => $now,
                'is_mine' => true,
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

    bvm_buyer_offer_create_log('Database/server error: ' . $e->getMessage());
    bvm_buyer_offer_create_error('server_error', 'A server error occurred.', 500);
}
