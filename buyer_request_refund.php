<?php
declare(strict_types=1);

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const BV_MOBILE_API_VERSION = 'mobile-v1';

function bv_mobile_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function bv_mobile_json(int $statusCode, array $payload): void
{
    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        $payload['meta'] = [];
    }

    $payload['meta']['api_version'] = BV_MOBILE_API_VERSION;
    $payload['meta']['generated_at'] = bv_mobile_now();

    http_response_code($statusCode);
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
    error_log('[BV Mobile Buyer Request Refund] ' . $message);
}

function bv_mobile_get_bearer_token(): string
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

    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        return trim((string) $matches[1]);
    }

    $input = bv_mobile_read_input();
    $fallback = isset($input['token']) ? trim((string) $input['token']) : '';
    return $fallback;
}

function bv_mobile_read_input(): array
{
    static $input = null;
    if (is_array($input)) {
        return $input;
    }

    $input = $_POST;
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    $raw = file_get_contents('php://input');

    if ($raw !== false && trim($raw) !== '' && str_contains($contentType, 'application/json')) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = array_replace($input, $decoded);
        }
    }

    return $input;
}

function bv_mobile_clean_int($value, int $default = 0): int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_float($value)) {
        return (int) $value;
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value !== '' && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
    }

    return $default;
}

function bv_mobile_clean_text($value, int $maxLen = 1000): string
{
    if ($value === null || is_array($value) || is_object($value)) {
        return '';
    }

    $text = trim((string) $value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLen, 'UTF-8');
    }

    return substr($text, 0, $maxLen);
}

function bv_mobile_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $root = dirname(__DIR__, 3);
    $dbIncludes = [
        $root . '/config/db.php',
        $root . '/includes/db.php',
        $root . '/db.php',
    ];

    foreach ($dbIncludes as $path) {
        if (is_file($path) && is_readable($path)) {
            $loader = static function (string $includePath): void {
                $pdo = $PDO = $db = $conn = null;
                ob_start();
                /** @noinspection PhpIncludeInspection */
                include $includePath;
                ob_end_clean();

                foreach (['pdo' => $pdo, 'PDO' => $PDO, 'db' => $db, 'conn' => $conn] as $key => $value) {
                    if ($value instanceof PDO) {
                        $GLOBALS[$key] = $value;
                    } elseif ($value instanceof mysqli) {
                        $GLOBALS[$key] = $value;
                    }
                }
            };
            $loader($path);
        }
    }

    foreach (['pdo', 'PDO', 'db', 'conn'] as $key) {
        if (isset($GLOBALS[$key]) && $GLOBALS[$key] instanceof PDO) {
            $pdo = $GLOBALS[$key];
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            break;
        }
    }

    if (!$pdo instanceof PDO) {
        foreach (['pdo', 'PDO', 'db', 'conn'] as $key) {
            if (isset($GLOBALS[$key]) && $GLOBALS[$key] instanceof mysqli) {
                throw new RuntimeException('PDO connection required.');
            }
        }
        throw new RuntimeException('PDO connection required.');
    }

    $helperIncludes = [
        $root . '/includes/order_refund.php',
        $root . '/includes/order_cancel.php',
        $root . '/includes/refund_notifications.php',
        $root . '/includes/mailer.php',
    ];

    foreach ($helperIncludes as $path) {
        if (is_file($path) && is_readable($path)) {
            ob_start();
            /** @noinspection PhpIncludeInspection */
            include_once $path;
            ob_end_clean();
        }
    }

    return $pdo;
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
        bv_mobile_log('Table check failed: ' . $e->getMessage());
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
        bv_mobile_log('Column check failed for ' . $table . ': ' . $e->getMessage());
        return [];
    }
}

function bv_mobile_has_col(PDO $pdo, string $table, string $column): bool
{
    $columns = bv_mobile_columns($pdo, $table);
    return isset($columns[strtolower($column)]);
}

function bv_mobile_select_col(PDO $pdo, string $alias, string $table, string $column, ?string $as = null): string
{
    $as = $as ?? $column;
    return bv_mobile_has_col($pdo, $table, $column) ? $alias . '.`' . $column . '` AS `' . $as . '`' : 'NULL AS `' . $as . '`';
}

