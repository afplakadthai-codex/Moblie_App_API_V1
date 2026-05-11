<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/auth_login.php
// Token-based mobile login. Issues a Bearer token on valid credentials.
//
// CRITICAL: Never touches $_SESSION. Never outputs HTML. Never redirects.
// Does NOT interfere with the website session-based login in login.php.
//
// CORS: Not opened broadly. Configure Access-Control-Allow-Origin later
//       when mobile app domain or API gateway is finalized.
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
        'ok'    => false,
        'error' => ['code' => 'method_not_allowed', 'message' => 'Only POST requests are accepted.'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// =============================================================================
// Root path helpers
// File lives at: /public_html/api/mobile/v1/auth_login.php
// dirname(__DIR__, 3) → /public_html
// dirname(__DIR__, 4) → account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_mobile_auth_public_root')) {
    function bv_mobile_auth_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_mobile_auth_project_root')) {
    function bv_mobile_auth_project_root(): string
    {
        return dirname(bv_mobile_auth_public_root());
    }
}

// =============================================================================
// Helpers
// =============================================================================

if (!function_exists('bv_mobile_auth_json')) {
    function bv_mobile_auth_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_mobile_auth_error')) {
    function bv_mobile_auth_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_mobile_auth_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_mobile_auth_log')) {
    function bv_mobile_auth_log(string $message): void
    {
        // error_log is always the guaranteed fallback — never crashes
        error_log('[BV Mobile Auth] ' . $message);
        $line          = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        $logCandidates = [
            bv_mobile_auth_project_root() . '/private_html/mobile_api.log',
            bv_mobile_auth_public_root()  . '/logs/mobile_api.log',
        ];
        foreach ($logCandidates as $logFile) {
            $logDir = dirname($logFile);
            if (is_dir($logDir) && is_writable($logDir)) {
                @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
                break;
            }
        }
    }
}

if (!function_exists('bv_mobile_auth_db')) {
    function bv_mobile_auth_db(): ?object
    {
        static $cached = false;
        static $connection = null;
        if ($cached !== false) {
            return $connection;
        }
        $cached     = true;
        $publicRoot = bv_mobile_auth_public_root();
        $candidates = [
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $publicRoot . '/includes/config.php',
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

            // 1) Connection object captured from local scope
            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $n) {
                if (isset($vars[$n]) && ($vars[$n] instanceof PDO || $vars[$n] instanceof mysqli)) {
                    $connection = $vars[$n];
                    return $connection;
                }
            }
            // 2) Connection object in $GLOBALS
            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $gn) {
                if (isset($GLOBALS[$gn])) {
                    $obj = $GLOBALS[$gn];
                    if ($obj instanceof PDO || $obj instanceof mysqli) {
                        $connection = $obj;
                        return $connection;
                    }
                }
            }

            $h  = $vars['db_host'] ?? $vars['host']    ?? $vars['DB_HOST']  ?? null;
            $u  = $vars['db_user'] ?? $vars['user']    ?? $vars['DB_USER']  ?? null;
            $p  = $vars['db_pass'] ?? $vars['pass']    ?? $vars['DB_PASS']  ?? null;
            $n  = $vars['db_name'] ?? $vars['name']    ?? $vars['DB_NAME']  ?? null;
            $pt = (int) ($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);

            if ($h && $u !== null && $n) {
                try {
                    $pdo = new PDO(
                        "mysql:host={$h};port={$pt};dbname={$n};charset=utf8mb4",
                        $u,
                        (string) $p,
                        [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES   => false,
                        ]
                    );
                    $connection = $pdo;
                    return $connection;
                } catch (\Throwable $e) {
                    error_log('[BV Mobile Auth] PDO connect failed: ' . $e->getMessage());
                }
            }
            if ($h && $u !== null && $n && function_exists('mysqli_connect')) {
                try {
                    $m = @new mysqli($h, (string) $u, (string) $p, $n, $pt ?: 3306);
                    if (!$m->connect_errno) {
                        $m->set_charset('utf8mb4');
                        $connection = $m;
                        return $connection;
                    }
                    error_log('[BV Mobile Auth] mysqli connect failed: ' . $m->connect_error);
                } catch (\Throwable $e) {
                    error_log('[BV Mobile Auth] mysqli exception: ' . $e->getMessage());
                }
            }
            break;
        }
        return null;
    }
}

