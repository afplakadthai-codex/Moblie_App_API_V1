<?php
declare(strict_types=1);

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

const BV_MOBILE_API_VERSION = 'mobile-v1';

function bv_mobile_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function bv_mobile_json($statusCode, array $payload): void
{
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
    error_log('[BV Mobile Buyer Order Detail] ' . $message);
}

function bv_mobile_get_bearer_token(): string
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
        return trim((string) $matches[1]);
    }

    return isset($_GET['token']) ? trim((string) $_GET['token']) : '';
}

function bv_mobile_clean_int($value, int $default = 0): int
{
    if ($value === null || $value === '') {
        return $default;
    }

    if (is_int($value)) {
        return $value;
    }

    if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
        return (int) $value;
    }

    return $default;
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

        try {
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
            $loader($path);
        } catch (Throwable $e) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            bv_mobile_log('DB include failed for ' . $path . ': ' . $e->getMessage());
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

function bv_mobile_select_col(PDO $pdo, string $tableAlias, string $table, string $column, ?string $alias = null): string
{
    $alias = $alias ?? $column;
    return bv_mobile_has_col($pdo, $table, $column)
        ? $tableAlias . '.`' . $column . '` AS `' . $alias . '`'
        : 'NULL AS `' . $alias . '`';
}

function bv_mobile_first_existing_value(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && trim((string) $row[$key]) !== '') {
            return trim((string) $row[$key]);
        }
    }

    return $default;
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

    if (!$user) {
        bv_mobile_error('unauthorized', 'Unauthorized.', 401);
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
        'role' => (string) ($user['role'] ?? 'user'),
    ];
}

function bv_mobile_money_value($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }

    return round((float) $value, 2);
}

function bv_mobile_fetch_order(PDO $pdo, int $orderId, int $userId): ?array
{
    if (!bv_mobile_table_exists($pdo, 'orders') || !bv_mobile_has_col($pdo, 'orders', 'id') || !bv_mobile_has_col($pdo, 'orders', 'user_id')) {
        throw new RuntimeException('Orders table is unavailable.');
    }

    $columns = [
        'id', 'user_id', 'order_code', 'status', 'payment_status', 'payment_provider', 'currency',
        'subtotal', 'subtotal_before_discount', 'seller_discount_total', 'discount_amount', 'shipping_amount',
        'total', 'order_total', 'buyer_name', 'buyer_email', 'buyer_phone', 'customer_name', 'email', 'phone',
        'shipping_name', 'shipping_email', 'shipping_phone', 'shipping_address_line1', 'shipping_address_line2',
        'shipping_city', 'shipping_province', 'shipping_postal_code', 'shipping_country',
        'ship_name', 'ship_email', 'ship_phone', 'ship_address', 'ship_address_line1', 'ship_address_line2',
        'ship_district', 'ship_province', 'ship_postal_code', 'ship_country',
        'address_line1', 'address_line2', 'buyer_address_line1', 'buyer_address_line2',
        'city', 'district', 'province', 'postal_code', 'country', 'created_at', 'paid_at', 'updated_at',
    ];

    $select = [];
    foreach (array_values(array_unique($columns)) as $column) {
        $select[] = bv_mobile_select_col($pdo, 'o', 'orders', $column);
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM `orders` o WHERE o.`id` = :order_id AND o.`user_id` = :user_id LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':order_id' => $orderId,
        ':user_id' => $userId,
    ]);

    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    return $order ?: null;
}

function bv_mobile_public_listing_url(array $item): string
{
    $listingId = (int) ($item['listing_id'] ?? 0);
    return $listingId > 0 ? '/listing.php?id=' . $listingId : '';
}

function bv_mobile_resolve_asset_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path) === 1 || str_starts_with($path, '//')) {
        return $path;
    }

    $path = str_replace("\0", '', $path);
    $path = preg_replace('#/+#', '/', $path) ?? '';
    if ($path === '' || str_contains($path, '..')) {
        return '';
    }

    return $path[0] === '/' ? $path : '/' . ltrim($path, '/');
}

