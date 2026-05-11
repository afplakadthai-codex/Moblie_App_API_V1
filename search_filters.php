<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/search_filters.php
// Read-only search filter options endpoint for the mobile app.
// Do NOT modify existing website files. This file is standalone.
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
// File lives at: /public_html/api/mobile/v1/search_filters.php
// dirname(__DIR__, 3) → /public_html
// dirname(__DIR__, 4) → account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_mobile_filters_public_root')) {
    function bv_mobile_filters_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_mobile_filters_project_root')) {
    function bv_mobile_filters_project_root(): string
    {
        return dirname(bv_mobile_filters_public_root());
    }
}

// =============================================================================
// Helper functions
// =============================================================================

if (!function_exists('bv_mobile_filters_json')) {
    function bv_mobile_filters_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_mobile_filters_error')) {
    function bv_mobile_filters_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_mobile_filters_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_mobile_filters_log')) {
    function bv_mobile_filters_log(string $message): void
    {
        error_log('[BV Mobile Filters API] ' . $message);
        $line          = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        $logCandidates = [
            bv_mobile_filters_project_root() . '/private_html/mobile_api.log',
            bv_mobile_filters_public_root()  . '/logs/mobile_api.log',
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

if (!function_exists('bv_mobile_filters_db')) {
    function bv_mobile_filters_db(): ?object
    {
        static $cached = false;
        static $connection = null;
        if ($cached !== false) {
            return $connection;
        }
        $cached     = true;
        $publicRoot = bv_mobile_filters_public_root();
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
                    error_log('[BV Mobile Filters API] PDO connect failed: ' . $e->getMessage());
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
                    error_log('[BV Mobile Filters API] mysqli connect failed: ' . $m->connect_error);
                } catch (\Throwable $e) {
                    error_log('[BV Mobile Filters API] mysqli exception: ' . $e->getMessage());
                }
            }
            break;
        }
        return null;
    }
}

if (!function_exists('bv_mobile_filters_table_exists')) {
    // Direct SELECT 1 probe — avoids SHOW TABLES LIKE false negatives
    function bv_mobile_filters_table_exists(object $db, string $table): bool
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
            error_log('[BV Mobile Filters API] table_exists error: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('bv_mobile_filters_columns')) {
    function bv_mobile_filters_columns(object $db, string $table): array
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
            error_log('[BV Mobile Filters API] columns error: ' . $e->getMessage());
        }
        return $cols;
    }
}