if (!function_exists('bv_mobile_auth_table_exists')) {
    // Direct SELECT 1 probe — avoids SHOW TABLES LIKE false negatives
    function bv_mobile_auth_table_exists(object $db, string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }
        $sql = "SELECT 1 FROM `{$table}` LIMIT 1";
        try {
            if ($db instanceof PDO) {
                $prev = $db->getAttribute(PDO::ATTR_ERRMODE);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                try {
                    $db->query($sql);
                    $db->setAttribute(PDO::ATTR_ERRMODE, $prev);
                    return true;
                } catch (\PDOException $e) {
                    $db->setAttribute(PDO::ATTR_ERRMODE, $prev);
                    return false;
                }
            }
            if ($db instanceof mysqli) {
                $res = $db->query($sql);
                if ($res !== false) {
                    if ($res instanceof mysqli_result) {
                        $res->free();
                    }
                    return true;
                }
                return false;
            }
        } catch (\Throwable $e) {
            error_log('[BV Mobile Auth] table_exists error: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('bv_mobile_auth_columns')) {
    function bv_mobile_auth_columns(object $db, string $table): array
    {
        $cols = [];
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
                $st->execute();
                foreach ($st->fetchAll() as $row) {
                    $cols[] = strtolower((string) ($row['Field'] ?? ''));
                }
            } elseif ($db instanceof mysqli) {
                $safe = str_replace('`', '', $table);
                $res  = $db->query("SHOW COLUMNS FROM `{$safe}`");
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $cols[] = strtolower((string) ($row['Field'] ?? ''));
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[BV Mobile Auth] columns error: ' . $e->getMessage());
        }
        return $cols;
    }
}

if (!function_exists('bv_mobile_auth_has_col')) {
    function bv_mobile_auth_has_col(object $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = in_array(strtolower($column), bv_mobile_auth_columns($db, $table), true);
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_mobile_auth_clean_string')) {
    function bv_mobile_auth_clean_string($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
    }
}

if (!function_exists('bv_mobile_auth_client_ip')) {
    function bv_mobile_auth_client_ip(): string
    {
        // Use REMOTE_ADDR only — never trust forwarded headers without reverse-proxy config
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }
}

if (!function_exists('bv_mobile_auth_read_input')) {
    /**
     * Reads POST body as JSON first, falls back to $_POST form fields.
     * Returns array with keys: email, password, device_name, device_id.
     * Never throws; returns empty strings on missing fields.
     */
    function bv_mobile_auth_read_input(): array
    {
        $defaults = [
            'email'       => '',
            'password'    => '',
            'device_name' => '',
            'device_id'   => '',
        ];

        $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));

        // JSON body
        if (str_contains($contentType, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return [
                        'email'       => trim((string) ($decoded['email']       ?? '')),
                        'password'    => (string)       ($decoded['password']    ?? ''),
                        'device_name' => trim((string) ($decoded['device_name'] ?? '')),
                        'device_id'   => trim((string) ($decoded['device_id']   ?? '')),
                    ];
                }
            }
        }

        // Form-encoded fallback (for testing with curl/Postman without JSON header)
        return [
            'email'       => trim((string) ($_POST['email']       ?? '')),
            'password'    => (string)       ($_POST['password']    ?? ''),
            'device_name' => trim((string) ($_POST['device_name'] ?? '')),
            'device_id'   => trim((string) ($_POST['device_id']   ?? '')),
        ];
    }
}

// ── PDO/mysqli single-row query helper ────────────────────────────────────────

