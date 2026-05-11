<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/checkout_start.php
// Starts checkout from an authenticated buyer's DB-backed mobile cart.
//
// CRITICAL: token auth only. No PHP sessions, CSRF checks, redirects, HTML,
// Stripe calls, sold marking, or paid-handler execution in this file.
// =============================================================================

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

if (!function_exists('bvm_checkout_start_public_root')) {
    function bvm_checkout_start_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bvm_checkout_start_project_root')) {
    function bvm_checkout_start_project_root(): string
    {
        return dirname(bvm_checkout_start_public_root());
    }
}

if (!function_exists('bvm_checkout_start_json')) {
    function bvm_checkout_start_json(int $statusCode, array $payload): void
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

if (!function_exists('bvm_checkout_start_error')) {
    function bvm_checkout_start_error(string $code, string $message, int $statusCode = 400): void
    {
        if (isset($GLOBALS['bvm_checkout_start_tx_db']) && is_object($GLOBALS['bvm_checkout_start_tx_db'])) {
            bvm_checkout_start_rollback($GLOBALS['bvm_checkout_start_tx_db']);
            unset($GLOBALS['bvm_checkout_start_tx_db']);
        }

        bvm_checkout_start_json($statusCode, [
            'ok'    => false,
            'error' => [
                'code'    => $code,
                'message' => $message,
            ],
        ]);
    }
}

if (!function_exists('bvm_checkout_start_db')) {
    function bvm_checkout_start_db(): ?object
    {
        static $loaded = false;
        static $connection = null;

        if ($loaded) {
            return $connection;
        }
        $loaded = true;

        $publicRoot = bvm_checkout_start_public_root();
        $projectRoot = bvm_checkout_start_project_root();
        $candidates = [
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $projectRoot . '/config/db.php',
            $projectRoot . '/includes/db.php',
            getcwd() . '/config/db.php',
            getcwd() . '/includes/db.php',
        ];

        foreach (array_values(array_unique($candidates)) as $cfg) {
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
                    if ($connection instanceof PDO) {
                        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    } elseif ($connection instanceof mysqli) {
                        @$connection->set_charset('utf8mb4');
                    }
                    return $connection;
                }
            }

            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $key) {
                if (isset($GLOBALS[$key]) && ($GLOBALS[$key] instanceof PDO || $GLOBALS[$key] instanceof mysqli)) {
                    $connection = $GLOBALS[$key];
                    if ($connection instanceof PDO) {
                        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    } elseif ($connection instanceof mysqli) {
                        @$connection->set_charset('utf8mb4');
                    }
                    return $connection;
                }
            }

            $dsnValue = $vars['dsn'] ?? null;
            $h  = $vars['db_host'] ?? $vars['host'] ?? $vars['DB_HOST'] ?? null;
            $u  = $vars['db_user'] ?? $vars['user'] ?? $vars['DB_USER'] ?? null;
            $p  = $vars['db_pass'] ?? $vars['pass'] ?? $vars['DB_PASS'] ?? null;
            $n  = $vars['db_name'] ?? $vars['name'] ?? $vars['DB_NAME'] ?? null;
            $pt = (int) ($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);

            try {
                if (is_string($dsnValue) && $dsnValue !== '' && $u !== null) {
                    $connection = new PDO($dsnValue, (string) $u, (string) $p, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]);
                    return $connection;
                }

                if ($h && $u !== null && $n) {
                    $connection = new PDO(
                        "mysql:host={$h};port={$pt};dbname={$n};charset=utf8mb4",
                        (string) $u,
                        (string) $p,
                        [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES   => false,
                        ]
                    );
                    return $connection;
                }
            } catch (Throwable $e) {
                error_log('[BVM Checkout Start] PDO connection failed: ' . $e->getMessage());
            }
        }

        return null;
    }
}

