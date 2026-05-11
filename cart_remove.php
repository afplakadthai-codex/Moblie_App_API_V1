<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/cart_remove.php
// Authenticated endpoint to remove a listing/item from the DB-backed mobile cart.
//
// CRITICAL: Never uses $_SESSION. Never requires CSRF. Never redirects.
// Never outputs HTML. Never modifies stock, creates orders, or calls Stripe.
// =============================================================================

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

if (!function_exists('bvm_cart_remove_public_root')) {
    function bvm_cart_remove_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bvm_cart_remove_json')) {
    function bvm_cart_remove_json(int $statusCode, array $payload): void
    {
        if (!isset($payload['meta'])) {
            $payload['meta'] = [
                'api_version'  => 'mobile-v1',
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }

        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bvm_cart_remove_error')) {
    function bvm_cart_remove_error(string $code, string $message, int $statusCode = 400): void
    {
        bvm_cart_remove_json($statusCode, [
            'ok'    => false,
            'error' => [
                'code'    => $code,
                'message' => $message,
            ],
        ]);
    }
}

if (!function_exists('bvm_cart_remove_db')) {
    function bvm_cart_remove_db(): ?object
    {
        static $loaded = false;
        static $connection = null;

        if ($loaded) {
            return $connection;
        }
        $loaded = true;

        $publicRoot = bvm_cart_remove_public_root();
        $candidates = [
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
        ];

        foreach ($candidates as $cfg) {
            if (!is_file($cfg)) {
                continue;
            }

            $loader = static function (string $path): array {
                $db_host = $db_user = $db_pass = $db_name = $db_port = null;
                $host = $user = $pass = $name = $port = null;
                $DB_HOST = $DB_USER = $DB_PASS = $DB_NAME = $DB_PORT = null;
                $dsn = null;
                $pdo = $conn = $db = $mysqli = $link = null;
                /** @noinspection PhpIncludeInspection */
                @include $path;

                return compact(
                    'db_host', 'db_user', 'db_pass', 'db_name', 'db_port',
                    'host', 'user', 'pass', 'name', 'port',
                    'DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DB_PORT',
                    'dsn', 'pdo', 'conn', 'db', 'mysqli', 'link'
                );
            };

            $vars = $loader($cfg);

            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $key) {
                if (isset($vars[$key]) && ($vars[$key] instanceof PDO || $vars[$key] instanceof mysqli)) {
                    $connection = $vars[$key];
                    return $connection;
                }
            }

            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $key) {
                if (isset($GLOBALS[$key]) && ($GLOBALS[$key] instanceof PDO || $GLOBALS[$key] instanceof mysqli)) {
                    $connection = $GLOBALS[$key];
                    return $connection;
                }
            }

            $h  = $vars['db_host'] ?? $vars['host'] ?? $vars['DB_HOST'] ?? null;
            $u  = $vars['db_user'] ?? $vars['user'] ?? $vars['DB_USER'] ?? null;
            $p  = $vars['db_pass'] ?? $vars['pass'] ?? $vars['DB_PASS'] ?? null;
            $n  = $vars['db_name'] ?? $vars['name'] ?? $vars['DB_NAME'] ?? null;
            $pt = (int) ($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);

            if ($h && $u !== null && $n) {
                try {
                    $pdo = new PDO(
                        "mysql:host={$h};port={$pt};dbname={$n};charset=utf8mb4",
                        (string) $u,
                        (string) $p,
                        [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES   => false,
                        ]
                    );
                    $connection = $pdo;
                    return $connection;
                } catch (Throwable $e) {
                    error_log('[BVM Cart Remove] PDO connection failed: ' . $e->getMessage());
                }

                if (class_exists('mysqli')) {
                    try {
                        $mysqli = @new mysqli((string) $h, (string) $u, (string) $p, (string) $n, $pt ?: 3306);
                        if (!$mysqli->connect_errno) {
                            $mysqli->set_charset('utf8mb4');
                            $connection = $mysqli;
                            return $connection;
                        }
                    } catch (Throwable $e) {
                        error_log('[BVM Cart Remove] mysqli connection failed: ' . $e->getMessage());
                    }
                }
            }
        }

        return null;
    }
}

