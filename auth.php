<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
    require_once $configFile;
}

$functionsFile = __DIR__ . '/functions.php';
if (is_file($functionsFile)) {
    require_once $functionsFile;
}

if (!function_exists('admin_db')) {
    function admin_db(): PDO
    {
        global $pdo;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        throw new RuntimeException('PDO connection not available.');
    }
}

if (!function_exists('admin_str_cut')) {
    function admin_str_cut($value, int $length = 500): string
    {
        $value = (string)$value;

        if ($length < 0) {
            $length = 0;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return substr($value, 0, $length);
    }
}

if (!function_exists('admin_e')) {
    function admin_e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_base_url')) {
    function admin_base_url(string $path = ''): string
    {
        $base = defined('ADMIN_BASE_URL') ? (string)ADMIN_BASE_URL : '/admin/';
        $base = rtrim($base, '/') . '/';

        return $base . ltrim($path, '/');
    }
}

if (!function_exists('admin_normalize_role')) {
    function admin_normalize_role(string $role): string
    {
        $role = strtolower(trim($role));

        $map = [
            'superadmin'  => 'super_admin',
            'super-admin' => 'super_admin',
            'super admin' => 'super_admin',
            'administrator' => 'admin',
        ];

        return $map[$role] ?? $role;
    }
}

if (!function_exists('admin_table_columns')) {
    function admin_table_columns(string $table): array
    {
        static $cache = [];

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $columns = [];

        try {
            $pdo = admin_db();
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $field = isset($row['Field']) ? (string)$row['Field'] : '';
                if ($field !== '') {
                    $columns[$field] = true;
                }
            }
        } catch (Throwable $e) {
            $columns = [];
        }

        $cache[$table] = $columns;

        return $columns;
    }
}

if (!function_exists('admin_table_has_column')) {
    function admin_table_has_column(string $table, string $column): bool
    {
        $columns = admin_table_columns($table);
        return isset($columns[$column]);
    }
}

if (!function_exists('admin_extract_permissions')) {
    function admin_extract_permissions(array $admin): array
    {
        if (isset($admin['permissions']) && is_array($admin['permissions'])) {
            return array_values(array_filter($admin['permissions'], 'is_string'));
        }

        if (!isset($admin['permissions'])) {
            return [];
        }

        $raw = $admin['permissions'];

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, 'is_string'));
        }

        $parts = array_map('trim', explode(',', $raw));
        return array_values(array_filter($parts, 'strlen'));
    }
}

if (!function_exists('admin_current_user')) {
    function admin_current_user(): ?array
    {
        if (isset($_SESSION['admin_user']) && is_array($_SESSION['admin_user'])) {
            return $_SESSION['admin_user'];
        }

        $legacyId = (int)($_SESSION['admin_user_id'] ?? 0);
        if ($legacyId <= 0) {
            return null;
        }

        $user = [
            'id'          => $legacyId,
            'username'    => (string)($_SESSION['admin_username'] ?? ''),
            'name'        => (string)($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? ''),
            'role'        => admin_normalize_role((string)($_SESSION['admin_role'] ?? $_SESSION['admin_role_code'] ?? 'admin')),
            'email'       => (string)($_SESSION['admin_email'] ?? ''),
            'permissions' => isset($_SESSION['admin_permissions']) && is_array($_SESSION['admin_permissions'])
                ? $_SESSION['admin_permissions']
                : [],
            'login_at'    => (string)($_SESSION['admin_login_at'] ?? ''),
        ];

        if ($user['name'] === '') {
            $user['name'] = $user['username'];
        }

        $_SESSION['admin_user'] = $user;

        return $user;
    }
}

if (!function_exists('admin_current_user_id')) {
    function admin_current_user_id(): int
    {
        $user = admin_current_user();
        return (int)($user['id'] ?? 0);
    }
}

if (!function_exists('admin_current_role')) {
    function admin_current_role(): string
    {
        $user = admin_current_user();
        return admin_normalize_role((string)($user['role'] ?? ''));
    }
}

