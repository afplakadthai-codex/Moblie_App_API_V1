<?php
declare(strict_types=1);

ini_set('display_errors', '0');
while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

const BVM_NOTIFICATION_MARK_ALL_READ_API_VERSION = 'mobile-v1';

function bvm_notification_mark_all_read_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function bvm_notification_mark_all_read_payload(array $payload): array
{
    if (!isset($payload['meta'])) {
        $payload['meta'] = [
            'api_version' => BVM_NOTIFICATION_MARK_ALL_READ_API_VERSION,
            'generated_at' => bvm_notification_mark_all_read_now(),
        ];
    }
    return $payload;
}

function bvm_notification_mark_all_read_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode(bvm_notification_mark_all_read_payload($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function bvm_notification_mark_all_read_error(string $code, string $message, int $statusCode): void
{
    bvm_notification_mark_all_read_json($statusCode, [
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);
}

function bvm_notification_mark_all_read_log(string $message): void
{
    error_log('[Mobile API Notification Mark All Read] ' . $message);
}

function bvm_notification_mark_all_read_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $publicRoot = dirname(__DIR__, 3);
    $projectRoot = dirname(__DIR__, 4);
    $candidates = [
        $publicRoot . '/config/db.php',
        $publicRoot . '/includes/db.php',
        $publicRoot . '/db.php',
        $projectRoot . '/config/db.php',
        $projectRoot . '/includes/db.php',
        $projectRoot . '/db.php',
    ];

    foreach ($candidates as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $loader = static function (string $includePath): void {
            $pdo = $PDO = $db = $conn = null;
            ob_start();
            /** @noinspection PhpIncludeInspection */
            include $includePath;
            @ob_end_clean();

            foreach (['pdo' => $pdo, 'PDO' => $PDO, 'db' => $db, 'conn' => $conn] as $name => $value) {
                if ($value instanceof PDO) {
                    $GLOBALS[$name] = $value;
                }
            }
        };

        try {
            $loader($path);
        } catch (Throwable $e) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            bvm_notification_mark_all_read_log('Database include failed for ' . $path . ': ' . $e->getMessage());
            continue;
        }

        foreach (['pdo', 'PDO', 'db', 'conn'] as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
                $pdo = $GLOBALS[$name];
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                return $pdo;
            }
        }
    }

    throw new RuntimeException('PDO connection required.');
}

function bvm_notification_mark_all_read_table_exists(PDO $pdo, string $table): bool
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function bvm_notification_mark_all_read_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        return [];
    }

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute([':table' => $table]);

    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[strtolower((string) $column)] = (string) $column;
    }

    $cache[$table] = $columns;
    return $columns;
}

function bvm_notification_mark_all_read_has_col(PDO $pdo, string $table, string $column): bool
{
    $columns = bvm_notification_mark_all_read_columns($pdo, $table);
    return isset($columns[strtolower($column)]);
}

function bvm_notification_mark_all_read_col_sql(PDO $pdo, string $table, string $column): string
{
    $columns = bvm_notification_mark_all_read_columns($pdo, $table);
    $actual = $columns[strtolower($column)] ?? null;
    if ($actual === null || preg_match('/^[A-Za-z0-9_]+$/', $actual) !== 1) {
        throw new RuntimeException('Missing required column: ' . $table . '.' . $column);
    }

    return '`' . $actual . '`';
}

function bvm_notification_mark_all_read_get_bearer_token(): ?string
{
    $authorization = '';

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $authorization = trim((string) $value);
                    break;
                }
            }
        }
    }

    if ($authorization === '') {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    }

    if ($authorization !== '' && preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) === 1) {
        $token = trim((string) $matches[1]);
        return $token !== '' ? $token : null;
    }

    return null;
}