if (!function_exists('bvm_checkout_start_fetch_all')) {
    function bvm_checkout_start_fetch_all(object $db, string $sql, array $params = []): array
    {
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException($db->error ?: 'Unable to prepare SQL.');
            }
            if ($params) {
                $types = str_repeat('s', count($params));
                $values = array_map(static fn($value): string => (string) $value, $params);
                $stmt->bind_param($types, ...$values);
            }
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException($error ?: 'Unable to execute SQL.');
            }
            $result = $stmt->get_result();
            $rows = $result ? ($result->fetch_all(MYSQLI_ASSOC) ?: []) : [];
            if ($result) {
                $result->free();
            }
            $stmt->close();
            return $rows;
        }

        throw new RuntimeException('Unsupported database connection.');
    }
}

if (!function_exists('bvm_checkout_start_fetch_one')) {
    function bvm_checkout_start_fetch_one(object $db, string $sql, array $params = []): ?array
    {
        $rows = bvm_checkout_start_fetch_all($db, $sql, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('bvm_checkout_start_execute')) {
    function bvm_checkout_start_execute(object $db, string $sql, array $params = []): bool
    {
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            return $stmt->execute($params);
        }

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException($db->error ?: 'Unable to prepare SQL.');
            }
            if ($params) {
                $types = str_repeat('s', count($params));
                $values = array_map(static fn($value): string => (string) $value, $params);
                $stmt->bind_param($types, ...$values);
            }
            $ok = $stmt->execute();
            if (!$ok) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException($error ?: 'Unable to execute SQL.');
            }
            $stmt->close();
            return true;
        }

        throw new RuntimeException('Unsupported database connection.');
    }
}

if (!function_exists('bvm_checkout_start_last_insert_id')) {
    function bvm_checkout_start_last_insert_id(object $db): int
    {
        if ($db instanceof PDO) {
            return (int) $db->lastInsertId();
        }
        if ($db instanceof mysqli) {
            return (int) $db->insert_id;
        }
        return 0;
    }
}

if (!function_exists('bvm_checkout_start_begin')) {
    function bvm_checkout_start_begin(object $db): void
    {
        if ($db instanceof PDO) {
            $db->beginTransaction();
            return;
        }
        if ($db instanceof mysqli) {
            $db->begin_transaction();
            return;
        }
        throw new RuntimeException('Unsupported database connection.');
    }
}

if (!function_exists('bvm_checkout_start_commit')) {
    function bvm_checkout_start_commit(object $db): void
    {
        if ($db instanceof PDO) {
            $db->commit();
            return;
        }
        if ($db instanceof mysqli) {
            $db->commit();
            return;
        }
        throw new RuntimeException('Unsupported database connection.');
    }
}

