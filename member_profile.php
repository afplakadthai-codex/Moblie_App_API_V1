<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/member_profile.php
// Authenticated endpoint: returns full member profile for the token owner.
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
// File lives at: /public_html/api/mobile/v1/member_profile.php
// dirname(__DIR__, 3) → /public_html
// dirname(__DIR__, 4) → account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_member_profile_public_root')) {
    function bv_member_profile_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_member_profile_project_root')) {
    function bv_member_profile_project_root(): string
    {
        return dirname(bv_member_profile_public_root());
    }
}

// =============================================================================
// Helper functions
// =============================================================================

if (!function_exists('bv_member_profile_json')) {
    function bv_member_profile_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_member_profile_error')) {
    function bv_member_profile_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_member_profile_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_member_profile_log')) {
    function bv_member_profile_log(string $message): void
    {
        error_log('[BV Member Profile] ' . $message);
        $line          = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        $logCandidates = [
            bv_member_profile_project_root() . '/private_html/mobile_api.log',
            bv_member_profile_public_root()  . '/logs/mobile_api.log',
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

if (!function_exists('bv_member_profile_db')) {
    function bv_member_profile_db(): ?object
    {
        static $cached = false;
        static $connection = null;
        if ($cached !== false) {
            return $connection;
        }
        $cached     = true;
        $publicRoot = bv_member_profile_public_root();
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
                    error_log('[BV Member Profile] PDO connect failed: ' . $e->getMessage());
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
                    error_log('[BV Member Profile] mysqli connect failed: ' . $m->connect_error);
                } catch (\Throwable $e) {
                    error_log('[BV Member Profile] mysqli exception: ' . $e->getMessage());
                }
            }
            break;
        }
        return null;
    }
}

if (!function_exists('bv_member_profile_table_exists')) {
    // Direct SELECT 1 probe — avoids SHOW TABLES LIKE false negatives
    function bv_member_profile_table_exists(object $db, string $table): bool
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
            error_log('[BV Member Profile] table_exists error: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('bv_member_profile_columns')) {
    function bv_member_profile_columns(object $db, string $table): array
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
            error_log('[BV Member Profile] columns error: ' . $e->getMessage());
        }
        return $cols;
    }
}

if (!function_exists('bv_member_profile_has_col')) {
    function bv_member_profile_has_col(object $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = in_array(strtolower($column), bv_member_profile_columns($db, $table), true);
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_member_profile_clean_string')) {
    function bv_member_profile_clean_string($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
    }
}

if (!function_exists('bv_member_profile_read_bearer_token')) {
    function bv_member_profile_read_bearer_token(): string
    {
        $header = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strtolower($k) === 'authorization') {
                    $header = (string) $v;
                    break;
                }
            }
        }
        if ($header === '') {
            return '';
        }
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return '';
        }
        return $m[1];
    }
}

// ── Query helpers ─────────────────────────────────────────────────────────────

if (!function_exists('bv_mp_query_row')) {
    function bv_mp_query_row(object $db, string $sql, array $params = []): ?array
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
            error_log('[BV Member Profile] query_row error: ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('bv_mp_query_scalar')) {
    function bv_mp_query_scalar(object $db, string $sql, array $params = [], $default = 0)
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql);
                $st->execute($params);
                $row = $st->fetch(PDO::FETCH_NUM);
                return $row ? $row[0] : $default;
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) {
                    return $default;
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
                $row = $res ? $res->fetch_row() : null;
                $st->close();
                return $row ? $row[0] : $default;
            }
        } catch (\Throwable $e) {
            error_log('[BV Member Profile] query_scalar error: ' . $e->getMessage());
        }
        return $default;
    }
}

if (!function_exists('bv_mp_execute')) {
    function bv_mp_execute(object $db, string $sql, array $params = []): bool
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
            error_log('[BV Member Profile] execute error: ' . $e->getMessage());
        }
        return false;
    }
}