function bvm_notification_mark_all_read_auth(PDO $pdo): array
{
    $token = bvm_notification_mark_all_read_get_bearer_token();
    if ($token === null) {
        bvm_notification_mark_all_read_error('token_missing', 'Authorization token is required.', 401);
    }

    if (strlen($token) < 16 || strlen($token) > 512) {
        bvm_notification_mark_all_read_error('unauthorized', 'Unauthorized', 401);
    }

    foreach (['mobile_auth_tokens', 'users'] as $table) {
        if (!bvm_notification_mark_all_read_table_exists($pdo, $table)) {
            throw new RuntimeException('Required authentication table is missing: ' . $table);
        }
    }

    foreach (['token_hash', 'user_id', 'expires_at', 'revoked_at'] as $column) {
        if (!bvm_notification_mark_all_read_has_col($pdo, 'mobile_auth_tokens', $column)) {
            throw new RuntimeException('Required authentication column is missing: mobile_auth_tokens.' . $column);
        }
    }

    foreach (['id', 'account_status'] as $column) {
        if (!bvm_notification_mark_all_read_has_col($pdo, 'users', $column)) {
            throw new RuntimeException('Required users column is missing: users.' . $column);
        }
    }

    $tokenIdSelect = bvm_notification_mark_all_read_has_col($pdo, 'mobile_auth_tokens', 'id')
        ? 'mat.' . bvm_notification_mark_all_read_col_sql($pdo, 'mobile_auth_tokens', 'id') . ' AS `token_id`,'
        : '0 AS `token_id`,';

    $sql = 'SELECT ' . $tokenIdSelect . ' u.' . bvm_notification_mark_all_read_col_sql($pdo, 'users', 'id') . ' AS `id`'
        . ' FROM `mobile_auth_tokens` mat'
        . ' INNER JOIN `users` u ON u.' . bvm_notification_mark_all_read_col_sql($pdo, 'users', 'id') . ' = mat.' . bvm_notification_mark_all_read_col_sql($pdo, 'mobile_auth_tokens', 'user_id')
        . ' WHERE mat.' . bvm_notification_mark_all_read_col_sql($pdo, 'mobile_auth_tokens', 'token_hash') . ' = :token_hash'
        . ' AND mat.' . bvm_notification_mark_all_read_col_sql($pdo, 'mobile_auth_tokens', 'revoked_at') . ' IS NULL'
        . ' AND mat.' . bvm_notification_mark_all_read_col_sql($pdo, 'mobile_auth_tokens', 'expires_at') . ' > NOW()'
        . " AND u." . bvm_notification_mark_all_read_col_sql($pdo, 'users', 'account_status') . " = 'active'"
        . ' LIMIT 1';

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token_hash' => $tokenHash]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        bvm_notification_mark_all_read_error('unauthorized', 'Unauthorized', 401);
    }

    if (bvm_notification_mark_all_read_has_col($pdo, 'mobile_auth_tokens', 'last_used_at')) {
        try {
            if ((int) ($user['token_id'] ?? 0) > 0 && bvm_notification_mark_all_read_has_col($pdo, 'mobile_auth_tokens', 'id')) {
                $update = $pdo->prepare('UPDATE `mobile_auth_tokens` SET ' . bvm_notification_mark_all_read_col_sql($pdo, 'mobile_auth_tokens', 'last_used_at') . ' = NOW() WHERE ' . bvm_notification_mark_all_read_col_sql($pdo, 'mobile_auth_tokens', 'id') . ' = :token_id LIMIT 1');
                $update->execute([':token_id' => (int) $user['token_id']]);
            } else {
                $update = $pdo->prepare('UPDATE `mobile_auth_tokens` SET ' . bvm_notification_mark_all_read_col_sql($pdo, 'mobile_auth_tokens', 'last_used_at') . ' = NOW() WHERE ' . bvm_notification_mark_all_read_col_sql($pdo, 'mobile_auth_tokens', 'token_hash') . ' = :token_hash LIMIT 1');
                $update->execute([':token_hash' => $tokenHash]);
            }
        } catch (Throwable $e) {
            bvm_notification_mark_all_read_log('Token last_used_at update failed: ' . $e->getMessage());
        }
    }

    return ['id' => (int) $user['id']];
}

function bvm_notification_mark_all_read_clean_int($value, int $default, int $min, int $max): int
{
    if ($value === null || $value === '') {
        return $default;
    }

    $filtered = filter_var($value, FILTER_VALIDATE_INT);
    if ($filtered === false) {
        return $default;
    }

    return max($min, min($max, (int) $filtered));
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        bvm_notification_mark_all_read_error('method_not_allowed', 'Only POST requests are accepted.', 405);
    }

    $pdo = bvm_notification_mark_all_read_db();
    $user = bvm_notification_mark_all_read_auth($pdo);

    if (!bvm_notification_mark_all_read_table_exists($pdo, 'notifications')) {
        throw new RuntimeException('Required notifications table is missing.');
    }

    foreach (['id', 'user_id', 'is_read', 'read_at'] as $column) {
        if (!bvm_notification_mark_all_read_has_col($pdo, 'notifications', $column)) {
            throw new RuntimeException('Required notifications column is missing: notifications.' . $column);
        }
    }

    $update = $pdo->prepare('UPDATE `notifications` SET `is_read` = 1, `read_at` = NOW() WHERE `user_id` = :user_id AND `is_read` = 0');
    $update->execute([':user_id' => (int) $user['id']]);

    bvm_notification_mark_all_read_json(200, [
        'ok' => true,
        'data' => [
            'updated_count' => $update->rowCount(),
        ],
    ]);
} catch (Throwable $e) {
    bvm_notification_mark_all_read_log($e->getMessage());
    bvm_notification_mark_all_read_error('server_error', 'Server error', 500);
}
