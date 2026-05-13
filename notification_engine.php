<?php
declare(strict_types=1);

if (!function_exists('bv_notify_boot')) {
    function bv_notify_boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $baseDir = __DIR__;
        $dbFiles = [
            $baseDir . '/../config/db.php',
            $baseDir . '/db.php',
            $baseDir . '/../includes/db.php',
        ];

        foreach ($dbFiles as $dbFile) {
            if (is_file($dbFile)) {
                require_once $dbFile;
            }
        }

        $files = [
            $baseDir . '/notification_templates.php',
            $baseDir . '/notification_recipients.php',
        ];

        foreach ($files as $file) {
            if (is_file($file)) {
                require_once $file;
            }
        }

        if (!function_exists('bv_queue_mail')) {
            $mailerFile = $baseDir . '/mailer.php';
            if (is_file($mailerFile)) {
                require_once $mailerFile;
            }
        }
    }
}

if (!function_exists('bv_notify_db')) {
    function bv_notify_db()
    {
        static $db = false;

        if ($db !== false) {
            return $db;
        }

        $candidates = [];

        if (isset($GLOBALS['pdo'])) {
            $candidates[] = $GLOBALS['pdo'];
        }
        if (isset($GLOBALS['db'])) {
            $candidates[] = $GLOBALS['db'];
        }
        if (isset($GLOBALS['mysqli'])) {
            $candidates[] = $GLOBALS['mysqli'];
        }

        $resolverFunctions = [
            'bv_db',
            'bv_pdo',
            'get_db',
            'db',
        ];

        foreach ($resolverFunctions as $fn) {
            if (function_exists($fn)) {
                try {
                    $resolved = $fn();
                    if ($resolved) {
                        $candidates[] = $resolved;
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate instanceof \PDO || $candidate instanceof \mysqli) {
                $db = $candidate;
                return $db;
            }
        }

        $db = null;
        return $db;
    }
}

if (!function_exists('bv_notify_query_all')) {
    function bv_notify_query_all(string $sql, array $params = []): array
    {
        $db = bv_notify_db();
        if (!$db) {
            return [];
        }

        if ($db instanceof \PDO) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $ok = $stmt->execute(array_values($params));
            if (!$ok) {
                return [];
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        }

        if ($db instanceof \mysqli) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            if ($params) {
                $types = str_repeat('s', count($params));
                $values = array_values($params);
                $bind = [$types];
                foreach ($values as $k => $v) {
                    $bind[] = &$values[$k];
                }
                call_user_func_array([$stmt, 'bind_param'], $bind);
            }
            if (!$stmt->execute()) {
                $stmt->close();
                return [];
            }
            $result = $stmt->get_result();
            if (!$result) {
                $stmt->close();
                return [];
            }
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return is_array($rows) ? $rows : [];
        }

        return [];
    }
}

if (!function_exists('bv_notify_query_one')) {
    function bv_notify_query_one(string $sql, array $params = []): ?array
    {
        $rows = bv_notify_query_all($sql, $params);
        if (!$rows) {
            return null;
        }

        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }
}

if (!function_exists('bv_notify_execute')) {
    function bv_notify_execute(string $sql, array $params = []): array
    {
        $db = bv_notify_db();
        if (!$db) {
            return ['ok' => false, 'affected_rows' => 0, 'error' => 'db_unavailable'];
        }

        if ($db instanceof \PDO) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return ['ok' => false, 'affected_rows' => 0, 'error' => 'prepare_failed'];
            }
            $ok = $stmt->execute(array_values($params));
            return [
                'ok' => (bool) $ok,
                'affected_rows' => $ok ? (int) $stmt->rowCount() : 0,
                'error' => $ok ? null : 'execute_failed',
            ];
        }

        if ($db instanceof \mysqli) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return ['ok' => false, 'affected_rows' => 0, 'error' => 'prepare_failed'];
            }
            if ($params) {
                $types = str_repeat('s', count($params));
                $values = array_values($params);
                $bind = [$types];
                foreach ($values as $k => $v) {
                    $bind[] = &$values[$k];
                }
                call_user_func_array([$stmt, 'bind_param'], $bind);
            }
            $ok = $stmt->execute();
            $affectedRows = $ok ? (int) $stmt->affected_rows : 0;
            $stmt->close();
            return [
                'ok' => (bool) $ok,
                'affected_rows' => $affectedRows,
                'error' => $ok ? null : 'execute_failed',
            ];
        }

        return ['ok' => false, 'affected_rows' => 0, 'error' => 'unsupported_db_driver'];
    }
}