if (!function_exists('bvm_checkout_start_rollback')) {
    function bvm_checkout_start_rollback(object $db): void
    {
        try {
            if ($db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            } elseif ($db instanceof mysqli) {
                $db->rollback();
            }
        } catch (Throwable $e) {
            error_log('[BVM Checkout Start] rollback failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('bvm_checkout_start_quote_ident')) {
    function bvm_checkout_start_quote_ident(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Unsafe SQL identifier.');
        }
        return '`' . $identifier . '`';
    }
}

if (!function_exists('bvm_checkout_start_table_exists')) {
    function bvm_checkout_start_table_exists(object $db, string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
                $stmt->execute([$table]);
                return (bool) $stmt->fetchColumn();
            }

            $rows = bvm_checkout_start_fetch_all($db, 'SHOW TABLES LIKE ?', [$table]);
            return !empty($rows);
        } catch (Throwable $e) {
            error_log('[BVM Checkout Start] table check failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bvm_checkout_start_columns')) {
    function bvm_checkout_start_columns(object $db, string $table): array
    {
        static $cache = [];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return [];
        }
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $columns = [];
        try {
            $rows = bvm_checkout_start_fetch_all($db, 'SHOW COLUMNS FROM ' . bvm_checkout_start_quote_ident($table));
            foreach ($rows as $row) {
                $field = (string) ($row['Field'] ?? '');
                if ($field !== '') {
                    $columns[$field] = $row;
                }
            }
        } catch (Throwable $e) {
            error_log('[BVM Checkout Start] column lookup failed: ' . $e->getMessage());
        }

        $cache[$table] = $columns;
        return $columns;
    }
}

if (!function_exists('bvm_checkout_start_has_col')) {
    function bvm_checkout_start_has_col(array $columns, string $column): bool
    {
        return array_key_exists($column, $columns);
    }
}

if (!function_exists('bvm_checkout_start_pick_col')) {
    function bvm_checkout_start_pick_col(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (bvm_checkout_start_has_col($columns, $candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}

if (!function_exists('bvm_checkout_start_string')) {
    function bvm_checkout_start_string($value, int $max = 255): string
    {
        $value = trim((string) ($value ?? ''));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }
        return substr($value, 0, $max);
    }
}

if (!function_exists('bvm_checkout_start_money')) {
    function bvm_checkout_start_money($value): float
    {
        return round(max(0.0, (float) $value), 2);
    }
}

if (!function_exists('bvm_checkout_start_auth')) {
    function bvm_checkout_start_auth(object $db): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $key => $value) {
                if (strtolower((string) $key) === 'authorization') {
                    $header = (string) $value;
                    break;
                }
            }
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim((string) $header), $matches)) {
            bvm_checkout_start_error('unauthorized', 'Unauthorized.', 401);
        }

        $token = trim((string) $matches[1]);
        if ($token === '') {
            bvm_checkout_start_error('unauthorized', 'Unauthorized.', 401);
        }

        $usersCols = bvm_checkout_start_columns($db, 'users');
        $profileCols = ['u.id', 'u.email', 'u.role', 'u.account_status'];
        foreach (['first_name', 'last_name', 'phone', 'whatsapp', 'address_line1', 'address_line2', 'road', 'subdistrict', 'district', 'province', 'postal_code', 'country'] as $col) {
            if (bvm_checkout_start_has_col($usersCols, $col)) {
                $profileCols[] = 'u.' . bvm_checkout_start_quote_ident($col);
            }
        }

        $row = bvm_checkout_start_fetch_one(
            $db,
            'SELECT mat.id AS mobile_token_id, mat.token_prefix, ' . implode(', ', $profileCols) . '
             FROM mobile_auth_tokens mat
             INNER JOIN users u ON u.id = mat.user_id
             WHERE mat.token_hash = ?
               AND mat.revoked_at IS NULL
               AND mat.expires_at > NOW()
               AND u.account_status = ?
             LIMIT 1',
            [hash('sha256', $token), 'active']
        );

        if (!$row) {
            bvm_checkout_start_error('unauthorized', 'Unauthorized.', 401);
        }

        $name = trim(trim((string) ($row['first_name'] ?? '')) . ' ' . trim((string) ($row['last_name'] ?? '')));
        if ($name === '') {
            $name = trim((string) ($row['email'] ?? ''));
        }

        return [
            'id'              => (int) ($row['id'] ?? 0),
            'name'            => $name,
            'first_name'      => bvm_checkout_start_string($row['first_name'] ?? '', 100),
            'last_name'       => bvm_checkout_start_string($row['last_name'] ?? '', 100),
            'email'           => bvm_checkout_start_string($row['email'] ?? '', 190),
            'phone'           => bvm_checkout_start_string($row['phone'] ?? '', 40),
            'whatsapp'        => bvm_checkout_start_string($row['whatsapp'] ?? '', 40),
            'role'            => bvm_checkout_start_string($row['role'] ?? 'user', 30) ?: 'user',
            'address_line1'   => bvm_checkout_start_string($row['address_line1'] ?? '', 255),
            'address_line2'   => bvm_checkout_start_string($row['address_line2'] ?? '', 255),
            'road'            => bvm_checkout_start_string($row['road'] ?? '', 255),
            'subdistrict'     => bvm_checkout_start_string($row['subdistrict'] ?? '', 150),
            'district'        => bvm_checkout_start_string($row['district'] ?? '', 150),
            'province'        => bvm_checkout_start_string($row['province'] ?? '', 150),
            'postal_code'     => bvm_checkout_start_string($row['postal_code'] ?? '', 20),
            'country'         => bvm_checkout_start_string($row['country'] ?? '', 120),
            'mobile_token_id' => (int) ($row['mobile_token_id'] ?? 0),
            'token_prefix'    => bvm_checkout_start_string($row['token_prefix'] ?? '', 16),
        ];
    }
}

