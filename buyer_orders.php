<?php
declare(strict_types=1);

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const BV_MOBILE_API_VERSION = 'mobile-v1';

function bv_mobile_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function bv_mobile_json($statusCode, $payload): void
{
    if (!is_array($payload)) {
        $payload = ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Server error.']];
    }

    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        $payload['meta'] = [];
    }

    $payload['meta']['api_version'] = BV_MOBILE_API_VERSION;
    $payload['meta']['generated_at'] = bv_mobile_now();

    http_response_code((int) $statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function bv_mobile_error(string $code, string $message, int $statusCode): void
{
    bv_mobile_json($statusCode, [
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);
}

function bv_mobile_log(string $message): void
{
    error_log('[BV Mobile Buyer Orders] ' . $message);
}

function bv_mobile_clean_int($value, int $default, int $min, int $max): int
{
    if ($value === null || $value === '') {
        return $default;
    }

    if (is_int($value)) {
        $int = $value;
    } elseif (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
        $int = (int) $value;
    } else {
        return $default;
    }

    if ($int < $min) {
        return $min;
    }

    if ($int > $max) {
        return $max;
    }

    return $int;
}

function bv_mobile_get_bearer_token(): ?string
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $rawHeaders = getallheaders();
        if (is_array($rawHeaders)) {
            $headers = $rawHeaders;
        }
    }

    $authorization = '';
    foreach ($headers as $name => $value) {
        if (strcasecmp((string) $name, 'Authorization') === 0) {
            $authorization = trim((string) $value);
            break;
        }
    }

    if ($authorization === '') {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    }

    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        $token = trim($matches[1]);
        return $token !== '' ? $token : null;
    }

    $queryToken = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
    return $queryToken !== '' ? $queryToken : null;
}

function bv_mobile_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $root = dirname(__DIR__, 3);
    $candidates = [
        $root . '/config/db.php',
        $root . '/includes/db.php',
        $root . '/db.php',
    ];

    foreach ($candidates as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $loader = static function (string $includePath): void {
            $pdo = $PDO = $db = $conn = null;
            ob_start();
            /** @noinspection PhpIncludeInspection */
            include $includePath;
            ob_end_clean();

            foreach (['pdo' => $pdo, 'PDO' => $PDO, 'db' => $db, 'conn' => $conn] as $key => $value) {
                if ($value instanceof PDO) {
                    $GLOBALS[$key] = $value;
                }
            }
        };

        try {
            $loader($path);
        } catch (Throwable $e) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            bv_mobile_log('DB include failed for ' . $path . ': ' . $e->getMessage());
            continue;
        }

        foreach (['pdo', 'PDO', 'db', 'conn'] as $key) {
            if (isset($GLOBALS[$key]) && $GLOBALS[$key] instanceof PDO) {
                $pdo = $GLOBALS[$key];
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                return $pdo;
            }
        }
    }

    foreach (['pdo', 'PDO', 'db', 'conn'] as $key) {
        if (isset($GLOBALS[$key]) && $GLOBALS[$key] instanceof PDO) {
            $pdo = $GLOBALS[$key];
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            return $pdo;
        }
    }

    throw new RuntimeException('PDO connection required.');
}

function bv_mobile_table_exists(PDO $pdo, string $table): bool
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        bv_mobile_log('Table detection failed: ' . $e->getMessage());
        return false;
    }
}

function bv_mobile_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        return [];
    }

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $table]);
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $columns[strtolower((string) $column)] = (string) $column;
        }
        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        bv_mobile_log('Column detection failed for ' . $table . ': ' . $e->getMessage());
        return [];
    }
}

function bv_mobile_has_col(PDO $pdo, string $table, string $column): bool
{
    $columns = bv_mobile_columns($pdo, $table);
    return isset($columns[strtolower($column)]);
}

