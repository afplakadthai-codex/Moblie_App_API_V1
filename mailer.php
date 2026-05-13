<?php
declare(strict_types=1);

require_once __DIR__ . '/mail_engine.php';

/**
 * Bettavaro production mailer helper
 * - backward compatible with existing callers
 * - multi-profile aware (mailers.default / support / noreply)
 * - queue-ready via includes/mail_queue.php
 * - still supports SMTP first + mail() fallback
 */

if (!function_exists('bv_mailer_raw_engine_config')) {
    function bv_mailer_raw_engine_config(): array
    {
        $cfg = function_exists('mail_engine_get_config') ? mail_engine_get_config() : [];
        return is_array($cfg) ? $cfg : [];
    }
}

if (!function_exists('bv_mailer_get_profile')) {
    function bv_mailer_get_profile(?string $profile = 'default'): array
    {
        $cfg = bv_mailer_raw_engine_config();
        $profile = trim((string)$profile);
        if ($profile === '') {
            $profile = 'default';
        }

        $mailers = isset($cfg['mailers']) && is_array($cfg['mailers']) ? $cfg['mailers'] : [];

        if (!empty($mailers[$profile]) && is_array($mailers[$profile])) {
            $selected = $mailers[$profile];
        } elseif (!empty($mailers['default']) && is_array($mailers['default'])) {
            $selected = $mailers['default'];
        } else {
            // backward compatible with older flat config
            $selected = [
                'from_email' => (string)($cfg['from_email'] ?? ''),
                'from_name'  => (string)($cfg['from_name'] ?? 'Bettavaro'),
                'reply_to'   => (string)($cfg['reply_to'] ?? ($cfg['from_email'] ?? '')),
            ];
        }

        $fromEmail = trim((string)($selected['from_email'] ?? ''));
        $fromName  = trim((string)($selected['from_name'] ?? 'Bettavaro'));
        $replyTo   = trim((string)($selected['reply_to'] ?? $fromEmail));

        if ($fromEmail === '') {
            $fromEmail = trim((string)($cfg['from_email'] ?? 'subport@bettavaro.com'));
        }
        if ($fromName === '') {
            $fromName = trim((string)($cfg['from_name'] ?? 'Bettavaro'));
        }
        if ($replyTo === '') {
            $replyTo = $fromEmail;
        }

        return [
            'from_email' => $fromEmail,
            'from_name'  => $fromName,
            'reply_to'   => $replyTo,
        ];
    }
}

if (!function_exists('bv_mailer_role_email')) {
    function bv_mailer_role_email(string $role, string $default = ''): string
    {
        $cfg = bv_mailer_raw_engine_config();
        $roles = isset($cfg['roles']) && is_array($cfg['roles']) ? $cfg['roles'] : [];
        $email = trim((string)($roles[$role] ?? ''));
        if ($email !== '') {
            return $email;
        }

        // backward compatibility
        if ($role === 'admin_alert') {
            $legacy = trim((string)($cfg['admin_email'] ?? $cfg['from_email'] ?? ''));
            if ($legacy !== '') {
                return $legacy;
            }
        }

        return $default;
    }
}

if (!function_exists('bv_mailer_config')) {
    function bv_mailer_config(): array
    {
        $engineCfg = bv_mailer_raw_engine_config();

        $appName = defined('APP_NAME') ? (string)APP_NAME : 'Bettavaro';
        $appUrl  = defined('APP_URL') ? (string)APP_URL : 'https://www.bettavaro.com';

        $defaultProfile = bv_mailer_get_profile('default');

        return [
            'site_name' => $appName,
            'site_url'  => $appUrl,

            'use_smtp' => true,

            // old flat keys retained
            'host'       => (string)($engineCfg['host'] ?? $engineCfg['smtp_host'] ?? 'localhost'),
            'port'       => (int)($engineCfg['port'] ?? $engineCfg['smtp_port'] ?? 25),
            'encryption' => (string)($engineCfg['encryption'] ?? $engineCfg['smtp_encryption'] ?? ''),
            'username'   => (string)($engineCfg['username'] ?? $engineCfg['smtp_username'] ?? $defaultProfile['from_email']),
            'password'   => (string)($engineCfg['password'] ?? $engineCfg['smtp_password'] ?? ''),

            'from_email' => $defaultProfile['from_email'],
            'from_name'  => $defaultProfile['from_name'],
            'reply_to'   => $defaultProfile['reply_to'],

            'fallback_to_mail' => array_key_exists('fallback_to_mail', $engineCfg) ? !empty($engineCfg['fallback_to_mail']) : true,
            'admin_email'      => bv_mailer_role_email('admin_alert', $defaultProfile['from_email']),
            'admin_name'       => $defaultProfile['from_name'],

            'mailers' => isset($engineCfg['mailers']) && is_array($engineCfg['mailers']) ? $engineCfg['mailers'] : ['default' => $defaultProfile],
            'roles'   => isset($engineCfg['roles']) && is_array($engineCfg['roles']) ? $engineCfg['roles'] : [],
        ];
    }
}