if (!function_exists('bvm_checkout_start_input')) {
    function bvm_checkout_start_input(array $buyer): array
    {
        $raw = (string) file_get_contents('php://input');
        $data = [];
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        if ($raw !== '' && strpos($contentType, 'application/json') !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        } elseif (!empty($_POST)) {
            $data = $_POST;
        } elseif ($raw !== '') {
            parse_str($raw, $parsed);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }

        $value = static function (string $key, string $fallback = '', int $max = 255) use ($data): string {
            return bvm_checkout_start_string($data[$key] ?? $fallback, $max);
        };

        $shippingName = $value('shipping_name', (string) ($buyer['name'] ?? ''), 190);
        $shippingEmail = $value('shipping_email', (string) ($buyer['email'] ?? ''), 190);
        $shippingPhone = $value('shipping_phone', (string) ($buyer['phone'] ?: ($buyer['whatsapp'] ?? '')), 80);
        $line1 = $value('shipping_address_line1', (string) ($buyer['address_line1'] ?? ''), 255);
        $line2 = $value('shipping_address_line2', (string) ($buyer['address_line2'] ?? ''), 255);
        $city = $value('shipping_city', (string) ($buyer['district'] ?? ''), 150);
        $province = $value('shipping_province', (string) ($buyer['province'] ?? ''), 150);
        $postal = $value('shipping_postal_code', (string) ($buyer['postal_code'] ?? ''), 20);
        $country = $value('shipping_country', (string) ($buyer['country'] ?? ''), 120);

        return [
            'shipping_name'          => $shippingName,
            'shipping_email'         => $shippingEmail,
            'shipping_phone'         => $shippingPhone,
            'shipping_address_line1' => $line1,
            'shipping_address_line2' => $line2,
            'shipping_city'          => $city,
            'shipping_province'      => $province,
            'shipping_postal_code'   => $postal,
            'shipping_country'       => $country,
            'note'                   => $value('note', '', 2000),
            'ship_address'           => trim(implode("\n", array_filter([$line1, $line2, $city, $province, $postal, $country], static fn($v): bool => trim((string) $v) !== ''))),
        ];
    }
}

if (!function_exists('bvm_checkout_start_fetch_active_cart')) {
    function bvm_checkout_start_fetch_active_cart(object $db, int $buyerId): ?array
    {
        $cartCols = bvm_checkout_start_columns($db, 'mobile_carts');
        if (!$cartCols || !bvm_checkout_start_has_col($cartCols, 'id') || !bvm_checkout_start_has_col($cartCols, 'user_id')) {
            return null;
        }

        $orderCol = bvm_checkout_start_pick_col($cartCols, ['updated_at', 'created_at', 'id']) ?: 'id';
        $sql = 'SELECT * FROM `mobile_carts` WHERE `user_id` = ?';
        $params = [$buyerId];
        if (bvm_checkout_start_has_col($cartCols, 'status')) {
            $sql .= ' AND `status` = ?';
            $params[] = 'active';
        }
        $sql .= ' ORDER BY ' . bvm_checkout_start_quote_ident($orderCol) . ' DESC LIMIT 1 FOR UPDATE';

        return bvm_checkout_start_fetch_one($db, $sql, $params);
    }
}

if (!function_exists('bvm_checkout_start_fetch_cart_items')) {
    function bvm_checkout_start_fetch_cart_items(object $db, int $cartId): array
    {
        $itemCols = bvm_checkout_start_columns($db, 'mobile_cart_items');
        $listingCols = bvm_checkout_start_columns($db, 'listings');
        if (!$itemCols || !$listingCols) {
            return [];
        }

        $select = ['ci.*', 'l.id AS listing_join_id'];
        foreach (['seller_id', 'user_id', 'owner_id', 'seller_user_id', 'title', 'name', 'slug', 'price', 'final_price', 'currency', 'status', 'sale_status', 'stock_available', 'stock_total', 'stock_sold', 'cover_image', 'main_image', 'image_url', 'species', 'strain'] as $col) {
            if (bvm_checkout_start_has_col($listingCols, $col)) {
                $select[] = 'l.' . bvm_checkout_start_quote_ident($col) . ' AS listing_' . $col;
            }
        }

        return bvm_checkout_start_fetch_all(
            $db,
            'SELECT ' . implode(', ', $select) . '
             FROM `mobile_cart_items` ci
             INNER JOIN `listings` l ON l.id = ci.listing_id
             WHERE ci.cart_id = ?
             ORDER BY ci.id ASC',
            [$cartId]
        );
    }
}