function bv_mobile_auth_user(PDO $pdo): array
{
    $rawToken = bv_mobile_get_bearer_token();
    if ($rawToken === '' || strlen($rawToken) < 16 || strlen($rawToken) > 512) {
        bv_mobile_error('unauthorized', 'Unauthorized.', 401);
    }

    if (!bv_mobile_table_exists($pdo, 'mobile_auth_tokens') || !bv_mobile_table_exists($pdo, 'users')) {
        throw new RuntimeException('Required authentication tables are missing.');
    }

    if (!bv_mobile_has_col($pdo, 'mobile_auth_tokens', 'token_hash') || !bv_mobile_has_col($pdo, 'mobile_auth_tokens', 'user_id') || !bv_mobile_has_col($pdo, 'users', 'id')) {
        throw new RuntimeException('Required authentication columns are missing.');
    }

    $select = ['u.`id` AS `id`'];
    foreach (['first_name', 'last_name', 'name', 'email', 'role', 'account_status'] as $column) {
        if (bv_mobile_has_col($pdo, 'users', $column)) {
            $select[] = 'u.`' . $column . '` AS `' . $column . '`';
        }
    }

    $where = ['t.`token_hash` = :token_hash'];
    if (bv_mobile_has_col($pdo, 'mobile_auth_tokens', 'revoked_at')) {
        $where[] = 't.`revoked_at` IS NULL';
    }
    if (bv_mobile_has_col($pdo, 'mobile_auth_tokens', 'expires_at')) {
        $where[] = 't.`expires_at` > UTC_TIMESTAMP()';
    }
    if (bv_mobile_has_col($pdo, 'users', 'account_status')) {
        $where[] = "u.`account_status` = 'active'";
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM `mobile_auth_tokens` t INNER JOIN `users` u ON u.`id` = t.`user_id` WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token_hash' => hash('sha256', $rawToken)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($user) || empty($user['id'])) {
        bv_mobile_error('unauthorized', 'Unauthorized.', 401);
    }

    $user['id'] = (int) $user['id'];
    return $user;
}

function bv_mobile_fetch_order(PDO $pdo, int $orderId, int $userId): ?array
{
    if ($orderId <= 0 || $userId <= 0 || !bv_mobile_table_exists($pdo, 'orders')) {
        return null;
    }

    if (!bv_mobile_has_col($pdo, 'orders', 'id') || !bv_mobile_has_col($pdo, 'orders', 'user_id')) {
        throw new RuntimeException('Required order columns are missing.');
    }

    $select = ['o.`id` AS `id`', 'o.`user_id` AS `user_id`'];
    foreach (['order_code', 'status', 'payment_status', 'payment_provider', 'payment_reference', 'currency', 'subtotal', 'discount_amount', 'shipping_amount', 'tax_amount', 'total', 'order_total', 'gross_paid_amount', 'refundable_gross_amount', 'order_source', 'created_at', 'paid_at', 'updated_at'] as $column) {
        $select[] = bv_mobile_select_col($pdo, 'o', 'orders', $column);
    }

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM `orders` o WHERE o.`id` = :order_id AND o.`user_id` = :user_id LIMIT 1');
    $stmt->execute([':order_id' => $orderId, ':user_id' => $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($order) ? $order : null;
}

function bv_mobile_fetch_order_items(PDO $pdo, int $orderId, array $selectedIds = []): array
{
    if ($orderId <= 0 || !bv_mobile_table_exists($pdo, 'order_items') || !bv_mobile_has_col($pdo, 'order_items', 'order_id') || !bv_mobile_has_col($pdo, 'order_items', 'id')) {
        return [];
    }

    $select = ['oi.`id` AS `id`', 'oi.`order_id` AS `order_id`'];
    foreach (['listing_id', 'seller_id', 'seller_user_id', 'title', 'listing_title', 'quantity', 'qty', 'unit_price', 'price', 'line_total', 'subtotal', 'currency'] as $column) {
        $select[] = bv_mobile_select_col($pdo, 'oi', 'order_items', $column);
    }

    $params = [':order_id' => $orderId];
    $where = ['oi.`order_id` = :order_id'];

    $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds), static fn (int $id): bool => $id > 0)));
    if ($selectedIds !== []) {
        $placeholders = [];
        foreach ($selectedIds as $index => $id) {
            $placeholder = ':item_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }
        $where[] = 'oi.`id` IN (' . implode(', ', $placeholders) . ')';
    }

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM `order_items` oi WHERE ' . implode(' AND ', $where) . ' ORDER BY oi.`id` ASC');
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($items) ? $items : [];
}

