<?php
// /includes/mail_queue.php

if (!function_exists('mq_json_encode')) {
    function mq_json_encode($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('mq_normalize_emails')) {
    function mq_normalize_emails($emails)
    {
        if ($emails === null || $emails === '') {
            return [];
        }

        if (is_string($emails)) {
            $emails = [$emails];
        }

        if (!is_array($emails)) {
            return [];
        }

        $out = [];
        foreach ($emails as $email) {
            $email = trim((string)$email);
            if ($email === '') {
                continue;
            }
            $out[] = $email;
        }

        return array_values(array_unique($out));
    }
}

if (!function_exists('mq_make_queue_key')) {
    function mq_make_queue_key($prefix, $parts = [])
    {
        $raw = $prefix . '|' . implode('|', array_map(static function ($v) {
            if (is_scalar($v) || $v === null) {
                return (string)$v;
            }
            return mq_json_encode($v);
        }, $parts));

        return substr(hash('sha256', $raw), 0, 64);
    }
}

if (!function_exists('mq_push')) {
    /**
     * Push email job into mail_queue
     *
     * @param PDO   $pdo
     * @param array $job
     * @return array
     */
    function mq_push(PDO $pdo, array $job)
    {
        $mailerProfile = isset($job['mailer_profile']) && $job['mailer_profile'] !== ''
            ? (string)$job['mailer_profile']
            : 'default';

        $to  = mq_normalize_emails($job['to'] ?? []);
        $cc  = mq_normalize_emails($job['cc'] ?? []);
        $bcc = mq_normalize_emails($job['bcc'] ?? []);

        if (empty($to)) {
            throw new InvalidArgumentException('mq_push requires at least one recipient in "to".');
        }

        $subject = trim((string)($job['subject'] ?? ''));
        if ($subject === '') {
            throw new InvalidArgumentException('mq_push requires "subject".');
        }

        $htmlBody = isset($job['html_body']) ? (string)$job['html_body'] : null;
        $textBody = isset($job['text_body']) ? (string)$job['text_body'] : null;

        if (($htmlBody === null || $htmlBody === '') && ($textBody === null || $textBody === '')) {
            throw new InvalidArgumentException('mq_push requires html_body or text_body.');
        }

        $attachments = $job['attachments'] ?? [];
        $meta        = $job['meta'] ?? [];

        if (!is_array($attachments)) {
            $attachments = [];
        }
        if (!is_array($meta)) {
            $meta = [];
        }

        $maxAttempts = isset($job['max_attempts']) ? (int)$job['max_attempts'] : 3;
        if ($maxAttempts < 1) {
            $maxAttempts = 1;
        }

        $queueKey = isset($job['queue_key']) ? trim((string)$job['queue_key']) : null;
        if ($queueKey === '') {
            $queueKey = null;
        }

        $availableAt = isset($job['available_at']) && $job['available_at'] !== ''
            ? (string)$job['available_at']
            : date('Y-m-d H:i:s');

        $sql = "
            INSERT INTO mail_queue (
                queue_key,
                mailer_profile,
                to_json,
                cc_json,
                bcc_json,
                subject,
                html_body,
                text_body,
                attachments_json,
                meta_json,
                status,
                attempts,
                max_attempts,
                available_at,
                created_at
            ) VALUES (
                :queue_key,
                :mailer_profile,
                :to_json,
                :cc_json,
                :bcc_json,
                :subject,
                :html_body,
                :text_body,
                :attachments_json,
                :meta_json,
                'queued',
                0,
                :max_attempts,
                :available_at,
                NOW()
            )
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':queue_key'        => $queueKey,
                ':mailer_profile'   => $mailerProfile,
                ':to_json'          => mq_json_encode($to),
                ':cc_json'          => !empty($cc) ? mq_json_encode($cc) : null,
                ':bcc_json'         => !empty($bcc) ? mq_json_encode($bcc) : null,
                ':subject'          => $subject,
                ':html_body'        => $htmlBody,
                ':text_body'        => $textBody,
                ':attachments_json' => !empty($attachments) ? mq_json_encode($attachments) : null,
                ':meta_json'        => !empty($meta) ? mq_json_encode($meta) : null,
                ':max_attempts'     => $maxAttempts,
                ':available_at'     => $availableAt,
            ]);

            return [
                'ok' => true,
                'inserted' => true,
                'id' => (int)$pdo->lastInsertId(),
                'duplicate' => false,
            ];
        } catch (PDOException $e) {
            // Duplicate queue_key
            if ((int)$e->errorInfo[1] === 1062) {
                return [
                    'ok' => true,
                    'inserted' => false,
                    'id' => null,
                    'duplicate' => true,
                ];
            }
            throw $e;
        }
    }
}

if (!function_exists('mq_mark_sent')) {
    function mq_mark_sent(PDO $pdo, $id, $providerMessageId = null)
    {
        $stmt = $pdo->prepare("
            UPDATE mail_queue
            SET
                status = 'sent',
                provider_message_id = :provider_message_id,
                sent_at = NOW(),
                last_attempt_at = NOW(),
                locked_at = NULL,
                locked_by = NULL,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([
            ':provider_message_id' => $providerMessageId,
            ':id' => (int)$id,
        ]);
    }
}

if (!function_exists('mq_mark_retry_or_dead')) {
    function mq_mark_retry_or_dead(PDO $pdo, array $row, $errorMessage)
    {
        $attempts = (int)$row['attempts'] + 1;
        $maxAttempts = (int)$row['max_attempts'];

        $status = ($attempts >= $maxAttempts) ? 'dead' : 'queued';

        // backoff: 5m, 15m, 30m, 60m...
        $delayMinutesMap = [
            1 => 5,
            2 => 15,
            3 => 30,
            4 => 60,
        ];
        $delayMinutes = $delayMinutesMap[$attempts] ?? 120;

        $availableAt = date('Y-m-d H:i:s', time() + ($delayMinutes * 60));

        $stmt = $pdo->prepare("
            UPDATE mail_queue
            SET
                status = :status,
                attempts = :attempts,
                last_error = :last_error,
                last_attempt_at = NOW(),
                available_at = :available_at,
                locked_at = NULL,
                locked_by = NULL,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':status' => $status,
            ':attempts' => $attempts,
            ':last_error' => mb_substr((string)$errorMessage, 0, 65000),
            ':available_at' => $availableAt,
            ':id' => (int)$row['id'],
        ]);
    }
}

if (!function_exists('bv_queue_mail')) {
    function bv_queue_mail(array $job)
    {
        global $pdo;

        return mq_push($pdo, [
            'queue_key'     => $job['queue_key'] ?? null,
            'mailer_profile'=> $job['profile'] ?? 'default',
            'to'            => $job['to'] ?? [],
            'subject'       => $job['subject'] ?? '',
            'html_body'     => $job['html'] ?? null,
            'text_body'     => $job['text'] ?? null,
            'meta'          => $job['meta'] ?? [],
            'max_attempts'  => 3,
        ]);
    }
}