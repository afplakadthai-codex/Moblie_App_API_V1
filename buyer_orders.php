<?php
declare(strict_types=1);

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

const BVM_BUYER_ORDERS_API_VERSION = 'mobile_v1';

function bvm_buyer_orders_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function bvm_buyer_orders_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function bvm_buyer_orders_error(string $code, string $message, int $statusCode): void
{
    bvm_buyer_orders_json($statusCode, [
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);
}

function bvm_buyer_orders_log(string $message): void
{
    error_log('[Mobile API Buyer Orders] ' . $message);
}

function bvm_buyer_orders_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $apiRoot = dirname(__DIR__, 3);       // public_html
    $projectRoot = dirname(__DIR__, 4);   // repository/application root
    $candidates = [
        $apiRoot . '/config/db.php',
        $apiRoot . '/includes/db.php',
        $apiRoot . '/db.php',
        $projectRoot . '/config/db.php',
        $projectRoot . '/includes/db.php',
        $projectRoot . '/db.php',
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

            foreach (['pdo' => $pdo, 'PDO' => $PDO, 'db' => $db, 'conn' => $conn] as $name => $value) {
                if ($value instanceof PDO) {
                    $GLOBALS[$name] = $value;
                }
            }
        };

        try {
            $loader($path);
        } catch (Throwable $e) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            bvm_buyer_orders_log('Database include failed for ' . $path . ': ' . $e->getMessage());
            continue;
        }

        foreach (['pdo', 'PDO', 'db', 'conn'] as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
                $pdo = $GLOBALS[$name];
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                return $pdo;
            }
        }
    }

    foreach (['pdo', 'PDO', 'db', 'conn'] as $name) {
        if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
            $pdo = $GLOBALS[$name];
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            return $pdo;
        }
    }

    throw new RuntimeException('PDO connection required.');
}

function bvm_buyer_orders_table_exists(PDO $pdo, string $table): bool
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function bvm_buyer_orders_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        return [];
    }

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute([':table' => $table]);

    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[strtolower((string) $column)] = (string) $column;
    }

    $cache[$table] = $columns;
    return $columns;
}

function bvm_buyer_orders_has_col(PDO $pdo, string $table, string $column): bool
{
    $columns = bvm_buyer_orders_columns($pdo, $table);
    return isset($columns[strtolower($column)]);
}

function bvm_buyer_orders_col_sql(PDO $pdo, string $table, string $column): string
{
    $columns = bvm_buyer_orders_columns($pdo, $table);
    $actual = $columns[strtolower($column)] ?? null;
    if ($actual === null || preg_match('/^[A-Za-z0-9_]+$/', $actual) !== 1) {
        throw new RuntimeException('Missing required column: ' . $table . '.' . $column);
    }

    return '`' . $actual . '`';
}

function bvm_buyer_orders_get_bearer_token(): ?string
{
    $authorization = '';

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $authorization = trim((string) $value);
                    break;
                }
            }
        }
    }

    if ($authorization === '') {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    }

    if ($authorization !== '' && preg_match('/^Bearer\s+([^\s].*)$/i', $authorization, $matches) === 1) {
        $token = trim((string) $matches[1]);
        return $token !== '' ? $token : null;
    }

    return null;
}

