<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/buyer_offer_detail.php
// Read-only endpoint returning one authenticated buyer-owned listing offer and
// all of its messages. Never uses website sessions and never emits HTML.
// =============================================================================

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => ['code' => 'method_not_allowed', 'message' => 'Only GET requests are accepted.'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bvm_buyer_offer_detail_public_root(): string
{
    return dirname(__DIR__, 3);
}

function bvm_buyer_offer_detail_project_root(): string
{
    return dirname(bvm_buyer_offer_detail_public_root());
}

function bvm_buyer_offer_detail_json(int $statusCode, array $payload): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function bvm_buyer_offer_detail_error(string $code, string $message, int $statusCode): void
{
    bvm_buyer_offer_detail_json($statusCode, [
        'ok' => false,
        'error' => ['code' => $code, 'message' => $message],
    ]);
}

function bvm_buyer_offer_detail_log(string $message): void
{
    error_log('[BV Mobile Buyer Offer Detail] ' . $message);
}

function bvm_buyer_offer_detail_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $publicRoot = bvm_buyer_offer_detail_public_root();
    $projectRoot = bvm_buyer_offer_detail_project_root();
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

function bvm_buyer_offer_detail_bearer_token(): ?string
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

function bvm_buyer_offer_detail_authenticate(PDO $pdo): int
{
    $token = bvm_buyer_offer_detail_bearer_token();
    if ($token === null) {
        bvm_buyer_offer_detail_error('unauthorized', 'Missing bearer token.', 401);
    }

    $sql = 'SELECT id, user_id FROM mobile_auth_tokens '
        . 'WHERE token_hash = :token_hash '
        . 'AND revoked_at IS NULL '
        . 'AND expires_at > UTC_TIMESTAMP() '
        . 'LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $row = $stmt->fetch();

    if (!$row) {
        bvm_buyer_offer_detail_error('unauthorized', 'Invalid or expired bearer token.', 401);
    }

    return (int) $row['user_id'];
}

function bvm_buyer_offer_detail_required_id_param(string $name): int
{
    if (!isset($_GET[$name]) || trim((string) $_GET[$name]) === '') {
        bvm_buyer_offer_detail_error('missing_offer_id', 'Missing offer_id.', 400);
    }

    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($value === false || $value === null) {
        bvm_buyer_offer_detail_error('missing_offer_id', 'Missing offer_id.', 400);
    }

    return (int) $value;
}

function bvm_buyer_offer_detail_nullable_float($value): ?float
{
    return $value === null ? null : (float) $value;
}

function bvm_buyer_offer_detail_is_expired(?string $expiresAt, DateTimeImmutable $now): bool
{
    if ($expiresAt === null || $expiresAt === '') {
        return false;
    }

    return new DateTimeImmutable($expiresAt, new DateTimeZone('UTC')) < $now;
}

try {
    $pdo = bvm_buyer_offer_detail_pdo();
    $buyerUserId = bvm_buyer_offer_detail_authenticate($pdo);
    $offerId = bvm_buyer_offer_detail_required_id_param('offer_id');

    $sql = 'SELECT '
        . 'lo.id, lo.listing_id, lo.buyer_user_id, lo.seller_user_id, lo.status, lo.currency, '
        . 'lo.listing_price_snapshot, lo.latest_offer_price, lo.agreed_price, '
        . 'lo.last_message_at, lo.approved_at, lo.expires_at, lo.completed_order_id, lo.created_at, lo.updated_at, '
        . 'l.title AS listing_title, l.slug AS listing_slug, l.cover_image AS listing_cover_image, '
        . 'l.price AS listing_price, l.currency AS listing_currency, l.status AS listing_status, '
        . 's.first_name AS seller_first_name, s.last_name AS seller_last_name, s.email AS seller_email '
        . 'FROM listing_offers lo '
        . 'LEFT JOIN listings l ON l.id = lo.listing_id '
        . 'LEFT JOIN users s ON s.id = lo.seller_user_id '
        . 'WHERE lo.id = :offer_id AND lo.buyer_user_id = :buyer_user_id '
        . 'LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':offer_id' => $offerId,
        ':buyer_user_id' => $buyerUserId,
    ]);
    $row = $stmt->fetch();

    if (!$row) {
        bvm_buyer_offer_detail_error('offer_not_found', 'Offer not found.', 404);
    }

    $messagesStmt = $pdo->prepare(
        'SELECT id, sender_user_id, sender_role, message_type, offer_price, message_text, created_at '
        . 'FROM listing_offer_messages '
        . 'WHERE offer_id = :offer_id '
        . 'ORDER BY created_at ASC, id ASC'
    );
    $messagesStmt->execute([':offer_id' => (int) $row['id']]);

    $messages = [];
    foreach ($messagesStmt->fetchAll() as $messageRow) {
        $senderUserId = (int) $messageRow['sender_user_id'];
        $messages[] = [
            'id' => (int) $messageRow['id'],
            'sender_user_id' => $senderUserId,
            'sender_role' => $messageRow['sender_role'],
            'message_type' => $messageRow['message_type'],
            'offer_price' => bvm_buyer_offer_detail_nullable_float($messageRow['offer_price']),
            'message_text' => $messageRow['message_text'],
            'created_at' => $messageRow['created_at'],
            'is_mine' => $senderUserId === $buyerUserId,
        ];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $status = (string) $row['status'];
    $expiresAt = $row['expires_at'] === null ? null : (string) $row['expires_at'];
    $isExpired = bvm_buyer_offer_detail_is_expired($expiresAt, $now);
    $agreedPrice = bvm_buyer_offer_detail_nullable_float($row['agreed_price']);
    $completedOrderId = $row['completed_order_id'] === null ? null : (int) $row['completed_order_id'];

    $canCheckout = in_array($status, ['seller_accepted', 'buyer_checkout_ready'], true)
        && $agreedPrice !== null
        && $agreedPrice > 0
        && $completedOrderId === null
        && !$isExpired;

    if ($canCheckout) {
        $checkoutReason = 'ready';
    } elseif ($completedOrderId !== null || $status === 'completed') {
        $checkoutReason = 'completed';
    } elseif ($isExpired || $status === 'expired') {
        $checkoutReason = 'expired';
    } else {
        $checkoutReason = 'not_accepted';
    }

    $sellerName = trim((string) ($row['seller_first_name'] ?? '') . ' ' . (string) ($row['seller_last_name'] ?? ''));
    if ($sellerName === '') {
        $sellerName = trim((string) ($row['seller_email'] ?? ''));
    }

    bvm_buyer_offer_detail_json(200, [
        'ok' => true,
        'data' => [
            'offer' => [
                'id' => (int) $row['id'],
                'listing_id' => (int) $row['listing_id'],
                'buyer_user_id' => (int) $row['buyer_user_id'],
                'seller_user_id' => (int) $row['seller_user_id'],
                'status' => $row['status'],
                'currency' => $row['currency'],
                'listing_price_snapshot' => bvm_buyer_offer_detail_nullable_float($row['listing_price_snapshot']),
                'latest_offer_price' => bvm_buyer_offer_detail_nullable_float($row['latest_offer_price']),
                'agreed_price' => $agreedPrice,
                'last_message_at' => $row['last_message_at'],
                'approved_at' => $row['approved_at'],
                'expires_at' => $row['expires_at'],
                'completed_order_id' => $completedOrderId,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
            'listing' => [
                'id' => (int) $row['listing_id'],
                'title' => $row['listing_title'],
                'slug' => $row['listing_slug'],
                'cover_image' => $row['listing_cover_image'],
                'price' => bvm_buyer_offer_detail_nullable_float($row['listing_price']),
                'currency' => $row['listing_currency'],
                'status' => $row['listing_status'],
            ],
            'seller' => [
                'id' => (int) $row['seller_user_id'],
                'name' => $sellerName === '' ? null : $sellerName,
            ],
            'messages' => $messages,
            'checkout' => [
                'can_checkout' => $canCheckout,
                'reason' => $checkoutReason,
                'checkout_url' => $canCheckout ? '/offer_accept_checkout.php?offer_id=' . (int) $row['id'] : null,
            ],
        ],
        'meta' => [
            'api_version' => 'mobile-v1',
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);
} catch (Throwable $e) {
    bvm_buyer_offer_detail_log('Database/server error: ' . $e->getMessage());
    bvm_buyer_offer_detail_error('server_error', 'A server error occurred.', 500);
}
