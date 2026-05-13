<?php
declare(strict_types=1);

/**
 * Bettavaro Mail Engine
 * - Manual PHPMailer loader
 * - Reads config from includes/mail_config.php
 * - No vendor/autoload.php required
 * - PHP 7.x/8.x friendly
 */

if (defined('BETTAVARO_MAIL_ENGINE_LOADED')) {
    return;
}
define('BETTAVARO_MAIL_ENGINE_LOADED', true);

/* =========================================================
 * Basic path helpers
 * ========================================================= */
if (!function_exists('mail_engine_base_path')) {
    function mail_engine_base_path()
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('mail_engine_join_path')) {
    function mail_engine_join_path()
    {
        $parts = func_get_args();
        $path = '';

        foreach ($parts as $part) {
            if ($part === null || $part === '') {
                continue;
            }

            if ($path === '') {
                $path = rtrim((string) $part, '/\\');
            } else {
                $path .= DIRECTORY_SEPARATOR . trim((string) $part, '/\\');
            }
        }

        return $path;
    }
}

/* =========================================================
 * Log helpers
 * ========================================================= */
if (!function_exists('mail_engine_log_file')) {
    function mail_engine_log_file()
    {
        $base = mail_engine_base_path();

        $candidates = array(
            mail_engine_join_path($base, 'logs', 'mail.log'),
            mail_engine_join_path($base, 'storage', 'logs', 'mail.log'),
            mail_engine_join_path($base, 'tmp', 'mail.log'),
            mail_engine_join_path(__DIR__, '..', 'logs', 'mail.log'),
        );

        foreach ($candidates as $file) {
            $dir = dirname($file);
            if (is_dir($dir) || @mkdir($dir, 0775, true)) {
                return $file;
            }
        }

        return mail_engine_join_path($base, 'mail.log');
    }
}

if (!function_exists('mail_engine_log')) {
    function mail_engine_log($event, $data = array())
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $event;

        if (!empty($data)) {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }

        $line .= PHP_EOL;
        @file_put_contents(mail_engine_log_file(), $line, FILE_APPEND);
    }
}

/* =========================================================
 * Load config file
 * ========================================================= */
if (!function_exists('mail_engine_load_config_file')) {
    function mail_engine_load_config_file()
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $configFile = __DIR__ . '/mail_config.php';
        if (is_file($configFile)) {
            require_once $configFile;
            $loaded = true;
            return;
        }

        mail_engine_log('mail_config_missing', array(
            'path' => $configFile,
        ));
    }
}

/* =========================================================
 * Config reader
 * Reads EXACT keys from your current mail_config.php
 * ========================================================= */
if (!function_exists('mail_engine_get_config')) {
    function mail_engine_get_config()
    {
        mail_engine_load_config_file();

        if (function_exists('mail_engine_config')) {
            $config = mail_engine_config();
            if (is_array($config)) {
                return $config;
            }
        }

        return array(
            'enabled'        => false,
            'host'           => 'localhost',
            'port'           => 587,
            'encryption'     => 'tls',
            'auth'           => true,
            'username'       => '',
            'password'       => '',
            'from_email'     => '',
            'from_name'      => 'Bettavaro',
            'reply_to'       => '',
            'timeout'        => 20,
            'debug'          => false,
            'charset'        => 'UTF-8',
            'allow_insecure' => false,
        );
    }
}

if (!function_exists('mail_engine_config_value')) {
    function mail_engine_config_value($key, $default = null)
    {
        $config = mail_engine_get_config();
        return array_key_exists($key, $config) ? $config[$key] : $default;
    }
}

/* =========================================================
 * PHPMailer manual loader
 * ========================================================= */
