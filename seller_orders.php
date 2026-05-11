<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/seller_orders.php
// Authenticated seller endpoint: orders containing items owned by the token holder.
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
// File lives at: /public_html/api/mobile/v1/seller_orders.php
// dirname(__DIR__, 3) → /public_html
// dirname(__DIR__, 4) → account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_seller_orders_public_root')) {
    function bv_seller_orders_public_root(): string { return dirname(__DIR__, 3); }
}
if (!function_exists('bv_seller_orders_project_root')) {
    function bv_seller_orders_project_root(): string { return dirname(bv_seller_orders_public_root()); }
}

// =============================================================================
// Helpers
// =============================================================================

if (!function_exists('bv_seller_orders_json')) {
    function bv_seller_orders_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_seller_orders_error')) {
    function bv_seller_orders_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_seller_orders_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_seller_orders_log')) {
    function bv_seller_orders_log(string $message): void
    {
        error_log('[BV Seller Orders] ' . $message);
        $line = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        foreach ([
            bv_seller_orders_project_root() . '/private_html/mobile_api.log',
            bv_seller_orders_public_root()  . '/logs/mobile_api.log',
        ] as $logFile) {
            $logDir = dirname($logFile);
            if (is_dir($logDir) && is_writable($logDir)) {
                @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
                break;
            }
        }
    }
}

if (!function_exists('bv_seller_orders_db')) {
    function bv_seller_orders_db(): ?object
    {
        static $cached = false;
        static $connection = null;
        if ($cached !== false) { return $connection; }
        $cached     = true;
        $publicRoot = bv_seller_orders_public_root();
        foreach ([
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $publicRoot . '/includes/config.php',
        ] as $cfg) {
            if (!is_file($cfg)) { continue; }
            $loader = static function (string $path): array {
                $db_host = $db_user = $db_pass = $db_name = $db_port = null;
                $host = $user = $pass = $name = $port = null;
                $DB_HOST = $DB_USER = $DB_PASS = $DB_NAME = $DB_PORT = null;
                $dsn = null;
                $pdo = $conn = $db = $mysqli = $link = null;
                /** @noinspection PhpIncludeInspection */
                @include $path;
                return compact(
                    'db_host','db_user','db_pass','db_name','db_port',
                    'host','user','pass','name','port',
                    'DB_HOST','DB_USER','DB_PASS','DB_NAME','DB_PORT',
                    'dsn','pdo','conn','db','mysqli','link'
                );
            };
            $vars = $loader($cfg);
            foreach (['pdo','conn','db','mysqli','link'] as $n) {
                if (isset($vars[$n]) && ($vars[$n] instanceof PDO || $vars[$n] instanceof mysqli)) {
                    $connection = $vars[$n]; return $connection;
                }
            }
            foreach (['pdo','conn','db','mysqli','link'] as $gn) {
                if (isset($GLOBALS[$gn])) {
                    $obj = $GLOBALS[$gn];
                    if ($obj instanceof PDO || $obj instanceof mysqli) { $connection = $obj; return $connection; }
                }
            }
            $h  = $vars['db_host'] ?? $vars['host']    ?? $vars['DB_HOST']  ?? null;
            $u  = $vars['db_user'] ?? $vars['user']    ?? $vars['DB_USER']  ?? null;
            $p  = $vars['db_pass'] ?? $vars['pass']    ?? $vars['DB_PASS']  ?? null;
            $n  = $vars['db_name'] ?? $vars['name']    ?? $vars['DB_NAME']  ?? null;
            $pt = (int) ($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);
            if ($h && $u !== null && $n) {
                try {
                    $pdo = new PDO("mysql:host={$h};port={$pt};dbname={$n};charset=utf8mb4", $u, (string)$p, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]);
                    $connection = $pdo; return $connection;
                } catch (\Throwable $e) { error_log('[BV Seller Orders] PDO failed: ' . $e->getMessage()); }
            }
            if ($h && $u !== null && $n && function_exists('mysqli_connect')) {
                try {
                    $m = @new mysqli($h, (string)$u, (string)$p, $n, $pt ?: 3306);
                    if (!$m->connect_errno) { $m->set_charset('utf8mb4'); $connection = $m; return $connection; }
                    error_log('[BV Seller Orders] mysqli failed: ' . $m->connect_error);
                } catch (\Throwable $e) { error_log('[BV Seller Orders] mysqli exception: ' . $e->getMessage()); }
            }
            break;
        }
        return null;
    }
}