if (!function_exists('admin_current_permissions')) {
    function admin_current_permissions(): array
    {
        $user = admin_current_user();

        if (isset($user['permissions']) && is_array($user['permissions'])) {
            return $user['permissions'];
        }

        return isset($_SESSION['admin_permissions']) && is_array($_SESSION['admin_permissions'])
            ? $_SESSION['admin_permissions']
            : [];
    }
}

if (!function_exists('admin_is_logged_in')) {
    function admin_is_logged_in(): bool
    {
        return admin_current_user_id() > 0;
    }
}

if (!function_exists('admin_has_role')) {
    function admin_has_role(array $roles): bool
    {
        $current = admin_current_role();
        if ($current === '') {
            return false;
        }

        foreach ($roles as $role) {
            if (!is_string($role) || trim($role) === '') {
                continue;
            }

            if ($current === admin_normalize_role($role)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('admin_is_super_admin')) {
    function admin_is_super_admin(): bool
    {
        if (isset($_SESSION['admin_is_super_admin'])) {
            return (int)$_SESSION['admin_is_super_admin'] === 1;
        }

        return admin_has_role(['superadmin', 'super_admin']);
    }
}

if (!function_exists('admin_has_permission')) {
    function admin_has_permission(string $permission): bool
    {
        $permission = trim($permission);

        if ($permission === '') {
            return false;
        }

        if (admin_is_super_admin()) {
            return true;
        }

        $permissions = admin_current_permissions();

        if (in_array('*', $permissions, true)) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }
}

if (!function_exists('admin_require_login')) {
    function admin_require_login(): void
    {
        if (admin_is_logged_in()) {
            return;
        }

        $redirect = $_SERVER['REQUEST_URI'] ?? admin_base_url('index.php');
        header('Location: ' . admin_base_url('login.php?redirect=' . urlencode((string)$redirect)));
        exit;
    }
}

if (!function_exists('admin_require_roles')) {
    function admin_require_roles(array $roles): void
    {
        admin_require_login();

        if (!admin_has_role($roles)) {
            http_response_code(403);
            exit('403 Forbidden');
        }
    }
}

if (!function_exists('admin_require_super_admin')) {
    function admin_require_super_admin(): void
    {
        admin_require_login();

        if (!admin_is_super_admin()) {
            http_response_code(403);
            exit('403 Forbidden');
        }
    }
}

if (!function_exists('admin_require_permission')) {
    function admin_require_permission(string $permission): void
    {
        admin_require_login();

        if (!admin_has_permission($permission)) {
            http_response_code(403);
            exit('403 Forbidden');
        }
    }
}

if (!function_exists('admin_csrf_token')) {
    function admin_csrf_token(): string
    {
        if (empty($_SESSION['admin_csrf']) || !is_string($_SESSION['admin_csrf'])) {
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['admin_csrf'];
    }
}

if (!function_exists('admin_verify_csrf')) {
    function admin_verify_csrf(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['admin_csrf'])
            && hash_equals((string)$_SESSION['admin_csrf'], $token);
    }
}

if (!function_exists('admin_require_csrf')) {
    function admin_require_csrf(?string $token): void
    {
        if (!admin_verify_csrf($token)) {
            http_response_code(403);
            exit('Invalid CSRF token');
        }
    }
}

if (!function_exists('admin_safe_redirect')) {
    function admin_safe_redirect(?string $redirect): string
    {
        $redirect = trim((string)$redirect);

        if ($redirect === '') {
            return admin_base_url('index.php');
        }

        if (preg_match('#^https?://#i', $redirect)) {
            return admin_base_url('index.php');
        }

        if (strpos($redirect, "\r") !== false || strpos($redirect, "\n") !== false) {
            return admin_base_url('index.php');
        }

        if ($redirect[0] !== '/') {
            return admin_base_url('index.php');
        }

        if (isset($redirect[1]) && $redirect[0] === '/' && $redirect[1] === '/') {
            return admin_base_url('index.php');
        }

        return $redirect;
    }
}

if (!function_exists('admin_login')) {
    function admin_login(int $id, string $username, string $role = 'admin', string $name = '', array $extra = []): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid admin user id.');
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $cleanRole = admin_normalize_role($role !== '' ? $role : 'admin');
        $cleanName = trim($name) !== '' ? trim($name) : $username;
        $email = isset($extra['email']) ? (string)$extra['email'] : '';
        $permissions = isset($extra['permissions']) && is_array($extra['permissions'])
            ? array_values(array_filter($extra['permissions'], 'is_string'))
            : [];
        $loginAt = date('Y-m-d H:i:s');
        $isSuper = $cleanRole === 'super_admin' ? 1 : 0;

        $_SESSION['admin_user'] = [
            'id'          => $id,
            'username'    => $username,
            'name'        => $cleanName,
            'role'        => $cleanRole,
            'email'       => $email,
            'permissions' => $permissions,
            'login_at'    => $loginAt,
        ];

        // bridge สำหรับโค้ดเก่าที่อ่าน session คนละชื่อ
        $_SESSION['admin_user_id'] = $id;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_name'] = $cleanName;
        $_SESSION['admin_email'] = $email;
        $_SESSION['admin_role'] = $cleanRole;
        $_SESSION['admin_role_code'] = $cleanRole;
        $_SESSION['admin_permissions'] = $permissions;
        $_SESSION['admin_is_super_admin'] = $isSuper;
        $_SESSION['admin_login_at'] = $loginAt;

        // rotate request / csrf id ตอน login
        $_SESSION['admin_request_id'] = bin2hex(random_bytes(16));
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
}

if (!function_exists('admin_logout')) {
    function admin_logout(): void
    {
        $keys = [
            // new / central keys
            'admin_user',
            'admin_user_id',
            'admin_username',
            'admin_name',
            'admin_email',
            'admin_role',
            'admin_role_code',
            'admin_permissions',
            'admin_is_super_admin',
            'admin_login_at',
            'admin_request_id',
            'admin_csrf',

            // legacy / compatibility keys
            'admin',
            'admin_id',
            'auth_admin',
            'logged_admin',
            'user',
            'user_id',
            'username',
            'email',
            'name',
            'role',

            // flash / old input (optional but cleaner on logout)
            'admin_flash',
            'admin_flash_error',
            'admin_old_input',
        ];

        foreach ($keys as $key) {
            if (isset($_SESSION[$key])) {
                unset($_SESSION[$key]);
            }
        }

        if (empty($_SESSION)) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    (bool)$params['secure'],
                    (bool)$params['httponly']
                );
            }

            session_destroy();
            return;
        }

        session_regenerate_id(true);
    }
}