function bv_mobile_parse_item_ids($value): array
{
    if ($value === null || $value === '') {
        return [];
    }

    if (is_string($value)) {
        $parts = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    } elseif (is_array($value)) {
        $parts = $value;
    } else {
        $parts = [$value];
    }

    $ids = [];
    foreach ($parts as $part) {
        if (is_array($part)) {
            foreach (bv_mobile_parse_item_ids($part) as $nestedId) {
                $ids[] = $nestedId;
            }
            continue;
        }

        $id = bv_mobile_clean_int($part, 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function bv_mobile_detect_existing_active_refund(PDO $pdo, int $orderId, array $itemIds): ?array
{
    if ($orderId <= 0 || !bv_mobile_table_exists($pdo, 'order_refunds') || !bv_mobile_has_col($pdo, 'order_refunds', 'order_id')) {
        return null;
    }

    $statuses = ['draft', 'pending_approval', 'partially_approved', 'approved', 'processing', 'partially_refunded', 'refunded'];
    $statusPlaceholders = [];
    $params = [':order_id' => $orderId];
    foreach ($statuses as $index => $status) {
        $placeholder = ':status_' . $index;
        $statusPlaceholders[] = $placeholder;
        $params[$placeholder] = $status;
    }

    if ($itemIds === []) {
        $stmt = $pdo->prepare('SELECT r.`id`, ' . (bv_mobile_has_col($pdo, 'order_refunds', 'refund_code') ? 'r.`refund_code`' : 'NULL AS `refund_code`') . ' FROM `order_refunds` r WHERE r.`order_id` = :order_id AND r.`status` IN (' . implode(', ', $statusPlaceholders) . ') LIMIT 1');
        $stmt->execute($params);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($refund) ? $refund : null;
    }

    if (!bv_mobile_table_exists($pdo, 'order_refund_items') || !bv_mobile_has_col($pdo, 'order_refund_items', 'refund_id') || !bv_mobile_has_col($pdo, 'order_refund_items', 'order_item_id')) {
        return null;
    }

    $itemPlaceholders = [];
    foreach (array_values($itemIds) as $index => $itemId) {
        $placeholder = ':item_' . $index;
        $itemPlaceholders[] = $placeholder;
        $params[$placeholder] = (int) $itemId;
    }

    $stmt = $pdo->prepare(
        'SELECT r.`id`, ' . (bv_mobile_has_col($pdo, 'order_refunds', 'refund_code') ? 'r.`refund_code`' : 'NULL AS `refund_code`')
        . ' FROM `order_refunds` r INNER JOIN `order_refund_items` ri ON ri.`refund_id` = r.`id`'
        . ' WHERE r.`order_id` = :order_id AND r.`status` IN (' . implode(', ', $statusPlaceholders) . ')'
        . ' AND ri.`order_item_id` IN (' . implode(', ', $itemPlaceholders) . ') LIMIT 1'
    );
    $stmt->execute($params);
    $refund = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($refund) ? $refund : null;
}

function bv_mobile_money($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }

    return round((float) $value, 2);
}

function bv_mobile_item_qty(array $item): int
{
    $qty = bv_mobile_clean_int($item['quantity'] ?? ($item['qty'] ?? 1), 1);
    return max(1, $qty);
}

function bv_mobile_item_unit_price(array $item): float
{
    if (isset($item['unit_price']) && $item['unit_price'] !== null && $item['unit_price'] !== '') {
        return bv_mobile_money($item['unit_price']);
    }

    return bv_mobile_money($item['price'] ?? 0);
}

function bv_mobile_item_line_total(array $item): float
{
    foreach (['line_total', 'subtotal'] as $key) {
        if (isset($item[$key]) && $item[$key] !== null && $item[$key] !== '') {
            return bv_mobile_money($item[$key]);
        }
    }

    return round(bv_mobile_item_unit_price($item) * bv_mobile_item_qty($item), 2);
}

function bv_mobile_order_total(array $order): float
{
    foreach (['total', 'order_total', 'refundable_gross_amount', 'gross_paid_amount', 'subtotal'] as $key) {
        if (isset($order[$key]) && $order[$key] !== null && $order[$key] !== '') {
            $amount = bv_mobile_money($order[$key]);
            if ($amount > 0) {
                return $amount;
            }
        }
    }

    return 0.0;
}

function bv_mobile_calculate_requested_amount(array $order, array $items): float
{
    if ($items !== []) {
        $sum = 0.0;
        foreach ($items as $item) {
            $sum += bv_mobile_item_line_total($item);
        }
        return round($sum, 2);
    }

    return bv_mobile_order_total($order);
}

function bv_mobile_generate_refund_code(): string
{
    return 'RFN-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function bv_mobile_insert_row(PDO $pdo, string $table, array $values): int
{
    $columns = bv_mobile_columns($pdo, $table);
    $insert = [];
    $params = [];

    foreach ($values as $column => $value) {
        $lower = strtolower((string) $column);
        if (!isset($columns[$lower])) {
            continue;
        }
        $actual = $columns[$lower];
        $placeholder = ':p_' . count($params);
        $insert[$actual] = $placeholder;
        $params[$placeholder] = $value;
    }

    if ($insert === []) {
        throw new RuntimeException('No insertable columns for ' . $table);
    }

    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($insert)) . '`) VALUES (' . implode(', ', array_values($insert)) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $pdo->lastInsertId();
}

function bv_mobile_create_refund_request(PDO $pdo, array $buyer, array $order, array $items, array $payload): array
{
    if (!bv_mobile_table_exists($pdo, 'order_refunds')) {
        throw new RuntimeException('order_refunds table is missing.');
    }

    $now = bv_mobile_now();
    $mode = (string) $payload['request_mode'];
    $currency = strtoupper(substr((string) ($order['currency'] ?? ''), 0, 3));
    if ($currency === '') {
        $currency = 'USD';
    }

    $requestedAmount = bv_mobile_calculate_requested_amount($order, $items);
    if ($requestedAmount <= 0) {
        throw new RuntimeException('Requested refund amount is not positive.');
    }



    $refundCode = bv_mobile_generate_refund_code();
    $cancellationId = null;
    $cancelItemIds = [];

    $pdo->beginTransaction();
    try {
        if (bv_mobile_table_exists($pdo, 'order_cancellations')) {
            $cancellationValues = [
                'order_id' => (int) $order['id'],
                'order_code_snapshot' => (string) ($order['order_code'] ?? ''),
                'user_id' => (int) $buyer['id'],
                'requested_by_user_id' => (int) $buyer['id'],
                'actor_role' => 'buyer',
                'requested_by_role' => 'buyer',
                'cancel_source' => 'buyer',
                'cancel_reason_code' => (string) $payload['reason_code'],
                'cancel_reason_text' => (string) $payload['reason_text'],
                'status' => 'requested',
                'refund_status' => 'pending',
                'requested_refund_amount' => $requestedAmount,
                'refundable_amount' => $requestedAmount,
                'currency' => $currency,
                'requested_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $cancellationId = bv_mobile_insert_row($pdo, 'order_cancellations', $cancellationValues);

            if ($cancellationId > 0 && $items !== [] && bv_mobile_table_exists($pdo, 'order_cancellation_items')) {
                foreach ($items as $item) {
                    $lineTotal = bv_mobile_item_line_total($item);
                    $cancelItemValues = [
                        'cancellation_id' => $cancellationId,
                        'order_cancellation_id' => $cancellationId,
                        'order_id' => (int) $order['id'],
                        'order_item_id' => (int) $item['id'],
                        'listing_id' => !empty($item['listing_id']) ? (int) $item['listing_id'] : null,
                        'seller_user_id' => !empty($item['seller_user_id']) ? (int) $item['seller_user_id'] : (!empty($item['seller_id']) ? (int) $item['seller_id'] : null),
                        'qty_snapshot' => bv_mobile_item_qty($item),
                        'quantity_snapshot' => bv_mobile_item_qty($item),
                        'refund_qty' => bv_mobile_item_qty($item),
                        'requested_qty' => bv_mobile_item_qty($item),
                        'unit_price_snapshot' => bv_mobile_item_unit_price($item),
                        'line_total_snapshot' => $lineTotal,
                        'requested_refund_amount' => $lineTotal,
                        'refundable_amount' => $lineTotal,
                        'currency' => $currency,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $cancelItemIds[(int) $item['id']] = bv_mobile_insert_row($pdo, 'order_cancellation_items', $cancelItemValues);
                }
            }
        }

        $refundValues = [
            'order_id' => (int) $order['id'],
            'order_cancellation_id' => $cancellationId,
            'refund_code' => $refundCode,
            'refund_source' => 'buyer_request',
            'refund_reason_code' => (string) $payload['reason_code'],
            'refund_reason_text' => (string) $payload['reason_text'],
            'status' => 'pending_approval',
            'refund_mode' => $mode,
            'currency' => $currency,
            'subtotal_snapshot' => bv_mobile_money($order['subtotal'] ?? 0),
            'discount_snapshot' => bv_mobile_money($order['discount_amount'] ?? 0),
            'shipping_snapshot' => bv_mobile_money($order['shipping_amount'] ?? 0),
            'tax_snapshot' => bv_mobile_money($order['tax_amount'] ?? 0),
            'order_total_snapshot' => bv_mobile_order_total($order),
            'gross_paid_amount' => bv_mobile_money($order['gross_paid_amount'] ?? bv_mobile_order_total($order)),
            'refundable_gross_amount' => bv_mobile_money($order['refundable_gross_amount'] ?? $requestedAmount),
            'requested_refund_amount' => $requestedAmount,
            'approved_refund_amount' => 0,
            'actual_refund_amount' => 0,
            'actual_refunded_amount' => 0,
            'payment_provider' => (string) ($order['payment_provider'] ?? ''),
            'payment_reference_snapshot' => (string) ($order['payment_reference'] ?? ''),
            'payment_status_snapshot' => (string) ($order['payment_status'] ?? ''),
            'order_status_snapshot' => (string) ($order['status'] ?? ''),
            'order_source_snapshot' => (string) ($order['order_source'] ?? ''),
            'requested_by_user_id' => (int) $buyer['id'],
            'requested_by_role' => 'buyer_request',
            'requested_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $refundId = bv_mobile_insert_row($pdo, 'order_refunds', $refundValues);

        if ($items !== [] && bv_mobile_table_exists($pdo, 'order_refund_items')) {
            foreach ($items as $item) {
                $lineTotal = bv_mobile_item_line_total($item);
                $refundItemValues = [
                    'refund_id' => $refundId,
                    'order_cancellation_item_id' => $cancelItemIds[(int) $item['id']] ?? null,
                    'order_item_id' => (int) $item['id'],
                    'listing_id' => !empty($item['listing_id']) ? (int) $item['listing_id'] : null,
                    'requested_qty' => bv_mobile_item_qty($item),
                    'qty_snapshot' => bv_mobile_item_qty($item),
                    'unit_price_snapshot' => bv_mobile_item_unit_price($item),
                    'line_total_snapshot' => $lineTotal,
                    'max_refundable_amount' => $lineTotal,
                    'requested_refund_amount' => $lineTotal,
                    'approved_refund_amount' => 0,
                    'actual_refunded_amount' => 0,
                    'actual_refund_amount' => 0,
                    'currency' => $currency,
                    'refund_type' => 'item',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                bv_mobile_insert_row($pdo, 'order_refund_items', $refundItemValues);
            }
        }

        $pdo->commit();

        return [
            'id' => $refundId,
            'refund_code' => $refundCode,
            'status' => 'pending_approval',
            'requested_refund_amount' => $requestedAmount,
            'currency' => $currency,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        bv_mobile_log('Create refund request failed: ' . $e->getMessage());
        throw $e;
    }
}

function bv_mobile_try_queue_refund_notification(array $refund): void
{
    foreach (['bv_refund_notifications_queue_request_created', 'bv_refund_notification_request_created', 'bv_order_refund_queue_notification'] as $function) {
        if (!function_exists($function)) {
            continue;
        }

        try {
            $function($refund);
        } catch (Throwable $e) {
            bv_mobile_log('Refund notification failed: ' . $e->getMessage());
        }
        return;
    }
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        bv_mobile_json(405, [
            'ok' => false,
            'error' => [
                'code' => 'method_not_allowed',
                'message' => 'Method not allowed.',
            ],
        ]);
    }

    $pdo = bv_mobile_db();
    $buyer = bv_mobile_auth_user($pdo);
    $input = bv_mobile_read_input();

    $orderId = bv_mobile_clean_int($input['order_id'] ?? null, 0);
    if ($orderId <= 0) {
        bv_mobile_error('bad_request', 'Invalid request.', 400);
    }

    $rawItemIds = $input['order_item_ids'] ?? null;
    $selectedItemIds = bv_mobile_parse_item_ids($rawItemIds);
    $reasonCode = bv_mobile_clean_text($input['reason_code'] ?? '', 50);
    $reasonText = bv_mobile_clean_text($input['reason_text'] ?? '', 1000);
    $requestMode = strtolower(bv_mobile_clean_text($input['request_mode'] ?? '', 20));

    if ($requestMode === '') {
        $requestMode = $selectedItemIds === [] ? 'full' : 'partial';
    }

    if (!in_array($requestMode, ['full', 'partial'], true)) {
        bv_mobile_error('bad_request', 'Invalid request.', 400);
    }

    if ($requestMode === 'partial' && $selectedItemIds === []) {
        bv_mobile_error('bad_request', 'Invalid request.', 400);
    }

    $order = bv_mobile_fetch_order($pdo, $orderId, (int) $buyer['id']);
    if ($order === null) {
        bv_mobile_error('not_found', 'Order not found.', 404);
    }

    if (strtolower((string) ($order['payment_status'] ?? '')) !== 'paid') {
        bv_mobile_error('bad_request', 'Order is not eligible for refund request.', 400);
    }

    $orderStatus = strtolower((string) ($order['status'] ?? ''));
    if (in_array($orderStatus, ['cancelled', 'canceled', 'refunded'], true)) {
        bv_mobile_error('bad_request', 'Order is not eligible for refund request.', 400);
    }

    $items = [];
    if ($requestMode === 'partial') {
        $items = bv_mobile_fetch_order_items($pdo, $orderId, $selectedItemIds);
        $foundIds = array_values(array_map('intval', array_column($items, 'id')));
        sort($foundIds);
        $expectedIds = $selectedItemIds;
        sort($expectedIds);
        if ($foundIds !== $expectedIds) {
            bv_mobile_error('bad_request', 'Invalid request.', 400);
        }
    } else {
        $selectedItemIds = [];
        $items = bv_mobile_fetch_order_items($pdo, $orderId);
    }

    if ($items === [] && $requestMode === 'partial') {
        bv_mobile_error('bad_request', 'Invalid request.', 400);
    }

    $duplicate = bv_mobile_detect_existing_active_refund($pdo, $orderId, $requestMode === 'partial' ? $selectedItemIds : []);
    if ($duplicate !== null) {
        bv_mobile_json(409, [
            'ok' => false,
            'error' => [
                'code' => 'refund_already_requested',
                'message' => 'A refund request already exists for this order or item.',
            ],
        ]);
    }

    $refund = bv_mobile_create_refund_request($pdo, $buyer, $order, $items, [
        'request_mode' => $requestMode,
        'reason_code' => $reasonCode,
        'reason_text' => $reasonText,
    ]);

    $notificationPayload = [
        'refund' => $refund,
        'buyer' => $buyer,
        'order' => $order,
        'items' => $items,
    ];
    bv_mobile_try_queue_refund_notification($notificationPayload);

    $responseItemIds = $items !== [] ? array_values(array_map('intval', array_column($items, 'id'))) : [];

    bv_mobile_json(200, [
        'ok' => true,
        'data' => [
            'order_id' => (int) $order['id'],
            'order_code' => (string) ($order['order_code'] ?? ''),
            'request_mode' => $requestMode,
            'selected_item_ids' => $responseItemIds,
            'refund' => [
                'id' => (int) $refund['id'],
                'refund_code' => (string) $refund['refund_code'],
                'status' => (string) $refund['status'],
                'requested_refund_amount' => round((float) $refund['requested_refund_amount'], 2),
                'currency' => (string) $refund['currency'],
            ],
            'message' => 'Refund request submitted successfully.',
        ],
    ]);
} catch (RuntimeException $e) {
    bv_mobile_log($e->getMessage());
    $message = $e->getMessage() === 'PDO connection required.' ? 'PDO connection required.' : 'Server error.';
    bv_mobile_json(500, [
        'ok' => false,
        'error' => [
            'code' => 'server_error',
            'message' => $message,
        ],
    ]);
} catch (Throwable $e) {
    bv_mobile_log($e->getMessage());
    bv_mobile_json(500, [
        'ok' => false,
        'error' => [
            'code' => 'server_error',
            'message' => 'Server error.',
        ],
    ]);
}
