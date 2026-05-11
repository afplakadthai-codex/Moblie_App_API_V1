<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/checkout_create_session.php
// Creates a Stripe Checkout Session for an existing pending mobile order.
//
// CRITICAL: token auth only. No PHP sessions, CSRF checks, redirects, HTML,
// paid marking, listing sold marking, paid-handler execution, or order creation.
// =============================================================================

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

if (!function_exists('bvm_checkout_session_public_root')) {
    function bvm_checkout_session_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bvm_checkout_session_project_root')) {
    function bvm_checkout_session_project_root(): string
    {
        return dirname(bvm_checkout_session_public_root());
    }
}

if (!function_exists('bvm_checkout_session_quote_ident')) {
    function bvm_checkout_session_quote_ident(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

if (!function_exists('bvm_checkout_session_json')) {
    function bvm_checkout_session_json(int $statusCode, array $payload): void
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

if (!function_exists('bvm_checkout_session_error')) {
    function bvm_checkout_session_error(string $code, string $message, int $statusCode = 400): void
    {
        bvm_checkout_session_json($statusCode, [
            'ok'    => false,
            'error' => [
                'code'    => $code,
                'message' => $message,
            ],
        ]);
    }
}

if (!function_exists('bvm_checkout_session_db')) {
    function bvm_checkout_session_db(): ?object
    {
        static $loaded = false;
        static $connection = null;

        if ($loaded) {
            return $connection;
        }
        $loaded = true;

        $publicRoot = bvm_checkout_session_public_root();
        $projectRoot = bvm_checkout_session_project_root();
        $candidates = [
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $publicRoot . '/includes/config.php',
            $projectRoot . '/config/db.php',
            $projectRoot . '/includes/db.php',
            $projectRoot . '/includes/config.php',
            getcwd() . '/config/db.php',
            getcwd() . '/includes/db.php',
            getcwd() . '/includes/config.php',
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
                        $connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
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
                        $connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
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

            if (is_string($dsnValue) && $dsnValue !== '' && $u !== null) {
                try {
                    $pdo = new PDO($dsnValue, (string) $u, (string) $p, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]);
                    $connection = $pdo;
                    return $connection;
                } catch (Throwable $e) {
                    error_log('[BV Mobile Checkout Session] PDO DSN connect failed: ' . $e->getMessage());
                }
            }

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
                    error_log('[BV Mobile Checkout Session] PDO connect failed: ' . $e->getMessage());
                }
            }

            if ($h && $u !== null && $n && function_exists('mysqli_connect')) {
                try {
                    $m = @new mysqli((string) $h, (string) $u, (string) $p, (string) $n, $pt ?: 3306);
                    if (!$m->connect_errno) {
                        $m->set_charset('utf8mb4');
                        $connection = $m;
                        return $connection;
                    }
                    error_log('[BV Mobile Checkout Session] mysqli connect failed: ' . $m->connect_error);
                } catch (Throwable $e) {
                    error_log('[BV Mobile Checkout Session] mysqli exception: ' . $e->getMessage());
                }
            }
        }

        return null;
    }
}

if (!function_exists('bvm_checkout_session_query_row')) {
    function bvm_checkout_session_query_row(object $db, string $sql, array $params = []): ?array
    {
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Database prepare failed.');
            }
            if ($params) {
                $types = '';
                $values = [];
                foreach ($params as $param) {
                    if (is_int($param)) {
                        $types .= 'i';
                    } elseif (is_float($param)) {
                        $types .= 'd';
                    } else {
                        $types .= 's';
                    }
                    $values[] = $param;
                }
                $stmt->bind_param($types, ...$values);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            if ($res instanceof mysqli_result) {
                $res->free();
            }
            $stmt->close();
            return is_array($row) ? $row : null;
        }

        throw new RuntimeException('Unsupported database connection.');
    }
}

if (!function_exists('bvm_checkout_session_exec')) {
    function bvm_checkout_session_exec(object $db, string $sql, array $params = []): bool
    {
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            return $stmt->execute($params);
        }

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('Database prepare failed.');
            }
            if ($params) {
                $types = '';
                $values = [];
                foreach ($params as $param) {
                    if (is_int($param)) {
                        $types .= 'i';
                    } elseif (is_float($param)) {
                        $types .= 'd';
                    } else {
                        $types .= 's';
                    }
                    $values[] = $param;
                }
                $stmt->bind_param($types, ...$values);
            }
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        throw new RuntimeException('Unsupported database connection.');
    }
}