if (!function_exists('bvm_cart_remove_fetch_all')) {
    function bvm_cart_remove_fetch_all(object $db, string $sql, array $params = []): array
    {
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            if ($params) {
                $types = str_repeat('s', count($params));
                $values = array_map(static fn($value): string => (string) $value, $params);
                $stmt->bind_param($types, ...$values);
            }
            if (!$stmt->execute()) {
                $stmt->close();
                return [];
            }
            $result = $stmt->get_result();
            $rows = $result ? ($result->fetch_all(MYSQLI_ASSOC) ?: []) : [];
            if ($result) {
                $result->free();
            }
            $stmt->close();
            return $rows;
        }

        return [];
    }
}

if (!function_exists('bvm_cart_remove_fetch_one')) {
    function bvm_cart_remove_fetch_one(object $db, string $sql, array $params = []): ?array
    {
        $rows = bvm_cart_remove_fetch_all($db, $sql, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('bvm_cart_remove_execute')) {
    function bvm_cart_remove_execute(object $db, string $sql, array $params = []): int
    {
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        }

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return 0;
            }
            if ($params) {
                $types = str_repeat('s', count($params));
                $values = array_map(static fn($value): string => (string) $value, $params);
                $stmt->bind_param($types, ...$values);
            }
            $ok = $stmt->execute();
            $affected = $ok ? (int) $stmt->affected_rows : 0;
            $stmt->close();
            return $affected;
        }

        return 0;
    }
}

if (!function_exists('bvm_cart_remove_begin')) {
    function bvm_cart_remove_begin(object $db): void
    {
        if ($db instanceof PDO) {
            $db->beginTransaction();
            return;
        }
        if ($db instanceof mysqli) {
            $db->begin_transaction();
        }
    }
}

if (!function_exists('bvm_cart_remove_commit')) {
    function bvm_cart_remove_commit(object $db): void
    {
        if ($db instanceof PDO) {
            $db->commit();
            return;
        }
        if ($db instanceof mysqli) {
            $db->commit();
        }
    }
}

if (!function_exists('bvm_cart_remove_rollback')) {
    function bvm_cart_remove_rollback(object $db): void
    {
        try {
            if ($db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
                return;
            }
            if ($db instanceof mysqli) {
                $db->rollback();
            }
        } catch (Throwable $e) {
            error_log('[BVM Cart Remove] rollback failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('bvm_cart_remove_table_exists')) {
    function bvm_cart_remove_table_exists(object $db, string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare('SELECT 1 FROM `' . $table . '` LIMIT 1');
                $stmt->execute();
                return true;
            }

            if ($db instanceof mysqli) {
                $stmt = $db->prepare('SELECT 1 FROM `' . $table . '` LIMIT 1');
                if (!$stmt) {
                    return false;
                }
                $ok = $stmt->execute();
                $stmt->close();
                return (bool) $ok;
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }
}

if (!function_exists('bvm_cart_remove_columns')) {
    function bvm_cart_remove_columns(object $db, string $table): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return [];
        }

        static $cache = [];
        $cacheKey = spl_object_id($db) . ':' . $table;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $columns = [];
        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare('SHOW COLUMNS FROM `' . $table . '`');
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (isset($row['Field'])) {
                        $columns[] = (string) $row['Field'];
                    }
                }
            } elseif ($db instanceof mysqli) {
                $stmt = $db->prepare('SHOW COLUMNS FROM `' . $table . '`');
                if ($stmt && $stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            if (isset($row['Field'])) {
                                $columns[] = (string) $row['Field'];
                            }
                        }
                        $result->free();
                    }
                    $stmt->close();
                }
            }
        } catch (Throwable $e) {
            $columns = [];
        }

        $cache[$cacheKey] = $columns;
        return $columns;
    }
}

if (!function_exists('bvm_cart_remove_has_col')) {
    function bvm_cart_remove_has_col(array $columns, string $column): bool
    {
        return in_array($column, $columns, true);
    }
}

if (!function_exists('bvm_cart_remove_pick_col')) {
    function bvm_cart_remove_pick_col(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if (bvm_cart_remove_has_col($columns, $column)) {
                return $column;
            }
        }
        return null;
    }
}