if (!function_exists('bv_seller_orders_table_exists')) {
    function bv_seller_orders_table_exists(object $db, string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) { return false; }
        $sql = "SELECT 1 FROM `{$table}` LIMIT 1";
        try {
            if ($db instanceof PDO) {
                $prev = $db->getAttribute(PDO::ATTR_ERRMODE);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                try { $db->query($sql); $db->setAttribute(PDO::ATTR_ERRMODE, $prev); return true; }
                catch (\PDOException $e) { $db->setAttribute(PDO::ATTR_ERRMODE, $prev); return false; }
            }
            if ($db instanceof mysqli) {
                $res = $db->query($sql);
                if ($res !== false) { if ($res instanceof mysqli_result) { $res->free(); } return true; }
                return false;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Orders] table_exists: ' . $e->getMessage()); }
        return false;
    }
}

if (!function_exists('bv_seller_orders_columns')) {
    function bv_seller_orders_columns(object $db, string $table): array
    {
        $cols = [];
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
                $st->execute();
                foreach ($st->fetchAll() as $row) { $cols[] = strtolower((string)($row['Field'] ?? '')); }
            } elseif ($db instanceof mysqli) {
                $safe = str_replace('`', '', $table);
                $res  = $db->query("SHOW COLUMNS FROM `{$safe}`");
                if ($res) { while ($row = $res->fetch_assoc()) { $cols[] = strtolower((string)($row['Field'] ?? '')); } }
            }
        } catch (\Throwable $e) { error_log('[BV Seller Orders] columns: ' . $e->getMessage()); }
        return $cols;
    }
}

if (!function_exists('bv_seller_orders_has_col')) {
    function bv_seller_orders_has_col(object $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = in_array(strtolower($column), bv_seller_orders_columns($db, $table), true);
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_seller_orders_clean')) {
    function bv_seller_orders_clean($value): string
    {
        if ($value === null || $value === false) { return ''; }
        return htmlspecialchars_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5);
    }
}

if (!function_exists('bv_seller_orders_int')) {
    function bv_seller_orders_int($value, int $default = 0): int
    { return is_numeric($value) ? (int)$value : $default; }
}

if (!function_exists('bv_seller_orders_float')) {
    function bv_seller_orders_float($value, float $default = 0.0): float
    { return is_numeric($value) ? (float)$value : $default; }
}

if (!function_exists('bv_seller_orders_read_bearer')) {
    function bv_seller_orders_read_bearer(): string
    {
        $header = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION']))          { $header = (string)$_SERVER['HTTP_AUTHORIZATION']; }
        elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) { $header = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']; }
        elseif (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strtolower($k) === 'authorization') { $header = (string)$v; break; }
            }
        }
        if ($header === '' || !preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) { return ''; }
        return $m[1];
    }
}

// ── Query helpers ─────────────────────────────────────────────────────────────

if (!function_exists('bv_so_query_row')) {
    function bv_so_query_row(object $db, string $sql, array $params = []): ?array
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql); $st->execute($params);
                $row = $st->fetch(); return $row ?: null;
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) { return null; }
                if (!empty($params)) {
                    $types = implode('', array_map(static fn($v) => is_int($v)?'i':(is_float($v)?'d':'s'), $params));
                    $ref   = [&$types];
                    foreach ($params as $k => $_) { $ref[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $ref);
                }
                $st->execute(); $res = $st->get_result();
                $row = $res ? $res->fetch_assoc() : null; $st->close();
                return $row ?: null;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Orders] query_row: ' . $e->getMessage()); }
        return null;
    }
}