if (!function_exists('bvm_checkout_session_table_exists')) {
    function bvm_checkout_session_table_exists(object $db, string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
                $stmt->execute([$table]);
                return (bool) $stmt->fetchColumn();
            }

            if ($db instanceof mysqli) {
                $sql = 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1';
                $stmt = $db->prepare($sql);
                if (!$stmt) {
                    return false;
                }
                $stmt->bind_param('s', $table);
                $stmt->execute();
                $res = $stmt->get_result();
                $exists = $res && $res->num_rows > 0;
                if ($res instanceof mysqli_result) {
                    $res->free();
                }
                $stmt->close();
                return $exists;
            }
        } catch (Throwable $e) {
            error_log('[BV Mobile Checkout Session] table exists failed: ' . $e->getMessage());
        }

        return false;
    }
}

if (!function_exists('bvm_checkout_session_columns')) {
    function bvm_checkout_session_columns(object $db, string $table): array
    {
        static $cache = [];

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return [];
        }

        $cacheKey = spl_object_id($db) . ':' . $table;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $cols = [];
        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $stmt->execute([$table]);
                while (($name = $stmt->fetchColumn()) !== false) {
                    $cols[(string) $name] = true;
                }
            } elseif ($db instanceof mysqli) {
                $sql = 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?';
                $stmt = $db->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param('s', $table);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($res && ($row = $res->fetch_assoc())) {
                        $cols[(string) $row['COLUMN_NAME']] = true;
                    }
                    if ($res instanceof mysqli_result) {
                        $res->free();
                    }
                    $stmt->close();
                }
            }
        } catch (Throwable $e) {
            error_log('[BV Mobile Checkout Session] columns failed: ' . $e->getMessage());
        }

        $cache[$cacheKey] = $cols;
        return $cols;
    }
}

if (!function_exists('bvm_checkout_session_has_col')) {
    function bvm_checkout_session_has_col(object $db, string $table, string $column): bool
    {
        $cols = bvm_checkout_session_columns($db, $table);
        return isset($cols[$column]);
    }
}