if (!function_exists('bvm_cart_remove_int')) {
    function bvm_cart_remove_int($value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

if (!function_exists('bvm_cart_remove_string')) {
    function bvm_cart_remove_string($value): string
    {
        return trim((string) ($value ?? ''));
    }
}

if (!function_exists('bvm_cart_remove_money')) {
    function bvm_cart_remove_money($value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }
        return round((float) $value, 2);
    }
}

if (!function_exists('bvm_cart_remove_abs_url')) {
    function bvm_cart_remove_abs_url(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $base = rtrim((defined('BV_SITE_URL') ? (string) BV_SITE_URL : 'https://www.bettavaro.com'), '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('bvm_cart_remove_listing_url')) {
    function bvm_cart_remove_listing_url($listingId, ?string $slug = null): string
    {
        $base = rtrim((defined('BV_SITE_URL') ? (string) BV_SITE_URL : 'https://www.bettavaro.com'), '/');
        $cleanSlug = trim((string) $slug);
        $id = bvm_cart_remove_int($listingId);

        if ($cleanSlug !== '') {
            return $base . '/listing.php?slug=' . urlencode($cleanSlug);
        }
        if ($id > 0) {
            return $base . '/listing.php?id=' . $id;
        }
        return $base . '/listing.php';
    }
}

if (!function_exists('bvm_cart_remove_auth')) {
    function bvm_cart_remove_auth(object $db): array
    {
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            bvm_cart_remove_error('unauthorized', 'Unauthorized.', 401);
        }

        $token = trim((string) $matches[1]);
        if ($token === '') {
            bvm_cart_remove_error('unauthorized', 'Unauthorized.', 401);
        }

        $tokenHash = hash('sha256', $token);
        $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.role
                FROM mobile_auth_tokens mat
                INNER JOIN users u ON u.id = mat.user_id
                WHERE mat.token_hash = ?
                  AND mat.revoked_at IS NULL
                  AND mat.expires_at > NOW()
                  AND u.account_status = 'active'
                LIMIT 1";

        try {
            $row = bvm_cart_remove_fetch_one($db, $sql, [$tokenHash]);
        } catch (Throwable $e) {
            error_log('[BVM Cart Remove] auth lookup failed: ' . $e->getMessage());
            bvm_cart_remove_error('server_error', 'Unable to authenticate request.', 500);
        }

        if (!$row) {
            bvm_cart_remove_error('unauthorized', 'Unauthorized.', 401);
        }

        $name = trim(trim((string) ($row['first_name'] ?? '')) . ' ' . trim((string) ($row['last_name'] ?? '')));
        if ($name === '') {
            $name = trim((string) ($row['email'] ?? ''));
        }

        return [
            'id'    => bvm_cart_remove_int($row['id'] ?? 0),
            'name'  => $name,
            'email' => bvm_cart_remove_string($row['email'] ?? ''),
            'role'  => bvm_cart_remove_string($row['role'] ?? 'user') ?: 'user',
        ];
    }
}

if (!function_exists('bvm_cart_remove_input')) {
    function bvm_cart_remove_input(): array
    {
        $data = $_POST;
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        $raw = (string) file_get_contents('php://input');
        $trimmedRaw = trim($raw);

        if (str_contains($contentType, 'application/json') || ($trimmedRaw !== '' && $trimmedRaw[0] === '{')) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                bvm_cart_remove_error('invalid_json', 'Invalid JSON request body.', 400);
            }
            $data = $decoded;
        } elseif (!$data && $raw !== '') {
            parse_str($raw, $parsed);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }

        $cartItemId = bvm_cart_remove_int($data['cart_item_id'] ?? 0);
        $listingId = bvm_cart_remove_int($data['listing_id'] ?? 0);

        if ($cartItemId <= 0 && $listingId <= 0) {
            bvm_cart_remove_error('invalid_request', 'cart_item_id or listing_id is required.', 422);
        }

        return [
            'cart_item_id' => $cartItemId > 0 ? $cartItemId : null,
            'listing_id'   => $listingId > 0 ? $listingId : null,
        ];
    }
}