if (!function_exists('bv_so_query_rows')) {
    function bv_so_query_rows(object $db, string $sql, array $params = []): array
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql); $st->execute($params);
                return $st->fetchAll() ?: [];
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) { return []; }
                if (!empty($params)) {
                    $types = implode('', array_map(static fn($v) => is_int($v)?'i':(is_float($v)?'d':'s'), $params));
                    $ref   = [&$types];
                    foreach ($params as $k => $_) { $ref[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $ref);
                }
                $st->execute(); $res = $st->get_result();
                $rows = [];
                if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
                $st->close(); return $rows;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Orders] query_rows: ' . $e->getMessage()); }
        return [];
    }
}

if (!function_exists('bv_so_query_scalar')) {
    function bv_so_query_scalar(object $db, string $sql, array $params = [], $default = 0)
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql); $st->execute($params);
                $row = $st->fetch(PDO::FETCH_NUM); return $row ? $row[0] : $default;
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) { return $default; }
                if (!empty($params)) {
                    $types = implode('', array_map(static fn($v) => is_int($v)?'i':(is_float($v)?'d':'s'), $params));
                    $ref   = [&$types];
                    foreach ($params as $k => $_) { $ref[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $ref);
                }
                $st->execute(); $res = $st->get_result();
                $row = $res ? $res->fetch_row() : null; $st->close();
                return $row ? $row[0] : $default;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Orders] query_scalar: ' . $e->getMessage()); }
        return $default;
    }
}

if (!function_exists('bv_so_execute')) {
    function bv_so_execute(object $db, string $sql, array $params = []): bool
    {
        try {
            if ($db instanceof PDO) { return $db->prepare($sql)->execute($params); }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) { return false; }
                if (!empty($params)) {
                    $types = implode('', array_map(static fn($v) => is_int($v)?'i':(is_float($v)?'d':'s'), $params));
                    $ref   = [&$types];
                    foreach ($params as $k => $_) { $ref[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $ref);
                }
                $result = $st->execute(); $st->close(); return $result;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Orders] execute: ' . $e->getMessage()); }
        return false;
    }
}

