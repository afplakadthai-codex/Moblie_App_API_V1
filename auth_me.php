<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/auth_me.php
// Validates a mobile Bearer token and returns the current user profile.
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'ok'    => false,
        'error' => ['code' => 'method_not_allowed', 'message' => 'Only GET requests are accepted.'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// =============================================================================
// Root path helpers
// File lives at: /public_html/api/mobile/v1/auth_me.php
// dirname(__DIR__, 3) → /public_html
// dirname(__DIR__, 4) → account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_me_public_root')) {
    function bv_me_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_me_project_root')) {
    function bv_me_project_root(): string
    {
        return dirname(bv_me_public_root());
    }
}

// =============================================================================
// Helpers
// =============================================================================

if (!function_exists('bv_me_json')) {
    function bv_me_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_me_error')) {
    function bv_me_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_me_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_me_log')) {
    function bv_me_log(string $message): void
    {
        error_log('[BV Mobile Me] ' . $message);
        $line          = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        $logCandidates = [
            bv_me_project_root() . '/private_html/mobile_api.log',
            bv_me_public_root()  . '/logs/mobile_api.log',
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

if (!function_exists('bv_me_db')) {
    function bv_me_db(): ?object
    {
        static $cached = false;
        static $connection = null;
        if ($cached !== false) {
            return $connection;
        }
        $cached     = true;
        $publicRoot = bv_me_public_root();
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

            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $n) {
                if (isset($vars[$n]) && ($vars[$n] instanceof PDO || $vars[$n] instanceof mysqli)) {
                    $connection = $vars[$n];
                    return $connection;
                }
            }
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
                    error_log('[BV Mobile Me] PDO connect failed: ' . $e->getMessage());
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
                    error_log('[BV Mobile Me] mysqli connect failed: ' . $m->connect_error);
                } catch (\Throwable $e) {
                    error_log('[BV Mobile Me] mysqli exception: ' . $e->getMessage());
                }
            }
            break;
        }
        return null;
    }
}

if (!function_exists('bv_me_table_exists')) {
    function bv_me_table_exists(object $db, string $table): bool
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
            error_log('[BV Mobile Me] table_exists error: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('bv_me_clean_string')) {
    function bv_me_clean_string($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
    }
}

if (!function_exists('bv_me_read_bearer_token')) {
    /**
     * Extracts the raw Bearer token from the Authorization header.
     * Returns empty string if header is absent or malformed.
     */
    function bv_me_read_bearer_token(): string
    {
        $header = '';

        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            // Some Apache + mod_rewrite setups move the header here
            $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            // Header name lookup is case-insensitive
            foreach ($apacheHeaders as $k => $v) {
                if (strtolower($k) === 'authorization') {
                    $header = (string) $v;
                    break;
                }
            }
        }

        if ($header === '') {
            return '';
        }

        // Must start with "Bearer " (case-insensitive)
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return '';
        }

        return $m[1];
    }
}

// ── Query helpers ─────────────────────────────────────────────────────────────

if (!function_exists('bv_me_query_row')) {
    function bv_me_query_row(object $db, string $sql, array $params = []): ?array
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
            error_log('[BV Mobile Me] query_row error: ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('bv_me_execute')) {
    function bv_me_execute(object $db, string $sql, array $params = []): bool
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
            error_log('[BV Mobile Me] execute error: ' . $e->getMessage());
        }
        return false;
    }
}

// =============================================================================
// Main execution
// =============================================================================
try {

    // ── Read and validate Bearer token from header ────────────────────────────
    $plainToken = bv_me_read_bearer_token();

    if ($plainToken === '') {
        bv_me_error('token_missing', 'Authorization token is required.', 401);
    }

    // Hash the plain token for DB lookup — never store or compare plain tokens
    $tokenHash = hash('sha256', $plainToken);

    // ── Connect to DB ─────────────────────────────────────────────────────────
    $db = bv_me_db();
    if ($db === null) {
        bv_me_log('No database connection available.');
        bv_me_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Confirm required tables ───────────────────────────────────────────────
    if (!bv_me_table_exists($db, 'mobile_auth_tokens')) {
        bv_me_log('mobile_auth_tokens table not found.');
        bv_me_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }
    if (!bv_me_table_exists($db, 'users')) {
        bv_me_log('users table not found.');
        bv_me_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Look up token + user in a single JOIN ─────────────────────────────────
    // Checks: token_hash match, not revoked, not expired, user active.
    // Never exposes token_hash or password_hash in the SELECT list.
    $row = bv_me_query_row(
        $db,
        "SELECT
            mat.id            AS token_id,
            u.id              AS user_id,
            u.email,
            u.role,
            u.account_status,
            u.first_name,
            u.last_name
         FROM mobile_auth_tokens mat
         INNER JOIN users u ON u.id = mat.user_id
         WHERE mat.token_hash = ?
           AND mat.revoked_at IS NULL
           AND mat.expires_at > NOW()
           AND u.account_status = 'active'
         LIMIT 1",
        [$tokenHash]
    );

    if ($row === null) {
        // Do not distinguish between wrong token / expired / revoked / inactive user —
        // all are the same generic response to prevent information leakage.
        bv_me_error('token_invalid', 'Token is invalid or has expired.', 401);
    }

    // ── Touch last_used_at (best-effort, never crashes auth on failure) ───────
    bv_me_execute(
        $db,
        'UPDATE mobile_auth_tokens SET last_used_at = NOW() WHERE id = ? LIMIT 1',
        [(int) $row['token_id']]
    );

    // ── Build safe user profile ───────────────────────────────────────────────
    $firstName   = bv_me_clean_string($row['first_name']    ?? '');
    $lastName    = bv_me_clean_string($row['last_name']     ?? '');
    $displayName = trim($firstName . ' ' . $lastName);

    bv_me_json(200, [
        'ok'   => true,
        'data' => [
            'authenticated' => true,
            'user'          => [
                'id'             => (int) $row['user_id'],
                'email'          => bv_me_clean_string($row['email']          ?? ''),
                'role'           => bv_me_clean_string($row['role']           ?? 'user'),
                'account_status' => bv_me_clean_string($row['account_status'] ?? 'active'),
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
    bv_me_log('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_me_json(500, [
        'ok'    => false,
        'error' => [
            'code'    => 'server_error',
            'message' => 'Something went wrong.',
        ],
    ]);
}