if (!function_exists('bvm_cart_remove_fetch_active_cart')) {
    function bvm_cart_remove_fetch_active_cart(object $db, int $buyerId): ?array
    {
        $cartCols = bvm_cart_remove_columns($db, 'mobile_carts');
        $userCol = bvm_cart_remove_pick_col($cartCols, ['user_id', 'buyer_id', 'buyer_user_id', 'customer_id', 'member_id']);
        if (!$userCol || !bvm_cart_remove_has_col($cartCols, 'id')) {
            bvm_cart_remove_error('server_error', 'Mobile cart table is not configured.', 500);
        }

        $where = '`' . $userCol . '` = ?';
        $params = [$buyerId];
        if (bvm_cart_remove_has_col($cartCols, 'status')) {
            $where .= ' AND `status` = ?';
            $params[] = 'active';
        }
        if (bvm_cart_remove_has_col($cartCols, 'deleted_at')) {
            $where .= ' AND `deleted_at` IS NULL';
        }

        $orderCol = bvm_cart_remove_pick_col($cartCols, ['updated_at', 'created_at', 'id']) ?: 'id';
        return bvm_cart_remove_fetch_one(
            $db,
            'SELECT * FROM `mobile_carts` WHERE ' . $where . ' ORDER BY `' . $orderCol . '` DESC LIMIT 1',
            $params
        );
    }
}