if (!function_exists('admin_find_user_by_login')) {
    function admin_find_user_by_login(string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        $pdo = admin_db();
        $columns = admin_table_columns('admin_users');

        if (empty($columns) || !isset($columns['id'])) {
            return null;
        }

        $select = ['`id`'];

        foreach (['username', 'email', 'name', 'password_hash', 'role', 'is_active', 'permissions'] as $col) {
            if (isset($columns[$col])) {
                $select[] = '`' . $col . '`';
            }
        }

        $where = [];
        $params = [];

        if (isset($columns['username'])) {
            $where[] = '`username` = :login_username';
            $params[':login_username'] = $login;
        }

        if (isset($columns['email'])) {
            $where[] = '`email` = :login_email';
            $params[':login_email'] = $login;
        }

        if (empty($where)) {
            return null;
        }

        $sql = '
            SELECT ' . implode(', ', $select) . '
            FROM `admin_users`
            WHERE (' . implode(' OR ', $where) . ')
            LIMIT 1
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['id'] = (int)($row['id'] ?? 0);
        $row['username'] = (string)($row['username'] ?? '');
        $row['email'] = (string)($row['email'] ?? '');
        $row['name'] = (string)($row['name'] ?? $row['username']);
        $row['role'] = admin_normalize_role((string)($row['role'] ?? 'admin'));
        $row['is_active'] = array_key_exists('is_active', $row) ? (int)$row['is_active'] : 1;

        return $row;
    }
}

