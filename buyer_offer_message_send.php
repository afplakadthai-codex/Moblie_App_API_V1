<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/buyer_offer_message_send.php
// Allows an authenticated buyer to send a message or a new offer price in an
// existing buyer-owned offer thread. Never uses website sessions or emits HTML.
// =============================================================================

ini_set('display_errors', '0');

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

function bvm_buyer_offer_message_send_public_root(): string
{
    return dirname(__DIR__, 3);
}

function bvm_buyer_offer_message_send_project_root(): string
{
    return dirname(bvm_buyer_offer_message_send_public_root());
}

function bvm_buyer_offer_message_send_json(int $statusCode, array $payload): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function bvm_buyer_offer_message_send_error(string $code, string $message, int $statusCode): void
{
    bvm_buyer_offer_message_send_json($statusCode, [
        'ok' => false,
        'error' => ['code' => $code, 'message' => $message],
    ]);
}

function bvm_buyer_offer_message_send_log(string $message): void
{
    error_log('[BV Mobile Buyer Offer Message Send] ' . $message);
}

function bvm_buyer_offer_message_send_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $publicRoot = bvm_buyer_offer_message_send_public_root();
    $projectRoot = bvm_buyer_offer_message_send_project_root();
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

function bvm_buyer_offer_message_send_bearer_token(): ?string
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

function bvm_buyer_offer_message_send_authenticate(PDO $pdo): int
{
    $token = bvm_buyer_offer_message_send_bearer_token();
    if ($token === null) {
        bvm_buyer_offer_message_send_error('unauthorized', 'Missing bearer token.', 401);
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
        bvm_buyer_offer_message_send_error('unauthorized', 'Invalid or expired bearer token.', 401);
    }

    return (int) $row['user_id'];
}