if (!function_exists('mail_engine_load_phpmailer')) {
    function mail_engine_load_phpmailer()
    {
        if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            return true;
        }

        $paths = array(
            __DIR__ . '/lib/PHPMailer/src',
            __DIR__ . '/PHPMailer/src',
            mail_engine_base_path() . '/includes/lib/PHPMailer/src',
            mail_engine_base_path() . '/vendor/phpmailer/phpmailer/src',
        );

        foreach ($paths as $base) {
            $exceptionFile = $base . '/Exception.php';
            $phpmailerFile = $base . '/PHPMailer.php';
            $smtpFile      = $base . '/SMTP.php';

            if (is_file($exceptionFile) && is_file($phpmailerFile) && is_file($smtpFile)) {
                require_once $exceptionFile;
                require_once $phpmailerFile;
                require_once $smtpFile;

                if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
                    mail_engine_log('phpmailer_loaded', array(
                        'base' => $base,
                    ));
                    return true;
                }
            }
        }

        mail_engine_log('phpmailer_missing', array(
            'searched_paths' => $paths,
        ));

        return false;
    }
}

/* =========================================================
 * Address helpers
 * ========================================================= */
if (!function_exists('mail_engine_normalize_addresses')) {
    function mail_engine_normalize_addresses($input)
    {
        $result = array();

        if (empty($input)) {
            return $result;
        }

        if (is_string($input)) {
            $input = preg_split('/[,;]+/', $input);
        }

        if (!is_array($input)) {
            return $result;
        }

        foreach ($input as $item) {
            if (is_string($item)) {
                $email = trim($item);
                if ($email !== '') {
                    $result[] = array(
                        'email' => $email,
                        'name'  => '',
                    );
                }
            } elseif (is_array($item) && !empty($item['email'])) {
                $result[] = array(
                    'email' => trim((string) $item['email']),
                    'name'  => isset($item['name']) ? trim((string) $item['name']) : '',
                );
            }
        }

        return $result;
    }
}

if (!function_exists('mail_engine_is_valid_email')) {
    function mail_engine_is_valid_email($email)
    {
        return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL);
    }
}

/* =========================================================
 * Main send function
 * ========================================================= */