if (!function_exists('bvm_cart_remove_build_cart_snapshot')) {
    function bvm_cart_remove_build_cart_snapshot(object $db, array $buyer, ?int $cartId): array
    {
        if (!$cartId || $cartId <= 0) {
            return [
                'buyer' => $buyer,
                'cart'  => [
                    'cart_id'      => null,
                    'source_table' => 'mobile_carts',
                    'items'        => [],
                    'summary'      => [
                        'item_count'     => 0,
                        'seller_count'   => 0,
                        'subtotal'       => 0.0,
                        'discount_total' => 0.0,
                        'shipping_total' => 0.0,
                        'total'          => 0.0,
                        'currency'       => 'USD',
                        'can_checkout'   => false,
                    ],
                ],
            ];
        }

        $itemCols = bvm_cart_remove_columns($db, 'mobile_cart_items');
        $listingCols = bvm_cart_remove_columns($db, 'listings');
        $itemCartCol = bvm_cart_remove_pick_col($itemCols, ['cart_id', 'mobile_cart_id']);

        if (!$itemCartCol || !bvm_cart_remove_has_col($itemCols, 'listing_id')) {
            bvm_cart_remove_error('server_error', 'Mobile cart items table is not configured.', 500);
        }

        $selectParts = ['ci.*', 'l.`id` AS `listing_join_id`'];
        $listingAliases = [
            'title'           => 'listing_title',
            'slug'            => 'listing_slug',
            'seller_id'       => 'listing_seller_id',
            'price'           => 'listing_price',
            'final_price'     => 'listing_final_price',
            'currency'        => 'listing_currency',
            'status'          => 'listing_status',
            'sale_status'     => 'listing_sale_status',
            'stock_available' => 'listing_stock_available',
            'stock_status'    => 'listing_stock_status',
            'cover_image'     => 'listing_cover_image',
            'image_url'       => 'listing_image_url',
            'main_image'      => 'listing_main_image',
            'thumbnail_url'   => 'listing_thumbnail_url',
            'user_id'         => 'listing_user_id',
            'owner_id'        => 'listing_owner_id',
            'seller_user_id'  => 'listing_seller_user_id',
        ];
        foreach ($listingAliases as $column => $alias) {
            if (bvm_cart_remove_has_col($listingCols, $column)) {
                $selectParts[] = 'l.`' . $column . '` AS `' . $alias . '`';
            }
        }

        $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM `mobile_cart_items` ci LEFT JOIN `listings` l ON ci.`listing_id` = l.`id` WHERE ci.`' . $itemCartCol . '` = ?';
        $itemOrderCol = bvm_cart_remove_pick_col($itemCols, ['updated_at', 'created_at', 'id']);
        if ($itemOrderCol) {
            $sql .= ' ORDER BY ci.`' . $itemOrderCol . '` ASC';
        }

        $rows = bvm_cart_remove_fetch_all($db, $sql, [$cartId]);
        $cartRow = bvm_cart_remove_fetch_one($db, 'SELECT * FROM `mobile_carts` WHERE `id` = ? LIMIT 1', [$cartId]) ?: [];
        $items = [];
        $sellers = [];
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $cartCurrency = strtoupper(bvm_cart_remove_string($cartRow['currency'] ?? '')) ?: 'USD';
        $currency = $cartCurrency;
        $canCheckout = count($rows) > 0;

        $qtyCol = bvm_cart_remove_pick_col($itemCols, ['quantity', 'qty']);
        $priceCol = bvm_cart_remove_pick_col($itemCols, ['unit_price', 'price', 'final_price']);
        $itemCurrencyCol = bvm_cart_remove_pick_col($itemCols, ['currency']);

        foreach ($rows as $row) {
            $quantity = max(1, bvm_cart_remove_int($row[$qtyCol] ?? 1));
            $rowCurrency = strtoupper(bvm_cart_remove_string($itemCurrencyCol ? ($row[$itemCurrencyCol] ?? '') : ''));
            if ($rowCurrency === '') {
                $rowCurrency = strtoupper(bvm_cart_remove_string($row['listing_currency'] ?? $currency)) ?: $currency;
            }
            if ($currency === '' && $rowCurrency !== '') {
                $currency = $rowCurrency;
            }

            $unitPrice = bvm_cart_remove_money($priceCol ? ($row[$priceCol] ?? 0) : 0);
            if ($unitPrice <= 0 && isset($row['listing_final_price'])) {
                $unitPrice = bvm_cart_remove_money($row['listing_final_price']);
            }
            if ($unitPrice <= 0 && isset($row['listing_price'])) {
                $unitPrice = bvm_cart_remove_money($row['listing_price']);
            }

            $lineSubtotal = bvm_cart_remove_money($unitPrice * $quantity);
            $discountAmount = 0.0;
            $lineTotal = bvm_cart_remove_money(max(0.0, $lineSubtotal - $discountAmount));
            $subtotal = bvm_cart_remove_money($subtotal + $lineSubtotal);
            $discountTotal = bvm_cart_remove_money($discountTotal + $discountAmount);

            $sellerId = bvm_cart_remove_int($row['listing_seller_id'] ?? ($row['listing_seller_user_id'] ?? ($row['listing_owner_id'] ?? ($row['listing_user_id'] ?? 0))));
            if ($sellerId > 0) {
                $sellers[$sellerId] = true;
            }

            $listingStatus = strtolower(bvm_cart_remove_string($row['listing_status'] ?? ''));
            $saleStatus = strtolower(bvm_cart_remove_string($row['listing_sale_status'] ?? ''));
            $stockAvailable = isset($row['listing_stock_available']) && is_numeric($row['listing_stock_available'])
                ? (int) $row['listing_stock_available']
                : null;
            $stockStatus = bvm_cart_remove_string($row['listing_stock_status'] ?? '');

            if ($listingStatus !== '' && !in_array($listingStatus, ['active', 'available', 'published'], true)) {
                $canCheckout = false;
            }
            if (in_array($saleStatus, ['sold', 'reserved'], true)) {
                $canCheckout = false;
            }
            if ($stockAvailable !== null && $stockAvailable <= 0) {
                $canCheckout = false;
            }

            $imagePath = bvm_cart_remove_string($row['listing_image_url'] ?? '');
            if ($imagePath === '') {
                $imagePath = bvm_cart_remove_string($row['listing_cover_image'] ?? '');
            }
            if ($imagePath === '') {
                $imagePath = bvm_cart_remove_string($row['listing_main_image'] ?? '');
            }
            if ($imagePath === '') {
                $imagePath = bvm_cart_remove_string($row['listing_thumbnail_url'] ?? '');
            }

            $listingId = bvm_cart_remove_int($row['listing_id'] ?? 0);
            if ($listingId > 0 && bvm_cart_remove_int($row['listing_join_id'] ?? 0) <= 0) {
                $canCheckout = false;
            }
            $slug = bvm_cart_remove_string($row['listing_slug'] ?? '');

            $items[] = [
                'cart_item_id'    => bvm_cart_remove_int($row['id'] ?? 0),
                'listing_id'      => $listingId,
                'title'           => bvm_cart_remove_string($row['listing_title'] ?? ''),
                'slug'            => $slug,
                'image_url'       => $imagePath !== '' ? bvm_cart_remove_abs_url($imagePath) : '',
                'seller_id'       => $sellerId > 0 ? $sellerId : null,
                'quantity'        => $quantity,
                'unit_price'      => $unitPrice,
                'line_subtotal'   => $lineSubtotal,
                'discount_amount' => $discountAmount,
                'line_total'      => $lineTotal,
                'currency'        => $rowCurrency,
                'stock_status'    => $stockStatus,
                'listing_status'  => $listingStatus,
                'listing_url'     => bvm_cart_remove_listing_url($listingId, $slug),
            ];
        }

        $itemCount = 0;
        foreach ($items as $item) {
            $itemCount += (int) $item['quantity'];
        }

        $shippingTotal = 0.0;
        $total = bvm_cart_remove_money($subtotal - $discountTotal + $shippingTotal);

        return [
            'buyer' => $buyer,
            'cart'  => [
                'cart_id'      => $cartId,
                'source_table' => 'mobile_carts',
                'items'        => $items,
                'summary'      => [
                    'item_count'     => $itemCount,
                    'seller_count'   => count($sellers),
                    'subtotal'       => $subtotal,
                    'discount_total' => $discountTotal,
                    'shipping_total' => $shippingTotal,
                    'total'          => $total,
                    'currency'       => $currency ?: 'USD',
                    'can_checkout'   => $canCheckout && $itemCount > 0,
                ],
            ],
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bvm_cart_remove_error('method_not_allowed', 'Only POST requests are accepted.', 405);
}

$db = bvm_cart_remove_db();
if (!$db) {
    bvm_cart_remove_error('server_error', 'Database connection is unavailable.', 500);
}

if (!bvm_cart_remove_table_exists($db, 'mobile_carts') || !bvm_cart_remove_table_exists($db, 'mobile_cart_items') || !bvm_cart_remove_table_exists($db, 'listings')) {
    bvm_cart_remove_error('server_error', 'Mobile cart tables are unavailable.', 500);
}

$buyer = bvm_cart_remove_auth($db);
$input = bvm_cart_remove_input();
$removed = [
    'success'       => true,
    'affected_rows' => 0,
];

try {
    bvm_cart_remove_begin($db);

    $cart = bvm_cart_remove_fetch_active_cart($db, (int) $buyer['id']);
    if (!$cart) {
        $snapshot = bvm_cart_remove_build_cart_snapshot($db, $buyer, null);
        bvm_cart_remove_commit($db);
        $snapshot['removed'] = $removed;
        bvm_cart_remove_json(200, [
            'ok'   => true,
            'data' => [
                'buyer'   => $snapshot['buyer'],
                'removed' => $snapshot['removed'],
                'cart'    => $snapshot['cart'],
            ],
        ]);
    }

    $cartId = bvm_cart_remove_int($cart['id'] ?? 0);
    if ($cartId <= 0) {
        throw new RuntimeException('Invalid cart id.');
    }

    $itemCols = bvm_cart_remove_columns($db, 'mobile_cart_items');
    $itemCartCol = bvm_cart_remove_pick_col($itemCols, ['cart_id', 'mobile_cart_id']);
    if (!$itemCartCol || !bvm_cart_remove_has_col($itemCols, 'id') || !bvm_cart_remove_has_col($itemCols, 'listing_id')) {
        throw new RuntimeException('Mobile cart items table is not configured.');
    }

    if ($input['cart_item_id'] !== null) {
        $affectedRows = bvm_cart_remove_execute(
            $db,
            'DELETE FROM `mobile_cart_items` WHERE `id` = ? AND `' . $itemCartCol . '` = ?',
            [$input['cart_item_id'], $cartId]
        );
    } else {
        $affectedRows = bvm_cart_remove_execute(
            $db,
            'DELETE FROM `mobile_cart_items` WHERE `listing_id` = ? AND `' . $itemCartCol . '` = ?',
            [$input['listing_id'], $cartId]
        );
    }

    $removed['affected_rows'] = max(0, $affectedRows);

    $cartCols = bvm_cart_remove_columns($db, 'mobile_carts');
    if (bvm_cart_remove_has_col($cartCols, 'updated_at')) {
        bvm_cart_remove_execute($db, 'UPDATE `mobile_carts` SET `updated_at` = ? WHERE `id` = ?', [date('Y-m-d H:i:s'), $cartId]);
    }

    $snapshot = bvm_cart_remove_build_cart_snapshot($db, $buyer, $cartId);
    bvm_cart_remove_commit($db);
} catch (Throwable $e) {
    bvm_cart_remove_rollback($db);
    error_log('[BVM Cart Remove] remove failed: ' . $e->getMessage());
    bvm_cart_remove_error('server_error', 'Unable to remove item from cart.', 500);
}

bvm_cart_remove_json(200, [
    'ok'   => true,
    'data' => [
        'buyer'   => $snapshot['buyer'],
        'removed' => $removed,
        'cart'    => $snapshot['cart'],
    ],
]);