function bvm_buyer_orders_auth(PDO $pdo): array
{
    $token = bvm_buyer_orders_get_bearer_token();
    if ($token === null || strlen($token) < 16 || strlen($token) > 512) {
        bvm_buyer_orders_error('unauthorized', 'Unauthorized', 401);
    }

    foreach (['mobile_auth_tokens', 'users'] as $table) {
        if (!bvm_buyer_orders_table_exists($pdo, $table)) {
            throw new RuntimeException('Required authentication table is missing: ' . $table);
        }
    }

    foreach (['token_hash', 'user_id', 'expires_at', 'revoked_at'] as $column) {
        if (!bvm_buyer_orders_has_col($pdo, 'mobile_auth_tokens', $column)) {
            throw new RuntimeException('Required authentication column is missing: mobile_auth_tokens.' . $column);
        }
    }

    foreach (['id', 'email', 'account_status'] as $column) {
        if (!bvm_buyer_orders_has_col($pdo, 'users', $column)) {
            throw new RuntimeException('Required users column is missing: users.' . $column);
        }
    }

    $userSelect = [
        'u.' . bvm_buyer_orders_col_sql($pdo, 'users', 'id') . ' AS `id`',
        'u.' . bvm_buyer_orders_col_sql($pdo, 'users', 'email') . ' AS `email`',
        'u.' . bvm_buyer_orders_col_sql($pdo, 'users', 'account_status') . ' AS `account_status`',
    ];

    foreach (['first_name', 'last_name', 'name', 'role'] as $column) {
        if (bvm_buyer_orders_has_col($pdo, 'users', $column)) {
            $userSelect[] = 'u.' . bvm_buyer_orders_col_sql($pdo, 'users', $column) . ' AS `' . $column . '`';
        }
    }

    $sql = 'SELECT ' . implode(', ', $userSelect)
        . ' FROM `mobile_auth_tokens` mat'
        . ' INNER JOIN `users` u ON u.' . bvm_buyer_orders_col_sql($pdo, 'users', 'id') . ' = mat.' . bvm_buyer_orders_col_sql($pdo, 'mobile_auth_tokens', 'user_id')
        . ' WHERE mat.' . bvm_buyer_orders_col_sql($pdo, 'mobile_auth_tokens', 'token_hash') . ' = :token_hash'
        . ' AND mat.' . bvm_buyer_orders_col_sql($pdo, 'mobile_auth_tokens', 'revoked_at') . ' IS NULL'
        . ' AND mat.' . bvm_buyer_orders_col_sql($pdo, 'mobile_auth_tokens', 'expires_at') . ' > UTC_TIMESTAMP()'
        . " AND u." . bvm_buyer_orders_col_sql($pdo, 'users', 'account_status') . " = 'active'"
        . ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        bvm_buyer_orders_error('unauthorized', 'Unauthorized', 401);
    }

    if (bvm_buyer_orders_has_col($pdo, 'mobile_auth_tokens', 'last_used_at')) {
        try {
            $update = $pdo->prepare('UPDATE `mobile_auth_tokens` SET ' . bvm_buyer_orders_col_sql($pdo, 'mobile_auth_tokens', 'last_used_at') . ' = UTC_TIMESTAMP() WHERE ' . bvm_buyer_orders_col_sql($pdo, 'mobile_auth_tokens', 'token_hash') . ' = :token_hash LIMIT 1');
            $update->execute([':token_hash' => hash('sha256', $token)]);
        } catch (Throwable $e) {
            bvm_buyer_orders_log('Token last_used_at update failed: ' . $e->getMessage());
        }
    }

    $firstName = trim((string) ($user['first_name'] ?? ''));
    $lastName = trim((string) ($user['last_name'] ?? ''));
    $name = trim($firstName . ' ' . $lastName);
    if ($name === '') {
        $name = trim((string) ($user['name'] ?? ''));
    }
    if ($name === '') {
        $name = trim((string) ($user['email'] ?? ''));
    }

    return [
        'id' => (int) $user['id'],
        'name' => $name,
        'email' => (string) ($user['email'] ?? ''),
    ];
}

function bvm_buyer_orders_clean_int($value, int $default, int $min, int $max): int
{
    if ($value === null || $value === '') {
        return $default;
    }

    $int = null;
    if (is_int($value)) {
        $int = $value;
    } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
        $int = (int) trim($value);
    }

    if ($int === null) {
        return $default;
    }

    return max($min, min($max, $int));
}

function bvm_buyer_orders_clean_filter($value, int $maxLength = 60): string
{
    if ($value === null || is_array($value) || is_object($value)) {
        return '';
    }

    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return '';
    }

    if (strlen($value) > $maxLength || preg_match('/^[a-z0-9_-]+$/', $value) !== 1) {
        bvm_buyer_orders_error('bad_request', 'Invalid filter', 400);
    }

    return $value;
}

function bvm_buyer_orders_money($value): float
{
    return round((float) ($value ?? 0), 2);
}

function bvm_buyer_orders_item_counts(PDO $pdo, array $orderIds): array
{
    if ($orderIds === [] || !bvm_buyer_orders_table_exists($pdo, 'order_items') || !bvm_buyer_orders_has_col($pdo, 'order_items', 'order_id')) {
        return [];
    }

    $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
    $params = [];
    $placeholders = [];

    foreach ($orderIds as $index => $orderId) {
        $key = ':order_id_' . $index;
        $placeholders[] = $key;
        $params[$key] = $orderId;
    }

    $sql = 'SELECT ' . bvm_buyer_orders_col_sql($pdo, 'order_items', 'order_id') . ' AS `order_id`, COUNT(*) AS `item_count`'
        . ' FROM `order_items`'
        . ' WHERE ' . bvm_buyer_orders_col_sql($pdo, 'order_items', 'order_id') . ' IN (' . implode(', ', $placeholders) . ')'
        . ' GROUP BY ' . bvm_buyer_orders_col_sql($pdo, 'order_items', 'order_id');

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[(int) $row['order_id']] = (int) $row['item_count'];
    }

    return $counts;
}