function bv_mobile_auth_user(PDO $pdo): array
{
    $rawToken = bv_mobile_get_bearer_token();
    if ($rawToken === null || strlen($rawToken) < 16 || strlen($rawToken) > 512) {
        bv_mobile_error('unauthorized', 'Unauthorized.', 401);
    }

    if (!bv_mobile_table_exists($pdo, 'mobile_auth_tokens') || !bv_mobile_table_exists($pdo, 'users')) {
        throw new RuntimeException('Required authentication tables are missing.');
    }

    if (!bv_mobile_has_col($pdo, 'mobile_auth_tokens', 'token_hash') || !bv_mobile_has_col($pdo, 'mobile_auth_tokens', 'user_id') || !bv_mobile_has_col($pdo, 'users', 'id')) {
        throw new RuntimeException('Required authentication columns are missing.');
    }

    $userSelect = ['u.id AS id'];
    foreach (['first_name', 'last_name', 'name', 'email', 'role', 'account_status'] as $column) {
        if (bv_mobile_has_col($pdo, 'users', $column)) {
            $userSelect[] = 'u.`' . $column . '` AS `' . $column . '`';
        }
    }

    $where = ['t.token_hash = :token_hash'];
    if (bv_mobile_has_col($pdo, 'mobile_auth_tokens', 'revoked_at')) {
        $where[] = 't.revoked_at IS NULL';
    }
    if (bv_mobile_has_col($pdo, 'mobile_auth_tokens', 'expires_at')) {
        $where[] = 't.expires_at > UTC_TIMESTAMP()';
    }
    if (bv_mobile_has_col($pdo, 'users', 'account_status')) {
        $where[] = "u.account_status = 'active'";
    }

    $sql = 'SELECT ' . implode(', ', $userSelect) . ' FROM mobile_auth_tokens t INNER JOIN users u ON u.id = t.user_id WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token_hash' => hash('sha256', $rawToken)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        bv_mobile_error('unauthorized', 'Unauthorized.', 401);
    }

    $firstName = trim((string) ($user['first_name'] ?? ''));
    $lastName = trim((string) ($user['last_name'] ?? ''));
    $fallbackName = trim((string) ($user['name'] ?? ''));
    $fullName = trim($firstName . ' ' . $lastName);
    if ($fullName === '') {
        $fullName = $fallbackName;
    }
    if ($fullName === '') {
        $fullName = trim((string) ($user['email'] ?? ''));
    }

    return [
        'id' => (int) $user['id'],
        'name' => $fullName,
        'email' => (string) ($user['email'] ?? ''),
        'role' => (string) ($user['role'] ?? 'user'),
    ];
}

function bv_mobile_order_status_allowed(string $status): bool
{
    if ($status === '') {
        return true;
    }

    return in_array($status, [
        'pending',
        'pending_payment',
        'reserved',
        'paid',
        'paid-awaiting-verify',
        'processing',
        'confirmed',
        'packing',
        'shipped',
        'completed',
        'cancelled',
        'refunded',
    ], true);
}

function bv_mobile_payment_status_allowed(string $status): bool
{
    if ($status === '') {
        return true;
    }

    return in_array($status, [
        'pending',
        'pending_payment',
        'unpaid',
        'paid',
        'failed',
        'refunded',
        'cancelled',
    ], true);
}