if (!function_exists('mail_engine_send')) {
    function mail_engine_send($params = array())
    {
        $params = is_array($params) ? $params : array();

        $to          = isset($params['to']) ? $params['to'] : array();
        $cc          = isset($params['cc']) ? $params['cc'] : array();
        $bcc         = isset($params['bcc']) ? $params['bcc'] : array();
        $subject     = isset($params['subject']) ? (string) $params['subject'] : '';
        $html        = isset($params['html']) ? (string) $params['html'] : '';
        $text        = isset($params['text']) ? (string) $params['text'] : '';
        $attachments = isset($params['attachments']) && is_array($params['attachments']) ? $params['attachments'] : array();

        $toList  = mail_engine_normalize_addresses($to);
        $ccList  = mail_engine_normalize_addresses($cc);
        $bccList = mail_engine_normalize_addresses($bcc);

        if (empty($toList)) {
            mail_engine_log('mail_failed', array(
                'subject' => $subject,
                'error'   => 'No recipient provided',
            ));
            return false;
        }

        $config = mail_engine_get_config();

        if (empty($config['enabled'])) {
            mail_engine_log('mail_skipped', array(
                'subject' => $subject,
                'error'   => 'Mail engine disabled in config',
            ));
            return false;
        }

        if (!mail_engine_load_phpmailer()) {
            mail_engine_log('mail_failed', array(
                'subject' => $subject,
                'error'   => 'PHPMailer could not be loaded',
            ));
            return false;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            $mail->isSMTP();
            $mail->Host       = (string) $config['host'];
            $mail->Port       = (int) $config['port'];
            $mail->SMTPAuth   = !empty($config['auth']);
            $mail->Username   = isset($config['username']) ? (string) $config['username'] : '';
            $mail->Password   = isset($config['password']) ? (string) $config['password'] : '';
            $mail->CharSet    = isset($config['charset']) ? (string) $config['charset'] : 'UTF-8';
            $mail->Timeout    = isset($config['timeout']) ? (int) $config['timeout'] : 20;
            $mail->isHTML(true);

            $encryption = isset($config['encryption']) ? strtolower(trim((string) $config['encryption'])) : '';
            if ($encryption === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
            }

            if (!empty($config['allow_insecure'])) {
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ),
                );
            }

            if (!empty($config['debug'])) {
                $mail->SMTPDebug = 2;
            }

            $fromEmail = isset($params['from_email']) && $params['from_email'] !== ''
                ? (string) $params['from_email']
                : (isset($config['from_email']) ? (string) $config['from_email'] : '');

            $fromName = isset($params['from_name']) && $params['from_name'] !== ''
                ? (string) $params['from_name']
                : (isset($config['from_name']) ? (string) $config['from_name'] : 'Bettavaro');

            $replyTo = isset($params['reply_to']) && $params['reply_to'] !== ''
                ? (string) $params['reply_to']
                : (isset($config['reply_to']) ? (string) $config['reply_to'] : '');

            if (!mail_engine_is_valid_email($fromEmail)) {
                throw new Exception('Invalid from_email in config: ' . $fromEmail);
            }

            $mail->setFrom($fromEmail, $fromName);

            if ($replyTo !== '' && mail_engine_is_valid_email($replyTo)) {
                $mail->addReplyTo($replyTo, $fromName);
            }

            foreach ($toList as $row) {
                if (mail_engine_is_valid_email($row['email'])) {
                    $mail->addAddress($row['email'], $row['name']);
                }
            }

            foreach ($ccList as $row) {
                if (mail_engine_is_valid_email($row['email'])) {
                    $mail->addCC($row['email'], $row['name']);
                }
            }

            foreach ($bccList as $row) {
                if (mail_engine_is_valid_email($row['email'])) {
                    $mail->addBCC($row['email'], $row['name']);
                }
            }

            if ($html === '' && $text !== '') {
                $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
            }

            if ($text === '' && $html !== '') {
                $tmp = str_replace(array('<br>', '<br/>', '<br />'), "\n", $html);
                $text = trim(html_entity_decode(strip_tags($tmp), ENT_QUOTES, 'UTF-8'));
            }

            if ($html === '' && $text === '') {
                throw new Exception('Email body is empty');
            }

            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text;

            if (!empty($attachments)) {
                foreach ($attachments as $attachment) {
                    if (is_string($attachment) && is_file($attachment)) {
                        $mail->addAttachment($attachment);
                    } elseif (is_array($attachment) && !empty($attachment['path']) && is_file($attachment['path'])) {
                        $attachName = !empty($attachment['name']) ? (string) $attachment['name'] : '';
                        if ($attachName !== '') {
                            $mail->addAttachment($attachment['path'], $attachName);
                        } else {
                            $mail->addAttachment($attachment['path']);
                        }
                    }
                }
            }

            $ok = $mail->send();

            if ($ok) {
                mail_engine_log('mail_sent', array(
                    'to'      => array_map(function ($item) {
                        return $item['email'];
                    }, $toList),
                    'subject' => $subject,
                ));
                return true;
            }

            mail_engine_log('mail_failed', array(
                'to'      => array_map(function ($item) {
                    return $item['email'];
                }, $toList),
                'subject' => $subject,
                'error'   => 'Unknown send() failure',
            ));

            return false;

        } catch (\Throwable $e) {
            mail_engine_log('mail_failed', array(
                'to'      => array_map(function ($item) {
                    return $item['email'];
                }, $toList),
                'subject' => $subject,
                'error'   => $e->getMessage(),
            ));
            return false;
        } catch (\Exception $e) {
            mail_engine_log('mail_failed', array(
                'to'      => array_map(function ($item) {
                    return $item['email'];
                }, $toList),
                'subject' => $subject,
                'error'   => $e->getMessage(),
            ));
            return false;
        }
    }
}

/* =========================================================
 * Simple wrapper
 * ========================================================= */
if (!function_exists('send_app_mail')) {
    function send_app_mail($to, $subject, $html, $text = '', $options = array())
    {
        $payload = array(
            'to'      => $to,
            'subject' => (string) $subject,
            'html'    => (string) $html,
            'text'    => (string) $text,
        );

        if (is_array($options) && !empty($options)) {
            $payload = array_merge($payload, $options);
        }

        return mail_engine_send($payload);
    }
}