if (!function_exists('bv_notify_event_supported')) {
    function bv_notify_event_supported(string $eventKey): bool
    {
        static $events = [
            'refund.request.created' => true,
            'refund.completed' => true,
            'order.payment.received' => true,
        ];

        return isset($events[$eventKey]);
    }
}

if (!function_exists('bv_notify_log')) {
    function bv_notify_log(array $row): void
    {
        static $tableExists;

        if ($tableExists === null) {
            $check = bv_notify_query_one("SHOW TABLES LIKE 'notification_logs'");
            $tableExists = (bool) $check;
        }

        if (!$tableExists) {
            return;
        }

        $sql = 'INSERT INTO notification_logs (
            event_key,
            entity_type,
            entity_id,
            recipient_type,
            recipient_email,
            channel,
            queue_key,
            provider_message_id,
            status,
            subject_snapshot,
            payload_snapshot,
            meta_json,
            error_message,
            sent_at,
            failed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $payloadSnapshot = isset($row['payload_snapshot']) ? $row['payload_snapshot'] : null;
        $metaJson = isset($row['meta_json']) ? $row['meta_json'] : null;

        if (is_array($payloadSnapshot)) {
            $payloadSnapshot = json_encode($payloadSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_array($metaJson)) {
            $metaJson = json_encode($metaJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        bv_notify_execute($sql, [
            (string) ($row['event_key'] ?? ''),
            (string) ($row['entity_type'] ?? ''),
            (int) ($row['entity_id'] ?? 0),
            (string) ($row['recipient_type'] ?? 'user'),
            (string) ($row['recipient_email'] ?? ''),
            (string) ($row['channel'] ?? 'email'),
            isset($row['queue_key']) ? (string) $row['queue_key'] : null,
            isset($row['provider_message_id']) ? (string) $row['provider_message_id'] : null,
            (string) ($row['status'] ?? 'queued'),
            isset($row['subject_snapshot']) ? (string) $row['subject_snapshot'] : null,
            $payloadSnapshot,
            $metaJson,
            isset($row['error_message']) ? (string) $row['error_message'] : null,
            isset($row['sent_at']) ? (string) $row['sent_at'] : null,
            isset($row['failed_at']) ? (string) $row['failed_at'] : null,
        ]);
    }
}

if (!function_exists('bv_notify_log_failure')) {
    function bv_notify_log_failure(...$args): void
    {
        $eventKey = isset($args[0]) ? (string) $args[0] : '';
        $recipient = isset($args[1]) && is_array($args[1]) ? $args[1] : [];
        $payload = isset($args[2]) && is_array($args[2]) ? $args[2] : [];
        $errorMessage = 'notification_failed';

        if (isset($args[3])) {
            if ($args[3] instanceof \Throwable) {
                $errorMessage = $args[3]->getMessage();
            } else {
                $errorMessage = (string) $args[3];
            }
        }

        bv_notify_log([
            'event_key' => $eventKey,
            'entity_type' => (string) ($payload['meta']['entity_type'] ?? ($payload['entity_type'] ?? 'unknown')),
            'entity_id' => (int) ($payload['meta']['entity_id'] ?? ($payload['entity_id'] ?? 0)),
            'recipient_type' => (string) ($recipient['type'] ?? 'user'),
            'recipient_email' => (string) ($recipient['email'] ?? ($payload['to'] ?? '')),
            'channel' => (string) ($payload['meta']['channel'] ?? 'email'),
            'queue_key' => isset($payload['queue_key']) ? (string) $payload['queue_key'] : null,
            'provider_message_id' => null,
            'status' => 'failed',
            'subject_snapshot' => isset($payload['subject']) ? (string) $payload['subject'] : null,
            'payload_snapshot' => $payload,
            'meta_json' => isset($payload['meta']) ? $payload['meta'] : null,
            'error_message' => $errorMessage,
            'failed_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

if (!function_exists('bv_notify_queue_payload')) {
    function bv_notify_queue_payload(array $payload): array
    {
        if (!function_exists('bv_queue_mail')) {
            return [
                'ok' => false,
                'error' => 'bv_queue_mail_missing',
            ];
        }

        try {
            $queueResult = bv_queue_mail($payload);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }

        $ok = false;
        $providerMessageId = null;
        $raw = $queueResult;

        if (is_bool($queueResult)) {
            $ok = $queueResult;
        } elseif (is_numeric($queueResult)) {
            $ok = ((int) $queueResult) > 0;
            $providerMessageId = (string) $queueResult;
        } elseif (is_string($queueResult)) {
            $ok = $queueResult !== '';
            $providerMessageId = $queueResult !== '' ? $queueResult : null;
        } elseif (is_array($queueResult)) {
            $ok = (bool) ($queueResult['ok'] ?? $queueResult['success'] ?? false);
            $providerMessageId = isset($queueResult['id']) ? (string) $queueResult['id'] : (isset($queueResult['message_id']) ? (string) $queueResult['message_id'] : null);
        }

        return [
            'ok' => $ok,
            'provider_message_id' => $providerMessageId,
            'raw' => $raw,
        ];
    }
}

if (!function_exists('bv_notify_money')) {
    function bv_notify_money($amount): float
    {
        return round((float)$amount, 2);
    }
}

if (!function_exists('bv_notify_money_format')) {
    function bv_notify_money_format(float $amount, string $currency): string
    {
        return number_format(bv_notify_money($amount), 2) . ' ' . strtoupper(trim($currency) !== '' ? trim($currency) : 'USD');
    }
}

if (!function_exists('bv_notify_pick_money')) {
    function bv_notify_pick_money(array $row, array $keys, float $default = 0.0): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return bv_notify_money($row[$key]);
            }
        }
        return bv_notify_money($default);
    }
}

if (!function_exists('bv_notify_build_seller_refund_email_payload')) {
    function bv_notify_build_seller_refund_email_payload(string $eventKey, int $refundId, int $sellerId): ?array
    {
        if ($refundId <= 0 || $sellerId <= 0) {
            return null;
        }

        $refund = bv_notify_query_one('SELECT * FROM order_refunds WHERE id = ? LIMIT 1', [$refundId]);
        if (!$refund) {
            return null;
        }
       $items = bv_notify_query_all(
            'SELECT ri.id
             FROM order_refund_items ri
             INNER JOIN order_items oi ON oi.id = ri.order_item_id
             INNER JOIN listings l ON l.id = oi.listing_id
             WHERE ri.refund_id = ?
               AND l.seller_id = ?
             ORDER BY ri.id ASC',
            [$refundId, $sellerId]
        );

        if ($items === []) {
            return null;
        }

        $orderCode = (string)($refund['order_code'] ?? $refund['order_number'] ?? $refund['order_no'] ?? '');
        if ($orderCode === '') {
            $orderId = (int)($refund['order_id'] ?? 0);
            if ($orderId > 0) {
                $order = bv_notify_query_one('SELECT id, order_code, order_number, order_no FROM orders WHERE id = ? LIMIT 1', [$orderId]);
                if (is_array($order)) {
                    $orderCode = (string)($order['order_code'] ?? $order['order_number'] ?? $order['order_no'] ?? '');
                }
                if ($orderCode === '') {
                    $orderCode = (string)$orderId;
                }
            }
        }
        if ($orderCode === '') {
            $orderCode = (string)($refund['order_id'] ?? '');
        }

        $reason = trim((string)($refund['refund_reason_text'] ?? $refund['reason'] ?? ''));
        $refundCode = (int)($refund['id'] ?? $refundId);
        $subjectPrefix = $eventKey === 'refund.completed' ? 'Refund Completed' : 'Refund Request';
        $subject = $subjectPrefix . ' #' . $refundCode . ' (Seller Summary)';
        $detailUrl = '/seller/refund_view.php?id=' . $refundCode;
        $dashboardUrl = '/seller/refunds.php';

        $html = ''
            . '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111827;">'
            . '<p>Hello Seller,</p>'
            . '<p>Bettavaro</p>'
            . '<p>Order: <strong>#' . htmlspecialchars($orderCode, ENT_QUOTES, 'UTF-8') . '</strong>'
            . ($reason !== '' ? '<br>Reason: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') : '')
            . '</p>'
            . '<p><a href="' . htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') . '">View refund details</a><br>'
            . '<a href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . '">Open dashboard</a></p>'
            . '</div>';

        $text = "Hello Seller,

Bettavaro
Order: #{$orderCode}"
            . ($reason !== '' ? "
Reason: {$reason}" : '')
            . "
Refund details: {$detailUrl}
Dashboard: {$dashboardUrl}";

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
    }
}

if (!function_exists('bv_notify_apply_refund_seller_template_override')) {
    function bv_notify_apply_refund_seller_template_override(string $eventKey, string $recipientType, array $context, array $recipient, array $template): array
    {
        if (!in_array($eventKey, ['refund.request.created', 'refund.completed'], true)) {
            return $template;
        }

        if (strtolower(trim($recipientType)) !== 'seller') {
            return $template;
        }

        $refundId = (int)($context['refund_id'] ?? 0);
        $sellerId = (int)($recipient['seller_id'] ?? ($recipient['id'] ?? ($context['seller_id'] ?? 0)));

        $sellerTemplate = bv_notify_build_seller_refund_email_payload($eventKey, $refundId, $sellerId);
        if (!is_array($sellerTemplate) || $sellerTemplate === []) {
            return $template;
        }

        return [
            'subject' => (string)($sellerTemplate['subject'] ?? ($template['subject'] ?? '')),
            'html' => (string)($sellerTemplate['html'] ?? ($template['html'] ?? '')),
            'text' => (string)($sellerTemplate['text'] ?? ($template['text'] ?? '')),
        ];
    }
}

if (!function_exists('bv_notify')) {
    function bv_notify(string $eventKey, array $context = [], array $options = []): array
    {
        bv_notify_boot();

        $result = [
            'ok' => false,
            'event_key' => $eventKey,
            'queued' => 0,
            'failed' => 0,
            'results' => [],
        ];

        if (!bv_notify_event_supported($eventKey)) {
            $result['results'][] = [
                'ok' => false,
                'error' => 'event_not_supported',
                'event_key' => $eventKey,
            ];
            return $result;
        }

        if (!function_exists('bv_queue_mail')) {
            $result['results'][] = [
                'ok' => false,
                'error' => 'queue_function_missing',
                'event_key' => $eventKey,
            ];
            return $result;
        }

        $recipients = [];

        $recipientResolvers = [
            'bv_notification_recipients_for_event',
            'bv_notification_resolve_recipients',
            'bv_notify_resolve_recipients',
            'bv_recipients_for_notification',
        ];

        foreach ($recipientResolvers as $resolver) {
            if (function_exists($resolver)) {
                try {
                    $resolved = $resolver($eventKey, $context, $options);
                    if (is_array($resolved)) {
                        $recipients = $resolved;
                        break;
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        if (!$recipients) {
            if (isset($options['recipients']) && is_array($options['recipients'])) {
                $recipients = $options['recipients'];
            } elseif (isset($context['recipients']) && is_array($context['recipients'])) {
                $recipients = $context['recipients'];
            }
        }

        $template = [];
        $templateResolvers = [
            'bv_notification_template_build',
            'bv_notification_resolve_template',
            'bv_notify_resolve_template',
            'bv_notification_template_for_event',
        ];

        foreach ($templateResolvers as $resolver) {
            if (function_exists($resolver)) {
                try {
                    if ($resolver === 'bv_notification_template_build') {
                        continue;
                    }
                    $resolved = $resolver($eventKey, $context, $options);
                    if (is_array($resolved)) {
                        $template = $resolved;
                        break;
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        if (!$template) {
            $template = [
                'subject' => isset($options['subject']) ? (string) $options['subject'] : (isset($context['subject']) ? (string) $context['subject'] : ('Notification: ' . $eventKey)),
                'html' => isset($options['html']) ? (string) $options['html'] : (isset($context['html']) ? (string) $context['html'] : ''),
                'text' => isset($options['text']) ? (string) $options['text'] : (isset($context['text']) ? (string) $context['text'] : ''),
            ];
        }

        if (!$recipients) {
            $result['results'][] = [
                'ok' => false,
                'error' => 'no_recipients',
                'event_key' => $eventKey,
            ];
            return $result;
        }

        $entityType = (string) ($context['entity_type'] ?? 'unknown');
        $entityId = (int) ($context['entity_id'] ?? 0);
        $profile = (string) ($options['profile'] ?? 'default');
        $channel = (string) ($options['channel'] ?? 'email');
        $hasRecipientTemplateBuilder = function_exists('bv_notification_template_build');

        foreach ($recipients as $recipient) {
            if (!is_array($recipient)) {
                continue;
            }

            $recipientEmail = isset($recipient['email']) ? trim((string) $recipient['email']) : '';
            if ($recipientEmail === '') {
                $result['failed']++;
                $result['results'][] = [
                    'ok' => false,
                    'recipient' => $recipient,
                    'error' => 'recipient_email_missing',
                ];
                continue;
            }

            $recipientType = (string) ($recipient['type'] ?? 'user');
            $queueKeyRaw = $eventKey . '_' . $recipientType . '_' . (string) $entityId . '_' . md5(strtolower($recipientEmail));
            $queueKey = strtolower((string) preg_replace('/[^a-z0-9_.-]+/i', '_', $queueKeyRaw));
            $queueKey = trim($queueKey, '_');
            if ($queueKey === '') {
                $queueKey = 'notify_' . md5($queueKeyRaw);
            }

            $templateForRecipient = $template;
            if ($hasRecipientTemplateBuilder) {
                try {
                    $builtTemplate = bv_notification_template_build($eventKey, $recipientType, $context);
                    if (is_array($builtTemplate) && $builtTemplate) {
                        $templateForRecipient = $builtTemplate;
                    }
                } catch (\Throwable $e) {
                }
            }

            $payload = [
                'queue_key' => $queueKey,
                'profile' => $profile,
                'to' => [$recipientEmail],
                'subject' => (string) ($templateForRecipient['subject'] ?? ''),
                'html' => (string) ($templateForRecipient['html'] ?? ''),
                'text' => (string) ($templateForRecipient['text'] ?? ''),
                'meta' => [
                    'event_key' => $eventKey,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'recipient_type' => $recipientType,
                    'channel' => $channel,
                    'context' => $context,
                ],
            ];

            try {
                $queued = bv_notify_queue_payload($payload);

                if (!empty($queued['ok'])) {
                    $result['queued']++;
                    $result['results'][] = [
                        'ok' => true,
                        'recipient' => $recipientEmail,
                        'queue_key' => $queueKey,
                        'provider_message_id' => $queued['provider_message_id'] ?? null,
                    ];

                    bv_notify_log([
                        'event_key' => $eventKey,
                        'entity_type' => $entityType,
                        'entity_id' => $entityId,
                        'recipient_type' => $recipientType,
                        'recipient_email' => $recipientEmail,
                        'channel' => $channel,
                        'queue_key' => $queueKey,
                        'provider_message_id' => $queued['provider_message_id'] ?? null,
                        'status' => 'queued',
                        'subject_snapshot' => $payload['subject'],
                        'payload_snapshot' => $payload,
                        'meta_json' => $payload['meta'],
                        'sent_at' => null,
                        'failed_at' => null,
                    ]);
                } else {
                    $result['failed']++;
                    $error = isset($queued['error']) ? (string) $queued['error'] : 'queue_failed';

                    $result['results'][] = [
                        'ok' => false,
                        'recipient' => $recipientEmail,
                        'queue_key' => $queueKey,
                        'error' => $error,
                    ];

                    bv_notify_log_failure($eventKey, $recipient, $payload, $error);
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['results'][] = [
                    'ok' => false,
                    'recipient' => $recipientEmail,
                    'queue_key' => $queueKey,
                    'error' => $e->getMessage(),
                ];
                bv_notify_log_failure($eventKey, $recipient, $payload, $e);
            }
        }

        $result['ok'] = $result['queued'] > 0 && $result['failed'] === 0;
        return $result;
    }
}