function bvm_buyer_orders_select_expr(PDO $pdo, string $column, string $fallback): string
{
    if (bvm_buyer_orders_has_col($pdo, 'orders', $column)) {
        return 'o.' . bvm_buyer_orders_col_sql($pdo, 'orders', $column) . ' AS `' . $column . '`';
    }

    return $fallback . ' AS `' . $column . '`';
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        bvm_buyer_orders_error('method_not_allowed', 'Method not allowed', 405);
    }

    $page = bvm_buyer_orders_clean_int($_GET['page'] ?? null, 1, 1, 1000000);
    $limit = bvm_buyer_orders_clean_int($_GET['limit'] ?? null, 20, 1, 50);
    $status = bvm_buyer_orders_clean_filter($_GET['status'] ?? null);
    $paymentStatus = bvm_buyer_orders_clean_filter($_GET['payment_status'] ?? null);
    $offset = ($page - 1) * $limit;

    $token = bvm_buyer_orders_get_bearer_token();
    if ($token === null || strlen($token) < 16 || strlen($token) > 512) {
        bvm_buyer_orders_error('unauthorized', 'Unauthorized', 401);
    }

    $pdo = bvm_buyer_orders_db();
    $buyer = bvm_buyer_orders_auth($pdo);

    if (!bvm_buyer_orders_table_exists($pdo, 'orders')) {
        throw new RuntimeException('Orders table is missing.');
    }

    foreach (['id', 'user_id'] as $column) {
        if (!bvm_buyer_orders_has_col($pdo, 'orders', $column)) {
            throw new RuntimeException('Required orders column is missing: orders.' . $column);
        }
    }

    $select = [
        'o.' . bvm_buyer_orders_col_sql($pdo, 'orders', 'id') . ' AS `id`',
        bvm_buyer_orders_select_expr($pdo, 'order_code', "''"),
        bvm_buyer_orders_select_expr($pdo, 'status', "''"),
        bvm_buyer_orders_select_expr($pdo, 'payment_status', "''"),
        bvm_buyer_orders_select_expr($pdo, 'payment_provider', 'NULL'),
        bvm_buyer_orders_select_expr($pdo, 'currency', "'USD'"),
        bvm_buyer_orders_select_expr($pdo, 'subtotal', '0'),
        bvm_buyer_orders_select_expr($pdo, 'shipping_amount', '0'),
        bvm_buyer_orders_select_expr($pdo, 'total', '0'),
        bvm_buyer_orders_select_expr($pdo, 'created_at', 'NULL'),
        bvm_buyer_orders_select_expr($pdo, 'paid_at', 'NULL'),
        bvm_buyer_orders_select_expr($pdo, 'updated_at', 'NULL'),
    ];

    $where = ['o.' . bvm_buyer_orders_col_sql($pdo, 'orders', 'user_id') . ' = :buyer_id'];
    $params = [':buyer_id' => $buyer['id']];

    if ($status !== '' && bvm_buyer_orders_has_col($pdo, 'orders', 'status')) {
        $where[] = 'o.' . bvm_buyer_orders_col_sql($pdo, 'orders', 'status') . ' = :status';
        $params[':status'] = $status;
    }

    if ($paymentStatus !== '' && bvm_buyer_orders_has_col($pdo, 'orders', 'payment_status')) {
        $where[] = 'o.' . bvm_buyer_orders_col_sql($pdo, 'orders', 'payment_status') . ' = :payment_status';
        $params[':payment_status'] = $paymentStatus;
    }

    $orderBy = bvm_buyer_orders_has_col($pdo, 'orders', 'created_at')
        ? ' ORDER BY o.' . bvm_buyer_orders_col_sql($pdo, 'orders', 'created_at') . ' DESC, o.' . bvm_buyer_orders_col_sql($pdo, 'orders', 'id') . ' DESC'
        : ' ORDER BY o.' . bvm_buyer_orders_col_sql($pdo, 'orders', 'id') . ' DESC';

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM `orders` o'
        . ' WHERE ' . implode(' AND ', $where)
        . $orderBy
        . ' LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        $rows = array_slice($rows, 0, $limit);
    }

    $orderIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $itemCounts = bvm_buyer_orders_item_counts($pdo, $orderIds);

    $orders = [];
    foreach ($rows as $row) {
        $orderId = (int) $row['id'];
        $itemCount = (int) ($itemCounts[$orderId] ?? 0);
        $orderStatus = strtolower((string) ($row['status'] ?? ''));
        $paymentStatusValue = strtolower((string) ($row['payment_status'] ?? ''));

        $orders[] = [
            'id' => $orderId,
            'order_code' => (string) ($row['order_code'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'payment_status' => (string) ($row['payment_status'] ?? ''),
            'payment_provider' => $row['payment_provider'] !== null ? (string) $row['payment_provider'] : null,
            'currency' => (string) ($row['currency'] ?? 'USD'),
            'subtotal' => bvm_buyer_orders_money($row['subtotal'] ?? 0),
            'shipping_amount' => bvm_buyer_orders_money($row['shipping_amount'] ?? 0),
            'total' => bvm_buyer_orders_money($row['total'] ?? 0),
            'item_count' => $itemCount,
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'paid_at' => $row['paid_at'] !== null ? (string) $row['paid_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            'can_view_detail' => true,
            'can_request_refund' => $paymentStatusValue === 'paid' && !in_array($orderStatus, ['cancelled', 'canceled', 'refunded'], true) && $itemCount > 0,
        ];
    }

    bvm_buyer_orders_json(200, [
        'ok' => true,
        'data' => [
            'buyer' => $buyer,
            'orders' => $orders,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'has_more' => $hasMore,
            ],
        ],
        'meta' => [
            'api_version' => BVM_BUYER_ORDERS_API_VERSION,
            'generated_at' => bvm_buyer_orders_now(),
        ],
    ]);
} catch (Throwable $e) {
    bvm_buyer_orders_log($e->getMessage());
    bvm_buyer_orders_error('server_error', 'Server error', 500);
}
