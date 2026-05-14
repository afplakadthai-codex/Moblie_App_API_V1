<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('member_e')) {
    function member_e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('member_login_url')) {
    function member_login_url(): string
    {
        return '/login.php';
    }
}

if (!function_exists('member_dashboard_url')) {
    function member_dashboard_url(): string
    {
        return '/member/index.php';
    }
}

if (!function_exists('member_safe_redirect_path')) {
    function member_safe_redirect_path(?string $path, string $fallback = '/member/index.php'): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return $fallback;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $fallback;
        }

        if ($path[0] !== '/' || strpos($path, '//') === 0) {
            return $fallback;
        }

        $lower = strtolower($path);
        $blocked = [
            '/login.php',
            '/logout.php',
            '/member/forgot-password.php',
            '/member/forgot-password-submit.php',
            '/member/reset-password.php',
            '/member/reset-password-submit.php',
        ];

        foreach ($blocked as $bad) {
            if ($lower === $bad || strpos($lower, $bad . '?') === 0) {
                return $fallback;
            }
        }

        return $path;
    }
}

if (!function_exists('member_sync_legacy_session_from_user')) {
    function member_sync_legacy_session_from_user(): void
    {
        if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
            return;
        }

        $user = $_SESSION['user'];

        if (!empty($user['id'])) {
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['member_id'] = (int) $user['id'];
        }

        $_SESSION['user_first_name'] = (string) ($user['first_name'] ?? '');
        $_SESSION['user_last_name'] = (string) ($user['last_name'] ?? '');
        $_SESSION['user_email'] = (string) ($user['email'] ?? '');
        $_SESSION['member_email'] = (string) ($user['email'] ?? '');
        $_SESSION['user_role'] = (string) ($user['role'] ?? 'user');
        $_SESSION['member_role'] = (string) ($user['role'] ?? 'user');
        $_SESSION['user_account_status'] = (string) ($user['account_status'] ?? 'active');
        $_SESSION['user_email_verified_at'] = $user['email_verified_at'] ?? null;
        $_SESSION['seller_application_status'] = isset($user['seller_application_status'])
            ? (string) $user['seller_application_status']
            : '';

        $fullName = trim(
            (string) ($user['first_name'] ?? '') . ' ' .
            (string) ($user['last_name'] ?? '')
        );

        $_SESSION['user_name'] = $fullName;
    }
}

if (!function_exists('member_build_user_from_legacy_session')) {
    function member_build_user_from_legacy_session(): ?array
    {
        $id = 0;

        if (!empty($_SESSION['user_id'])) {
            $id = (int) $_SESSION['user_id'];
        } elseif (!empty($_SESSION['member_id'])) {
            $id = (int) $_SESSION['member_id'];
        }

        if ($id <= 0) {
            return null;
        }

        return [
            'id' => $id,
            'first_name' => (string) ($_SESSION['user_first_name'] ?? ''),
            'last_name' => (string) ($_SESSION['user_last_name'] ?? ''),
            'email' => (string) ($_SESSION['user_email'] ?? $_SESSION['member_email'] ?? ''),
            'role' => (string) ($_SESSION['user_role'] ?? $_SESSION['member_role'] ?? 'user'),
            'account_status' => (string) ($_SESSION['user_account_status'] ?? 'active'),
            'email_verified_at' => $_SESSION['user_email_verified_at'] ?? null,
            'seller_application_status' => isset($_SESSION['seller_application_status'])
                ? (string) $_SESSION['seller_application_status']
                : '',
        ];
    }
}

if (!function_exists('member_bootstrap_session_user')) {
    function member_bootstrap_session_user(): void
    {
        if (isset($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['id'])) {
            member_sync_legacy_session_from_user();
            return;
        }

        $rebuilt = member_build_user_from_legacy_session();
        if ($rebuilt !== null) {
            $_SESSION['user'] = $rebuilt;
            member_sync_legacy_session_from_user();
        }
    }
}

if (!function_exists('member_user_id')) {
    function member_user_id(): int
    {
        return !empty($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : 0;
    }
}

if (!function_exists('member_user_first_name')) {
    function member_user_first_name(): string
    {
        return (string) ($_SESSION['user']['first_name'] ?? '');
    }
}

if (!function_exists('member_user_last_name')) {
    function member_user_last_name(): string
    {
        return (string) ($_SESSION['user']['last_name'] ?? '');
    }
}

if (!function_exists('member_user_name')) {
    function member_user_name(): string
    {
        $full = trim(member_user_first_name() . ' ' . member_user_last_name());
        if ($full !== '') {
            return $full;
        }

        $email = member_user_email();
        if ($email !== '') {
            return $email;
        }

        return 'Member';
    }
}

if (!function_exists('member_user_email')) {
    function member_user_email(): string
    {
        return (string) ($_SESSION['user']['email'] ?? '');
    }
}

if (!function_exists('member_user_role')) {
    function member_user_role(): string
    {
        $role = (string) ($_SESSION['user']['role'] ?? 'user');
        return $role !== '' ? $role : 'user';
    }
}

if (!function_exists('member_account_status')) {
    function member_account_status(): string
    {
        $status = (string) ($_SESSION['user']['account_status'] ?? 'active');
        return $status !== '' ? $status : 'active';
    }
}

if (!function_exists('seller_application_status')) {
    function seller_application_status(): string
    {
        return isset($_SESSION['user']['seller_application_status'])
            ? (string) $_SESSION['user']['seller_application_status']
            : '';
    }
}

if (!function_exists('member_is_logged_in')) {
    function member_is_logged_in(): bool
    {
        return member_user_id() > 0;
    }
}

if (!function_exists('member_is_seller_approved')) {
    function member_is_seller_approved(): bool
    {
        return member_user_role() === 'seller' && seller_application_status() === 'approved';
    }
}

if (!function_exists('member_is_seller_pending')) {
    function member_is_seller_pending(): bool
    {
        return in_array(seller_application_status(), ['draft', 'submitted', 'under_review'], true);
    }
}

if (!function_exists('member_require_login')) {
    function member_require_login(): void
    {
        if (member_is_logged_in()) {
            return;
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? member_dashboard_url();
        $target = member_safe_redirect_path(
            is_string($requestUri) ? $requestUri : member_dashboard_url(),
            member_dashboard_url()
        );

        $_SESSION['login_redirect'] = $target;
        header('Location: ' . member_login_url() . '?redirect=' . rawurlencode($target));
        exit;
    }
}

if (!function_exists('member_require_role')) {
    function member_require_role(array $allowedRoles): void
    {
        if (!in_array(member_user_role(), $allowedRoles, true)) {
            http_response_code(403);
            echo '403 Forbidden';
            exit;
        }
    }
}

member_bootstrap_session_user();

$memberGuardSkipEnforce = defined('MEMBER_GUARD_SKIP_ENFORCE') && MEMBER_GUARD_SKIP_ENFORCE === true;
if (!$memberGuardSkipEnforce) {
    member_require_login();
}