function bvm_buyer_offer_message_send_request_data(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $rawBody = file_get_contents('php://input');
        $decoded = json_decode($rawBody === false ? '' : $rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function bvm_buyer_offer_message_send_required_offer_id(array $data): int
{
    if (!array_key_exists('offer_id', $data) || trim((string) $data['offer_id']) === '') {
        bvm_buyer_offer_message_send_error('missing_offer_id', 'Missing offer_id.', 400);
    }

    $offerId = filter_var($data['offer_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($offerId === false) {
        bvm_buyer_offer_message_send_error('missing_offer_id', 'Missing offer_id.', 400);
    }

    return (int) $offerId;
}

function bvm_buyer_offer_message_send_string_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function bvm_buyer_offer_message_send_parse_message_text(array $data): ?string
{
    if (!array_key_exists('message_text', $data) || $data['message_text'] === null) {
        return null;
    }

    $messageText = trim((string) $data['message_text']);
    if ($messageText === '') {
        return null;
    }

    if (bvm_buyer_offer_message_send_string_length($messageText) > 2000) {
        bvm_buyer_offer_message_send_error('empty_message', 'message_text must be 2000 characters or fewer.', 400);
    }

    return $messageText;
}

function bvm_buyer_offer_message_send_parse_offer_price(array $data): ?string
{
    if (!array_key_exists('offer_price', $data) || $data['offer_price'] === null || trim((string) $data['offer_price']) === '') {
        return null;
    }

    $rawPrice = trim((string) $data['offer_price']);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $rawPrice)) {
        bvm_buyer_offer_message_send_error('invalid_offer_price', 'offer_price must be a positive decimal with up to 2 decimal places.', 400);
    }

    $price = (float) $rawPrice;
    if ($price <= 0) {
        bvm_buyer_offer_message_send_error('invalid_offer_price', 'offer_price must be greater than 0.', 400);
    }

    return number_format($price, 2, '.', '');
}

function bvm_buyer_offer_message_send_nullable_float($value): ?float
{
    return $value === null ? null : (float) $value;
}

try {
    $pdo = bvm_buyer_offer_message_send_pdo();
    $buyerUserId = bvm_buyer_offer_message_send_authenticate($pdo);
    $requestData = bvm_buyer_offer_message_send_request_data();
    $offerId = bvm_buyer_offer_message_send_required_offer_id($requestData);
    $messageText = bvm_buyer_offer_message_send_parse_message_text($requestData);
    $offerPrice = bvm_buyer_offer_message_send_parse_offer_price($requestData);

    if ($messageText === null && $offerPrice === null) {
        bvm_buyer_offer_message_send_error('empty_message', 'Provide message_text or offer_price.', 400);
    }

    $pdo->beginTransaction();

    $offerStmt = $pdo->prepare(
        'SELECT id, buyer_user_id, seller_user_id, status, listing_price_snapshot, latest_offer_price, agreed_price, '
        . 'last_message_at, expires_at, completed_order_id '
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
        bvm_buyer_offer_message_send_error('offer_not_found', 'Offer not found.', 404);
    }

    if ((int) $offer['buyer_user_id'] === (int) $offer['seller_user_id']) {
        $pdo->rollBack();
        bvm_buyer_offer_message_send_error('offer_closed', 'Buyer cannot message their own listing offer.', 409);
    }

    $status = (string) $offer['status'];
    $expiresAt = $offer['expires_at'] === null ? null : (string) $offer['expires_at'];
    $isTimedOut = $expiresAt !== null && $expiresAt !== '' && strtotime($expiresAt . ' UTC') !== false && strtotime($expiresAt . ' UTC') <= time();
    if (in_array($status, ['completed', 'expired', 'cancelled', 'rejected'], true) || $isTimedOut) {
        $pdo->rollBack();
        bvm_buyer_offer_message_send_error('offer_closed', 'Offer is not open for new buyer messages.', 409);
    }

    if ($offerPrice !== null && (float) $offerPrice > (float) $offer['listing_price_snapshot']) {
        $pdo->rollBack();
        bvm_buyer_offer_message_send_error('offer_price_too_high', 'offer_price cannot be higher than the listing price.', 400);
    }

    $nowStmt = $pdo->query('SELECT UTC_TIMESTAMP() AS now_at');
    $nowRow = $nowStmt->fetch();
    $now = (string) ($nowRow['now_at'] ?? gmdate('Y-m-d H:i:s'));
    $messageType = $offerPrice === null ? 'message' : 'offer';

    $insertStmt = $pdo->prepare(
        'INSERT INTO listing_offer_messages '
        . '(offer_id, sender_user_id, sender_role, message_type, offer_price, message_text, created_at) '
        . 'VALUES (:offer_id, :sender_user_id, :sender_role, :message_type, :offer_price, :message_text, :created_at)'
    );
    $insertStmt->execute([
        ':offer_id' => (int) $offer['id'],
        ':sender_user_id' => $buyerUserId,
        ':sender_role' => 'buyer',
        ':message_type' => $messageType,
        ':offer_price' => $offerPrice,
        ':message_text' => $messageText,
        ':created_at' => $now,
    ]);
    $messageId = (int) $pdo->lastInsertId();

    if ($offerPrice !== null) {
        $updateStmt = $pdo->prepare(
            'UPDATE listing_offers SET '
            . 'latest_offer_price = :latest_offer_price, '
            . 'agreed_price = NULL, '
            . 'approved_at = NULL, '
            . 'expires_at = NULL, '
            . 'status = :status, '
            . 'last_message_at = :last_message_at, '
            . 'updated_at = :updated_at '
            . 'WHERE id = :offer_id'
        );
        $updateStmt->execute([
            ':latest_offer_price' => $offerPrice,
            ':status' => 'open',
            ':last_message_at' => $now,
            ':updated_at' => $now,
            ':offer_id' => (int) $offer['id'],
        ]);
    } else {
        $updateStmt = $pdo->prepare(
            'UPDATE listing_offers SET '
            . 'last_message_at = :last_message_at, '
            . 'updated_at = :updated_at '
            . 'WHERE id = :offer_id'
        );
        $updateStmt->execute([
            ':last_message_at' => $now,
            ':updated_at' => $now,
            ':offer_id' => (int) $offer['id'],
        ]);
    }

    $freshStmt = $pdo->prepare(
        'SELECT id, status, latest_offer_price, agreed_price, last_message_at '
        . 'FROM listing_offers '
        . 'WHERE id = :offer_id '
        . 'LIMIT 1'
    );
    $freshStmt->execute([':offer_id' => (int) $offer['id']]);
    $freshOffer = $freshStmt->fetch();

    $pdo->commit();

    bvm_buyer_offer_message_send_json(200, [
        'ok' => true,
        'data' => [
            'offer' => [
                'id' => (int) $freshOffer['id'],
                'status' => $freshOffer['status'],
                'latest_offer_price' => bvm_buyer_offer_message_send_nullable_float($freshOffer['latest_offer_price']),
                'agreed_price' => bvm_buyer_offer_message_send_nullable_float($freshOffer['agreed_price']),
                'last_message_at' => $freshOffer['last_message_at'],
            ],
            'message' => [
                'id' => $messageId,
                'sender_user_id' => $buyerUserId,
                'sender_role' => 'buyer',
                'message_type' => $messageType,
                'offer_price' => bvm_buyer_offer_message_send_nullable_float($offerPrice),
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

    bvm_buyer_offer_message_send_log('Database/server error: ' . $e->getMessage());
    bvm_buyer_offer_message_send_error('server_error', 'A server error occurred.', 500);
}