if (!function_exists('bv_auth_query_row')) {
    function bv_auth_query_row(object $db, string $sql, array $params = []): ?array
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql);
                $st->execute($params);
                $row = $st->fetch();
                return $row ?: null;
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) {
                    return null;
                }
                if (!empty($params)) {
                    $types   = implode('', array_map(
                        static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'),
                        $params
                    ));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) {
                        $bindRef[] = &$params[$k];
                    }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $st->close();
                return $row ?: null;
            }
        } catch (\Throwable $e) {
            error_log('[BV Mobile Auth] query_row error: ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('bv_auth_execute')) {
    function bv_auth_execute(object $db, string $sql, array $params = []): bool
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql);
                return $st->execute($params);
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) {
                    return false;
                }
                if (!empty($params)) {
                    $types   = implode('', array_map(
                        static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'),
                        $params
                    ));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) {
                        $bindRef[] = &$params[$k];
                    }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $result = $st->execute();
                $st->close();
                return $result;
            }
        } catch (\Throwable $e) {
            error_log('[BV Mobile Auth] execute error: ' . $e->getMessage());
        }
        return false;
    }
}

// =============================================================================
// Main execution
// =============================================================================
try {

    // ── Step 1: Read input ────────────────────────────────────────────────────
    $input = bv_mobile_auth_read_input();

    $emailRaw    = $input['email'];
    $passwordRaw = $input['password'];
    $deviceName  = substr($input['device_name'], 0, 120);
    $deviceId    = substr($input['device_id'],   0, 120);

    // ── Step 2: Validate email and password not empty ─────────────────────────
    if ($emailRaw === '') {
        bv_mobile_auth_error('validation_error', 'Email is required.', 422);
    }
    if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
        bv_mobile_auth_error('validation_error', 'A valid email address is required.', 422);
    }
    if ($passwordRaw === '') {
        bv_mobile_auth_error('validation_error', 'Password is required.', 422);
    }

    // ── Connect to DB ─────────────────────────────────────────────────────────
    $db = bv_mobile_auth_db();
    if ($db === null) {
        bv_mobile_auth_log('No database connection available.');
        bv_mobile_auth_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Confirm required tables exist ─────────────────────────────────────────
    if (!bv_mobile_auth_table_exists($db, 'users')) {
        bv_mobile_auth_log('users table not found.');
        bv_mobile_auth_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    if (!bv_mobile_auth_table_exists($db, 'mobile_auth_tokens')) {
        bv_mobile_auth_log('mobile_auth_tokens table not found.');
        bv_mobile_auth_error('token_table_missing', 'Mobile auth is not ready.', 503);
    }

    // ── Confirm required user columns exist ───────────────────────────────────
    if (!bv_mobile_auth_has_col($db, 'users', 'account_status')) {
        bv_mobile_auth_log('users.account_status column missing.');
        bv_mobile_auth_error('account_status_missing', 'Service configuration error.', 500);
    }
    if (!bv_mobile_auth_has_col($db, 'users', 'password_hash')) {
        bv_mobile_auth_log('users.password_hash column missing.');
        bv_mobile_auth_error('server_error', 'Something went wrong.', 500);
    }

    // ── Step 3: Load user by email ────────────────────────────────────────────
    $userRow = bv_auth_query_row(
        $db,
        'SELECT id, email, password_hash, account_status, role,
                first_name, last_name
         FROM users
         WHERE email = ?
         LIMIT 1',
        [$emailRaw]
    );

    // ── Step 4 & 5: Validate account status and password ─────────────────────
    // Use a constant-time path to prevent user enumeration:
    // always run password_verify even if user not found (against a dummy hash).
    $dummyHash = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';

    $passwordToVerify = $userRow ? (string) ($userRow['password_hash'] ?? '') : $dummyHash;
    $passwordMatches  = password_verify($passwordRaw, $passwordToVerify);

    if (!$userRow || !$passwordMatches) {
        bv_mobile_auth_error('invalid_credentials', 'Invalid email or password.', 401);
    }

    // Account status gate — must be exactly 'active'
    $accountStatus = (string) ($userRow['account_status'] ?? '');
    if ($accountStatus !== 'active') {
        bv_mobile_auth_error('account_inactive', 'Your account is not active.', 403);
    }

    $userId    = (int) $userRow['id'];
    $userRole  = bv_mobile_auth_clean_string($userRow['role']       ?? 'user');
    $firstName = bv_mobile_auth_clean_string($userRow['first_name'] ?? '');
    $lastName  = bv_mobile_auth_clean_string($userRow['last_name']  ?? '');
    $userEmail = bv_mobile_auth_clean_string($userRow['email']      ?? '');

    // Reject admin accounts from mobile token auth
    if ($userRole === 'admin') {
        bv_mobile_auth_error('invalid_credentials', 'Invalid email or password.', 401);
    }

    // ── Step 6 & 7: Generate token ────────────────────────────────────────────
    $plainToken  = bin2hex(random_bytes(32));     // 64 hex chars
    $tokenHash   = hash('sha256', $plainToken);   // 64 hex chars
    $tokenPrefix = substr($plainToken, 0, 12);    // first 12 chars for lookup hint

    // ── Step 8: Detect mobile_auth_tokens columns and INSERT ─────────────────
    $matCols = bv_mobile_auth_columns($db, 'mobile_auth_tokens');
    $hasMatCol = static fn(string $c): bool => in_array(strtolower($c), $matCols, true);

    // Build INSERT dynamically based on what columns actually exist
    $insertCols   = [];
    $insertParams = [];

    if ($hasMatCol('user_id')) {
        $insertCols[]   = 'user_id';
        $insertParams[] = $userId;
    }
    if ($hasMatCol('token_hash')) {
        $insertCols[]   = 'token_hash';
        $insertParams[] = $tokenHash;
    }
    if ($hasMatCol('token_prefix')) {
        $insertCols[]   = 'token_prefix';
        $insertParams[] = $tokenPrefix;
    }
    if ($hasMatCol('device_name') && $deviceName !== '') {
        $insertCols[]   = 'device_name';
        $insertParams[] = $deviceName;
    }
    if ($hasMatCol('device_id') && $deviceId !== '') {
        $insertCols[]   = 'device_id';
        $insertParams[] = $deviceId;
    }
    if ($hasMatCol('ip_address')) {
        $insertCols[]   = 'ip_address';
        $insertParams[] = bv_mobile_auth_client_ip();
    }
    if ($hasMatCol('user_agent')) {
        $insertCols[]   = 'user_agent';
        $insertParams[] = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
    if ($hasMatCol('expires_at')) {
        $insertCols[]   = 'expires_at';
        $insertParams[] = date('Y-m-d H:i:s', strtotime('+90 days'));
    }

    if (empty($insertCols)) {
        bv_mobile_auth_log("mobile_auth_tokens has no usable columns for user {$userId}");
        bv_mobile_auth_error('server_error', 'Something went wrong.', 500);
    }

    $colList      = implode(', ', array_map(static fn($c) => "`{$c}`", $insertCols));
    $phList       = implode(', ', array_fill(0, count($insertParams), '?'));
    $insertSql    = "INSERT INTO mobile_auth_tokens ({$colList}) VALUES ({$phList})";

    $inserted = bv_auth_execute($db, $insertSql, $insertParams);

    if (!$inserted) {
        bv_mobile_auth_log("Failed to insert mobile token for user {$userId}");
        bv_mobile_auth_error('server_error', 'Something went wrong.', 500);
    }

    // ── Step 9: Update last_login_at ──────────────────────────────────────────
    if (bv_mobile_auth_has_col($db, 'users', 'last_login_at')) {
        bv_auth_execute(
            $db,
            'UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ? LIMIT 1',
            [bv_mobile_auth_client_ip(), $userId]
        );
    }

    // ── Step 10: Return success ───────────────────────────────────────────────
    $displayName = trim($firstName . ' ' . $lastName);

    bv_mobile_auth_json(200, [
        'ok'   => true,
        'data' => [
            'token'          => $plainToken,
            'token_type'     => 'Bearer',
            'expires_in_days' => 90,
            'user'           => [
                'id'             => $userId,
                'email'          => $userEmail,
                'role'           => $userRole,
                'account_status' => $accountStatus,
                'first_name'     => $firstName,
                'last_name'      => $lastName,
                'display_name'   => $displayName,
            ],
        ],
        'meta' => [
            'api_version'  => 'v1',
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);

} catch (\Throwable $e) {
    bv_mobile_auth_log('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_mobile_auth_json(500, [
        'ok'    => false,
        'error' => [
            'code'    => 'server_error',
            'message' => 'Something went wrong.',
        ],
    ]);
}