// =============================================================================
// Main execution
// =============================================================================
try {

    // ── Auth ──────────────────────────────────────────────────────────────────
    $plainToken = bv_seller_orders_read_bearer();
    if ($plainToken === '') {
        bv_seller_orders_error('token_missing', 'Authorization token is required.', 401);
    }
    $tokenHash = hash('sha256', $plainToken);

    $db = bv_seller_orders_db();
    if ($db === null) {
        bv_seller_orders_log('No database connection.');
        bv_seller_orders_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    $tokenRow = bv_so_query_row(
        $db,
        "SELECT mat.id AS token_id, u.id AS user_id, u.email, u.role,
                u.account_status, u.first_name, u.last_name
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
        bv_seller_orders_error('token_invalid', 'Token is invalid or has expired.', 401);
    }

    bv_so_execute($db,
        'UPDATE mobile_auth_tokens SET last_used_at = NOW() WHERE id = ? LIMIT 1',
        [(int)$tokenRow['token_id']]
    );

    $userId    = (int)$tokenRow['user_id'];
    $userRole  = bv_seller_orders_clean($tokenRow['role']       ?? 'user');
    $firstName = bv_seller_orders_clean($tokenRow['first_name'] ?? '');
    $lastName  = bv_seller_orders_clean($tokenRow['last_name']  ?? '');

    if (!in_array($userRole, ['seller', 'admin'], true)) {
        bv_seller_orders_error('seller_required', 'Seller access is required.', 403);
    }

    // Admin override
    $filterSellerId = $userId;
    if ($userRole === 'admin') {
        $rawAdminTarget = bv_seller_orders_int($_GET['seller_id'] ?? 0, 0);
        if ($rawAdminTarget > 0) { $filterSellerId = $rawAdminTarget; }
    }

    // ── Verify required tables ────────────────────────────────────────────────
    if (!bv_seller_orders_table_exists($db, 'orders')) {
        bv_seller_orders_log('orders table not found.');
        bv_seller_orders_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }
    if (!bv_seller_orders_table_exists($db, 'order_items')) {
        bv_seller_orders_log('order_items table not found.');
        bv_seller_orders_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Detect column availability ────────────────────────────────────────────
    $oCols   = bv_seller_orders_columns($db, 'orders');
    $hasOCol = static fn(string $c): bool => in_array(strtolower($c), $oCols, true);

    $oiCols   = bv_seller_orders_columns($db, 'order_items');
    $hasOiCol = static fn(string $c): bool => in_array(strtolower($c), $oiCols, true);

    // ── Determine seller ownership JOIN condition on order_items ──────────────
    // Priority: oi.seller_id → oi.seller_user_id → join listings and use seller col there
    // Never use orders.user_id (that is the buyer).
    $oiSellerCol = null;
    foreach (['seller_id', 'seller_user_id'] as $cand) {
        if ($hasOiCol($cand)) { $oiSellerCol = $cand; break; }
    }

    // Fallback: join listings to find seller column
    $hasListings    = bv_seller_orders_table_exists($db, 'listings');
    $listingJoinSQL = '';
    $listingOwnerCol = null;
    if ($oiSellerCol === null && $hasListings && $hasOiCol('listing_id')) {
        $lCols = bv_seller_orders_columns($db, 'listings');
        foreach (['seller_id', 'seller_user_id', 'user_id', 'owner_user_id'] as $cand) {
            if (in_array($cand, $lCols, true)) { $listingOwnerCol = $cand; break; }
        }
        if ($listingOwnerCol !== null) {
            $listingJoinSQL = " INNER JOIN listings l ON l.id = oi.listing_id AND l.`{$listingOwnerCol}` = ?";
        }
    }

    if ($oiSellerCol === null && $listingOwnerCol === null) {
        bv_seller_orders_log('Cannot determine seller ownership column on order_items or listings.');
        bv_seller_orders_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // Build ownership condition for the subquery used in WHERE + stats
    // The subquery identifies order IDs that belong to this seller
    $ownerSubqueryParams = [$filterSellerId];
    if ($oiSellerCol !== null) {
        $ownerSubquery = "SELECT DISTINCT order_id FROM order_items WHERE `{$oiSellerCol}` = ?";
    } else {
        // listings JOIN path — param already set for the JOIN's ON clause
        $ownerSubquery = "SELECT DISTINCT oi2.order_id FROM order_items oi2
                          INNER JOIN listings l2 ON l2.id = oi2.listing_id
                          WHERE l2.`{$listingOwnerCol}` = ?";
    }

    // ── Parse query parameters ────────────────────────────────────────────────
    $page    = max(1, bv_seller_orders_int($_GET['page']  ?? 1, 1));
    $limit   = min(50, max(1, bv_seller_orders_int($_GET['limit'] ?? 20, 20)));
    $offset  = ($page - 1) * $limit;

    $qStatus        = trim((string)($_GET['status']         ?? 'all'));
    $qPaymentStatus = trim((string)($_GET['payment_status'] ?? 'all'));
    $qSort          = trim((string)($_GET['sort']           ?? 'newest'));
    $qSearch        = trim((string)($_GET['q']              ?? ''));

    $allowedStatuses = ['all','pending','pending_payment','reserved','paid','confirmed',
                        'processing','packing','shipped','completed','cancelled','refunded'];
    $allowedPayStatuses = ['all','pending','paid','failed','refunded','cancelled'];
    $allowedSorts    = ['newest','oldest','total_high','total_low'];

    if (!in_array($qStatus,        $allowedStatuses,    true)) { $qStatus        = 'all'; }
    if (!in_array($qPaymentStatus, $allowedPayStatuses, true)) { $qPaymentStatus = 'all'; }
    if (!in_array($qSort,          $allowedSorts,       true)) { $qSort          = 'newest'; }

    // ── Optional tables ───────────────────────────────────────────────────────
    $hasRefunds       = bv_seller_orders_table_exists($db, 'order_refunds');
    $hasCancellations = bv_seller_orders_table_exists($db, 'order_cancellations');
    $hasUsers         = bv_seller_orders_table_exists($db, 'users');

    // ── Build orders WHERE clause ─────────────────────────────────────────────
    $whereParts  = ["o.id IN ({$ownerSubquery})"];
    $whereParams = $ownerSubqueryParams;

    if ($qStatus !== 'all' && $hasOCol('status')) {
        $whereParts[]  = 'o.`status` = ?';
        $whereParams[] = $qStatus;
    }
    if ($qPaymentStatus !== 'all' && $hasOCol('payment_status')) {
        $whereParts[]  = 'o.`payment_status` = ?';
        $whereParams[] = $qPaymentStatus;
    }
    if ($qSearch !== '') {
        $searchParts = [];
        // order_code search
        if ($hasOCol('order_code')) {
            $searchParts[]  = 'o.`order_code` LIKE ?';
            $whereParams[]  = '%' . $qSearch . '%';
        }
        // buyer name / email
        if ($hasOCol('buyer_name'))  { $searchParts[] = 'o.`buyer_name` LIKE ?';  $whereParams[] = '%'.$qSearch.'%'; }
        if ($hasOCol('buyer_email')) { $searchParts[] = 'o.`buyer_email` LIKE ?'; $whereParams[] = '%'.$qSearch.'%'; }
        // item title via subquery
        if ($hasOiCol('title_snapshot') || $hasOiCol('item_title')) {
            $titleCol = $hasOiCol('title_snapshot') ? 'title_snapshot' : 'item_title';
            $searchParts[] = "EXISTS (SELECT 1 FROM order_items oix WHERE oix.order_id = o.id AND oix.`{$titleCol}` LIKE ?)";
            $whereParams[] = '%' . $qSearch . '%';
        }
        if (!empty($searchParts)) {
            $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
        }
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $whereParts);

    // ── ORDER BY ──────────────────────────────────────────────────────────────
    $totalCol = $hasOCol('total') ? 'o.`total`' : null;
    $orderSQL = match($qSort) {
        'oldest'     => $hasOCol('created_at') ? 'ORDER BY o.created_at ASC, o.id ASC'   : 'ORDER BY o.id ASC',
        'total_high' => $totalCol               ? "ORDER BY {$totalCol} DESC, o.id DESC"  : 'ORDER BY o.id DESC',
        'total_low'  => $totalCol               ? "ORDER BY {$totalCol} ASC, o.id DESC"   : 'ORDER BY o.id DESC',
        default      => $hasOCol('created_at') ? 'ORDER BY o.created_at DESC, o.id DESC' : 'ORDER BY o.id DESC',
    };

    // ── Build SELECT for orders ───────────────────────────────────────────────
    $orderSelectCols = ['o.id'];
    foreach (['order_code','status','payment_status','payment_provider','currency','total',
              'buyer_name','buyer_email','user_id',
              'ship_name','ship_country','ship_province','ship_city','ship_postal_code',
              'ship_district','ship_subdistrict',
              'created_at','paid_at','updated_at'] as $col) {
        if ($hasOCol($col)) { $orderSelectCols[] = "o.`{$col}`"; }
    }

    $orderSelectSQL = 'SELECT ' . implode(', ', $orderSelectCols) . ' FROM orders o';

    // ── Count ─────────────────────────────────────────────────────────────────
    $totalCount = (int) bv_so_query_scalar(
        $db,
        "SELECT COUNT(DISTINCT o.id) FROM orders o {$whereSQL}",
        $whereParams,
        0
    );

    // ── Fetch order rows ──────────────────────────────────────────────────────
    $dataParams = array_merge($whereParams, [$limit, $offset]);
    $orderRows  = bv_so_query_rows(
        $db,
        "{$orderSelectSQL} {$whereSQL} {$orderSQL} LIMIT ? OFFSET ?",
        $dataParams
    );

    // ── Collect order IDs for batch sub-queries ───────────────────────────────
    $orderIds = array_map(static fn($r) => (int)$r['id'], $orderRows);

    // Batch fetch seller-owned items for all orders on this page
    $itemsByOrder   = [];
    $refundByOrder  = [];
    $cancelByOrder  = [];

    if (!empty($orderIds)) {
        $ph = implode(', ', array_fill(0, count($orderIds), '?'));

        // ── Seller-owned items per order (subtotal + fulfillment + count) ─────
        if ($oiSellerCol !== null) {
            $itemParams = array_merge([$filterSellerId], $orderIds);
            $itemSQL    = "SELECT oi.order_id,
                                  COUNT(*) AS item_row_count,
                                  COALESCE(SUM(oi.line_total), SUM(oi.qty * oi.unit_price), 0) AS seller_subtotal,
                                  GROUP_CONCAT(COALESCE(oi.fulfillment_status, '') SEPARATOR ',') AS fulfillment_csv
                           FROM order_items oi
                           WHERE oi.`{$oiSellerCol}` = ?
                             AND oi.order_id IN ({$ph})
                           GROUP BY oi.order_id";
            $itemRows = bv_so_query_rows($db, $itemSQL, $itemParams);
        } else {
            // Listings JOIN path
            $itemParams = array_merge([$filterSellerId], $orderIds);
            $itemSQL    = "SELECT oi.order_id,
                                  COUNT(*) AS item_row_count,
                                  COALESCE(SUM(oi.line_total), SUM(oi.qty * oi.unit_price), 0) AS seller_subtotal,
                                  GROUP_CONCAT(COALESCE(oi.fulfillment_status, '') SEPARATOR ',') AS fulfillment_csv
                           FROM order_items oi
                           INNER JOIN listings l ON l.id = oi.listing_id AND l.`{$listingOwnerCol}` = ?
                           WHERE oi.order_id IN ({$ph})
                           GROUP BY oi.order_id";
            $itemRows = bv_so_query_rows($db, $itemSQL, $itemParams);
        }
        foreach ($itemRows as $ir) {
            $itemsByOrder[(int)$ir['order_id']] = $ir;
        }

        // ── Latest refund per order ───────────────────────────────────────────
        if ($hasRefunds) {
            $refCols     = bv_seller_orders_columns($db, 'order_refunds');
            $hasRefCol   = static fn(string $c): bool => in_array($c, $refCols, true);
            $refSelectCols = ['order_id', 'id'];
            if ($hasRefCol('refund_code')) { $refSelectCols[] = 'refund_code'; }
            if ($hasRefCol('status'))      { $refSelectCols[] = 'status'; }
            $refRows = bv_so_query_rows(
                $db,
                'SELECT ' . implode(',', $refSelectCols) . " FROM order_refunds
                 WHERE order_id IN ({$ph})
                 ORDER BY id DESC",
                $orderIds
            );
            foreach ($refRows as $rr) {
                $oid = (int)$rr['order_id'];
                if (!isset($refundByOrder[$oid])) { $refundByOrder[$oid] = $rr; }
            }
        }

        // ── Latest cancellation per order ─────────────────────────────────────
        if ($hasCancellations) {
            $canCols     = bv_seller_orders_columns($db, 'order_cancellations');
            $hasCanCol   = static fn(string $c): bool => in_array($c, $canCols, true);
            $canSelectCols = ['order_id', 'id'];
            if ($hasCanCol('status')) { $canSelectCols[] = 'status'; }
            $canRows = bv_so_query_rows(
                $db,
                'SELECT ' . implode(',', $canSelectCols) . " FROM order_cancellations
                 WHERE order_id IN ({$ph})
                 ORDER BY id DESC",
                $orderIds
            );
            foreach ($canRows as $cr) {
                $oid = (int)$cr['order_id'];
                if (!isset($cancelByOrder[$oid])) { $cancelByOrder[$oid] = $cr; }
            }
        }

        // ── Buyer names from users table if available ─────────────────────────
        $buyersByUserId = [];
        if ($hasUsers && $hasOCol('user_id')) {
            $buyerUserIds = array_unique(array_filter(
                array_map(static fn($r) => bv_seller_orders_int($r['user_id'] ?? 0), $orderRows),
                static fn($id) => $id > 0
            ));
            if (!empty($buyerUserIds)) {
                $bph      = implode(', ', array_fill(0, count($buyerUserIds), '?'));
                $buyerRows = bv_so_query_rows(
                    $db,
                    "SELECT id, first_name, last_name, email FROM users WHERE id IN ({$bph})",
                    array_values($buyerUserIds)
                );
                foreach ($buyerRows as $br) {
                    $buyersByUserId[(int)$br['id']] = $br;
                }
            }
        }
    }

    // ── Fulfillment derivation helper ─────────────────────────────────────────
    $deriveFulfillment = static function (string $csv, string $orderStatus): array {
        $statuses = array_filter(array_map('trim', explode(',', $csv)));
        if (empty($statuses)) {
            // Fallback from order status
            $mapped = match($orderStatus) {
                'shipped'   => ['status' => 'shipped',   'label' => 'Shipped'],
                'completed' => ['status' => 'completed', 'label' => 'Completed'],
                'paid','confirmed','processing','packing' => ['status' => 'pending', 'label' => 'To Ship'],
                default     => ['status' => 'unknown',   'label' => 'Unknown'],
            };
            return $mapped;
        }
        $unique = array_unique($statuses);
        if (count($unique) === 1) {
            $s = $unique[0];
            return match($s) {
                'completed'  => ['status' => 'completed',  'label' => 'Completed'],
                'shipped'    => ['status' => 'shipped',    'label' => 'Shipped'],
                'processing' => ['status' => 'processing', 'label' => 'Preparing'],
                'pending'    => ['status' => 'pending',    'label' => 'To Ship'],
                default      => ['status' => 'pending',    'label' => 'To Ship'],
            };
        }
        $hasShipped    = in_array('shipped',    $statuses, true);
        $hasCompleted  = in_array('completed',  $statuses, true);
        $hasProcessing = in_array('processing', $statuses, true);
        $hasPending    = in_array('pending',    $statuses, true);
        if ($hasShipped && $hasPending)    { return ['status' => 'mixed',      'label' => 'Mixed'];    }
        if ($hasShipped && $hasProcessing) { return ['status' => 'mixed',      'label' => 'Mixed'];    }
        if ($hasCompleted && $hasShipped)  { return ['status' => 'mixed',      'label' => 'Mixed'];    }
        if ($hasProcessing)                { return ['status' => 'processing', 'label' => 'Preparing']; }
        return ['status' => 'pending', 'label' => 'To Ship'];
    };

    // ── Format orders ─────────────────────────────────────────────────────────
    $orders = [];
    foreach ($orderRows as $row) {
        $orderId     = (int)$row['id'];
        $orderStatus = bv_seller_orders_clean($row['status'] ?? '');
        $buyerUserId = bv_seller_orders_int($row['user_id'] ?? 0);

        // Buyer name resolution: orders.buyer_name first, then users table
        $buyerName  = bv_seller_orders_clean($row['buyer_name']  ?? '');
        $buyerEmail = bv_seller_orders_clean($row['buyer_email'] ?? '');
        if ($buyerUserId > 0 && isset($buyersByUserId[$buyerUserId])) {
            $bu = $buyersByUserId[$buyerUserId];
            if ($buyerName === '') {
                $buyerName = trim(
                    bv_seller_orders_clean($bu['first_name'] ?? '') . ' ' .
                    bv_seller_orders_clean($bu['last_name']  ?? '')
                );
            }
            if ($buyerEmail === '') {
                $buyerEmail = bv_seller_orders_clean($bu['email'] ?? '');
            }
        }

        // Seller slice from batched items
        $itemData       = $itemsByOrder[$orderId] ?? null;
        $sellerSubtotal = round(bv_seller_orders_float($itemData['seller_subtotal'] ?? 0), 2);
        $sellerCount    = bv_seller_orders_int($itemData['item_row_count']           ?? 0);
        $fulfillmentCsv = (string)($itemData['fulfillment_csv'] ?? '');
        $fulfillment    = $deriveFulfillment($fulfillmentCsv, $orderStatus);

        // Latest refund
        $refRow = $refundByOrder[$orderId] ?? null;
        $latestRefund = [
            'id'          => $refRow ? bv_seller_orders_int($refRow['id']) : null,
            'status'      => $refRow ? bv_seller_orders_clean($refRow['status']      ?? '') : '',
            'refund_code' => $refRow ? bv_seller_orders_clean($refRow['refund_code'] ?? '') : '',
        ];

        // Latest cancellation
        $canRow = $cancelByOrder[$orderId] ?? null;
        $latestCancellation = [
            'id'     => $canRow ? bv_seller_orders_int($canRow['id']) : null,
            'status' => $canRow ? bv_seller_orders_clean($canRow['status'] ?? '') : '',
        ];

        // Shipping — map from actual schema columns
        $shipProvince = bv_seller_orders_clean(
            $row['ship_province'] ?? $row['ship_district'] ?? $row['ship_subdistrict'] ?? ''
        );
        $shipCity = bv_seller_orders_clean(
            $row['ship_city'] ?? $row['ship_district'] ?? ''
        );

        $orders[] = [
            'id'                  => $orderId,
            'order_code'          => bv_seller_orders_clean($row['order_code']        ?? ''),
            'status'              => $orderStatus,
            'payment_status'      => bv_seller_orders_clean($row['payment_status']    ?? ''),
            'payment_provider'    => bv_seller_orders_clean($row['payment_provider']  ?? ''),
            'currency'            => bv_seller_orders_clean($row['currency']          ?? 'USD'),
            'order_total'         => round(bv_seller_orders_float($row['total']       ?? 0), 2),
            'seller_subtotal'     => $sellerSubtotal,
            'seller_item_count'   => $sellerCount,
            'buyer'               => [
                'id'    => $buyerUserId > 0 ? $buyerUserId : null,
                'name'  => $buyerName,
                'email' => $buyerEmail,
            ],
            'shipping'            => [
                'name'        => bv_seller_orders_clean($row['ship_name']         ?? ''),
                'country'     => bv_seller_orders_clean($row['ship_country']      ?? ''),
                'province'    => $shipProvince,
                'city'        => $shipCity,
                'postal_code' => bv_seller_orders_clean($row['ship_postal_code']  ?? ''),
            ],
            'fulfillment'         => $fulfillment,
            'latest_refund'       => $latestRefund,
            'latest_cancellation' => $latestCancellation,
            'created_at'          => bv_seller_orders_clean($row['created_at']     ?? ''),
            'paid_at'             => bv_seller_orders_clean($row['paid_at']        ?? ''),
            'updated_at'          => bv_seller_orders_clean($row['updated_at']     ?? ''),
        ];
    }

    // ── Stats (all seller orders, unfiltered by page params) ─────────────────
    $statsBase = [
        'total' => 0, 'pending' => 0, 'pending_payment' => 0,
        'paid' => 0, 'processing' => 0, 'shipped' => 0,
        'completed' => 0, 'cancelled' => 0, 'refunded' => 0,
    ];

    $statsBase['total'] = (int) bv_so_query_scalar(
        $db,
        "SELECT COUNT(DISTINCT o.id) FROM orders o WHERE o.id IN ({$ownerSubquery})",
        $ownerSubqueryParams,
        0
    );

    if ($hasOCol('status')) {
        $statsRows = bv_so_query_rows(
            $db,
            "SELECT o.`status`, COUNT(DISTINCT o.id) AS cnt
             FROM orders o
             WHERE o.id IN ({$ownerSubquery})
             GROUP BY o.`status`",
            $ownerSubqueryParams
        );
        foreach ($statsRows as $sr) {
            $s = (string)($sr['status'] ?? '');
            if (array_key_exists($s, $statsBase)) {
                $statsBase[$s] = (int)($sr['cnt'] ?? 0);
            }
        }
    }

    // ── Seller identity ───────────────────────────────────────────────────────
    $sellerName = trim($firstName . ' ' . $lastName);
    if ($sellerName === '' && $filterSellerId !== $userId) {
        $sellerRow = bv_so_query_row(
            $db,
            'SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1',
            [$filterSellerId]
        );
        if ($sellerRow) {
            $sellerName = trim(
                bv_seller_orders_clean($sellerRow['first_name'] ?? '') . ' ' .
                bv_seller_orders_clean($sellerRow['last_name']  ?? '')
            );
        }
    }

    // ── Response ──────────────────────────────────────────────────────────────
    bv_seller_orders_json(200, [
        'ok'   => true,
        'data' => [
            'seller'     => [
                'id'   => $filterSellerId,
                'name' => $sellerName ?: "Seller #{$filterSellerId}",
                'role' => $userRole,
            ],
            'orders'     => $orders,
            'stats'      => $statsBase,
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
    bv_seller_orders_log('Unhandled exception: ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_seller_orders_json(500, [
        'ok'    => false,
        'error' => ['code' => 'server_error', 'message' => 'Something went wrong.'],
    ]);
}