// =============================================================================
// Main execution
// =============================================================================
try {

    // ── Read Bearer token ─────────────────────────────────────────────────────
    $plainToken = bv_member_profile_read_bearer_token();
    if ($plainToken === '') {
        bv_member_profile_error('token_missing', 'Authorization token is required.', 401);
    }

    $tokenHash = hash('sha256', $plainToken);

    // ── Connect ───────────────────────────────────────────────────────────────
    $db = bv_member_profile_db();
    if ($db === null) {
        bv_member_profile_log('No database connection available.');
        bv_member_profile_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Validate token + fetch core user fields in one JOIN ───────────────────
    // Detects which optional user columns exist before building SELECT
    $uCols   = bv_member_profile_columns($db, 'users');
    $hasUCol = static fn(string $c): bool => in_array(strtolower($c), $uCols, true);

    $userSelect = ['u.id', 'u.email', 'u.role', 'u.account_status'];
    foreach (['first_name', 'last_name', 'phone', 'whatsapp', 'country',
              'province', 'district', 'created_at'] as $optCol) {
        if ($hasUCol($optCol)) {
            $userSelect[] = "u.{$optCol}";
        }
    }

    $tokenRow = bv_mp_query_row(
        $db,
        'SELECT mat.id AS token_id, '
        . implode(', ', $userSelect)
        . ' FROM mobile_auth_tokens mat'
        . ' INNER JOIN users u ON u.id = mat.user_id'
        . " WHERE mat.token_hash = ?"
        . "   AND mat.revoked_at IS NULL"
        . "   AND mat.expires_at > NOW()"
        . "   AND u.account_status = 'active'"
        . ' LIMIT 1',
        [$tokenHash]
    );

    if ($tokenRow === null) {
        bv_member_profile_error('token_invalid', 'Token is invalid or has expired.', 401);
    }

    // ── Update last_used_at (best-effort) ─────────────────────────────────────
    bv_mp_execute(
        $db,
        'UPDATE mobile_auth_tokens SET last_used_at = NOW() WHERE id = ? LIMIT 1',
        [(int) $tokenRow['token_id']]
    );

    // ── Build user data ───────────────────────────────────────────────────────
    $userId    = (int) $tokenRow['id'];
    $userRole  = bv_member_profile_clean_string($tokenRow['role']           ?? 'user');
    $firstName = bv_member_profile_clean_string($tokenRow['first_name']     ?? '');
    $lastName  = bv_member_profile_clean_string($tokenRow['last_name']      ?? '');

    $userData = [
        'id'             => $userId,
        'email'          => bv_member_profile_clean_string($tokenRow['email']          ?? ''),
        'role'           => $userRole,
        'account_status' => bv_member_profile_clean_string($tokenRow['account_status'] ?? 'active'),
        'first_name'     => $firstName,
        'last_name'      => $lastName,
        'display_name'   => trim($firstName . ' ' . $lastName),
        'phone'          => bv_member_profile_clean_string($tokenRow['phone']          ?? ''),
        'whatsapp'       => bv_member_profile_clean_string($tokenRow['whatsapp']       ?? ''),
        'country'        => bv_member_profile_clean_string($tokenRow['country']        ?? ''),
        'province'       => bv_member_profile_clean_string($tokenRow['province']       ?? ''),
        'district'       => bv_member_profile_clean_string($tokenRow['district']       ?? ''),
        'created_at'     => bv_member_profile_clean_string($tokenRow['created_at']     ?? ''),
    ];

    // ── Seller data ───────────────────────────────────────────────────────────
    $siteBase  = rtrim(defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com', '/');
    $profileUrl = $siteBase . '/seller.php?id=' . $userId;

    $sellerData = [
        'is_seller'          => $userRole === 'seller',
        'seller_id'          => $userRole === 'seller' ? $userId : null,
        'farm_name'          => '',
        'seller_status'      => '',
        'application_status' => '',
        'profile_url'        => $userRole === 'seller' ? $profileUrl : '',
    ];

    // Prefer seller_profiles (approved sellers have a row here)
    $hasSellerProfiles = bv_member_profile_table_exists($db, 'seller_profiles');
    if ($hasSellerProfiles) {
        $spRow = bv_mp_query_row(
            $db,
            'SELECT farm_name, seller_status, public_display_name
             FROM seller_profiles
             WHERE user_id = ?
             LIMIT 1',
            [$userId]
        );
        if ($spRow) {
            $sellerData['is_seller']     = true;
            $sellerData['seller_id']     = $userId;
            $sellerData['farm_name']     = bv_member_profile_clean_string($spRow['farm_name']     ?? '');
            $sellerData['seller_status'] = bv_member_profile_clean_string($spRow['seller_status'] ?? '');
            $sellerData['profile_url']   = $profileUrl;
        }
    }

    // Also pull seller_applications for application_status (covers pending sellers too)
    $hasSellerApps = bv_member_profile_table_exists($db, 'seller_applications');
    if ($hasSellerApps) {
        $saRow = bv_mp_query_row(
            $db,
            'SELECT application_status, farm_name
             FROM seller_applications
             WHERE user_id = ?
             ORDER BY id DESC
             LIMIT 1',
            [$userId]
        );
        if ($saRow) {
            $sellerData['application_status'] = bv_member_profile_clean_string(
                $saRow['application_status'] ?? ''
            );
            // Fill farm_name from application if seller_profiles didn't supply one
            if ($sellerData['farm_name'] === '') {
                $sellerData['farm_name'] = bv_member_profile_clean_string($saRow['farm_name'] ?? '');
            }
        }
    }

    // ── Stats ─────────────────────────────────────────────────────────────────
    $statsData = [
        'active_listings' => 0,
        'total_listings'  => 0,
        'sold_items'      => 0,
        'review_count'    => 0,
        'average_rating'  => 0.0,
    ];

    $hasListings = bv_member_profile_table_exists($db, 'listings');
    if ($hasListings) {
        $lHasStatus     = bv_member_profile_has_col($db, 'listings', 'status');
        $lHasSaleStatus = bv_member_profile_has_col($db, 'listings', 'sale_status');
        $lHasSellerId   = bv_member_profile_has_col($db, 'listings', 'seller_id');

        if ($lHasSellerId) {
            // Total listings for this seller
            $statsData['total_listings'] = (int) bv_mp_query_scalar(
                $db,
                'SELECT COUNT(*) FROM listings WHERE seller_id = ?',
                [$userId],
                0
            );

            // Active listings — status IN ('active','published','available')
            // Live schema uses enum('draft','pending','active','sold','hidden') so only 'active' applies
            if ($lHasStatus) {
                $statsData['active_listings'] = (int) bv_mp_query_scalar(
                    $db,
                    "SELECT COUNT(*) FROM listings
                     WHERE seller_id = ? AND status IN ('active','published','available')",
                    [$userId],
                    0
                );
            }

            // Sold items — sale_status = 'sold'
            if ($lHasSaleStatus) {
                $statsData['sold_items'] = (int) bv_mp_query_scalar(
                    $db,
                    "SELECT COUNT(*) FROM listings WHERE seller_id = ? AND sale_status = 'sold'",
                    [$userId],
                    0
                );
            } elseif ($lHasStatus) {
                // Fallback: listings.status = 'sold'
                $statsData['sold_items'] = (int) bv_mp_query_scalar(
                    $db,
                    "SELECT COUNT(*) FROM listings WHERE seller_id = ? AND status = 'sold'",
                    [$userId],
                    0
                );
            }

            // Reviews on seller's listings
            $hasReviews = bv_member_profile_table_exists($db, 'listing_reviews');
            if ($hasReviews) {
                $rvHasStatus = bv_member_profile_has_col($db, 'listing_reviews', 'status');
                $approvedWhere = $rvHasStatus ? "AND lr.status = 'approved'" : '';

                $reviewRow = bv_mp_query_row(
                    $db,
                    "SELECT COUNT(*) AS rv_count, COALESCE(AVG(lr.rating), 0) AS rv_avg
                     FROM listing_reviews lr
                     INNER JOIN listings l ON l.id = lr.listing_id
                     WHERE l.seller_id = ? {$approvedWhere}",
                    [$userId]
                );
                if ($reviewRow) {
                    $statsData['review_count']  = (int) ($reviewRow['rv_count'] ?? 0);
                    $statsData['average_rating'] = round((float) ($reviewRow['rv_avg'] ?? 0.0), 2);
                }
            }
        }
    }

    // ── Build response ────────────────────────────────────────────────────────
    bv_member_profile_json(200, [
        'ok'   => true,
        'data' => [
            'user'   => $userData,
            'seller' => $sellerData,
            'stats'  => $statsData,
        ],
        'meta' => [
            'api_version'  => 'v1',
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);

} catch (\Throwable $e) {
    bv_member_profile_log('Unhandled exception: ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_member_profile_json(500, [
        'ok'    => false,
        'error' => [
            'code'    => 'server_error',
            'message' => 'Something went wrong.',
        ],
    ]);
}