if (!function_exists('bv_mailer_normalize_email_list')) {
    function bv_mailer_normalize_email_list($emails): array
    {
        if (is_string($emails)) {
            $emails = preg_split('/[,;]+/', $emails) ?: [];
        }

        if (!is_array($emails)) {
            return [];
        }

        $out = [];
        foreach ($emails as $email) {
            $email = trim((string)$email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }
}

if (!function_exists('bv_mailer_subject_encode')) {
    function bv_mailer_subject_encode(string $subject): string
    {
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }
}

if (!function_exists('bv_mailer_send_via_phpmail')) {
    function bv_mailer_send_via_phpmail(array $to, string $subject, string $body, array $options = []): array
    {
        $profileName = trim((string)($options['profile'] ?? 'default'));
        $profile = bv_mailer_get_profile($profileName !== '' ? $profileName : 'default');

        $fromEmail = (string)($options['from_email'] ?? $profile['from_email']);
        $fromName  = (string)($options['from_name'] ?? $profile['from_name']);
        $replyTo   = (string)($options['reply_to'] ?? $profile['reply_to']);

        $isHtml = array_key_exists('is_html', $options) ? !empty($options['is_html']) : true;
        $htmlBody = $isHtml ? $body : nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        $textBody = isset($options['text']) ? (string)$options['text'] : trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));

        $payload = [
            'to'         => $to,
            'cc'         => $options['cc'] ?? [],
            'bcc'        => $options['bcc'] ?? [],
            'subject'    => $subject,
            'html'       => $htmlBody,
            'text'       => $textBody,
            'from_email' => $fromEmail,
            'from_name'  => $fromName,
            'reply_to'   => $replyTo,
            'attachments'=> is_array($options['attachments'] ?? null) ? $options['attachments'] : [],
        ];

        try {
            $sent = mail_engine_send($payload);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'transport' => 'smtp',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'ok' => (bool)$sent,
            'transport' => 'smtp',
            'message' => $sent ? 'Sent via SMTP.' : 'SMTP send failed.',
        ];
    }
}

if (!function_exists('bv_mailer_send_via_mail')) {
    function bv_mailer_send_via_mail(array $to, string $subject, string $body, array $options = []): array
    {
        $profileName = trim((string)($options['profile'] ?? 'default'));
        $profile = bv_mailer_get_profile($profileName !== '' ? $profileName : 'default');

        $fromEmail = (string)($options['from_email'] ?? $profile['from_email']);
        $fromName  = (string)($options['from_name'] ?? $profile['from_name']);
        $replyTo   = (string)($options['reply_to'] ?? $profile['reply_to']);
        $textBody  = isset($options['text']) ? (string)$options['text'] : trim(strip_tags($body));

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: "' . addslashes($fromName) . '" <' . $fromEmail . '>',
            'Reply-To: ' . $replyTo,
        ];

        $subjectEncoded = bv_mailer_subject_encode($subject);
        $headerString   = implode("\r\n", $headers);

        if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $ok = @mail(implode(',', $to), $subjectEncoded, $textBody, $headerString, '-f' . $fromEmail);
        } else {
            $ok = @mail(implode(',', $to), $subjectEncoded, $textBody, $headerString);
        }

        return [
            'ok' => $ok,
            'transport' => 'mail()',
            'message' => $ok ? 'Sent via mail().' : 'mail() send failed.',
        ];
    }
}

if (!function_exists('bv_mailer_send')) {
    function bv_mailer_send($to, string $subject, string $body, array $options = []): array
    {
        $toNormalized = bv_mailer_normalize_email_list($to);
        if (!$toNormalized) {
            return [
                'ok' => false,
                'transport' => 'none',
                'message' => 'No valid recipient email.',
            ];
        }

        $result = bv_mailer_send_via_phpmail($toNormalized, $subject, $body, $options);

        if (!$result['ok']) {
            $cfg = bv_mailer_config();
            if (!empty($cfg['fallback_to_mail'])) {
                return bv_mailer_send_via_mail($toNormalized, $subject, $body, $options);
            }
        }

        return $result;
    }
}

if (!function_exists('bv_send_mail')) {
    function bv_send_mail(array $payload): bool
    {
        $to      = $payload['to'] ?? [];
        $subject = (string)($payload['subject'] ?? '');
        $html    = (string)($payload['html'] ?? '');
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];

        if (isset($payload['profile']) && !isset($options['profile'])) {
            $options['profile'] = $payload['profile'];
        }
        if (isset($payload['cc']) && !isset($options['cc'])) {
            $options['cc'] = $payload['cc'];
        }
        if (isset($payload['bcc']) && !isset($options['bcc'])) {
            $options['bcc'] = $payload['bcc'];
        }
        if (isset($payload['text']) && !isset($options['text'])) {
            $options['text'] = $payload['text'];
        }
        if (isset($payload['attachments']) && !isset($options['attachments'])) {
            $options['attachments'] = $payload['attachments'];
        }

        $result = bv_mailer_send($to, $subject, $html, $options);
        return !empty($result['ok']);
    }
}

// queue helpers (load lazily to avoid breaking older flows if file is absent)
if (is_file(__DIR__ . '/mail_queue.php')) {
    require_once __DIR__ . '/mail_queue.php';
}

if (!function_exists('bv_queue_mail')) {
    function bv_queue_mail(array $payload): array
    {
        if (!function_exists('bv_mail_queue_push')) {
            return [
                'ok' => false,
                'queued' => false,
                'reason' => 'mail_queue_not_available',
            ];
        }

        return bv_mail_queue_push([
            'queue_key'    => $payload['queue_key'] ?? null,
            'profile'      => $payload['profile'] ?? 'default',
            'to'           => $payload['to'] ?? [],
            'cc'           => $payload['cc'] ?? [],
            'bcc'          => $payload['bcc'] ?? [],
            'subject'      => $payload['subject'] ?? '',
            'html'         => $payload['html'] ?? '',
            'text'         => $payload['text'] ?? '',
            'attachments'  => $payload['attachments'] ?? [],
            'meta'         => $payload['meta'] ?? [],
            'max_attempts' => $payload['max_attempts'] ?? 3,
            'available_at' => $payload['available_at'] ?? null,
        ]);
    }
}