if (!function_exists('bv_mobile_filters_has_col')) {
    function bv_mobile_filters_has_col(object $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = in_array(strtolower($column), bv_mobile_filters_columns($db, $table), true);
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_mobile_filters_clean_string')) {
    function bv_mobile_filters_clean_string($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
    }
}

if (!function_exists('bv_mobile_filters_float')) {
    function bv_mobile_filters_float($value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}

if (!function_exists('bv_mobile_filters_public_where')) {
    /**
     * Returns [whereSQL, params] for the public-listing filter.
     * whereSQL starts with 'WHERE' if any conditions exist, or '' if none.
     */
    function bv_mobile_filters_public_where(
        bool $hasStatus,
        bool $hasSaleStatus,
        array $publicStatuses,
        array $publicSaleStatuses
    ): array {
        $parts  = [];
        $params = [];

        if ($hasStatus) {
            $ph      = implode(', ', array_fill(0, count($publicStatuses), '?'));
            $parts[] = "status IN ({$ph})";
            $params  = array_merge($params, $publicStatuses);
        }
        if ($hasSaleStatus) {
            $ph2     = implode(', ', array_fill(0, count($publicSaleStatuses), '?'));
            $parts[] = "sale_status IN ({$ph2})";
            $params  = array_merge($params, $publicSaleStatuses);
        }

        $whereSQL = !empty($parts) ? ('WHERE ' . implode(' AND ', $parts)) : '';
        return [$whereSQL, $params];
    }
}

if (!function_exists('bv_mobile_filters_fetch_distinct_options')) {
    /**
     * Query distinct non-empty values for a column from public listings,
     * with count per value. Returns formatted filter option array.
     */
    function bv_mobile_filters_fetch_distinct_options(
        object $db,
        string $column,
        string $whereSQL,
        array  $whereParams,
        int    $limit = 100
    ): array {
        // Validate column name
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return [];
        }

        $sql  = "SELECT `{$column}` AS filter_value, COUNT(*) AS filter_count
                 FROM listings
                 {$whereSQL}
                 " . ($whereSQL ? 'AND' : 'WHERE') . " `{$column}` IS NOT NULL
                 AND TRIM(`{$column}`) != ''
                 GROUP BY `{$column}`
                 ORDER BY filter_count DESC, `{$column}` ASC
                 LIMIT ?";

        $params = array_merge($whereParams, [$limit]);
        $rows   = [];

        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql);
                $st->execute($params);
                $rows = $st->fetchAll() ?: [];
            } elseif ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) {
                    return [];
                }
                $types   = implode('', array_map(
                    static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'),
                    $params
                ));
                $bindRef = [&$types];
                foreach ($params as $k => $_) {
                    $bindRef[] = &$params[$k];
                }
                call_user_func_array([$st, 'bind_param'], $bindRef);
                $st->execute();
                $res = $st->get_result();
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $rows[] = $row;
                    }
                }
                $st->close();
            }
        } catch (\Throwable $e) {
            error_log('[BV Mobile Filters API] distinct_options error on ' . $column . ': ' . $e->getMessage());
            return [];
        }

        $options = [];
        foreach ($rows as $row) {
            $val = bv_mobile_filters_clean_string($row['filter_value'] ?? '');
            if ($val === '') {
                continue;
            }
            $options[] = [
                'value' => $val,
                'label' => $val,
                'count' => (int) ($row['filter_count'] ?? 0),
            ];
        }
        return $options;
    }
}

