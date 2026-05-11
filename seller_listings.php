<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/seller_listings.php
// Authenticated seller endpoint: returns listings owned by the token holder.
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
// File lives at: /public_html/api/mobile/v1/seller_listings.php
// dirname(__DIR__, 3) → /public_html
// dirname(__DIR__, 4) → account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_seller_listings_public_root')) {
    function bv_seller_listings_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_seller_listings_project_root')) {
    function bv_seller_listings_project_root(): string
    {
        return dirname(bv_seller_listings_public_root());
    }
}

// =============================================================================
// Helpers
// =============================================================================

if (!function_exists('bv_seller_listings_json')) {
    function bv_seller_listings_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_seller_listings_error')) {
    function bv_seller_listings_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_seller_listings_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_seller_listings_log')) {
    function bv_seller_listings_log(string $message): void
    {
        error_log('[BV Seller Listings] ' . $message);
        $line          = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        $logCandidates = [
            bv_seller_listings_project_root() . '/private_html/mobile_api.log',
            bv_seller_listings_public_root()  . '/logs/mobile_api.log',
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

if (!function_exists('bv_seller_listings_db')) {
    function bv_seller_listings_db(): ?object
    {
        static $cached = false;
        static $connection = null;
        if ($cached !== false) {
            return $connection;
        }
        $cached     = true;
        $publicRoot = bv_seller_listings_public_root();
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
                    error_log('[BV Seller Listings] PDO connect failed: ' . $e->getMessage());
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
                    error_log('[BV Seller Listings] mysqli connect failed: ' . $m->connect_error);
                } catch (\Throwable $e) {
                    error_log('[BV Seller Listings] mysqli exception: ' . $e->getMessage());
                }
            }
            break;
        }
        return null;
    }
}

if (!function_exists('bv_seller_listings_table_exists')) {
    function bv_seller_listings_table_exists(object $db, string $table): bool
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
            error_log('[BV Seller Listings] table_exists error: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('bv_seller_listings_columns')) {
    function bv_seller_listings_columns(object $db, string $table): array
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
            error_log('[BV Seller Listings] columns error: ' . $e->getMessage());
        }
        return $cols;
    }
}