if (!function_exists('bvm_checkout_session_auth')) {
    function bvm_checkout_session_auth(object $db): array
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($auth === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $key => $value) {
                if (strcasecmp((string) $key, 'Authorization') === 0) {
                    $auth = (string) $value;
                    break;
                }
            }
        }

        if (!is_string($auth) || !preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
            bvm_checkout_session_error('unauthorized', 'Missing or invalid bearer token.', 401);
        }

        $token = trim($m[1]);
        if ($token === '') {
            bvm_checkout_session_error('unauthorized', 'Missing bearer token.', 401);
        }

        foreach (['mobile_auth_tokens', 'users'] as $table) {
            if (!bvm_checkout_session_table_exists($db, $table)) {
                bvm_checkout_session_error('server_error', 'Authentication tables are unavailable.', 500);
            }
        }
        foreach ([['mobile_auth_tokens', 'token_hash'], ['mobile_auth_tokens', 'user_id'], ['mobile_auth_tokens', 'revoked_at'], ['mobile_auth_tokens', 'expires_at'], ['users', 'id'], ['users', 'account_status']] as $required) {
            if (!bvm_checkout_session_has_col($db, $required[0], $required[1])) {
                bvm_checkout_session_error('server_error', 'Authentication schema is incomplete.', 500);
            }
        }

        $tokenHash = hash('sha256', $token);
        $userCols = bvm_checkout_session_columns($db, 'users');
        $select = [
            'mat.' . bvm_checkout_session_quote_ident('id') . ' AS token_id',
            'u.' . bvm_checkout_session_quote_ident('id') . ' AS id',
            'u.' . bvm_checkout_session_quote_ident('email') . ' AS email',
            'u.' . bvm_checkout_session_quote_ident('role') . ' AS role',
            'u.' . bvm_checkout_session_quote_ident('account_status') . ' AS account_status',
        ];
        $select[] = isset($userCols['first_name']) ? 'u.' . bvm_checkout_session_quote_ident('first_name') . ' AS first_name' : "'' AS first_name";
        $select[] = isset($userCols['last_name']) ? 'u.' . bvm_checkout_session_quote_ident('last_name') . ' AS last_name' : "'' AS last_name";
        $select[] = isset($userCols['name']) ? 'u.' . bvm_checkout_session_quote_ident('name') . ' AS name' : "'' AS name";

        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM ' . bvm_checkout_session_quote_ident('mobile_auth_tokens') . ' mat'
            . ' INNER JOIN ' . bvm_checkout_session_quote_ident('users') . ' u ON u.' . bvm_checkout_session_quote_ident('id') . ' = mat.' . bvm_checkout_session_quote_ident('user_id')
            . ' WHERE mat.' . bvm_checkout_session_quote_ident('token_hash') . ' = ?'
            . ' AND mat.' . bvm_checkout_session_quote_ident('revoked_at') . ' IS NULL'
            . ' AND mat.' . bvm_checkout_session_quote_ident('expires_at') . ' > UTC_TIMESTAMP()'
            . ' AND u.' . bvm_checkout_session_quote_ident('account_status') . ' = ?'
            . ' LIMIT 1';

        $row = bvm_checkout_session_query_row($db, $sql, [$tokenHash, 'active']);
        if (!$row) {
            bvm_checkout_session_error('unauthorized', 'Invalid, expired, or revoked token.', 401);
        }

        if (bvm_checkout_session_has_col($db, 'mobile_auth_tokens', 'last_used_at')) {
            try {
                bvm_checkout_session_exec(
                    $db,
                    'UPDATE ' . bvm_checkout_session_quote_ident('mobile_auth_tokens') . ' SET ' . bvm_checkout_session_quote_ident('last_used_at') . ' = UTC_TIMESTAMP() WHERE ' . bvm_checkout_session_quote_ident('id') . ' = ? LIMIT 1',
                    [(int) $row['token_id']]
                );
            } catch (Throwable $e) {
                error_log('[BV Mobile Checkout Session] last_used_at update failed: ' . $e->getMessage());
            }
        }

        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = trim(trim((string) ($row['first_name'] ?? '')) . ' ' . trim((string) ($row['last_name'] ?? '')));
        }

        return [
            'id'    => (int) $row['id'],
            'name'  => $name,
            'email' => (string) ($row['email'] ?? ''),
            'role'  => (string) ($row['role'] ?? 'user'),
        ];
    }
}

if (!function_exists('bvm_checkout_session_input')) {
    function bvm_checkout_session_input(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $raw = file_get_contents('php://input');
        $data = [];

        if (is_string($raw) && trim($raw) !== '' && stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        } elseif (!empty($_POST)) {
            $data = $_POST;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                parse_str($raw, $parsed);
                if (is_array($parsed)) {
                    $data = $parsed;
                }
            }
        }

        $orderId = isset($data['order_id']) ? filter_var($data['order_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($orderId === false || $orderId === null) {
            bvm_checkout_session_error('invalid_order', 'order_id is required and must be a positive integer.', 422);
        }

        return ['order_id' => (int) $orderId];
    }
}

if (!function_exists('bvm_checkout_session_fetch_order')) {
    function bvm_checkout_session_fetch_order(object $db, int $orderId, int $buyerId): ?array
    {
        if (!bvm_checkout_session_table_exists($db, 'orders')) {
            bvm_checkout_session_error('server_error', 'Orders table is unavailable.', 500);
        }
        foreach (['id', 'user_id', 'status', 'payment_status', 'total', 'currency'] as $column) {
            if (!bvm_checkout_session_has_col($db, 'orders', $column)) {
                bvm_checkout_session_error('server_error', 'Orders schema is incomplete.', 500);
            }
        }

        $sql = 'SELECT * FROM ' . bvm_checkout_session_quote_ident('orders')
            . ' WHERE ' . bvm_checkout_session_quote_ident('id') . ' = ?'
            . ' AND ' . bvm_checkout_session_quote_ident('user_id') . ' = ?'
            . ' LIMIT 1';

        return bvm_checkout_session_query_row($db, $sql, [$orderId, $buyerId]);
    }
}