// =============================================================================
// Main execution
// =============================================================================
try {
    $db = bv_mobile_filters_db();
    if ($db === null) {
        bv_mobile_filters_log('No database connection available.');
        bv_mobile_filters_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    if (!bv_mobile_filters_table_exists($db, 'listings')) {
        bv_mobile_filters_log('listings table not found.');
        bv_mobile_filters_error('not_found', 'Listings data is not available.', 404);
    }

    // ── Detect listing columns ────────────────────────────────────────────────
    $lCols   = bv_mobile_filters_columns($db, 'listings');
    $hasLCol = static fn(string $c): bool => in_array(strtolower($c), $lCols, true);

    $hasStatus     = $hasLCol('status');
    $hasSaleStatus = $hasLCol('sale_status');
    $hasPrice      = $hasLCol('price');
    $hasCurrency   = $hasLCol('currency');

    // Filterable columns to probe
    $filterableColumns = [
        'species'   => 'species',
        'strain'    => 'strain',
        'country'   => 'country',
        'city'      => 'city',
        'grade'     => 'grade',
        'sex'       => 'sex',
        'tail_type' => 'tail_type',
    ];

    // ── Public status constants (from live schema) ────────────────────────────
    // listings.status enum('draft','pending','active','sold','hidden')
    $publicStatuses     = ['active'];
    $publicSaleStatuses = ['available', 'reserved', 'sold'];

    [$whereSQL, $whereParams] = bv_mobile_filters_public_where(
        $hasStatus,
        $hasSaleStatus,
        $publicStatuses,
        $publicSaleStatuses
    );

    // ── Build each filter ─────────────────────────────────────────────────────
    $filters = [];
    foreach ($filterableColumns as $key => $dbCol) {
        if (!$hasLCol($dbCol)) {
            $filters[$key] = [];
            continue;
        }
        $filters[$key] = bv_mobile_filters_fetch_distinct_options(
            $db,
            $dbCol,
            $whereSQL,
            $whereParams,
            100
        );
    }

    // ── Price range ───────────────────────────────────────────────────────────
    $priceData = ['min' => 0.0, 'max' => 0.0, 'currency' => 'USD'];

    if ($hasPrice) {
        $priceSql  = "SELECT MIN(price) AS min_price, MAX(price) AS max_price FROM listings {$whereSQL}";
        $priceRow  = null;

        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($priceSql);
                $st->execute($whereParams);
                $priceRow = $st->fetch();
            } elseif ($db instanceof mysqli) {
                $st = $db->prepare($priceSql);
                if ($st !== false) {
                    if (!empty($whereParams)) {
                        $types   = implode('', array_map(
                            static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'),
                            $whereParams
                        ));
                        $bindRef = [&$types];
                        foreach ($whereParams as $k => $_) {
                            $bindRef[] = &$whereParams[$k];
                        }
                        call_user_func_array([$st, 'bind_param'], $bindRef);
                    }
                    $st->execute();
                    $res      = $st->get_result();
                    $priceRow = $res ? $res->fetch_assoc() : null;
                    $st->close();
                }
            }
        } catch (\Throwable $e) {
            error_log('[BV Mobile Filters API] price range error: ' . $e->getMessage());
        }

        if ($priceRow) {
            $priceData['min'] = round(bv_mobile_filters_float($priceRow['min_price'] ?? 0, 0.0), 2);
            $priceData['max'] = round(bv_mobile_filters_float($priceRow['max_price'] ?? 0, 0.0), 2);
        }

        // Most common currency among public listings
        if ($hasCurrency) {
            $currencySql = "SELECT currency, COUNT(*) AS cnt
                            FROM listings
                            {$whereSQL}
                            " . ($whereSQL ? 'AND' : 'WHERE') . " currency IS NOT NULL
                            AND TRIM(currency) != ''
                            GROUP BY currency
                            ORDER BY cnt DESC
                            LIMIT 1";
            try {
                $currRow = null;
                if ($db instanceof PDO) {
                    $st      = $db->prepare($currencySql);
                    $st->execute($whereParams);
                    $currRow = $st->fetch();
                } elseif ($db instanceof mysqli) {
                    $st = $db->prepare($currencySql);
                    if ($st !== false) {
                        if (!empty($whereParams)) {
                            $types   = implode('', array_map(
                                static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'),
                                $whereParams
                            ));
                            $bindRef = [&$types];
                            foreach ($whereParams as $k => $_) {
                                $bindRef[] = &$whereParams[$k];
                            }
                            call_user_func_array([$st, 'bind_param'], $bindRef);
                        }
                        $st->execute();
                        $res     = $st->get_result();
                        $currRow = $res ? $res->fetch_assoc() : null;
                        $st->close();
                    }
                }
                if ($currRow && !empty($currRow['currency'])) {
                    $priceData['currency'] = strtoupper(
                        bv_mobile_filters_clean_string($currRow['currency'])
                    );
                }
            } catch (\Throwable $e) {
                error_log('[BV Mobile Filters API] currency detect error: ' . $e->getMessage());
            }
        }
    }

    // ── Sort options ──────────────────────────────────────────────────────────
    // Always return full sort list; server-side falls back if ranking table missing
    $sortOptions = [
        ['value' => 'ranking',    'label' => 'Recommended'],
        ['value' => 'newest',     'label' => 'Newest'],
        ['value' => 'price_low',  'label' => 'Price: Low to High'],
        ['value' => 'price_high', 'label' => 'Price: High to Low'],
    ];

    // ── Build response ────────────────────────────────────────────────────────
    bv_mobile_filters_json(200, [
        'ok'   => true,
        'data' => [
            'species'   => $filters['species'],
            'strain'    => $filters['strain'],
            'country'   => $filters['country'],
            'city'      => $filters['city'],
            'grade'     => $filters['grade'],
            'sex'       => $filters['sex'],
            'tail_type' => $filters['tail_type'],
            'price'     => $priceData,
            'sort'      => $sortOptions,
        ],
        'meta' => [
            'api_version'  => 'v1',
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);

} catch (\Throwable $e) {
    bv_mobile_filters_log('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_mobile_filters_json(500, [
        'ok'    => false,
        'error' => [
            'code'    => 'server_error',
            'message' => 'Something went wrong.',
        ],
    ]);
}