function bv_mobile_fetch_order_items(PDO $pdo, array $order): array
{
    $orderId = (int) ($order['id'] ?? 0);
    if ($orderId <= 0 || !bv_mobile_table_exists($pdo, 'order_items') || !bv_mobile_has_col($pdo, 'order_items', 'order_id')) {
        return [];
    }

    $hasListings = bv_mobile_table_exists($pdo, 'listings') && bv_mobile_has_col($pdo, 'order_items', 'listing_id') && bv_mobile_has_col($pdo, 'listings', 'id');
    $listingJoin = $hasListings ? ' LEFT JOIN `listings` l ON l.`id` = oi.`listing_id`' : '';

    $sellerJoin = '';
    if (bv_mobile_table_exists($pdo, 'users') && bv_mobile_has_col($pdo, 'users', 'id')) {
        if (bv_mobile_has_col($pdo, 'order_items', 'seller_id')) {
            $sellerJoin = ' LEFT JOIN `users` su ON su.`id` = oi.`seller_id`';
        } elseif ($hasListings && bv_mobile_has_col($pdo, 'listings', 'seller_id')) {
            $sellerJoin = ' LEFT JOIN `users` su ON su.`id` = l.`seller_id`';
        }
    }

    $select = [];
    foreach (['id', 'listing_id', 'title', 'listing_title', 'item_title', 'title_snapshot', 'quantity', 'qty', 'unit_price', 'price', 'line_total', 'subtotal', 'currency', 'fulfillment_status', 'carrier', 'tracking_number', 'processed_at', 'shipped_at', 'completed_at', 'seller_id', 'seller_name_snapshot', 'cover_image_snapshot'] as $column) {
        $select[] = bv_mobile_select_col($pdo, 'oi', 'order_items', $column, 'oi_' . $column);
    }

    foreach (['id', 'title', 'slug', 'seller_id', 'price', 'currency', 'image_path', 'image_url', 'cover_image', 'main_image'] as $column) {
        $select[] = $hasListings ? bv_mobile_select_col($pdo, 'l', 'listings', $column, 'listing_' . $column) : 'NULL AS `listing_' . $column . '`';
    }

    foreach (['id', 'first_name', 'last_name', 'name', 'email'] as $column) {
        $select[] = $sellerJoin !== '' ? bv_mobile_select_col($pdo, 'su', 'users', $column, 'seller_' . $column) : 'NULL AS `seller_' . $column . '`';
    }

    $orderBy = bv_mobile_has_col($pdo, 'order_items', 'id') ? ' ORDER BY oi.`id` ASC' : '';
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM `order_items` oi' . $listingJoin . $sellerJoin . ' WHERE oi.`order_id` = :order_id' . $orderBy;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':order_id' => $orderId]);

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $qty = (int) bv_mobile_money_value(bv_mobile_first_existing_value($row, ['oi_quantity', 'oi_qty'], '1'));
        if ($qty <= 0) {
            $qty = 1;
        }

        $unitPrice = bv_mobile_money_value(bv_mobile_first_existing_value($row, ['oi_unit_price', 'oi_price', 'listing_price'], '0'));
        $lineTotalRaw = bv_mobile_first_existing_value($row, ['oi_line_total', 'oi_subtotal'], '');
        $lineTotal = $lineTotalRaw !== '' ? bv_mobile_money_value($lineTotalRaw) : bv_mobile_money_value($unitPrice * $qty);
        $currency = bv_mobile_first_existing_value($row, ['oi_currency', 'listing_currency'], (string) ($order['currency'] ?? 'USD'));
        $listingId = (int) ($row['oi_listing_id'] ?? $row['listing_id'] ?? 0);

        $sellerFirstName = trim((string) ($row['seller_first_name'] ?? ''));
        $sellerLastName = trim((string) ($row['seller_last_name'] ?? ''));
        $sellerName = trim($sellerFirstName . ' ' . $sellerLastName);
        if ($sellerName === '') {
            $sellerName = bv_mobile_first_existing_value($row, ['seller_name', 'oi_seller_name_snapshot'], '');
        }

        $sellerId = (int) ($row['seller_id'] ?? $row['oi_seller_id'] ?? $row['listing_seller_id'] ?? 0);
        $imagePath = bv_mobile_first_existing_value($row, ['listing_image_path', 'listing_image_url', 'listing_cover_image', 'listing_main_image', 'oi_cover_image_snapshot'], '');

        $fulfillmentStatus = (string) ($row['oi_fulfillment_status'] ?? 'unknown');
        $trackingNumber = $row['oi_tracking_number'] !== null ? (string) $row['oi_tracking_number'] : null;
        $trackingAvailable = $trackingNumber !== null && trim($trackingNumber) !== '';
        $trackingMessage = '';
        if ($trackingAvailable) {
            $trackingMessage = 'Tracking information is available.';
        } elseif (in_array(strtolower(trim($fulfillmentStatus)), ['shipped', 'completed'], true)) {
            $trackingMessage = 'Tracking information is not available yet.';
        }
        
		$item = [
            'order_item_id' => (int) ($row['oi_id'] ?? 0),
            'listing_id' => $listingId,
            'title' => bv_mobile_first_existing_value($row, ['oi_title', 'oi_listing_title', 'oi_item_title', 'oi_title_snapshot', 'listing_title'], ''),
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'currency' => $currency,
            'seller' => [
                'id' => $sellerId,
                'name' => $sellerName,
                'email' => (string) ($row['seller_email'] ?? ''),
            ],
            'fulfillment' => [
                 'status' => $fulfillmentStatus,
                'carrier' => $row['oi_carrier'] !== null ? (string) $row['oi_carrier'] : null,
                'tracking_number' => $trackingNumber,
                'tracking_available' => $trackingAvailable,
                'tracking_message' => $trackingMessage, 
                'processed_at' => $row['oi_processed_at'] !== null ? (string) $row['oi_processed_at'] : null,
                'shipped_at' => $row['oi_shipped_at'] !== null ? (string) $row['oi_shipped_at'] : null,
                'completed_at' => $row['oi_completed_at'] !== null ? (string) $row['oi_completed_at'] : null,
            ],
        ];
        $item['listing_url'] = bv_mobile_public_listing_url($item);
        $item['image_url'] = bv_mobile_resolve_asset_url($imagePath);
        $items[] = $item;
    }

    return $items;
}