if (!function_exists('bvm_checkout_session_count_order_items')) {
    function bvm_checkout_session_count_order_items(object $db, int $orderId): int
    {
        if (!bvm_checkout_session_table_exists($db, 'order_items')) {
            return 0;
        }
        if (!bvm_checkout_session_has_col($db, 'order_items', 'order_id')) {
            return 0;
        }

        $row = bvm_checkout_session_query_row(
            $db,
            'SELECT COUNT(*) AS item_count FROM ' . bvm_checkout_session_quote_ident('order_items') . ' WHERE ' . bvm_checkout_session_quote_ident('order_id') . ' = ?',
            [$orderId]
        );

        return (int) ($row['item_count'] ?? 0);
    }
}

if (!function_exists('bvm_checkout_session_money_to_stripe_amount')) {
    function bvm_checkout_session_money_to_stripe_amount($amount, string $currency): int
    {
        $currency = strtoupper(trim($currency));
        $zeroDecimal = [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
            'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ];

        $numeric = (float) $amount;
        $multiplier = in_array($currency, $zeroDecimal, true) ? 1 : 100;

        return (int) round($numeric * $multiplier);
    }
}

if (!function_exists('bvm_checkout_session_base_url')) {
    function bvm_checkout_session_base_url(): string
    {
        if (defined('BETTAVARO_BASE_URL') && is_string(BETTAVARO_BASE_URL) && BETTAVARO_BASE_URL !== '') {
            return rtrim(BETTAVARO_BASE_URL, '/');
        }
        if (defined('APP_URL') && is_string(APP_URL) && APP_URL !== '') {
            return rtrim(APP_URL, '/');
        }
        if (defined('BASE_URL') && is_string(BASE_URL) && BASE_URL !== '') {
            return rtrim(BASE_URL, '/');
        }
        if (defined('SITE_URL') && is_string(SITE_URL) && SITE_URL !== '') {
            return rtrim(SITE_URL, '/');
        }

        return 'https://www.bettavaro.com';
    }
}

if (!function_exists('bvm_checkout_session_stripe_debug_log')) {
    function bvm_checkout_session_stripe_debug_log(string $message): void
    {
        $publicRoot = bvm_checkout_session_public_root();
       $logDir = $publicRoot . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
       @file_put_contents($logDir . '/mobile_checkout_stripe.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('bvm_checkout_session_stripe_response_log')) {
    function bvm_checkout_session_stripe_response_log(int $httpStatus, $rawResponse, ?array $response): void
    {
        $publicRoot = bvm_checkout_session_public_root();
        $logDir = $publicRoot . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $responseJson = is_string($rawResponse) ? $rawResponse : '';
        $hasUrl = is_array($response) && isset($response['url']) && trim((string) $response['url']) !== '';
        $hasId = is_array($response) && isset($response['id']) && trim((string) $response['id']) !== '';

        $entry = '[' . date('Y-m-d H:i:s') . ']'
            . ' http_status=' . $httpStatus
            . ' response_has_url=' . ($hasUrl ? 'yes' : 'no')
            . ' response_has_id=' . ($hasId ? 'yes' : 'no')
            . ' response_json=' . $responseJson
            . PHP_EOL;

        @file_put_contents($logDir . '/mobile_checkout_stripe_response.log', $entry, FILE_APPEND | LOCK_EX);
    }
}
		

if (!function_exists('bvm_checkout_session_apply_url_placeholders')) {
    function bvm_checkout_session_apply_url_placeholders(string $url, int $orderId, string $orderCode): string
    {
        return str_replace(
            ['{ORDER_ID}', '{order_id}', '{ORDER_CODE}', '{order_code}'],
            [(string) $orderId, (string) $orderId, $orderCode, $orderCode],
            $url
        );
    }
}

if (!function_exists('bvm_checkout_session_boot_stripe')) {
    function bvm_checkout_session_boot_stripe(): bool
    {
        $configPath = bvm_checkout_session_public_root() . '/includes/stripe_config.php';
        $exists = is_file($configPath);
        bvm_checkout_session_stripe_debug_log('checked_path=' . $configPath . ' exists=' . ($exists ? 'yes' : 'no'));

         if ($exists) {
            /** @noinspection PhpIncludeInspection */
            @include_once $configPath;
        }

        $hasSecretFunction = function_exists('bv_stripe_secret_key');
        $isReady = function_exists('bv_stripe_is_ready') && bv_stripe_is_ready();
        bvm_checkout_session_stripe_debug_log('stripe_config_loaded=' . ($exists ? 'yes' : 'no') . ' secret_function=' . ($hasSecretFunction ? 'yes' : 'no') . ' ready=' . ($isReady ? 'yes' : 'no'));

        return $hasSecretFunction && $isReady;
    }
}

if (!function_exists('bvm_checkout_session_create_stripe_session')) {
    function bvm_checkout_session_create_stripe_session(array $order, array $buyer): array
    {
        if (!bvm_checkout_session_boot_stripe()) {
             bvm_checkout_session_error('stripe_config_missing', 'Stripe configuration is unavailable.', 500);
        }

        if (!function_exists('curl_init')) {
            bvm_checkout_session_stripe_debug_log('Stripe API unavailable: curl extension missing');
            bvm_checkout_session_error('stripe_session_failed', 'Unable to create Stripe Checkout Session.', 502);
        }

        $secretKey = trim((string) bv_stripe_secret_key());
        if ($secretKey === '') {
            bvm_checkout_session_stripe_debug_log('Stripe API unavailable: empty secret key from configuration');
            bvm_checkout_session_error('stripe_config_missing', 'Stripe configuration is unavailable.', 500);
        }

        $orderId = (int) $order['id'];
        $orderCode = trim((string) ($order['order_code'] ?? ''));
        $currency = 'usd';
        $amount = bvm_checkout_session_money_to_stripe_amount($order['total'], (string) $order['currency']);
        $productName = $orderCode !== '' ? 'Bettavaro Order ' . $orderCode : 'Bettavaro Order ' . $orderId;


        $successUrl = function_exists('bv_stripe_success_url') ? trim((string) bv_stripe_success_url()) : '';
        $cancelUrl = function_exists('bv_stripe_cancel_url') ? trim((string) bv_stripe_cancel_url()) : '';

        if ($successUrl === '') {
            $successUrl = bvm_checkout_session_base_url() . '/payment-success.php?order_id=' . rawurlencode((string) $orderId) . '&source=mobile&session_id={CHECKOUT_SESSION_ID}';
        }
        if ($cancelUrl === '') {
            $cancelUrl = bvm_checkout_session_base_url() . '/payment-cancel.php?order_id=' . rawurlencode((string) $orderId) . '&source=mobile';
        }

        $successUrl = bvm_checkout_session_apply_url_placeholders($successUrl, $orderId, $orderCode);
        if (strpos($successUrl, '{CHECKOUT_SESSION_ID}') === false) {
            $successUrl .= (strpos($successUrl, '?') === false ? '?' : '&') . 'session_id={CHECKOUT_SESSION_ID}';
        }
        $cancelUrl = bvm_checkout_session_apply_url_placeholders($cancelUrl, $orderId, $orderCode);

        $payload = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'payment_method_types[]' => 'card',
            'line_items[0][price_data][currency]' => $currency,
            'line_items[0][price_data][product_data][name]' => $productName,
            'line_items[0][price_data][unit_amount]' => $amount,
            'line_items[0][quantity]' => 1,
            'metadata[order_id]' => (string) $orderId,
            'metadata[order_code]' => $orderCode,
            'metadata[user_id]' => (string) $buyer['id'],
            'metadata[source]' => 'mobile',
            'metadata[integration]' => 'mobile_api_v1',
            'client_reference_id' => (string) $orderId,
        ];

        if (!empty($buyer['email']) && filter_var($buyer['email'], FILTER_VALIDATE_EMAIL)) {
            $payload['customer_email'] = (string) $buyer['email'];
        }

        $updated = preg_replace('/[^0-9A-Za-z_\-]/', '', (string) ($order['updated_at'] ?? ''));
        if ($updated === '') {
            $updated = (string) time();
        }
      $idempotencyKey = 'mobile_checkout_order_' . $orderId . '_' . $updated;

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        if ($ch === false) {
            bvm_checkout_session_stripe_debug_log('Stripe API unavailable: curl_init failed');
            bvm_checkout_session_error('stripe_session_failed', 'Unable to create Stripe Checkout Session.', 502);
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/x-www-form-urlencoded',
                'Idempotency-Key: ' . $idempotencyKey,
            ],
            CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
        ]);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

         $response = null;
        if (is_string($rawResponse) && $rawResponse !== '') {
            $response = json_decode($rawResponse, true);
            if (!is_array($response)) {
                $response = null;
            }
        }
        bvm_checkout_session_stripe_response_log($httpStatus, $rawResponse, $response);		

        $stripeErrorType = '';
        $stripeErrorMessage = '';
        if (isset($response['error']) && is_array($response['error'])) {
            $stripeErrorType = isset($response['error']['type']) ? (string) $response['error']['type'] : '';
            $stripeErrorMessage = isset($response['error']['message']) ? (string) $response['error']['message'] : '';
        }

        if ($rawResponse === false || $httpStatus < 200 || $httpStatus >= 300 || !is_array($response)) {
            $message = 'Stripe API session create failed: http_status=' . $httpStatus;
            if ($stripeErrorType !== '') {
                $message .= ' error_type=' . $stripeErrorType;
            }
            if ($stripeErrorMessage !== '') {
                $message .= ' error_message=' . $stripeErrorMessage;
            }
            if ($curlError !== '') {
                $message .= ' curl_error=' . $curlError;
            }
            bvm_checkout_session_stripe_debug_log($message);
            bvm_checkout_session_error('stripe_session_failed', 'Unable to create Stripe Checkout Session.', 502);
        }

         $sessionId = isset($response['id']) ? trim((string) $response['id']) : '';
        $checkoutUrl = trim((string) ($response['url'] ?? ''));
        $responseDebug = 'Stripe API session response: response_has_url=' . ($checkoutUrl !== '' ? 'yes' : 'no')
            . ' response_has_id=' . ($sessionId !== '' ? 'yes' : 'no')
            . ' http_status=' . $httpStatus;

        if ($checkoutUrl === '') {
            bvm_checkout_session_stripe_debug_log($responseDebug);
            bvm_checkout_session_error('stripe_session_failed', 'Stripe Checkout Session response was incomplete.', 502);
        }
 
        bvm_checkout_session_stripe_debug_log($responseDebug);

        return [
            'session_id' => $sessionId, 
            'checkout_url' => $checkoutUrl,
        ];
    }
}

