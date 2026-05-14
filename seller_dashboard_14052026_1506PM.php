<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/seller_dashboard.php
// Authenticated seller dashboard summary endpoint.
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
// File lives at: /public_html/api/mobile/v1/seller_dashboard.php
// dirname(__DIR__, 3) → /public_html
// dirname(__DIR__, 4) → account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_seller_dashboard_public_root')) {
    function bv_seller_dashboard_public_root(): string { return dirname(__DIR__, 3); }
}
if (!function_exists('bv_seller_dashboard_project_root')) {
    function bv_seller_dashboard_project_root(): string { return dirname(bv_seller_dashboard_public_root()); }
}

// =============================================================================
// Helpers
// =============================================================================

if (!function_exists('bv_seller_dashboard_json')) {
    function bv_seller_dashboard_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_seller_dashboard_error')) {
    function bv_seller_dashboard_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_seller_dashboard_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_seller_dashboard_log')) {
    function bv_seller_dashboard_log(string $message): void
    {
        error_log('[BV Seller Dashboard] ' . $message);
        $line = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        foreach ([
            bv_seller_dashboard_project_root() . '/private_html/mobile_api.log',
            bv_seller_dashboard_public_root()  . '/logs/mobile_api.log',
        ] as $lf) {
            $ld = dirname($lf);
            if (is_dir($ld) && is_writable($ld)) { @file_put_contents($lf, $line, FILE_APPEND | LOCK_EX); break; }
        }
    }
}

if (!function_exists('bv_seller_dashboard_db')) {
    function bv_seller_dashboard_db(): ?object
    {
        static $cached = false;
        static $connection = null;
        if ($cached !== false) { return $connection; }
        $cached     = true;
        $publicRoot = bv_seller_dashboard_public_root();
        foreach ([
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $publicRoot . '/includes/config.php',
        ] as $cfg) {
            if (!is_file($cfg)) { continue; }
            $loader = static function (string $path): array {
                $db_host=$db_user=$db_pass=$db_name=$db_port=null;
                $host=$user=$pass=$name=$port=null;
                $DB_HOST=$DB_USER=$DB_PASS=$DB_NAME=$DB_PORT=null;
                $dsn=null; $pdo=$conn=$db=$mysqli=$link=null;
                /** @noinspection PhpIncludeInspection */ @include $path;
                return compact('db_host','db_user','db_pass','db_name','db_port',
                    'host','user','pass','name','port',
                    'DB_HOST','DB_USER','DB_PASS','DB_NAME','DB_PORT',
                    'dsn','pdo','conn','db','mysqli','link');
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
                    if ($obj instanceof PDO || $obj instanceof mysqli) { $connection=$obj; return $connection; }
                }
            }
            $h  = $vars['db_host'] ?? $vars['host']    ?? $vars['DB_HOST']  ?? null;
            $u  = $vars['db_user'] ?? $vars['user']    ?? $vars['DB_USER']  ?? null;
            $p  = $vars['db_pass'] ?? $vars['pass']    ?? $vars['DB_PASS']  ?? null;
            $n  = $vars['db_name'] ?? $vars['name']    ?? $vars['DB_NAME']  ?? null;
            $pt = (int)($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);
            if ($h && $u !== null && $n) {
                try {
                    $pdo = new PDO("mysql:host={$h};port={$pt};dbname={$n};charset=utf8mb4", $u, (string)$p, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]);
                    $connection=$pdo; return $connection;
                } catch (\Throwable $e) { error_log('[BV Seller Dashboard] PDO: '.$e->getMessage()); }
            }
            if ($h && $u !== null && $n && function_exists('mysqli_connect')) {
                try {
                    $m = @new mysqli($h,(string)$u,(string)$p,$n,$pt?:3306);
                    if (!$m->connect_errno) { $m->set_charset('utf8mb4'); $connection=$m; return $connection; }
                    error_log('[BV Seller Dashboard] mysqli: '.$m->connect_error);
                } catch (\Throwable $e) { error_log('[BV Seller Dashboard] mysqli ex: '.$e->getMessage()); }
            }
            break;
        }
        return null;
    }
}

if (!function_exists('bv_seller_dashboard_table_exists')) {
    function bv_seller_dashboard_table_exists(object $db, string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) { return false; }
        try {
            if ($db instanceof PDO) {
                $prev = $db->getAttribute(PDO::ATTR_ERRMODE);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                try { $db->query("SELECT 1 FROM `{$table}` LIMIT 1"); $db->setAttribute(PDO::ATTR_ERRMODE,$prev); return true; }
                catch (\PDOException $e) { $db->setAttribute(PDO::ATTR_ERRMODE,$prev); return false; }
            }
            if ($db instanceof mysqli) {
                $res = $db->query("SELECT 1 FROM `{$table}` LIMIT 1");
                if ($res!==false) { if ($res instanceof mysqli_result){$res->free();} return true; }
                return false;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Dashboard] table_exists: '.$e->getMessage()); }
        return false;
    }
}

if (!function_exists('bv_seller_dashboard_columns')) {
    function bv_seller_dashboard_columns(object $db, string $table): array
    {
        $cols=[];
        try {
            if ($db instanceof PDO) {
                $st=$db->prepare('SHOW COLUMNS FROM `'.str_replace('`','',$table).'`');
                $st->execute();
                foreach ($st->fetchAll() as $r) { $cols[]=strtolower((string)($r['Field']??'')); }
            } elseif ($db instanceof mysqli) {
                $safe=str_replace('`','',$table);
                $res=$db->query("SHOW COLUMNS FROM `{$safe}`");
                if ($res) { while ($r=$res->fetch_assoc()) { $cols[]=strtolower((string)($r['Field']??'')); } }
            }
        } catch (\Throwable $e) { error_log('[BV Seller Dashboard] columns: '.$e->getMessage()); }
        return $cols;
    }
}