function bv_mobile_fetch_order_refunds(PDO $pdo, int $orderId): array
{
    if ($orderId <= 0 || !bv_mobile_table_exists($pdo, 'order_refunds') || !bv_mobile_has_col($pdo, 'order_refunds', 'order_id')) {
        return [];
    }

    $select = [];
    foreach (['id', 'refund_code', 'status', 'requested_refund_amount', 'approved_refund_amount', 'actual_refunded_amount', 'currency', 'created_at', 'updated_at'] as $column) {
        $select[] = bv_mobile_select_col($pdo, 'r', 'order_refunds', $column);
    }

    $orderBy = bv_mobile_has_col($pdo, 'order_refunds', 'created_at') ? ' ORDER BY r.`created_at` DESC, r.`id` DESC' : ' ORDER BY r.`id` DESC';
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM `order_refunds` r WHERE r.`order_id` = :order_id' . $orderBy;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':order_id' => $orderId]);

    $refunds = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $refunds[] = [
            'id' => (int) ($row['id'] ?? 0),
            'refund_code' => (string) ($row['refund_code'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'requested_refund_amount' => bv_mobile_money_value($row['requested_refund_amount'] ?? 0),
            'approved_refund_amount' => bv_mobile_money_value($row['approved_refund_amount'] ?? 0),
            'actual_refunded_amount' => bv_mobile_money_value($row['actual_refunded_amount'] ?? 0),
            'currency' => (string) ($row['currency'] ?? 'USD'),
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    return $refunds;
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

function bv_mobile_build_shipping(array $order): array
{
    return [
        'name' => bv_mobile_first_existing_value($order, ['shipping_name', 'ship_name', 'buyer_name', 'customer_name'], ''),
        'email' => bv_mobile_first_existing_value($order, ['shipping_email', 'ship_email', 'buyer_email', 'email'], ''),
        'phone' => bv_mobile_first_existing_value($order, ['shipping_phone', 'ship_phone', 'buyer_phone', 'phone'], ''),
        'address_line1' => bv_mobile_first_existing_value($order, ['shipping_address_line1', 'ship_address_line1', 'address_line1', 'buyer_address_line1', 'ship_address'], ''),
        'address_line2' => bv_mobile_first_existing_value($order, ['shipping_address_line2', 'ship_address_line2', 'address_line2', 'buyer_address_line2'], ''),
        'city' => bv_mobile_first_existing_value($order, ['shipping_city', 'city', 'district', 'ship_district'], ''),
        'province' => bv_mobile_first_existing_value($order, ['shipping_province', 'ship_province', 'province'], ''),
        'postal_code' => bv_mobile_first_existing_value($order, ['shipping_postal_code', 'ship_postal_code', 'postal_code'], ''),
        'country' => bv_mobile_first_existing_value($order, ['shipping_country', 'ship_country', 'country'], ''),
    ];
}

function bv_mobile_build_actions(array $order, array $items, array $refunds): array
{
    $paymentStatus = strtolower((string) ($order['payment_status'] ?? ''));
    $orderStatus = strtolower((string) ($order['status'] ?? ''));
    $fulfillmentStatus = strtolower((string) ($order['fulfillment_status'] ?? ''));

    $hasActiveRefund = false;
    foreach ($refunds as $refund) {
        if (in_array(strtolower((string) ($refund['status'] ?? '')), ['pending_approval', 'approved', 'processing', 'partially_refunded', 'refunded'], true)) {
            $hasActiveRefund = true;
            break;
        }
    }

    $requestRefundAllowed = $paymentStatus === 'paid'
        && !in_array($orderStatus, ['cancelled', 'canceled', 'refunded'], true)
        && !$hasActiveRefund;

    $canReview = in_array($orderStatus, ['completed', 'shipped'], true)
        || in_array($fulfillmentStatus, ['shipped', 'completed'], true);

    $canConfirmDelivery = in_array($fulfillmentStatus, ['shipped', 'partially_fulfilled'], true)
        && !in_array($fulfillmentStatus, ['completed', 'cancelled', 'canceled', 'refunded', 'pending'], true);

    return [
        'request_refund_allowed' => $requestRefundAllowed,
        'can_review' => $canReview,
        'can_confirm_delivery' => $canConfirmDelivery,
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        bv_mobile_error('method_not_allowed', 'Method not allowed.', 405);
    }

    $orderId = bv_mobile_clean_int($_GET['order_id'] ?? null, 0);
    if ($orderId <= 0) {
        bv_mobile_error('bad_request', 'A valid order_id is required.', 400);
    }

    $pdo = bv_mobile_db();
    $buyer = bv_mobile_auth_user($pdo);
    $order = bv_mobile_fetch_order($pdo, $orderId, (int) $buyer['id']);
    if ($order === null) {
        bv_mobile_error('not_found', 'Order not found.', 404);
    }

    $items = bv_mobile_fetch_order_items($pdo, $order);
    $refunds = bv_mobile_fetch_order_refunds($pdo, $orderId);
    $statuses = array_map(static fn (array $item): string => (string) ($item['fulfillment']['status'] ?? ''), $items);
    $fulfillmentStatus = bv_mobile_derive_fulfillment_status($statuses);

    $sellerIds = [];
    foreach ($items as $item) {
        $sellerId = (int) ($item['seller']['id'] ?? 0);
        if ($sellerId > 0) {
            $sellerIds[$sellerId] = true;
        }
    }

    $total = bv_mobile_money_value($order['total'] ?? ($order['order_total'] ?? 0));
    if ($total <= 0 && isset($order['order_total'])) {
        $total = bv_mobile_money_value($order['order_total']);
    }

    $subtotal = isset($order['subtotal']) && $order['subtotal'] !== null
        ? bv_mobile_money_value($order['subtotal'])
        : (isset($order['subtotal_before_discount']) && $order['subtotal_before_discount'] !== null
            ? bv_mobile_money_value($order['subtotal_before_discount'])
            : $total);

    $discountAmount = isset($order['discount_amount']) && $order['discount_amount'] !== null
        ? bv_mobile_money_value($order['discount_amount'])
        : bv_mobile_money_value($order['seller_discount_total'] ?? 0);

    $responseOrder = [
        'id' => (int) ($order['id'] ?? 0),
        'order_code' => (string) ($order['order_code'] ?? ''),
        'status' => (string) ($order['status'] ?? ''),
        'payment_status' => (string) ($order['payment_status'] ?? ''),
        'payment_provider' => $order['payment_provider'] !== null ? (string) $order['payment_provider'] : null,
        'currency' => (string) ($order['currency'] ?? 'USD'),
        'subtotal' => $subtotal,
        'shipping_amount' => bv_mobile_money_value($order['shipping_amount'] ?? 0),
        'discount_amount' => $discountAmount,
        'total' => $total,
        'item_count' => count($items),
        'seller_count' => count($sellerIds),
        'fulfillment_status' => $fulfillmentStatus,
        'created_at' => $order['created_at'] !== null ? (string) $order['created_at'] : null,
        'paid_at' => $order['paid_at'] !== null ? (string) $order['paid_at'] : null,
        'updated_at' => $order['updated_at'] !== null ? (string) $order['updated_at'] : null,
    ];

    $orderForActions = $responseOrder;
    $orderForActions['fulfillment_status'] = $fulfillmentStatus;

    bv_mobile_json(200, [
        'ok' => true,
        'data' => [
            'buyer' => $buyer,
            'order' => $responseOrder,
            'shipping' => bv_mobile_build_shipping($order),
            'items' => $items,
            'refunds' => $refunds,
            'actions' => bv_mobile_build_actions($orderForActions, $items, $refunds),
        ],
    ]);
} catch (Throwable $e) {
    bv_mobile_log($e->getMessage());
    $message = $e->getMessage() === 'PDO connection required.' ? 'PDO connection required.' : 'Server error.';
    bv_mobile_error('server_error', $message, 500);
}