if (!function_exists('bvm_checkout_session_update_order_payment_reference')) {
    function bvm_checkout_session_update_order_payment_reference(object $db, int $orderId, string $sessionId, string $checkoutUrl): void
    {
        $sets = [];
        $params = [];

        if (bvm_checkout_session_has_col($db, 'orders', 'payment_provider')) {
            $sets[] = bvm_checkout_session_quote_ident('payment_provider') . ' = ?';
            $params[] = 'stripe';
        }
        if (bvm_checkout_session_has_col($db, 'orders', 'payment_reference')) {
            $sets[] = bvm_checkout_session_quote_ident('payment_reference') . ' = ?';
            $params[] = $sessionId;
        }
        foreach (['stripe_session_id', 'checkout_session_id'] as $column) {
            if (bvm_checkout_session_has_col($db, 'orders', $column)) {
                $sets[] = bvm_checkout_session_quote_ident($column) . ' = ?';
                $params[] = $sessionId;
            }
        }
        if (bvm_checkout_session_has_col($db, 'orders', 'stripe_checkout_url')) {
            $sets[] = bvm_checkout_session_quote_ident('stripe_checkout_url') . ' = ?';
            $params[] = $checkoutUrl;
        }
        if (bvm_checkout_session_has_col($db, 'orders', 'checkout_url')) {
            $sets[] = bvm_checkout_session_quote_ident('checkout_url') . ' = ?';
            $params[] = $checkoutUrl;
        }
        if (bvm_checkout_session_has_col($db, 'orders', 'stripe_session_status')) {
            $sets[] = bvm_checkout_session_quote_ident('stripe_session_status') . ' = ?';
            $params[] = 'open';
        }
        if (bvm_checkout_session_has_col($db, 'orders', 'checkout_session_status')) {
            $sets[] = bvm_checkout_session_quote_ident('checkout_session_status') . ' = ?';
            $params[] = 'open';
        }
        if (bvm_checkout_session_has_col($db, 'orders', 'payment_status')) {
            $sets[] = bvm_checkout_session_quote_ident('payment_status') . ' = CASE WHEN ' . bvm_checkout_session_quote_ident('payment_status') . ' IS NULL OR ' . bvm_checkout_session_quote_ident('payment_status') . " = '' THEN 'pending_payment' ELSE " . bvm_checkout_session_quote_ident('payment_status') . ' END';
        }
        if (bvm_checkout_session_has_col($db, 'orders', 'updated_at')) {
            $sets[] = bvm_checkout_session_quote_ident('updated_at') . ' = NOW()';
        }

        if (!$sets) {
            return;
        }

        $params[] = $orderId;
        $sql = 'UPDATE ' . bvm_checkout_session_quote_ident('orders')
            . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . bvm_checkout_session_quote_ident('id') . ' = ? LIMIT 1';

        bvm_checkout_session_exec($db, $sql, $params);
    }
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        bvm_checkout_session_error('method_not_allowed', 'Only POST requests are allowed.', 405);
    }

    $db = bvm_checkout_session_db();
    if (!$db) {
        bvm_checkout_session_error('server_error', 'Database connection is unavailable.', 500);
    }

    $buyer = bvm_checkout_session_auth($db);
    $input = bvm_checkout_session_input();
    $order = bvm_checkout_session_fetch_order($db, (int) $input['order_id'], (int) $buyer['id']);

    if (!$order) {
        bvm_checkout_session_error('order_not_found', 'Order was not found for the authenticated buyer.', 404);
    }

    $status = (string) ($order['status'] ?? '');
    if (!in_array($status, ['pending_payment', 'reserved'], true)) {
        bvm_checkout_session_error('order_not_payable', 'Order is not in a payable status.', 409);
    }

    $paymentStatus = (string) ($order['payment_status'] ?? '');
    if (!in_array($paymentStatus, ['pending_payment', 'unpaid', ''], true)) {
        bvm_checkout_session_error('order_not_payable', 'Order payment status is not payable.', 409);
    }

    $total = (float) ($order['total'] ?? 0);
    if ($total <= 0) {
        bvm_checkout_session_error('order_not_payable', 'Order total must be greater than zero.', 409);
    }

    $currency = strtoupper(trim((string) ($order['currency'] ?? '')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        bvm_checkout_session_error('invalid_order', 'Order currency must be a valid 3-letter code.', 422);
    }
    $order['currency'] = $currency;

    if (bvm_checkout_session_table_exists($db, 'order_items') && bvm_checkout_session_count_order_items($db, (int) $order['id']) < 1) {
        bvm_checkout_session_error('empty_order', 'Order must contain at least one item.', 409);
    }

    $session = bvm_checkout_session_create_stripe_session($order, $buyer);
    bvm_checkout_session_update_order_payment_reference($db, (int) $order['id'], $session['session_id'], $session['checkout_url']);

    $responsePaymentStatus = ($paymentStatus === '') ? 'pending_payment' : $paymentStatus;

    bvm_checkout_session_json(200, [
        'ok'   => true,
        'data' => [
            'buyer'   => [
                'id'    => (int) $buyer['id'],
                'name'  => (string) $buyer['name'],
                'email' => (string) $buyer['email'],
                'role'  => (string) $buyer['role'],
            ],
            'order'   => [
                'id'             => (int) $order['id'],
                'order_code'     => (string) ($order['order_code'] ?? ''),
                'status'         => $status,
                'payment_status' => $responsePaymentStatus,
                'currency'       => $currency,
                'total'          => round($total, 2),
            ],
            'payment' => [
                'provider'     => 'stripe',
                'session_id'   => $session['session_id'],
                'checkout_url' => $session['checkout_url'],
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('[BV Mobile Checkout Session] Server error: ' . $e->getMessage());
    bvm_checkout_session_error('server_error', 'Unexpected server error.', 500);
}