if (!function_exists('bvm_checkout_start_validate_items')) {
    function bvm_checkout_start_validate_items(object $db, array $items, array $buyer, array $cart): array
    {
        $itemCols = bvm_checkout_start_columns($db, 'mobile_cart_items');
        $listingCols = bvm_checkout_start_columns($db, 'listings');
        $valid = [];
        $currency = '';
        $subtotal = 0.0;

        foreach ($items as $item) {
            $listingId = (int) ($item['listing_join_id'] ?? $item['listing_id'] ?? 0);
            if ($listingId <= 0) {
                bvm_checkout_start_error('listing_unavailable', 'A cart item is no longer available.', 409);
            }

            $status = strtolower((string) ($item['listing_status'] ?? ''));
            if (!in_array($status, ['active', 'available', 'published'], true)) {
                bvm_checkout_start_error('listing_unavailable', 'A listing in your cart is no longer available.', 409);
            }

            if (bvm_checkout_start_has_col($listingCols, 'sale_status')) {
                $saleStatus = strtolower((string) ($item['listing_sale_status'] ?? ''));
                if (in_array($saleStatus, ['sold', 'reserved'], true)) {
                    bvm_checkout_start_error('listing_unavailable', 'A listing in your cart is no longer available.', 409);
                }
            }

            if (bvm_checkout_start_has_col($listingCols, 'stock_available') && (int) ($item['listing_stock_available'] ?? 0) <= 0) {
                bvm_checkout_start_error('listing_unavailable', 'A listing in your cart is out of stock.', 409);
            }

            $sellerId = (int) ($item['listing_seller_id'] ?? $item['listing_user_id'] ?? $item['listing_owner_id'] ?? $item['listing_seller_user_id'] ?? 0);
            if ($sellerId > 0 && $sellerId === (int) $buyer['id']) {
                bvm_checkout_start_error('own_listing_not_allowed', 'You cannot checkout your own listing.', 409);
            }

            $itemCurrency = strtoupper(trim((string) ($item['listing_currency'] ?? $item['currency'] ?? $cart['currency'] ?? 'USD')));
            if ($itemCurrency === '') {
                $itemCurrency = 'USD';
            }
            if ($currency === '') {
                $currency = $itemCurrency;
            } elseif ($currency !== $itemCurrency) {
                bvm_checkout_start_error('currency_mismatch', 'All cart items must use the same currency.', 409);
            }

            $price = bvm_checkout_start_money($item['listing_price'] ?? 0);
            if (bvm_checkout_start_has_col($listingCols, 'final_price') && bvm_checkout_start_money($item['listing_final_price'] ?? 0) > 0) {
                $price = bvm_checkout_start_money($item['listing_final_price']);
            }
            if ($price <= 0) {
                bvm_checkout_start_error('listing_unavailable', 'A listing in your cart has an invalid price.', 409);
            }

            $cartPriceCol = bvm_checkout_start_pick_col($itemCols, ['unit_price', 'price']);
            $cartPrice = $cartPriceCol ? bvm_checkout_start_money($item[$cartPriceCol] ?? 0) : 0.0;
            if ($cartPriceCol && abs($cartPrice - $price) >= 0.01 && isset($item['id'])) {
                bvm_checkout_start_execute($db, 'UPDATE `mobile_cart_items` SET ' . bvm_checkout_start_quote_ident($cartPriceCol) . ' = ? WHERE `id` = ?', [$price, (int) $item['id']]);
            }
            if (bvm_checkout_start_has_col($itemCols, 'currency') && isset($item['id']) && strtoupper((string) ($item['currency'] ?? '')) !== $itemCurrency) {
                bvm_checkout_start_execute($db, 'UPDATE `mobile_cart_items` SET `currency` = ? WHERE `id` = ?', [$itemCurrency, (int) $item['id']]);
            }

            $lineTotal = bvm_checkout_start_money($price);
            $subtotal = bvm_checkout_start_money($subtotal + $lineTotal);

            $valid[] = [
                'cart_item_id' => (int) ($item['id'] ?? 0),
                'listing_id'   => $listingId,
                'seller_id'    => $sellerId,
                'title'        => bvm_checkout_start_string($item['listing_title'] ?? $item['listing_name'] ?? 'Listing #' . $listingId, 255),
                'slug'         => bvm_checkout_start_string($item['listing_slug'] ?? '', 255),
                'species'      => bvm_checkout_start_string($item['listing_species'] ?? '', 120),
                'strain'       => bvm_checkout_start_string($item['listing_strain'] ?? '', 120),
                'cover_image'  => bvm_checkout_start_string($item['listing_cover_image'] ?? $item['listing_main_image'] ?? $item['listing_image_url'] ?? '', 255),
                'quantity'     => 1,
                'unit_price'   => $price,
                'line_total'   => $lineTotal,
                'currency'     => $itemCurrency,
            ];
        }

        return [
            'items'       => $valid,
            'currency'    => $currency ?: strtoupper((string) ($cart['currency'] ?? 'USD')),
            'subtotal'    => bvm_checkout_start_money($subtotal),
            'item_count'  => count($valid),
        ];
    }
}