function bv_mobile_build_order_query(PDO $pdo, array $buyer, array $filters, bool $countOnly = false): array
{
    if (!bv_mobile_table_exists($pdo, 'orders') || !bv_mobile_has_col($pdo, 'orders', 'id')) {
        throw new RuntimeException('Orders table is unavailable.');
    }

    $select = $countOnly ? 'COUNT(*)' : 'o.`id` AS `id`';
    if (!$countOnly) {
        $selectParts = ['o.`id` AS `id`'];
        foreach (['order_code', 'status', 'payment_status', 'payment_provider', 'currency', 'created_at', 'paid_at', 'updated_at'] as $column) {
            $selectParts[] = bv_mobile_has_col($pdo, 'orders', $column) ? 'o.`' . $column . '` AS `' . $column . '`' : 'NULL AS `' . $column . '`';
        }
        if (bv_mobile_has_col($pdo, 'orders', 'total')) {
            $selectParts[] = 'o.`total` AS `total`';
        } elseif (bv_mobile_has_col($pdo, 'orders', 'order_total')) {
            $selectParts[] = 'o.`order_total` AS `total`';
        } else {
            $selectParts[] = '0 AS `total`';
        }
        $select = implode(', ', $selectParts);
    }

    $where = [];
    $params = [];

    if (bv_mobile_has_col($pdo, 'orders', 'user_id')) {
        $ownership = 'o.`user_id` = :buyer_id';
        $params[':buyer_id'] = (int) $buyer['id'];
        if (($buyer['email'] ?? '') !== '' && bv_mobile_has_col($pdo, 'orders', 'buyer_email')) {
            $ownership = '(' . $ownership . ' OR (o.`user_id` IS NULL AND LOWER(o.`buyer_email`) = LOWER(:buyer_email_owner)))';
            $params[':buyer_email_owner'] = (string) $buyer['email'];
        }
        $where[] = $ownership;
    } elseif (($buyer['email'] ?? '') !== '' && bv_mobile_has_col($pdo, 'orders', 'buyer_email')) {
        $where[] = 'LOWER(o.`buyer_email`) = LOWER(:buyer_email_owner)';
        $params[':buyer_email_owner'] = (string) $buyer['email'];
    } elseif (($buyer['email'] ?? '') !== '' && bv_mobile_has_col($pdo, 'orders', 'email')) {
        $where[] = 'LOWER(o.`email`) = LOWER(:buyer_email_owner)';
        $params[':buyer_email_owner'] = (string) $buyer['email'];
    } else {
        throw new RuntimeException('No safe order ownership column is available.');
    }

    if (($filters['status'] ?? '') !== '' && bv_mobile_has_col($pdo, 'orders', 'status')) {
        $where[] = 'o.`status` = :status';
        $params[':status'] = (string) $filters['status'];
    }

    if (($filters['payment_status'] ?? '') !== '' && bv_mobile_has_col($pdo, 'orders', 'payment_status')) {
        $where[] = 'o.`payment_status` = :payment_status';
        $params[':payment_status'] = (string) $filters['payment_status'];
    }

    if (($filters['q'] ?? '') !== '' && bv_mobile_has_col($pdo, 'orders', 'order_code')) {
        $where[] = 'o.`order_code` LIKE :q';
        $params[':q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }

    $sql = 'SELECT ' . $select . ' FROM `orders` o WHERE ' . implode(' AND ', $where);

    if (!$countOnly) {
        if (bv_mobile_has_col($pdo, 'orders', 'created_at')) {
            $sql .= (($filters['sort'] ?? 'newest') === 'oldest') ? ' ORDER BY o.`created_at` ASC, o.`id` ASC' : ' ORDER BY o.`created_at` DESC, o.`id` DESC';
        } else {
            $sql .= (($filters['sort'] ?? 'newest') === 'oldest') ? ' ORDER BY o.`id` ASC' : ' ORDER BY o.`id` DESC';
        }
        $sql .= ' LIMIT ' . (int) $filters['per_page'] . ' OFFSET ' . (int) $filters['offset'];
    }

    return [$sql, $params];
}

function bv_mobile_derive_fulfillment_status(array $statuses): string
{
    $normalized = [];
    foreach ($statuses as $status) {
        $status = strtolower(trim((string) $status));
        if ($status !== '') {
            $normalized[] = $status;
        }
    }

    if ($normalized === []) {
        return 'unknown';
    }

    $counts = array_count_values($normalized);
    $total = count($normalized);

    if (($counts['refunded'] ?? 0) === $total) {
        return 'refunded';
    }
    if (($counts['cancelled'] ?? 0) === $total || ($counts['canceled'] ?? 0) === $total) {
        return 'cancelled';
    }
    if (($counts['completed'] ?? 0) === $total) {
        return 'completed';
    }
    if (($counts['shipped'] ?? 0) === $total) {
        return 'shipped';
    }

    $fulfilled = ($counts['shipped'] ?? 0) + ($counts['completed'] ?? 0);
    $unfinished = ($counts['pending'] ?? 0) + ($counts['processing'] ?? 0) + ($counts['packing'] ?? 0) + ($counts['confirmed'] ?? 0);
    if ($fulfilled > 0 && $unfinished > 0) {
        return 'partially_fulfilled';
    }
    if (($counts['processing'] ?? 0) > 0 || ($counts['packing'] ?? 0) > 0 || ($counts['confirmed'] ?? 0) > 0) {
        return 'processing';
    }
    if (($counts['pending'] ?? 0) === $total) {
        return 'pending';
    }
    if ($fulfilled > 0) {
        return 'partially_fulfilled';
    }

    return 'unknown';
}

function bv_mobile_fetch_item_summaries(PDO $pdo, array $orderIds): array
{
    if ($orderIds === [] || !bv_mobile_table_exists($pdo, 'order_items') || !bv_mobile_has_col($pdo, 'order_items', 'order_id')) {
        return [];
    }

    $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
    $placeholders = [];
    $params = [];
    foreach ($orderIds as $index => $orderId) {
        $key = ':order_id_' . $index;
        $placeholders[] = $key;
        $params[$key] = $orderId;
    }

    $sellerExpr = '0';
    $join = '';
    if (bv_mobile_has_col($pdo, 'order_items', 'seller_id')) {
        $sellerExpr = 'NULLIF(oi.`seller_id`, 0)';
    } elseif (bv_mobile_has_col($pdo, 'order_items', 'listing_id') && bv_mobile_table_exists($pdo, 'listings') && bv_mobile_has_col($pdo, 'listings', 'id') && bv_mobile_has_col($pdo, 'listings', 'seller_id')) {
        $join = ' LEFT JOIN `listings` l ON l.`id` = oi.`listing_id`';
        $sellerExpr = 'NULLIF(l.`seller_id`, 0)';
    }

    $statusSelect = bv_mobile_has_col($pdo, 'order_items', 'fulfillment_status') ? 'GROUP_CONCAT(oi.`fulfillment_status` SEPARATOR \'|\') AS statuses' : 'NULL AS statuses';
    $sql = 'SELECT oi.`order_id`, COUNT(*) AS item_count, COUNT(DISTINCT ' . $sellerExpr . ') AS seller_count, ' . $statusSelect . ' FROM `order_items` oi' . $join . ' WHERE oi.`order_id` IN (' . implode(', ', $placeholders) . ') GROUP BY oi.`order_id`';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $summary = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $statuses = [];
        if (isset($row['statuses']) && $row['statuses'] !== null && $row['statuses'] !== '') {
            $statuses = explode('|', (string) $row['statuses']);
        }
        $summary[(int) $row['order_id']] = [
            'item_count' => (int) ($row['item_count'] ?? 0),
            'seller_count' => (int) ($row['seller_count'] ?? 0),
            'fulfillment_status' => bv_mobile_derive_fulfillment_status($statuses),
        ];
    }

    return $summary;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        bv_mobile_error('method_not_allowed', 'Method not allowed.', 405);
    }

    $page = bv_mobile_clean_int($_GET['page'] ?? null, 1, 1, 1000000);
    $perPage = bv_mobile_clean_int($_GET['per_page'] ?? null, 20, 1, 50);
    $status = strtolower(trim((string) ($_GET['status'] ?? '')));
    $paymentStatus = strtolower(trim((string) ($_GET['payment_status'] ?? '')));
    $q = trim((string) ($_GET['q'] ?? ''));
    $sort = strtolower(trim((string) ($_GET['sort'] ?? 'newest')));

    if (!bv_mobile_order_status_allowed($status)) {
        bv_mobile_error('bad_request', 'Invalid status filter.', 400);
    }
    if (!bv_mobile_payment_status_allowed($paymentStatus)) {
        bv_mobile_error('bad_request', 'Invalid payment_status filter.', 400);
    }
    if (!in_array($sort, ['newest', 'oldest'], true)) {
        bv_mobile_error('bad_request', 'Invalid sort option.', 400);
    }
    if (mb_strlen($q, 'UTF-8') > 80) {
        bv_mobile_error('bad_request', 'Search query is too long.', 400);
    }

    $pdo = bv_mobile_db();
    $buyer = bv_mobile_auth_user($pdo);
    $offset = ($page - 1) * $perPage;
    $filters = [
        'status' => $status,
        'payment_status' => $paymentStatus,
        'q' => $q,
        'sort' => $sort,
        'per_page' => $perPage,
        'offset' => $offset,
    ];

    [$countSql, $countParams] = bv_mobile_build_order_query($pdo, $buyer, $filters, true);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $total = (int) $countStmt->fetchColumn();

    [$orderSql, $orderParams] = bv_mobile_build_order_query($pdo, $buyer, $filters, false);
    $orderStmt = $pdo->prepare($orderSql);
    $orderStmt->execute($orderParams);
    $rows = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

    $orderIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $summaries = bv_mobile_fetch_item_summaries($pdo, $orderIds);

    $orders = [];
    foreach ($rows as $row) {
        $orderId = (int) $row['id'];
        $summary = $summaries[$orderId] ?? ['item_count' => 0, 'seller_count' => 0, 'fulfillment_status' => 'unknown'];
        $orders[] = [
            'id' => $orderId,
            'order_code' => (string) ($row['order_code'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'payment_status' => (string) ($row['payment_status'] ?? ''),
            'payment_provider' => $row['payment_provider'] !== null ? (string) $row['payment_provider'] : null,
            'currency' => (string) ($row['currency'] ?? 'USD'),
            'total' => round((float) ($row['total'] ?? 0), 2),
            'item_count' => (int) $summary['item_count'],
            'seller_count' => (int) $summary['seller_count'],
            'fulfillment_status' => (string) $summary['fulfillment_status'],
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'paid_at' => $row['paid_at'] !== null ? (string) $row['paid_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            'detail_url' => '/api/mobile/v1/buyer_order_detail.php?order_id=' . $orderId,
        ];
    }

    $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;

    bv_mobile_json(200, [
        'ok' => true,
        'data' => [
            'buyer' => $buyer,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $totalPages > 0 && $page < $totalPages,
                'has_prev' => $page > 1 && $totalPages > 0,
            ],
            'orders' => $orders,
        ],
    ]);
} catch (Throwable $e) {
    bv_mobile_log($e->getMessage());
    bv_mobile_error('server_error', 'Server error.', 500);
}