if (!function_exists('admin_update_login_meta')) {
    function admin_update_login_meta(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $pdo = admin_db();
        $set = [];
        $params = [':id' => $id];

        if (admin_table_has_column('admin_users', 'last_login_at')) {
            $set[] = '`last_login_at` = NOW()';
        }

        if (admin_table_has_column('admin_users', 'last_login_ip')) {
            $set[] = '`last_login_ip` = :last_login_ip';
            $params[':last_login_ip'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        }

        if (admin_table_has_column('admin_users', 'last_login_user_agent')) {
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
            if (function_exists('mb_substr')) {
                $ua = mb_substr($ua, 0, 500);
            } else {
                $ua = substr($ua, 0, 500);
            }
            $set[] = '`last_login_user_agent` = :last_login_user_agent';
            $params[':last_login_user_agent'] = $ua;
        }

        if (empty($set)) {
            return;
        }

        $sql = '
            UPDATE `admin_users`
            SET ' . implode(', ', $set) . '
            WHERE `id` = :id
            LIMIT 1
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
}

if (!function_exists('admin_login_success')) {
    function admin_login_success(array $admin): void
    {
        admin_login(
            (int)($admin['id'] ?? 0),
            (string)($admin['username'] ?? ''),
            (string)($admin['role'] ?? 'admin'),
            (string)($admin['name'] ?? ''),
            [
                'email' => (string)($admin['email'] ?? ''),
                'permissions' => admin_extract_permissions($admin),
            ]
        );
    }
}

if (!function_exists('admin_audit_log')) {
    function admin_audit_log(PDO $pdo, string $event, string $entityType = '', ?int $entityId = null, array $meta = []): void
    {
        try {
            $columns = admin_table_columns('audit_logs');
            if (empty($columns)) {
                return;
            }

            $actor = admin_current_user();

            $payload = [
                'actor_type'   => 'admin',
                'actor_id'     => isset($actor['id']) ? (int)$actor['id'] : null,
                'actor_name'   => (string)($actor['name'] ?? $actor['username'] ?? $_SESSION['admin_name'] ?? 'system'),
                'actor_email'  => (string)($actor['email'] ?? $_SESSION['admin_email'] ?? ''),
                'event_type'   => $event,
                'entity_type'  => trim($entityType) !== '' ? trim($entityType) : 'system',
                'entity_id'    => $entityId,
                'entity_title' => isset($meta['entity_title']) ? (string)$meta['entity_title'] : null,
                'action'       => isset($meta['action']) && $meta['action'] !== '' ? (string)$meta['action'] : $event,
                'summary'      => isset($meta['summary']) && $meta['summary'] !== '' ? (string)$meta['summary'] : null,
                'before_json'  => array_key_exists('before', $meta)
                    ? json_encode($meta['before'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'after_json'   => array_key_exists('after', $meta)
                    ? json_encode($meta['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (!empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null),
                'request_id'   => (string)($_SESSION['admin_request_id'] ?? ''),
                'ip_address'   => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent'   => admin_str_cut($_SERVER['HTTP_USER_AGENT'] ?? '', 500),
                'created_at'   => date('Y-m-d H:i:s'),
            ];

            $fields = [];
            $holders = [];
            $params = [];

            foreach ($payload as $column => $value) {
                if (!isset($columns[$column])) {
                    continue;
                }

                $fields[] = '`' . $column . '`';
                $holders[] = ':' . $column;
                $params[':' . $column] = $value;
            }

            if (empty($fields)) {
                return;
            }

            $sql = '
                INSERT INTO `audit_logs` (' . implode(', ', $fields) . ')
                VALUES (' . implode(', ', $holders) . ')
            ';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } catch (Throwable $e) {
            // audit log ต้องไม่ทำให้ระบบหลักล้ม
        }
    }
}

if (!function_exists('admin_boot_session_bridge')) {
    function admin_boot_session_bridge(): void
    {
        $user = admin_current_user();

        if ($user && !isset($_SESSION['admin_is_super_admin'])) {
            $_SESSION['admin_is_super_admin'] = admin_normalize_role((string)($user['role'] ?? '')) === 'super_admin' ? 1 : 0;
        }

        if (!isset($_SESSION['admin_permissions']) || !is_array($_SESSION['admin_permissions'])) {
            $_SESSION['admin_permissions'] = isset($user['permissions']) && is_array($user['permissions'])
                ? $user['permissions']
                : [];
        }
    }
}

admin_boot_session_bridge();