if (!function_exists('bvm_checkout_start_generate_order_code')) {
    function bvm_checkout_start_generate_order_code(): string
    {
        return 'BTV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('bvm_checkout_start_order_source_value')) {
    function bvm_checkout_start_order_source_value(array $orderCols): string
    {
        $type = strtolower((string) ($orderCols['order_source']['Type'] ?? ''));
        return strpos($type, "'mobile'") !== false ? 'mobile' : 'shop';
    }
}

if (!function_exists('bvm_checkout_start_put_if_col')) {
    function bvm_checkout_start_put_if_col(array &$data, array $columns, string $column, $value): void
    {
        if (bvm_checkout_start_has_col($columns, $column)) {
            $data[$column] = $value;
        }
    }
}

if (!function_exists('bvm_checkout_start_insert_order')) {
    function bvm_checkout_start_insert_order(object $db, array $buyer, array $input, array $summary): array
    {
        $orderCols = bvm_checkout_start_columns($db, 'orders');
        $subtotal = bvm_checkout_start_money($summary['subtotal'] ?? 0);
        $shipping = 0.0;
        $discount = 0.0;
        $total = bvm_checkout_start_money($subtotal + $shipping - $discount);
        $orderCode = bvm_checkout_start_generate_order_code();

        $data = [];
        bvm_checkout_start_put_if_col($data, $orderCols, 'user_id', (int) $buyer['id']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'order_code', $orderCode);
        bvm_checkout_start_put_if_col($data, $orderCols, 'status', 'pending_payment');
        bvm_checkout_start_put_if_col($data, $orderCols, 'payment_status', 'pending_payment');
        bvm_checkout_start_put_if_col($data, $orderCols, 'payment_provider', 'stripe');
        bvm_checkout_start_put_if_col($data, $orderCols, 'order_source', bvm_checkout_start_order_source_value($orderCols));
        bvm_checkout_start_put_if_col($data, $orderCols, 'currency', $summary['currency']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'subtotal', $subtotal);
        bvm_checkout_start_put_if_col($data, $orderCols, 'subtotal_before_discount', $subtotal);
        bvm_checkout_start_put_if_col($data, $orderCols, 'shipping_amount', $shipping);
        bvm_checkout_start_put_if_col($data, $orderCols, 'discount_amount', $discount);
        bvm_checkout_start_put_if_col($data, $orderCols, 'seller_discount_total', $discount);
        bvm_checkout_start_put_if_col($data, $orderCols, 'total', $total);
        bvm_checkout_start_put_if_col($data, $orderCols, 'buyer_name', $buyer['name']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'buyer_email', $buyer['email']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'buyer_phone', $buyer['phone']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'buyer_whatsapp', $buyer['whatsapp']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'country', $input['shipping_country']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'ship_name', $input['shipping_name'] ?: $buyer['name']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'ship_email', $input['shipping_email'] ?: $buyer['email']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'ship_phone', $input['shipping_phone']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'ship_address', $input['ship_address']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'ship_address_line1', $input['shipping_address_line1']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'ship_address_line2', $input['shipping_address_line2']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'ship_road', $buyer['road'] ?? '');
        bvm_checkout_start_put_if_col($data, $orderCols, 'ship_subdistrict', $buyer['subdistrict'] ?? '');
        bvm_checkout_start_put_if_col($data, $orderCols, 'ship_district', $input['shipping_city']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'ship_province', $input['shipping_province']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'ship_postal_code', $input['shipping_postal_code']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'ship_country', $input['shipping_country']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'note', $input['note']);
        bvm_checkout_start_put_if_col($data, $orderCols, 'session_token', 'mobile_token:' . (int) ($buyer['mobile_token_id'] ?? 0));

        if (bvm_checkout_start_has_col($orderCols, 'meta_json')) {
            $data['meta_json'] = json_encode([
                'source'          => 'mobile',
                'mobile_token_id' => (int) ($buyer['mobile_token_id'] ?? 0),
                'token_prefix'    => (string) ($buyer['token_prefix'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (!$data || !isset($data['order_code'])) {
            bvm_checkout_start_error('order_create_failed', 'Unable to create order.', 500);
        }

        $columns = array_keys($data);
        $sql = 'INSERT INTO `orders` (' . implode(', ', array_map('bvm_checkout_start_quote_ident', $columns)) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        bvm_checkout_start_execute($db, $sql, array_values($data));
        $orderId = bvm_checkout_start_last_insert_id($db);
        if ($orderId <= 0) {
            bvm_checkout_start_error('order_create_failed', 'Unable to create order.', 500);
        }

        return [
            'id'              => $orderId,
            'order_code'      => $orderCode,
            'status'          => 'pending_payment',
            'payment_status'  => 'pending_payment',
            'currency'        => (string) $summary['currency'],
            'subtotal'        => $subtotal,
            'shipping_amount' => $shipping,
            'discount_total'  => $discount,
            'total'           => $total,
            'item_count'      => (int) ($summary['item_count'] ?? 0),
        ];
    }
}

if (!function_exists('bvm_checkout_start_insert_order_items')) {
    function bvm_checkout_start_insert_order_items(object $db, int $orderId, array $items): void
    {
        $cols = bvm_checkout_start_columns($db, 'order_items');
        foreach ($items as $item) {
            $data = [];
            bvm_checkout_start_put_if_col($data, $cols, 'order_id', $orderId);
            bvm_checkout_start_put_if_col($data, $cols, 'listing_id', (int) $item['listing_id']);
            bvm_checkout_start_put_if_col($data, $cols, 'seller_id', (int) $item['seller_id']);
            bvm_checkout_start_put_if_col($data, $cols, 'item_type', 'listing');
            bvm_checkout_start_put_if_col($data, $cols, 'item_title', $item['title']);
            bvm_checkout_start_put_if_col($data, $cols, 'item_slug', $item['slug']);
            bvm_checkout_start_put_if_col($data, $cols, 'item_ref', 'LISTING-' . (int) $item['listing_id']);
            bvm_checkout_start_put_if_col($data, $cols, 'title_snapshot', $item['title']);
            bvm_checkout_start_put_if_col($data, $cols, 'name_snapshot', $item['title']);
            bvm_checkout_start_put_if_col($data, $cols, 'strain_snapshot', $item['strain']);
            bvm_checkout_start_put_if_col($data, $cols, 'species_snapshot', $item['species']);
            bvm_checkout_start_put_if_col($data, $cols, 'cover_image_snapshot', $item['cover_image']);
            bvm_checkout_start_put_if_col($data, $cols, 'currency', $item['currency']);
            bvm_checkout_start_put_if_col($data, $cols, 'quantity', 1);
            bvm_checkout_start_put_if_col($data, $cols, 'qty', 1);
            bvm_checkout_start_put_if_col($data, $cols, 'unit_price', $item['unit_price']);
            bvm_checkout_start_put_if_col($data, $cols, 'price', $item['unit_price']);
            bvm_checkout_start_put_if_col($data, $cols, 'line_total', $item['line_total']);
            bvm_checkout_start_put_if_col($data, $cols, 'subtotal', $item['line_total']);
            bvm_checkout_start_put_if_col($data, $cols, 'fulfillment_status', 'pending');
            if (bvm_checkout_start_has_col($cols, 'snapshot_json')) {
                $data['snapshot_json'] = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            }

            $columns = array_keys($data);
            if (!$columns || !isset($data['order_id'], $data['listing_id'])) {
                throw new RuntimeException('Order item schema is unsupported.');
            }
            $sql = 'INSERT INTO `order_items` (' . implode(', ', array_map('bvm_checkout_start_quote_ident', $columns)) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            bvm_checkout_start_execute($db, $sql, array_values($data));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bvm_checkout_start_error('method_not_allowed', 'Only POST requests are accepted.', 405);
}

try {
    $db = bvm_checkout_start_db();
    if (!$db) {
        bvm_checkout_start_error('server_error', 'Database connection is unavailable.', 500);
    }

    foreach (['mobile_auth_tokens', 'users', 'mobile_carts', 'mobile_cart_items', 'listings', 'orders', 'order_items'] as $requiredTable) {
        if (!bvm_checkout_start_table_exists($db, $requiredTable)) {
            bvm_checkout_start_error('server_error', 'Required database table is unavailable.', 500);
        }
    }

    $buyer = bvm_checkout_start_auth($db);
    $input = bvm_checkout_start_input($buyer);

    bvm_checkout_start_begin($db);
    $GLOBALS['bvm_checkout_start_tx_db'] = $db;
    try {
        $cart = bvm_checkout_start_fetch_active_cart($db, (int) $buyer['id']);
        if (!$cart) {
            bvm_checkout_start_rollback($db);
            bvm_checkout_start_error('cart_not_found', 'No active cart was found.', 404);
        }

        $cartId = (int) ($cart['id'] ?? 0);
        $items = bvm_checkout_start_fetch_cart_items($db, $cartId);
        if (!$items) {
            bvm_checkout_start_rollback($db);
            bvm_checkout_start_error('empty_cart', 'Your cart is empty.', 400);
        }

        $summary = bvm_checkout_start_validate_items($db, $items, $buyer, $cart);
        if (empty($summary['items'])) {
            bvm_checkout_start_rollback($db);
            bvm_checkout_start_error('empty_cart', 'Your cart is empty.', 400);
        }

        $order = bvm_checkout_start_insert_order($db, $buyer, $input, $summary);
        bvm_checkout_start_insert_order_items($db, (int) $order['id'], $summary['items']);

        $cartCols = bvm_checkout_start_columns($db, 'mobile_carts');
        if (bvm_checkout_start_has_col($cartCols, 'status')) {
            bvm_checkout_start_execute($db, 'UPDATE `mobile_carts` SET `status` = ? WHERE `id` = ? AND `user_id` = ?', ['converted', $cartId, (int) $buyer['id']]);
        }

        bvm_checkout_start_commit($db);
        unset($GLOBALS['bvm_checkout_start_tx_db']);

        bvm_checkout_start_json(200, [
            'ok'   => true,
            'data' => [
                'buyer' => [
                    'id'    => (int) $buyer['id'],
                    'name'  => (string) $buyer['name'],
                    'email' => (string) $buyer['email'],
                    'phone' => (string) $buyer['phone'],
                    'role'  => (string) $buyer['role'],
                ],
                'order' => $order,
                'next'  => [
                    'action'   => 'create_payment_session',
                    'endpoint' => '/api/mobile/v1/checkout_create_session.php',
                    'method'   => 'POST',
                    'payload'  => [
                        'order_id' => (int) $order['id'],
                    ],
                ],
            ],
        ]);
    } catch (Throwable $e) {
        bvm_checkout_start_rollback($db);
        unset($GLOBALS['bvm_checkout_start_tx_db']);
        error_log('[BVM Checkout Start] checkout failed: ' . $e->getMessage());
        bvm_checkout_start_error('server_error', 'Unable to start checkout.', 500);
    }
} catch (Throwable $e) {
    error_log('[BVM Checkout Start] fatal error: ' . $e->getMessage());
    bvm_checkout_start_error('server_error', 'A server error occurred.', 500);
}