if (!function_exists('bv_seller_listings_has_col')) {
    function bv_seller_listings_has_col(object $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = in_array(strtolower($column), bv_seller_listings_columns($db, $table), true);
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_seller_listings_clean_string')) {
    function bv_seller_listings_clean_string($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
    }
}

if (!function_exists('bv_seller_listings_int')) {
    function bv_seller_listings_int($value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}

if (!function_exists('bv_seller_listings_float')) {
    function bv_seller_listings_float($value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}

if (!function_exists('bv_seller_listings_asset_url')) {
    function bv_seller_listings_asset_url(?string $path): string
    {
        if (!$path || trim($path) === '') {
            return '';
        }
        $path = trim($path);
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        $base = rtrim(defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com', '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('bv_seller_listings_listing_url')) {
    function bv_seller_listings_listing_url(array $row): string
    {
        $base = rtrim(defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com', '/');
        $slug = trim((string) ($row['slug'] ?? ''));
        $id   = bv_seller_listings_int($row['id'] ?? 0);
        if ($slug !== '') {
            return $base . '/listing.php?slug=' . urlencode($slug);
        }
        return $id > 0 ? $base . '/listing.php?id=' . $id : $base . '/listing.php';
    }
}

if (!function_exists('bv_seller_listings_money')) {
    function bv_seller_listings_money($amount, string $currency): array
    {
        $amt      = bv_seller_listings_float($amount, 0.0);
        $currency = strtoupper(trim($currency ?: 'USD'));
        return [
            'amount'    => $amt,
            'currency'  => $currency,
            'formatted' => $currency . ' ' . number_format($amt, 2),
        ];
    }
}

if (!function_exists('bv_seller_listings_read_bearer')) {
    function bv_seller_listings_read_bearer(): string
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
        if ($header === '' || !preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return '';
        }
        return $m[1];
    }
}

// ── Query helpers ─────────────────────────────────────────────────────────────

if (!function_exists('bv_sl_query_row')) {
    function bv_sl_query_row(object $db, string $sql, array $params = []): ?array
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
                if ($st === false) return null;
                if (!empty($params)) {
                    $types   = implode('', array_map(static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'), $params));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) { $bindRef[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $st->close();
                return $row ?: null;
            }
        } catch (\Throwable $e) {
            error_log('[BV Seller Listings] query_row: ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('bv_sl_query_rows')) {
    function bv_sl_query_rows(object $db, string $sql, array $params = []): array
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql);
                $st->execute($params);
                return $st->fetchAll() ?: [];
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) return [];
                if (!empty($params)) {
                    $types   = implode('', array_map(static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'), $params));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) { $bindRef[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $st->execute();
                $res  = $st->get_result();
                $rows = [];
                if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
                $st->close();
                return $rows;
            }
        } catch (\Throwable $e) {
            error_log('[BV Seller Listings] query_rows: ' . $e->getMessage());
        }
        return [];
    }
}

if (!function_exists('bv_sl_query_scalar')) {
    function bv_sl_query_scalar(object $db, string $sql, array $params = [], $default = 0)
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
                if ($st === false) return $default;
                if (!empty($params)) {
                    $types   = implode('', array_map(static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'), $params));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) { $bindRef[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_row() : null;
                $st->close();
                return $row ? $row[0] : $default;
            }
        } catch (\Throwable $e) {
            error_log('[BV Seller Listings] query_scalar: ' . $e->getMessage());
        }
        return $default;
    }
}

if (!function_exists('bv_sl_execute')) {
    function bv_sl_execute(object $db, string $sql, array $params = []): bool
    {
        try {
            if ($db instanceof PDO) {
                return $db->prepare($sql)->execute($params);
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) return false;
                if (!empty($params)) {
                    $types   = implode('', array_map(static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'), $params));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) { $bindRef[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $result = $st->execute();
                $st->close();
                return $result;
            }
        } catch (\Throwable $e) {
            error_log('[BV Seller Listings] execute: ' . $e->getMessage());
        }
        return false;
    }
}

// =============================================================================
// Main execution
// =============================================================================
try {

    // ── Read and hash Bearer token ────────────────────────────────────────────
    $plainToken = bv_seller_listings_read_bearer();
    if ($plainToken === '') {
        bv_seller_listings_error('token_missing', 'Authorization token is required.', 401);
    }
    $tokenHash = hash('sha256', $plainToken);

    // ── Connect ───────────────────────────────────────────────────────────────
    $db = bv_seller_listings_db();
    if ($db === null) {
        bv_seller_listings_log('No database connection.');
        bv_seller_listings_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Validate token + user ─────────────────────────────────────────────────
    $tokenRow = bv_sl_query_row(
        $db,
        "SELECT mat.id AS token_id,
                u.id AS user_id,
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

    if ($tokenRow === null) {
        bv_seller_listings_error('token_invalid', 'Token is invalid or has expired.', 401);
    }

    // Update last_used_at best-effort
    bv_sl_execute(
        $db,
        'UPDATE mobile_auth_tokens SET last_used_at = NOW() WHERE id = ? LIMIT 1',
        [(int) $tokenRow['token_id']]
    );

    $userId   = (int) $tokenRow['user_id'];
    $userRole = bv_seller_listings_clean_string($tokenRow['role'] ?? 'user');
    $firstName = bv_seller_listings_clean_string($tokenRow['first_name'] ?? '');
    $lastName  = bv_seller_listings_clean_string($tokenRow['last_name']  ?? '');

    // ── Require seller or admin ───────────────────────────────────────────────
    if (!in_array($userRole, ['seller', 'admin'], true)) {
        bv_seller_listings_error('seller_required', 'Seller access is required.', 403);
    }

    // ── Confirm listings table ────────────────────────────────────────────────
    if (!bv_seller_listings_table_exists($db, 'listings')) {
        bv_seller_listings_log('listings table not found.');
        bv_seller_listings_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Detect listings columns ───────────────────────────────────────────────
    $lCols   = bv_seller_listings_columns($db, 'listings');
    $hasLCol = static fn(string $c): bool => in_array(strtolower($c), $lCols, true);

    // Ownership column — prefer seller_id, then alternatives
    $ownerCol = null;
    foreach (['seller_id', 'seller_user_id', 'user_id', 'owner_user_id'] as $cand) {
        if ($hasLCol($cand)) {
            $ownerCol = $cand;
            break;
        }
    }
    if ($ownerCol === null) {
        bv_seller_listings_log('No ownership column found on listings table.');
        bv_seller_listings_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // Determine effective seller user id to filter by
    $filterUserId = $userId;
    if ($userRole === 'admin') {
        $rawAdminSellerId = bv_seller_listings_int($_GET['seller_id'] ?? 0, 0);
        if ($rawAdminSellerId > 0) {
            $filterUserId = $rawAdminSellerId;
        }
    }

    // Cover image column
    $coverCol = null;
    foreach (['cover_image', 'image', 'image_path', 'main_image', 'thumbnail'] as $cand) {
        if ($hasLCol($cand)) { $coverCol = $cand; break; }
    }

    // Color column name varies
    $colorCol = $hasLCol('color_pattern') ? 'color_pattern' : ($hasLCol('color') ? 'color' : null);

    // ── Parse query parameters ────────────────────────────────────────────────
    $page    = max(1, bv_seller_listings_int($_GET['page']  ?? 1, 1));
    $limit   = min(50, max(1, bv_seller_listings_int($_GET['limit'] ?? 20, 20)));
    $offset  = ($page - 1) * $limit;

    $qSearch     = trim((string) ($_GET['q']           ?? ''));
    $qSort       = trim((string) ($_GET['sort']        ?? 'newest'));
    $qStatus     = trim((string) ($_GET['status']      ?? 'all'));
    $qSaleStatus = trim((string) ($_GET['sale_status'] ?? 'all'));

    $allowedStatuses     = ['all', 'draft', 'pending', 'active', 'published', 'available', 'hidden', 'sold', 'reserved'];
    $allowedSaleStatuses = ['all', 'available', 'reserved', 'sold'];
    $allowedSorts        = ['newest', 'oldest', 'price_low', 'price_high', 'status'];

    if (!in_array($qStatus,     $allowedStatuses,     true)) { $qStatus     = 'all'; }
    if (!in_array($qSaleStatus, $allowedSaleStatuses, true)) { $qSaleStatus = 'all'; }
    if (!in_array($qSort,       $allowedSorts,        true)) { $qSort       = 'newest'; }

    // ── Build WHERE clause ────────────────────────────────────────────────────
    // All column references are qualified with the l. alias used in FROM listings l,
    // preventing ambiguity when ranking/review JOINs are present.
    $whereParts  = ["l.`{$ownerCol}` = ?"];
    $whereParams = [$filterUserId];

    if ($qStatus !== 'all' && $hasLCol('status')) {
        $whereParts[]  = 'l.`status` = ?';
        $whereParams[] = $qStatus;
    }
    if ($qSaleStatus !== 'all' && $hasLCol('sale_status')) {
        $whereParts[]  = 'l.`sale_status` = ?';
        $whereParams[] = $qSaleStatus;
    }
    if ($qSearch !== '') {
        $searchParts = [];
        foreach (['title', 'slug', 'species', 'strain'] as $sc) {
            if ($hasLCol($sc)) {
                $searchParts[] = "l.`{$sc}` LIKE ?";
                $whereParams[] = '%' . $qSearch . '%';
            }
        }
        if (!empty($searchParts)) {
            $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
        }
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $whereParts);

    // ── Optional table detection ──────────────────────────────────────────────
    $hasRanking    = bv_seller_listings_table_exists($db, 'listing_ranking_scores');
    $hasRankCache  = !$hasRanking && bv_seller_listings_table_exists($db, 'listing_ranking_cache');
    $hasReviews    = bv_seller_listings_table_exists($db, 'listing_reviews');

    $rvRatingCol    = null;
    $rvStatusCol    = null;
    $rvListingIdCol = null;
    if ($hasReviews) {
        $rvCols = bv_seller_listings_columns($db, 'listing_reviews');
        foreach (['rating', 'score', 'stars'] as $c) {
            if (in_array($c, $rvCols, true)) { $rvRatingCol = $c; break; }
        }
        foreach (['status', 'approved', 'is_approved'] as $c) {
            if (in_array($c, $rvCols, true)) { $rvStatusCol = $c; break; }
        }
        foreach (['listing_id', 'item_id'] as $c) {
            if (in_array($c, $rvCols, true)) { $rvListingIdCol = $c; break; }
        }
        if (!$rvRatingCol || !$rvListingIdCol) {
            $hasReviews = false;
        }
    }

    // ── Build SELECT ──────────────────────────────────────────────────────────
    $selectCols = ['l.id'];
    foreach (['title','slug','price','currency','status','sale_status','sale_format',
              'species','strain','grade','sex','tail_type',
              'stock_total','stock_sold','stock_available',
              'created_at','updated_at'] as $col) {
        if ($hasLCol($col)) { $selectCols[] = "l.{$col}"; }
    }
    if ($coverCol) {
        $selectCols[] = "l.{$coverCol} AS cover_image";
    }
    if ($colorCol) {
        $selectCols[] = "l.{$colorCol} AS color";
    }

    // Ranking score
    if ($hasRanking) {
        $selectCols[] = 'COALESCE(lrs.final_score, 0) AS ranking_score';
    } elseif ($hasRankCache) {
        $rankScoreCol = bv_seller_listings_has_col($db, 'listing_ranking_cache', 'final_score')
            ? 'final_score'
            : (bv_seller_listings_has_col($db, 'listing_ranking_cache', 'ranking_score') ? 'ranking_score' : null);
        if ($rankScoreCol) {
            $selectCols[] = "COALESCE(lrc.{$rankScoreCol}, 0) AS ranking_score";
        } else {
            $selectCols[] = '0 AS ranking_score';
        }
    } else {
        $selectCols[] = '0 AS ranking_score';
    }

    // Review aggregation subquery
    if ($hasReviews) {
        $rvApprWhere = $rvStatusCol ? "AND rv.{$rvStatusCol} = 'approved'" : '';
        $selectCols[] = "(SELECT COALESCE(AVG(rv.{$rvRatingCol}), 0) FROM listing_reviews rv
                          WHERE rv.{$rvListingIdCol} = l.id {$rvApprWhere}) AS review_avg";
        $selectCols[] = "(SELECT COUNT(*) FROM listing_reviews rv
                          WHERE rv.{$rvListingIdCol} = l.id {$rvApprWhere}) AS review_count";
    } else {
        $selectCols[] = '0 AS review_avg';
        $selectCols[] = '0 AS review_count';
    }

    // ── JOINs ─────────────────────────────────────────────────────────────────
    $joins = '';
    if ($hasRanking) {
        $joins .= ' LEFT JOIN listing_ranking_scores lrs ON lrs.listing_id = l.id';
    } elseif ($hasRankCache && isset($rankScoreCol) && $rankScoreCol !== null) {
        $joins .= ' LEFT JOIN listing_ranking_cache lrc ON lrc.listing_id = l.id';
    }

    // ── ORDER BY ──────────────────────────────────────────────────────────────
    $orderSQL = match ($qSort) {
        'oldest'     => $hasLCol('created_at') ? 'ORDER BY l.created_at ASC, l.id ASC'   : 'ORDER BY l.id ASC',
        'price_low'  => $hasLCol('price')      ? 'ORDER BY l.price ASC, l.id DESC'        : 'ORDER BY l.id DESC',
        'price_high' => $hasLCol('price')      ? 'ORDER BY l.price DESC, l.id DESC'       : 'ORDER BY l.id DESC',
        'status'     => $hasLCol('status')     ? 'ORDER BY l.status ASC, l.id DESC'       : 'ORDER BY l.id DESC',
        default      => $hasLCol('created_at') ? 'ORDER BY l.created_at DESC, l.id DESC'  : 'ORDER BY l.id DESC',
    };

    $fromClause   = "FROM listings l{$joins}";
    $selectClause = 'SELECT ' . implode(', ', $selectCols);

    // ── Count query ───────────────────────────────────────────────────────────
    $totalCount = (int) bv_sl_query_scalar(
        $db,
        "SELECT COUNT(*) {$fromClause} {$whereSQL}",
        $whereParams,
        0
    );

    // ── Data query ────────────────────────────────────────────────────────────
    $dataParams = array_merge($whereParams, [$limit, $offset]);
    $rows = bv_sl_query_rows(
        $db,
        "{$selectClause} {$fromClause} {$whereSQL} {$orderSQL} LIMIT ? OFFSET ?",
        $dataParams
    );

    // ── Format items ──────────────────────────────────────────────────────────
    $items = [];
    foreach ($rows as $row) {
        $currency  = bv_seller_listings_clean_string($row['currency'] ?? 'USD') ?: 'USD';
        $coverUrl  = bv_seller_listings_asset_url(
            bv_seller_listings_clean_string($row['cover_image'] ?? '')
        );
        $reviewAvg = round(bv_seller_listings_float($row['review_avg']    ?? 0), 2);
        $revCount  = bv_seller_listings_int($row['review_count']          ?? 0);
        $rankScore = round(bv_seller_listings_float($row['ranking_score'] ?? 0), 4);

        $items[] = [
            'id'          => bv_seller_listings_int($row['id']),
            'title'       => bv_seller_listings_clean_string($row['title']       ?? ''),
            'slug'        => bv_seller_listings_clean_string($row['slug']        ?? ''),
            'url'         => bv_seller_listings_listing_url($row),
            'cover_image' => $coverUrl,
            'price'       => bv_seller_listings_money($row['price'] ?? 0, $currency),
            'status'      => bv_seller_listings_clean_string($row['status']      ?? ''),
            'sale_status' => bv_seller_listings_clean_string($row['sale_status'] ?? ''),
            'sale_format' => bv_seller_listings_clean_string($row['sale_format'] ?? ''),
            'species'     => bv_seller_listings_clean_string($row['species']     ?? ''),
            'strain'      => bv_seller_listings_clean_string($row['strain']      ?? ''),
            'grade'       => bv_seller_listings_clean_string($row['grade']       ?? ''),
            'sex'         => bv_seller_listings_clean_string($row['sex']         ?? ''),
            'color'       => bv_seller_listings_clean_string($row['color']       ?? ''),
            'tail_type'   => bv_seller_listings_clean_string($row['tail_type']   ?? ''),
            'stock'       => [
                'total'     => bv_seller_listings_int($row['stock_total']     ?? 1),
                'sold'      => bv_seller_listings_int($row['stock_sold']      ?? 0),
                'available' => bv_seller_listings_int($row['stock_available'] ?? 1),
            ],
            'rating'         => ['average' => $reviewAvg, 'count' => $revCount],
            'ranking_score'  => $rankScore,
            'created_at'     => bv_seller_listings_clean_string($row['created_at'] ?? ''),
            'updated_at'     => bv_seller_listings_clean_string($row['updated_at'] ?? ''),
        ];
    }

    // ── Stats (unfiltered by search/status — full picture for this seller) ────
    $statsData = [
        'total'     => 0,
        'draft'     => 0,
        'pending'   => 0,
        'active'    => 0,
        'hidden'    => 0,
        'sold'      => 0,
        'available' => 0,
        'reserved'  => 0,
    ];

    $statsData['total'] = (int) bv_sl_query_scalar(
        $db,
        "SELECT COUNT(*) FROM listings WHERE `{$ownerCol}` = ?",
        [$filterUserId],
        0
    );

    if ($hasLCol('status')) {
        $statusRows = bv_sl_query_rows(
            $db,
            "SELECT status, COUNT(*) AS cnt FROM listings WHERE `{$ownerCol}` = ? GROUP BY status",
            [$filterUserId]
        );
        foreach ($statusRows as $sr) {
            $s = (string) ($sr['status'] ?? '');
            if (array_key_exists($s, $statsData)) {
                $statsData[$s] = (int) ($sr['cnt'] ?? 0);
            }
        }
    }

    if ($hasLCol('sale_status')) {
        $saleRows = bv_sl_query_rows(
            $db,
            "SELECT sale_status, COUNT(*) AS cnt FROM listings WHERE `{$ownerCol}` = ? GROUP BY sale_status",
            [$filterUserId]
        );
        foreach ($saleRows as $sr) {
            $ss = (string) ($sr['sale_status'] ?? '');
            if (array_key_exists($ss, $statsData)) {
                $statsData[$ss] = (int) ($sr['cnt'] ?? 0);
            }
        }
    }

    // ── Seller identity block ─────────────────────────────────────────────────
    $sellerName = trim($firstName . ' ' . $lastName);
    if ($sellerName === '' && $filterUserId !== $userId) {
        // Admin viewing another seller — try to fetch their name
        $sellerNameRow = bv_sl_query_row(
            $db,
            'SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1',
            [$filterUserId]
        );
        if ($sellerNameRow) {
            $sellerName = trim(
                bv_seller_listings_clean_string($sellerNameRow['first_name'] ?? '') . ' ' .
                bv_seller_listings_clean_string($sellerNameRow['last_name']  ?? '')
            );
        }
    }

    // ── Build response ────────────────────────────────────────────────────────
    bv_seller_listings_json(200, [
        'ok'   => true,
        'data' => [
            'seller'     => [
                'id'   => $filterUserId,
                'name' => $sellerName ?: "Seller #{$filterUserId}",
                'role' => $userRole,
            ],
            'items'      => $items,
            'stats'      => $statsData,
            'pagination' => [
                'page'     => $page,
                'per_page' => $limit,
                'total'    => $totalCount,
                'has_more' => ($page * $limit) < $totalCount,
            ],
        ],
        'meta' => [
            'api_version'  => 'v1',
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);

} catch (\Throwable $e) {
    bv_seller_listings_log('Unhandled exception: ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_seller_listings_json(500, [
        'ok'    => false,
        'error' => ['code' => 'server_error', 'message' => 'Something went wrong.'],
    ]);
}