if (!function_exists('bv_seller_dashboard_has_col')) {
    function bv_seller_dashboard_has_col(object $db, string $table, string $column): bool
    {
        static $cache=[];
        $key=$table.'.'.$column;
        if (!array_key_exists($key,$cache)) {
            $cache[$key]=in_array(strtolower($column),bv_seller_dashboard_columns($db,$table),true);
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_sd_clean')) {
    function bv_sd_clean($v): string
    { if ($v===null||$v===false) return ''; return htmlspecialchars_decode(strip_tags((string)$v),ENT_QUOTES|ENT_HTML5); }
}
if (!function_exists('bv_sd_int')) {
    function bv_sd_int($v,int $d=0): int { return is_numeric($v)?(int)$v:$d; }
}
if (!function_exists('bv_sd_float')) {
    function bv_sd_float($v,float $d=0.0): float { return is_numeric($v)?(float)$v:$d; }
}
if (!function_exists('bv_sd_asset_url')) {
    function bv_sd_asset_url(?string $p): string
    {
        if (!$p||trim($p)==='') return '';
        $p=trim($p);
        if (str_starts_with($p,'http://')||str_starts_with($p,'https://')) return $p;
        return rtrim(defined('BV_SITE_URL')?BV_SITE_URL:'https://www.bettavaro.com','/').'/'.ltrim($p,'/');
    }
}
if (!function_exists('bv_sd_listing_url')) {
    function bv_sd_listing_url(?int $id,?string $slug): string
    {
        $base=rtrim(defined('BV_SITE_URL')?BV_SITE_URL:'https://www.bettavaro.com','/');
        if ($slug&&$slug!=='') return $base.'/listing.php?slug='.urlencode($slug);
        if ($id&&$id>0) return $base.'/listing.php?id='.$id;
        return $base.'/listing.php';
    }
}
if (!function_exists('bv_sd_read_bearer')) {
    function bv_sd_read_bearer(): string
    {
        $h='';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) { $h=(string)$_SERVER['HTTP_AUTHORIZATION']; }
        elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) { $h=(string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']; }
        elseif (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k=>$v) {
                if (strtolower($k)==='authorization') { $h=(string)$v; break; }
            }
        }
        if ($h===''||!preg_match('/^Bearer\s+(\S+)$/i',trim($h),$m)) return '';
        return $m[1];
    }
}

// ── Query helpers ─────────────────────────────────────────────────────────────
if (!function_exists('bv_sd_row')) {
    function bv_sd_row(object $db,string $sql,array $p=[]): ?array
    {
        try {
            if ($db instanceof PDO) { $st=$db->prepare($sql);$st->execute($p);$r=$st->fetch();return $r?:null; }
            if ($db instanceof mysqli) {
                $st=$db->prepare($sql); if($st===false)return null;
                if (!empty($p)) { $t=implode('',array_map(static fn($v)=>is_int($v)?'i':(is_float($v)?'d':'s'),$p));$ref=[&$t];foreach($p as $k=>$_){$ref[]=&$p[$k];}call_user_func_array([$st,'bind_param'],$ref); }
                $st->execute();$res=$st->get_result();$r=$res?$res->fetch_assoc():null;$st->close();return $r?:null;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Dashboard] row: '.$e->getMessage()); }
        return null;
    }
}
if (!function_exists('bv_sd_rows')) {
    function bv_sd_rows(object $db,string $sql,array $p=[]): array
    {
        try {
            if ($db instanceof PDO) { $st=$db->prepare($sql);$st->execute($p);return $st->fetchAll()?:[]; }
            if ($db instanceof mysqli) {
                $st=$db->prepare($sql); if($st===false)return [];
                if (!empty($p)) { $t=implode('',array_map(static fn($v)=>is_int($v)?'i':(is_float($v)?'d':'s'),$p));$ref=[&$t];foreach($p as $k=>$_){$ref[]=&$p[$k];}call_user_func_array([$st,'bind_param'],$ref); }
                $st->execute();$res=$st->get_result();$rows=[];if($res){while($r=$res->fetch_assoc()){$rows[]=$r;}}$st->close();return $rows;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Dashboard] rows: '.$e->getMessage()); }
        return [];
    }
}
if (!function_exists('bv_sd_scalar')) {
    function bv_sd_scalar(object $db,string $sql,array $p=[],$d=0)
    {
        try {
            if ($db instanceof PDO) { $st=$db->prepare($sql);$st->execute($p);$r=$st->fetch(PDO::FETCH_NUM);return $r?$r[0]:$d; }
            if ($db instanceof mysqli) {
                $st=$db->prepare($sql); if($st===false)return $d;
                if (!empty($p)) { $t=implode('',array_map(static fn($v)=>is_int($v)?'i':(is_float($v)?'d':'s'),$p));$ref=[&$t];foreach($p as $k=>$_){$ref[]=&$p[$k];}call_user_func_array([$st,'bind_param'],$ref); }
                $st->execute();$res=$st->get_result();$r=$res?$res->fetch_row():null;$st->close();return $r?$r[0]:$d;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Dashboard] scalar: '.$e->getMessage()); }
        return $d;
    }
}
if (!function_exists('bv_sd_exec')) {
    function bv_sd_exec(object $db,string $sql,array $p=[]): bool
    {
        try {
            if ($db instanceof PDO) { return $db->prepare($sql)->execute($p); }
            if ($db instanceof mysqli) {
                $st=$db->prepare($sql); if($st===false)return false;
                if (!empty($p)) { $t=implode('',array_map(static fn($v)=>is_int($v)?'i':(is_float($v)?'d':'s'),$p));$ref=[&$t];foreach($p as $k=>$_){$ref[]=&$p[$k];}call_user_func_array([$st,'bind_param'],$ref); }
                $result=$st->execute();$st->close();return $result;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Dashboard] exec: '.$e->getMessage()); }
        return false;
    }
}

// =============================================================================
// Main execution
// =============================================================================
try {

    // ── Auth ──────────────────────────────────────────────────────────────────
    $plainToken = bv_sd_read_bearer();
    if ($plainToken==='') { bv_seller_dashboard_error('token_missing','Authorization token is required.',401); }
    $tokenHash = hash('sha256',$plainToken);

    $db = bv_seller_dashboard_db();
    if ($db===null) { bv_seller_dashboard_log('No DB connection.'); bv_seller_dashboard_error('db_unavailable','Service temporarily unavailable.',503); }

    $tokenRow = bv_sd_row($db,
        "SELECT mat.id AS token_id,u.id AS user_id,u.email,u.role,u.account_status,u.first_name,u.last_name
         FROM mobile_auth_tokens mat INNER JOIN users u ON u.id=mat.user_id
         WHERE mat.token_hash=? AND mat.revoked_at IS NULL AND mat.expires_at>NOW() AND u.account_status='active' LIMIT 1",
        [$tokenHash]
    );
    if ($tokenRow===null) { bv_seller_dashboard_error('token_invalid','Token is invalid or has expired.',401); }

    bv_sd_exec($db,'UPDATE mobile_auth_tokens SET last_used_at=NOW() WHERE id=? LIMIT 1',[(int)$tokenRow['token_id']]);

    $userId    = (int)$tokenRow['user_id'];
    $userRole  = bv_sd_clean($tokenRow['role']       ?? 'user');
    $firstName = bv_sd_clean($tokenRow['first_name'] ?? '');
    $lastName  = bv_sd_clean($tokenRow['last_name']  ?? '');
    $userEmail = bv_sd_clean($tokenRow['email']      ?? '');

    if (!in_array($userRole,['seller','admin'],true)) { bv_seller_dashboard_error('seller_required','Seller access is required.',403); }

    // Admin override
    $filterSellerId = $userId;
    if ($userRole==='admin') {
        $rawAdmin = bv_sd_int($_GET['seller_id'] ?? 0,0);
        if ($rawAdmin>0) { $filterSellerId=$rawAdmin; }
    }

    // Period
    $days   = min(365,max(1,bv_sd_int($_GET['days'] ?? 30,30)));
    $dateTo = date('Y-m-d H:i:s');
    $dateFrom = date('Y-m-d H:i:s',strtotime("-{$days} days"));

    $siteBase = rtrim(defined('BV_SITE_URL')?BV_SITE_URL:'https://www.bettavaro.com','/');

    // ── Required tables check ─────────────────────────────────────────────────
    if (!bv_seller_dashboard_table_exists($db,'listings')) { bv_seller_dashboard_error('db_unavailable','Service temporarily unavailable.',503); }

    // ── Detect optional tables ────────────────────────────────────────────────
    $hasOrders      = bv_seller_dashboard_table_exists($db,'orders');
    $hasOI          = $hasOrders && bv_seller_dashboard_table_exists($db,'order_items');
    $hasReviews     = bv_seller_dashboard_table_exists($db,'listing_reviews');
    $hasRanking     = bv_seller_dashboard_table_exists($db,'listing_ranking_scores');
    $hasRankCache   = !$hasRanking && bv_seller_dashboard_table_exists($db,'listing_ranking_cache');
    $hasRefunds     = $hasOI && bv_seller_dashboard_table_exists($db,'order_refunds');
    $hasRefItems    = $hasRefunds && bv_seller_dashboard_table_exists($db,'order_refund_items');
    $hasBalance     = bv_seller_dashboard_table_exists($db,'seller_balance_entries');
    $hasSellerApps  = bv_seller_dashboard_table_exists($db,'seller_applications');

    // ── Listing ownership column ──────────────────────────────────────────────
    $lCols = bv_seller_dashboard_columns($db,'listings');
    $hasLCol = static fn(string $c): bool => in_array(strtolower($c),$lCols,true);
    $lOwnerCol = null;
    foreach (['seller_id','seller_user_id','user_id','owner_user_id'] as $cand) {
        if ($hasLCol($cand)) { $lOwnerCol=$cand; break; }
    }

    // ── Order item ownership ──────────────────────────────────────────────────
    $oiSellerCol    = null;
    $listingOwnerCol = null;
    if ($hasOI) {
        $oiCols = bv_seller_dashboard_columns($db,'order_items');
        foreach (['seller_id','seller_user_id'] as $cand) {
            if (in_array($cand,$oiCols,true)) { $oiSellerCol=$cand; break; }
        }
        if ($oiSellerCol===null) {
            foreach (['seller_id','seller_user_id','user_id','owner_user_id'] as $cand) {
                if ($hasLCol($cand)) { $listingOwnerCol=$cand; break; }
            }
        }
    }

    // Ownership subquery for orders (reused across order-related sections)
    // NEVER uses orders.user_id
    $orderOwnerSubquery = '';
    $orderOwnerParams   = [];
    if ($hasOI) {
        if ($oiSellerCol!==null) {
            $orderOwnerSubquery = "SELECT DISTINCT order_id FROM order_items WHERE `{$oiSellerCol}`=?";
            $orderOwnerParams   = [$filterSellerId];
        } elseif ($listingOwnerCol!==null) {
            $orderOwnerSubquery = "SELECT DISTINCT oi2.order_id FROM order_items oi2 INNER JOIN listings l2 ON l2.id=oi2.listing_id WHERE l2.`{$listingOwnerCol}`=?";
            $orderOwnerParams   = [$filterSellerId];
        }
    }

    // ── Seller profile ────────────────────────────────────────────────────────
    $farmName          = '';
    $applicationStatus = '';
    if ($filterSellerId !== $userId) {
        $sellerURow = bv_sd_row($db,'SELECT email,first_name,last_name FROM users WHERE id=? LIMIT 1',[$filterSellerId]);
        if ($sellerURow) {
            $firstName = bv_sd_clean($sellerURow['first_name']??'');
            $lastName  = bv_sd_clean($sellerURow['last_name'] ??'');
            $userEmail = bv_sd_clean($sellerURow['email']     ??'');
        }
    }
    if ($hasSellerApps) {
        $saRow = bv_sd_row($db,"SELECT farm_name,application_status FROM seller_applications WHERE user_id=? ORDER BY id DESC LIMIT 1",[$filterSellerId]);
        if ($saRow) {
            $farmName          = bv_sd_clean($saRow['farm_name']          ??'');
            $applicationStatus = bv_sd_clean($saRow['application_status'] ??'');
        }
    }
    $sellerName = trim($firstName.' '.$lastName) ?: "Seller #{$filterSellerId}";

    // ── Listing summary ───────────────────────────────────────────────────────
    $listingSummary = ['total'=>0,'active'=>0,'draft'=>0,'pending'=>0,'hidden'=>0,'sold'=>0,'available'=>0,'reserved'=>0];
    if ($lOwnerCol!==null) {
        $listingSummary['total'] = (int)bv_sd_scalar($db,"SELECT COUNT(*) FROM listings WHERE `{$lOwnerCol}`=?",[$filterSellerId]);
        if ($hasLCol('status')) {
            $lsRows = bv_sd_rows($db,"SELECT status,COUNT(*) AS cnt FROM listings WHERE `{$lOwnerCol}`=? GROUP BY status",[$filterSellerId]);
            foreach ($lsRows as $lr) {
                $s=bv_sd_clean($lr['status']??'');
                if (in_array($s,['active','published','available'],true)) { $listingSummary['active'] += (int)($lr['cnt']??0); }
                elseif (isset($listingSummary[$s])) { $listingSummary[$s] += (int)($lr['cnt']??0); }
            }
        }
        if ($hasLCol('sale_status')) {
            $ssRows = bv_sd_rows($db,"SELECT sale_status,COUNT(*) AS cnt FROM listings WHERE `{$lOwnerCol}`=? GROUP BY sale_status",[$filterSellerId]);
            foreach ($ssRows as $sr) {
                $s=bv_sd_clean($sr['sale_status']??'');
                if (in_array($s,['available','reserved'],true) && isset($listingSummary[$s])) {
                    $listingSummary[$s] = (int)($sr['cnt']??0);
                }
                if ($s==='sold') { $listingSummary['sold'] = max($listingSummary['sold'],(int)($sr['cnt']??0)); }
            }
        }
    }

    // ── Recent listings ───────────────────────────────────────────────────────
    $recentListings = [];
    if ($lOwnerCol!==null) {
        $rlSelCols = ['l.id'];
        foreach (['title','slug','price','currency','status','sale_status','created_at'] as $c) {
            if ($hasLCol($c)) { $rlSelCols[]="l.`{$c}`"; }
        }
        $rlCoverCol=null;
        foreach (['cover_image','image','image_path','main_image','thumbnail'] as $c) {
            if ($hasLCol($c)) { $rlCoverCol=$c; break; }
        }
        if ($rlCoverCol) { $rlSelCols[]="l.`{$rlCoverCol}` AS cover_image"; }

        $rlRankCol=null;
        $rlRankJoin='';
        if ($hasRanking) { $rlSelCols[]='COALESCE(lrs.final_score,0) AS ranking_score'; $rlRankJoin=' LEFT JOIN listing_ranking_scores lrs ON lrs.listing_id=l.id'; }
        elseif ($hasRankCache) {
            $rcCols=bv_seller_dashboard_columns($db,'listing_ranking_cache');
            $rlRankCol=in_array('final_score',$rcCols,true)?'final_score':(in_array('ranking_score',$rcCols,true)?'ranking_score':null);
            if ($rlRankCol) { $rlSelCols[]="COALESCE(lrc.`{$rlRankCol}`,0) AS ranking_score"; $rlRankJoin=' LEFT JOIN listing_ranking_cache lrc ON lrc.listing_id=l.id'; }
            else { $rlSelCols[]='0 AS ranking_score'; }
        } else { $rlSelCols[]='0 AS ranking_score'; }

        $rlOrderBy = $hasLCol('created_at') ? 'ORDER BY l.created_at DESC,l.id DESC' : 'ORDER BY l.id DESC';
        $rlRows = bv_sd_rows($db,
            'SELECT '.implode(', ',$rlSelCols)." FROM listings l{$rlRankJoin} WHERE l.`{$lOwnerCol}`=? {$rlOrderBy} LIMIT 5",
            [$filterSellerId]
        );
        foreach ($rlRows as $r) {
            $cur=bv_sd_clean($r['currency']??'USD')?:'USD';
            $amt=bv_sd_float($r['price']??0);
            $slug=bv_sd_clean($r['slug']??'');
            $recentListings[]=[
                'id'           => bv_sd_int($r['id']),
                'title'        => bv_sd_clean($r['title']??''),
                'slug'         => $slug,
                'url'          => bv_sd_listing_url(bv_sd_int($r['id']),$slug),
                'cover_image'  => bv_sd_asset_url(bv_sd_clean($r['cover_image']??'')),
                'price'        => ['amount'=>$amt,'currency'=>$cur,'formatted'=>$cur.' '.number_format($amt,2)],
                'status'       => bv_sd_clean($r['status']??''),
                'sale_status'  => bv_sd_clean($r['sale_status']??''),
                'ranking_score'=> round(bv_sd_float($r['ranking_score']??0),4),
                'created_at'   => bv_sd_clean($r['created_at']??''),
            ];
        }
    }

    // ── Order summary + sales summary + recent orders ─────────────────────────
    $orderSummary = ['total_orders'=>0,'pending'=>0,'pending_payment'=>0,'paid'=>0,
                     'processing'=>0,'shipped'=>0,'completed'=>0,'cancelled'=>0,'refunded'=>0];
    $salesSummary = ['currency'=>'USD','seller_gross_sales'=>0.0,'seller_paid_sales'=>0.0,
                     'seller_refunded_amount'=>0.0,'seller_net_sales'=>0.0,'sold_item_count'=>0];
    $fulfillmentSummary = ['to_ship'=>0,'processing'=>0,'shipped'=>0,'completed'=>0];
    $recentOrders = [];

    if ($hasOI && $orderOwnerSubquery!=='') {
        $oCols   = bv_seller_dashboard_columns($db,'orders');
        $hasOCol = static fn(string $c): bool => in_array(strtolower($c),$oCols,true);
        $oiCols2 = bv_seller_dashboard_columns($db,'order_items');
        $hasOiCol2 = static fn(string $c): bool => in_array(strtolower($c),$oiCols2,true);

        // Line total expression for order_items
        $ltExpr = $oiSellerCol!==null
            ? "CASE WHEN oi.line_total IS NOT NULL THEN oi.line_total ELSE oi.qty*oi.unit_price END"
            : "CASE WHEN oi.line_total IS NOT NULL THEN oi.line_total ELSE oi.qty*oi.unit_price END";
        $oiWhere = $oiSellerCol!==null
            ? "oi.`{$oiSellerCol}`=?"
            : "EXISTS(SELECT 1 FROM listings lx WHERE lx.id=oi.listing_id AND lx.`{$listingOwnerCol}`=?)";

        // Date filter on orders if column exists
        $periodWhere = $hasOCol('created_at') ? "AND o.created_at BETWEEN ? AND ?" : '';
        $periodParams = $hasOCol('created_at') ? [$dateFrom,$dateTo] : [];

        // Order status summary
        $orderSummary['total_orders'] = (int)bv_sd_scalar($db,
            "SELECT COUNT(DISTINCT o.id) FROM orders o WHERE o.id IN ({$orderOwnerSubquery}) {$periodWhere}",
            array_merge($orderOwnerParams,$periodParams)
        );
        if ($hasOCol('status')) {
            $osRows = bv_sd_rows($db,
                "SELECT o.status,COUNT(DISTINCT o.id) AS cnt FROM orders o WHERE o.id IN ({$orderOwnerSubquery}) {$periodWhere} GROUP BY o.status",
                array_merge($orderOwnerParams,$periodParams)
            );
            $statusMap = ['pending'=>'pending','pending_payment'=>'pending_payment','reserved'=>'pending',
                          'paid'=>'paid','paid-awaiting-verify'=>'paid','confirmed'=>'paid',
                          'processing'=>'processing','packing'=>'processing','shipped'=>'shipped',
                          'completed'=>'completed','cancelled'=>'cancelled','refunded'=>'refunded'];
            foreach ($osRows as $or2) {
                $s=bv_sd_clean($or2['status']??'');
                $bucket=$statusMap[$s]??null;
                if ($bucket && isset($orderSummary[$bucket])) { $orderSummary[$bucket]+=(int)($or2['cnt']??0); }
            }
        }

        // Sales summary — from order_items only (never orders.total)
        $paidOrderStatuses = "'paid','paid-awaiting-verify','confirmed','processing','packing','shipped','completed','refunded'";
        $paidWhereClause   = $hasOCol('payment_status')
            ? "(o.payment_status='paid' OR o.status IN ({$paidOrderStatuses}))"
            : "o.status IN ({$paidOrderStatuses})";

        if ($oiSellerCol!==null) {
            $grossRow = bv_sd_row($db,
                "SELECT COALESCE(SUM({$ltExpr}),0) AS gross
                 FROM order_items oi
                 INNER JOIN orders o ON o.id=oi.order_id
                 WHERE {$oiWhere} AND o.id IN ({$orderOwnerSubquery}) {$periodWhere}",
                array_merge([$filterSellerId],$orderOwnerParams,$periodParams)
            );
            $paidRow = bv_sd_row($db,
                "SELECT COALESCE(SUM({$ltExpr}),0) AS paid_sum,
                        COALESCE(SUM(CASE WHEN oi.quantity IS NOT NULL THEN oi.quantity ELSE oi.qty END),COUNT(*)) AS sold_cnt
                 FROM order_items oi
                 INNER JOIN orders o ON o.id=oi.order_id
                 WHERE {$oiWhere} AND o.id IN ({$orderOwnerSubquery}) AND {$paidWhereClause} {$periodWhere}",
                array_merge([$filterSellerId],$orderOwnerParams,$periodParams)
            );
        } else {
            $grossRow = bv_sd_row($db,
                "SELECT COALESCE(SUM({$ltExpr}),0) AS gross
                 FROM order_items oi
                 INNER JOIN orders o ON o.id=oi.order_id
                 INNER JOIN listings lx ON lx.id=oi.listing_id
                 WHERE lx.`{$listingOwnerCol}`=? AND o.id IN ({$orderOwnerSubquery}) {$periodWhere}",
                array_merge([$filterSellerId],$orderOwnerParams,$periodParams)
            );
            $paidRow = bv_sd_row($db,
                "SELECT COALESCE(SUM({$ltExpr}),0) AS paid_sum,
                        COALESCE(SUM(CASE WHEN oi.quantity IS NOT NULL THEN oi.quantity ELSE oi.qty END),COUNT(*)) AS sold_cnt
                 FROM order_items oi
                 INNER JOIN orders o ON o.id=oi.order_id
                 INNER JOIN listings lx ON lx.id=oi.listing_id
                 WHERE lx.`{$listingOwnerCol}`=? AND o.id IN ({$orderOwnerSubquery}) AND {$paidWhereClause} {$periodWhere}",
                array_merge([$filterSellerId],$orderOwnerParams,$periodParams)
            );
        }

        $salesSummary['seller_gross_sales'] = round(bv_sd_float($grossRow['gross']??0),2);
        $salesSummary['seller_paid_sales']  = round(bv_sd_float($paidRow['paid_sum']??0),2);
        $salesSummary['sold_item_count']    = bv_sd_int($paidRow['sold_cnt']??0);

        // Refunded amount — seller-owned refund items only
        if ($hasRefItems) {
            $riCols   = bv_seller_dashboard_columns($db,'order_refund_items');
            $hasRiCol = static fn(string $c): bool => in_array($c,$riCols,true);
            $rRefAmtCol = $hasRiCol('actual_refund_after_fee')?'ri.actual_refund_after_fee'
                        : ($hasRiCol('actual_refunded_amount')?'ri.actual_refunded_amount'
                        : ($hasRiCol('approved_refund_amount')?'ri.approved_refund_amount':'0'));
            $rCols    = bv_seller_dashboard_columns($db,'order_refunds');
            $hasRCol  = static fn(string $c): bool => in_array($c,$rCols,true);
            $refStatusWhere = $hasRCol('status') ? "AND r.status IN ('refunded','partially_refunded')" : '';

            if ($oiSellerCol!==null) {
                $refRow = bv_sd_row($db,
                    "SELECT COALESCE(SUM({$rRefAmtCol}),0) AS refunded
                     FROM order_refund_items ri
                     INNER JOIN order_refunds r ON r.id=ri.refund_id
                     INNER JOIN order_items oi ON oi.id=ri.order_item_id
                     WHERE oi.`{$oiSellerCol}`=? {$refStatusWhere}
                       AND r.order_id IN ({$orderOwnerSubquery}) {$periodWhere}",
                    array_merge([$filterSellerId],$orderOwnerParams,$periodParams)
                );
            } else {
                $refRow = bv_sd_row($db,
                    "SELECT COALESCE(SUM({$rRefAmtCol}),0) AS refunded
                     FROM order_refund_items ri
                     INNER JOIN order_refunds r ON r.id=ri.refund_id
                     INNER JOIN listings lx ON lx.id=ri.listing_id AND lx.`{$listingOwnerCol}`=?
                     WHERE 1=1 {$refStatusWhere}
                       AND r.order_id IN ({$orderOwnerSubquery}) {$periodWhere}",
                    array_merge([$filterSellerId],$orderOwnerParams,$periodParams)
                );
            }
            $salesSummary['seller_refunded_amount'] = round(bv_sd_float($refRow['refunded']??0),2);
        }
        $salesSummary['seller_net_sales'] = round(
            $salesSummary['seller_paid_sales'] - $salesSummary['seller_refunded_amount'],2
        );

        // Fulfillment summary — seller-owned order_items with fulfillment_status
        if ($oiSellerCol!==null && in_array('fulfillment_status',$oiCols2,true)) {
            $fsRows = bv_sd_rows($db,
                "SELECT COALESCE(oi.fulfillment_status,'pending') AS fs,COUNT(*) AS cnt
                 FROM order_items oi
                 INNER JOIN orders o ON o.id=oi.order_id
                 WHERE oi.`{$oiSellerCol}`=? AND o.id IN ({$orderOwnerSubquery})
                   AND o.status NOT IN ('cancelled','refunded')
                 GROUP BY oi.fulfillment_status",
                array_merge([$filterSellerId],$orderOwnerParams)
            );
            foreach ($fsRows as $fr) {
                $s=bv_sd_clean($fr['fs']??'pending');
                $c=(int)($fr['cnt']??0);
                if (in_array($s,['','pending','cancelled'],true)) { $fulfillmentSummary['to_ship']+=$c; }
                elseif ($s==='processing') { $fulfillmentSummary['processing']+=$c; }
                elseif ($s==='shipped')    { $fulfillmentSummary['shipped']+=$c; }
                elseif ($s==='completed')  { $fulfillmentSummary['completed']+=$c; }
                else { $fulfillmentSummary['to_ship']+=$c; }
            }
        }

        // Recent orders — latest 5 seller-owned
        $roSelCols=['o.id'];
        foreach (['order_code','status','payment_status','payment_provider','currency','total','buyer_name','buyer_email','user_id','paid_at','created_at'] as $c) {
            if ($hasOCol($c)) { $roSelCols[]="o.`{$c}`"; }
        }
        $roOrderBy = $hasOCol('created_at') ? 'ORDER BY o.created_at DESC,o.id DESC' : 'ORDER BY o.id DESC';
        $roRows = bv_sd_rows($db,
            'SELECT '.implode(', ',$roSelCols)." FROM orders o WHERE o.id IN ({$orderOwnerSubquery}) {$roOrderBy} LIMIT 5",
            $orderOwnerParams
        );

        if (!empty($roRows)) {
            $roIds = array_map(static fn($r)=>(int)$r['id'],$roRows);
            $roph  = implode(', ',array_fill(0,count($roIds),'?'));

            // Batch items for recent orders
            $roItemParams = ($oiSellerCol!==null) ? array_merge([$filterSellerId],$roIds) : array_merge([$filterSellerId],$roIds);
            $roItemJoin   = ($oiSellerCol!==null) ? '' : " INNER JOIN listings lxi ON lxi.id=oi.listing_id AND lxi.`{$listingOwnerCol}`=?";
            $roItemWhere  = ($oiSellerCol!==null) ? "oi.`{$oiSellerCol}`=? AND oi.order_id IN ({$roph})" : "oi.order_id IN ({$roph})";
            $roItemRows = bv_sd_rows($db,
                "SELECT oi.order_id,COUNT(*) AS item_count,
                        COALESCE(SUM(oi.line_total),SUM(oi.qty*oi.unit_price),0) AS subtotal,
                        GROUP_CONCAT(COALESCE(oi.fulfillment_status,'') SEPARATOR ',') AS fcsv
                 FROM order_items oi{$roItemJoin}
                 WHERE {$roItemWhere}
                 GROUP BY oi.order_id",
                $roItemParams
            );
            $roItemMap=[];
            foreach ($roItemRows as $rim) { $roItemMap[(int)$rim['order_id']]=$rim; }

            // Latest refund per order
            $roRefMap=[];
            if ($hasRefunds) {
                $rfCols=bv_seller_dashboard_columns($db,'order_refunds');
                $rfSelCols=['order_id','id'];
                foreach (['refund_code','status'] as $c) { if(in_array($c,$rfCols,true)) $rfSelCols[]=$c; }
                $rfRows=bv_sd_rows($db,
                    'SELECT '.implode(',',$rfSelCols)." FROM order_refunds WHERE order_id IN ({$roph}) ORDER BY id DESC",
                    $roIds
                );
                foreach ($rfRows as $rfr) {
                    $oid=(int)$rfr['order_id'];
                    if (!isset($roRefMap[$oid])) $roRefMap[$oid]=$rfr;
                }
            }

            // Fulfillment derive
            $deriveF = static function(string $csv,string $os): array {
                $ss=array_filter(array_map('trim',explode(',',$csv)));
                if (empty($ss)) {
                    return match($os) {
                        'shipped'   =>['status'=>'shipped',  'label'=>'Shipped'],
                        'completed' =>['status'=>'completed','label'=>'Completed'],
                        'paid','confirmed','processing','packing'=>['status'=>'pending','label'=>'To Ship'],
                        default     =>['status'=>'unknown',  'label'=>'Unknown'],
                    };
                }
                $u=array_unique($ss);
                if (count($u)===1) {
                    return match($u[0]) {
                        'completed'  =>['status'=>'completed',  'label'=>'Completed'],
                        'shipped'    =>['status'=>'shipped',    'label'=>'Shipped'],
                        'processing' =>['status'=>'processing', 'label'=>'Preparing'],
                        default      =>['status'=>'pending',    'label'=>'To Ship'],
                    };
                }
                $hS=in_array('shipped',$ss,true);$hC=in_array('completed',$ss,true);
                $hP=in_array('processing',$ss,true);$hPe=in_array('pending',$ss,true)||in_array('',$ss,true);
                if (($hS||$hC)&&($hPe||$hP)) return['status'=>'mixed','label'=>'Mixed'];
                if ($hS&&$hC) return['status'=>'mixed','label'=>'Mixed'];
                if ($hP) return['status'=>'processing','label'=>'Preparing'];
                return['status'=>'pending','label'=>'To Ship'];
            };

            foreach ($roRows as $ro) {
                $roid=(int)$ro['id'];
                $im=$roItemMap[$roid]??null;
                $rf=$roRefMap[$roid]??null;
                $fv=$deriveF((string)($im['fcsv']??''),bv_sd_clean($ro['status']??''));
                $recentOrders[]=[
                    'id'                =>$roid,
                    'order_code'        =>bv_sd_clean($ro['order_code']??''),
                    'status'            =>bv_sd_clean($ro['status']??''),
                    'payment_status'    =>bv_sd_clean($ro['payment_status']??''),
                    'currency'          =>bv_sd_clean($ro['currency']??'USD'),
                    'order_total'       =>round(bv_sd_float($ro['total']??0),2),
                    'seller_subtotal'   =>round(bv_sd_float($im['subtotal']??0),2),
                    'seller_item_count' =>bv_sd_int($im['item_count']??0),
                    'buyer'             =>['id'=>bv_sd_int($ro['user_id']??0)?:null,'name'=>bv_sd_clean($ro['buyer_name']??''),'email'=>bv_sd_clean($ro['buyer_email']??'')],
                    'fulfillment'       =>$fv,
                    'latest_refund'     =>['id'=>$rf?bv_sd_int($rf['id']):null,'status'=>$rf?bv_sd_clean($rf['status']??''):'','refund_code'=>$rf?bv_sd_clean($rf['refund_code']??''):''],
                    'created_at'        =>bv_sd_clean($ro['created_at']??''),
                    'paid_at'           =>bv_sd_clean($ro['paid_at']??''),
                ];
            }
        }
    }

    // ── Review summary ────────────────────────────────────────────────────────
    $reviewSummary = ['average_rating'=>0.0,'review_count'=>0,'five_star_count'=>0,'low_rating_count'=>0];
    if ($hasReviews && $lOwnerCol!==null) {
        $rvCols   = bv_seller_dashboard_columns($db,'listing_reviews');
        $hasRvCol = static fn(string $c): bool => in_array($c,$rvCols,true);
        $rvRating = $hasRvCol('rating')?'rating':($hasRvCol('score')?'score':null);
        $rvLinkId = $hasRvCol('listing_id')?'listing_id':($hasRvCol('item_id')?'item_id':null);
        if ($rvRating && $rvLinkId) {
            $rvApprWhere = $hasRvCol('status') ? "AND rv.status='approved'" : '';
            $rvRow = bv_sd_row($db,
                "SELECT COALESCE(AVG(rv.`{$rvRating}`),0) AS avg_r,COUNT(*) AS cnt,
                        SUM(CASE WHEN rv.`{$rvRating}`>=5 THEN 1 ELSE 0 END) AS five_cnt,
                        SUM(CASE WHEN rv.`{$rvRating}`<=2 THEN 1 ELSE 0 END) AS low_cnt
                 FROM listing_reviews rv
                 INNER JOIN listings l ON l.id=rv.`{$rvLinkId}` AND l.`{$lOwnerCol}`=?
                 WHERE 1=1 {$rvApprWhere}",
                [$filterSellerId]
            );
            if ($rvRow) {
                $reviewSummary['average_rating']  = round(bv_sd_float($rvRow['avg_r']??0),2);
                $reviewSummary['review_count']    = bv_sd_int($rvRow['cnt']??0);
                $reviewSummary['five_star_count'] = bv_sd_int($rvRow['five_cnt']??0);
                $reviewSummary['low_rating_count']= bv_sd_int($rvRow['low_cnt']??0);
            }
        }
    }

    // ── Ranking summary ───────────────────────────────────────────────────────
    $rankingSummary = ['average_score'=>0.0,'top_score'=>0.0,'top_listing'=>null];
    if ($lOwnerCol!==null && ($hasRanking||$hasRankCache)) {
        $rankTable   = $hasRanking ? 'listing_ranking_scores' : 'listing_ranking_cache';
        $rankCols    = bv_seller_dashboard_columns($db,$rankTable);
        $rankScoreCol= in_array('final_score',$rankCols,true)?'final_score':(in_array('ranking_score',$rankCols,true)?'ranking_score':null);
        $rankLinkCol = in_array('listing_id',$rankCols,true)?'listing_id':null;
        if ($rankScoreCol && $rankLinkCol) {
            $rankRow = bv_sd_row($db,
                "SELECT COALESCE(AVG(r.`{$rankScoreCol}`),0) AS avg_s,COALESCE(MAX(r.`{$rankScoreCol}`),0) AS top_s
                 FROM `{$rankTable}` r
                 INNER JOIN listings l ON l.id=r.`{$rankLinkCol}` AND l.`{$lOwnerCol}`=?",
                [$filterSellerId]
            );
            if ($rankRow) {
                $rankingSummary['average_score'] = round(bv_sd_float($rankRow['avg_s']??0),4);
                $rankingSummary['top_score']     = round(bv_sd_float($rankRow['top_s']??0),4);
            }
            if ($rankingSummary['top_score']>0) {
                $topRankRow = bv_sd_row($db,
                    "SELECT l.id,l.title,l.slug FROM listings l
                     INNER JOIN `{$rankTable}` r ON r.`{$rankLinkCol}`=l.id
                     WHERE l.`{$lOwnerCol}`=?
                     ORDER BY r.`{$rankScoreCol}` DESC LIMIT 1",
                    [$filterSellerId]
                );
                if ($topRankRow) {
                    $slug=bv_sd_clean($topRankRow['slug']??'');
                    $rankingSummary['top_listing']=[
                        'id'    =>bv_sd_int($topRankRow['id']),
                        'title' =>bv_sd_clean($topRankRow['title']??''),
                        'url'   =>bv_sd_listing_url(bv_sd_int($topRankRow['id']),$slug),
                    ];
                }
            }
        }
    }

    // ── Refund summary ────────────────────────────────────────────────────────
    $refundSummary = ['pending_approval'=>0,'approved'=>0,'processing'=>0,'refunded'=>0,'rejected'=>0,'failed'=>0];
    if ($hasRefunds && $orderOwnerSubquery!=='') {
        $rCols2   = bv_seller_dashboard_columns($db,'order_refunds');
        $hasRCol2 = static fn(string $c): bool => in_array($c,$rCols2,true);
        if ($hasRCol2('status')) {
            if ($hasRefItems && $oiSellerCol!==null) {
                $rSumRows = bv_sd_rows($db,
                    "SELECT r.status,COUNT(DISTINCT r.id) AS cnt
                     FROM order_refunds r
                     INNER JOIN order_refund_items ri ON ri.refund_id=r.id
                     INNER JOIN order_items oi ON oi.id=ri.order_item_id
                     WHERE oi.`{$oiSellerCol}`=? AND r.order_id IN ({$orderOwnerSubquery})
                     GROUP BY r.status",
                    array_merge([$filterSellerId],$orderOwnerParams)
                );
            } else {
                $rSumRows = bv_sd_rows($db,
                    "SELECT r.status,COUNT(DISTINCT r.id) AS cnt
                     FROM order_refunds r
                     WHERE r.order_id IN ({$orderOwnerSubquery})
                     GROUP BY r.status",
                    $orderOwnerParams
                );
            }
            $refundBuckets = [
                'draft'=>'pending_approval','pending_approval'=>'pending_approval',
                'approved'=>'approved','partially_approved'=>'approved',
                'processing'=>'processing',
                'refunded'=>'refunded','partially_refunded'=>'refunded',
                'rejected'=>'rejected','failed'=>'failed','cancelled'=>'failed',
            ];
            foreach ($rSumRows as $rsr) {
                $s=bv_sd_clean($rsr['status']??'');
                $bucket=$refundBuckets[$s]??null;
                if ($bucket) { $refundSummary[$bucket]+=(int)($rsr['cnt']??0); }
            }
        }
    }

    // ── Balance summary ───────────────────────────────────────────────────────
    $balanceSummary = ['available'=>0.0,'pending'=>0.0,'currency'=>'USD'];
    if ($hasBalance) {
        $beCols   = bv_seller_dashboard_columns($db,'seller_balance_entries');
        $hasBECol = static fn(string $c): bool => in_array($c,$beCols,true);
        if ($hasBECol('seller_id') && $hasBECol('amount') && $hasBECol('status')) {
            $bAvail = bv_sd_scalar($db,
                "SELECT COALESCE(SUM(amount),0) FROM seller_balance_entries WHERE seller_id=? AND status='available'",
                [$filterSellerId],0.0
            );
            $bPend = bv_sd_scalar($db,
                "SELECT COALESCE(SUM(amount),0) FROM seller_balance_entries WHERE seller_id=? AND status IN ('pending','on_hold')",
                [$filterSellerId],0.0
            );
            $bCurRow = bv_sd_row($db,
                "SELECT currency FROM seller_balance_entries WHERE seller_id=? AND status='available' LIMIT 1",
                [$filterSellerId]
            );
            $balanceSummary['available'] = round(bv_sd_float($bAvail),2);
            $balanceSummary['pending']   = round(bv_sd_float($bPend),2);
            if ($bCurRow) { $balanceSummary['currency'] = strtoupper(bv_sd_clean($bCurRow['currency']??'USD'))?:'USD'; }
        }
    }

    // ── Alerts ────────────────────────────────────────────────────────────────
    $alerts = [];
    if ($refundSummary['pending_approval']>0) {
        $alerts[]=['type'=>'refund','level'=>'warning','message'=>'You have refund requests waiting for approval.','count'=>$refundSummary['pending_approval']];
    }
    if ($fulfillmentSummary['to_ship']>0) {
        $alerts[]=['type'=>'fulfillment','level'=>'info','message'=>'You have orders waiting to ship.','count'=>$fulfillmentSummary['to_ship']];
    }
    if ($reviewSummary['low_rating_count']>0) {
        $alerts[]=['type'=>'review','level'=>'warning','message'=>'You have low-rating reviews to review.','count'=>$reviewSummary['low_rating_count']];
    }

    // ── Response ──────────────────────────────────────────────────────────────
    bv_seller_dashboard_json(200,[
        'ok'   => true,
        'data' => [
            'seller' => [
                'id'                 => $filterSellerId,
                'name'               => $sellerName,
                'email'              => $userEmail,
                'role'               => $userRole,
                'farm_name'          => $farmName,
                'application_status' => $applicationStatus,
                'profile_url'        => $siteBase.'/seller.php?id='.$filterSellerId,
            ],
            'period' => ['days'=>$days,'from'=>$dateFrom,'to'=>$dateTo],
            'listing_summary'      => $listingSummary,
            'order_summary'        => $orderSummary,
            'sales_summary'        => $salesSummary,
            'review_summary'       => $reviewSummary,
            'ranking_summary'      => $rankingSummary,
            'fulfillment_summary'  => $fulfillmentSummary,
            'refund_summary'       => $refundSummary,
            'balance_summary'      => $balanceSummary,
            'recent_orders'        => $recentOrders,
            'recent_listings'      => $recentListings,
            'alerts'               => $alerts,
        ],
        'meta' => [
            'api_version'  => 'v1',
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);

} catch (\Throwable $e) {
    bv_seller_dashboard_log('Unhandled exception: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    bv_seller_dashboard_json(500,[
        'ok'    => false,
        'error' => ['code'=>'server_error','message'=>'Something went wrong.'],
    ]);
}
