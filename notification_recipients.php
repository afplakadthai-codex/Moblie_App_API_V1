<?php
declare(strict_types=1);

if (!function_exists('bv_notification_db')) {
    function bv_notification_db()
    {
        if (function_exists('bv_notify_db')) {
            try {
                $db = bv_notify_db();
                if ($db) {
                    return $db;
                }
            } catch (\Throwable $e) {
            }
        }

        if (isset($GLOBALS['pdo']) && ($GLOBALS['pdo'] instanceof \PDO)) {
            return $GLOBALS['pdo'];
        }
        if (isset($GLOBALS['db']) && ($GLOBALS['db'] instanceof \PDO || $GLOBALS['db'] instanceof \mysqli)) {
            return $GLOBALS['db'];
        }
        if (isset($GLOBALS['mysqli']) && ($GLOBALS['mysqli'] instanceof \mysqli)) {
            return $GLOBALS['mysqli'];
        }

        foreach (['bv_db', 'bv_pdo', 'get_db', 'db'] as $fn) {
            if (!function_exists($fn)) {
                continue;
            }
            try {
                $db = $fn();
                if ($db instanceof \PDO || $db instanceof \mysqli) {
                    return $db;
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }
}

if (!function_exists('bv_notification_query_all')) {
    function bv_notification_query_all(string $sql, array $params = []): array
    {
        if (function_exists('bv_notify_query_all')) {
            try {
                return bv_notify_query_all($sql, $params);
            } catch (\Throwable $e) {
            }
        }

        $db = bv_notification_db();
        if (!$db) {
            return [];
        }

        if ($db instanceof \PDO) {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            if (!$stmt->execute(array_values($params))) {
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
                foreach ($values as $i => $v) {
                    $bind[] = &$values[$i];
                }
                @call_user_func_array([$stmt, 'bind_param'], $bind);
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

if (!function_exists('bv_notification_query_one')) {
    function bv_notification_query_one(string $sql, array $params = []): ?array
    {
        if (function_exists('bv_notify_query_one')) {
            try {
                return bv_notify_query_one($sql, $params);
            } catch (\Throwable $e) {
            }
        }

        $rows = bv_notification_query_all($sql, $params);
        if (!$rows) {
            return null;
        }

        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }
}

if (!function_exists('bv_notification_normalize_email')) {
    function bv_notification_normalize_email(string $email): string
    {
        $email = trim(strtolower($email));
        if ($email === '') {
            return '';
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}

if (!function_exists('bv_notification_admin_email')) {
    function bv_notification_admin_email(): string
    {
        if (defined('ADMIN_EMAIL') && is_string(ADMIN_EMAIL)) {
            $email = bv_notification_normalize_email(ADMIN_EMAIL);
            if ($email !== '') {
                return $email;
            }
        }

        if (isset($GLOBALS['admin_email']) && is_string($GLOBALS['admin_email'])) {
            $email = bv_notification_normalize_email($GLOBALS['admin_email']);
            if ($email !== '') {
                return $email;
            }
        }

        $queries = [
            "SELECT email FROM users WHERE role IN ('admin','super_admin') AND email IS NOT NULL AND email <> '' ORDER BY id ASC LIMIT 1",
            "SELECT email FROM admins WHERE email IS NOT NULL AND email <> '' ORDER BY id ASC LIMIT 1",
            "SELECT value AS email FROM settings WHERE `key` IN ('admin_email','support_email') AND value IS NOT NULL AND value <> '' ORDER BY `key` ASC LIMIT 1",
        ];

        foreach ($queries as $sql) {
            try {
                $row = bv_notification_query_one($sql);
                if (!$row) {
                    continue;
                }
                $value = isset($row['email']) ? (string) $row['email'] : (isset($row['value']) ? (string) $row['value'] : '');
                $email = bv_notification_normalize_email($value);
                if ($email !== '') {
                    return $email;
                }
            } catch (\Throwable $e) {
            }
        }

        return '';
    }
}

if (!function_exists('bv_notification_find_listing_seller_email')) {
    function bv_notification_find_listing_seller_email(int $listingId): string
    {
        if ($listingId <= 0) {
            return '';
        }

        $queries = [
            [
                'sql' => 'SELECT u.email FROM listings l INNER JOIN users u ON u.id = l.seller_id WHERE l.id = ? LIMIT 1',
                'params' => [$listingId],
            ],
            [
                'sql' => 'SELECT u.email FROM products p INNER JOIN users u ON u.id = p.seller_id WHERE p.id = ? LIMIT 1',
                'params' => [$listingId],
            ],
            [
                'sql' => 'SELECT seller_email AS email FROM listings WHERE id = ? LIMIT 1',
                'params' => [$listingId],
            ],
        ];

        foreach ($queries as $query) {
            try {
                $row = bv_notification_query_one($query['sql'], $query['params']);
                if (!$row) {
                    continue;
                }
                $email = bv_notification_normalize_email((string) ($row['email'] ?? ''));
                if ($email !== '') {
                    return $email;
                }
            } catch (\Throwable $e) {
            }
        }

        return '';
    }
}

if (!function_exists('bv_notification_context_from_order_id')) {
    function bv_notification_context_from_order_id(int $orderId): array
    {
        $context = [
            'order_id' => $orderId,
            'order' => [],
            'buyer' => [],
        ];

        if ($orderId <= 0) {
            return $context;
        }

        $queries = [
            [
                'sql' => "SELECT o.*, u.email AS buyer_email, CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS buyer_name
                          FROM orders o
                          LEFT JOIN users u ON u.id = o.buyer_id
                          WHERE o.id = ?
                          LIMIT 1",
                'params' => [$orderId],
            ],
            [
                'sql' => 'SELECT * FROM orders WHERE id = ? LIMIT 1',
                'params' => [$orderId],
            ],
        ];

        $order = null;
        foreach ($queries as $query) {
            try {
                $order = bv_notification_query_one($query['sql'], $query['params']);
                if ($order) {
                    break;
                }
            } catch (\Throwable $e) {
            }
        }

        if (!$order) {
            return $context;
        }

        $context['order'] = $order;
        if (isset($order['code']) && !isset($context['order_code'])) {
            $context['order_code'] = (string) $order['code'];
        } elseif (isset($order['order_code'])) {
            $context['order_code'] = (string) $order['order_code'];
        }

        if (isset($order['total'])) {
            $context['amount'] = (float) $order['total'];
            $context['order_total'] = (float) $order['total'];
        } elseif (isset($order['grand_total'])) {
            $context['amount'] = (float) $order['grand_total'];
            $context['order_total'] = (float) $order['grand_total'];
        }

        if (isset($order['currency'])) {
            $context['currency'] = (string) $order['currency'];
        } elseif (isset($order['currency_code'])) {
            $context['currency'] = (string) $order['currency_code'];
        }

        $buyerEmail = '';
        $buyerName = '';

        foreach (['buyer_email', 'email', 'customer_email'] as $emailKey) {
            if (!empty($order[$emailKey])) {
                $buyerEmail = bv_notification_normalize_email((string) $order[$emailKey]);
                if ($buyerEmail !== '') {
                    break;
                }
            }
        }

        foreach (['buyer_name', 'customer_name', 'full_name', 'name'] as $nameKey) {
            if (!empty($order[$nameKey])) {
                $buyerName = trim((string) $order[$nameKey]);
                if ($buyerName !== '') {
                    break;
                }
            }
        }

        if ($buyerEmail === '' && isset($order['buyer_id']) && (int) $order['buyer_id'] > 0) {
            try {
                $buyerRow = bv_notification_query_one(
                    "SELECT email, CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) AS full_name, name
                     FROM users
                     WHERE id = ?
                     LIMIT 1",
                    [(int) $order['buyer_id']]
                );
                if ($buyerRow) {
                    $buyerEmail = bv_notification_normalize_email((string) ($buyerRow['email'] ?? ''));
                    if ($buyerName === '') {
                        $buyerName = trim((string) ($buyerRow['full_name'] ?? $buyerRow['name'] ?? ''));
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $context['buyer'] = [
            'email' => $buyerEmail,
            'name' => $buyerName,
        ];

        return $context;
    }
}

if (!function_exists('bv_notification_context_from_refund_id')) {
    function bv_notification_context_from_refund_id(int $refundId): array
    {
        $context = [
            'refund_id' => $refundId,
            'refund' => [],
            'refund_items' => [],
            'order' => [],
            'order_id' => 0,
        ];

        if ($refundId <= 0) {
            return $context;
        }

        $refundQueries = [
            [
                'sql' => 'SELECT * FROM order_refunds WHERE id = ? LIMIT 1',
                'params' => [$refundId],
            ],
            [
                'sql' => 'SELECT * FROM refunds WHERE id = ? LIMIT 1',
                'params' => [$refundId],
            ],
            [
                'sql' => 'SELECT * FROM refund_requests WHERE id = ? LIMIT 1',
                'params' => [$refundId],
            ],
        ];

        $refund = null;
        foreach ($refundQueries as $query) {
            try {
                $refund = bv_notification_query_one($query['sql'], $query['params']);
                if ($refund) {
                    break;
                }
            } catch (\Throwable $e) {
            }
        }

        if (!$refund) {
            return $context;
        }

        $context['refund'] = $refund;

        $orderId = 0;
        foreach (['order_id', 'sale_id', 'parent_order_id'] as $key) {
            if (isset($refund[$key]) && (int) $refund[$key] > 0) {
                $orderId = (int) $refund[$key];
                break;
            }
        }

        if ($orderId > 0) {
            $orderContext = bv_notification_context_from_order_id($orderId);
            foreach ($orderContext as $key => $value) {
                $context[$key] = $value;
            }
            $context['order_id'] = $orderId;
        }

        $refundItemQueries = [
            [
                'sql' => 'SELECT * FROM order_refund_items WHERE refund_id = ?',
                'params' => [$refundId],
            ],
            [
                'sql' => 'SELECT * FROM refund_items WHERE refund_id = ?',
                'params' => [$refundId],
            ],
            [
                'sql' => 'SELECT * FROM refund_request_items WHERE refund_request_id = ?',
                'params' => [$refundId],
            ],
        ];

        foreach ($refundItemQueries as $query) {
            try {
                $items = bv_notification_query_all($query['sql'], $query['params']);
                if ($items) {
                    $context['refund_items'] = $items;
                    break;
                }
            } catch (\Throwable $e) {
            }
        }

        if (isset($refund['amount'])) {
            $context['amount'] = (float) $refund['amount'];
            $context['refund_amount'] = (float) $refund['amount'];
        } elseif (isset($refund['refund_amount'])) {
            $context['amount'] = (float) $refund['refund_amount'];
            $context['refund_amount'] = (float) $refund['refund_amount'];
        }

        if (isset($refund['currency'])) {
            $context['currency'] = (string) $refund['currency'];
        }

        return $context;
    }
}

if (!function_exists('bv_notification_recipients_for_event')) {
    function bv_notification_recipients_for_event(string $eventKey, array $context = []): array
    {
        $result = [];

        $normalizedContext = $context;

        if (($eventKey === 'refund.request.created' || $eventKey === 'refund.completed') && isset($context['refund_id'])) {
            $loaded = bv_notification_context_from_refund_id((int) $context['refund_id']);
            $normalizedContext = array_merge($loaded, $context);
        } elseif ($eventKey === 'order.payment.received' && isset($context['order_id'])) {
            $loaded = bv_notification_context_from_order_id((int) $context['order_id']);
            $normalizedContext = array_merge($loaded, $context);
        } elseif (isset($context['order_id']) && (!isset($context['order']) || !is_array($context['order']) || !$context['order'])) {
            $loaded = bv_notification_context_from_order_id((int) $context['order_id']);
            $normalizedContext = array_merge($loaded, $context);
        }

        $buyerEmail = '';
        $buyerName = '';

        if (isset($normalizedContext['buyer']) && is_array($normalizedContext['buyer'])) {
            $buyerEmail = bv_notification_normalize_email((string) ($normalizedContext['buyer']['email'] ?? ''));
            $buyerName = trim((string) ($normalizedContext['buyer']['name'] ?? ''));
        }

        if ($buyerEmail === '' && isset($normalizedContext['order']) && is_array($normalizedContext['order'])) {
            $order = $normalizedContext['order'];
            foreach (['buyer_email', 'email', 'customer_email'] as $emailKey) {
                if (!empty($order[$emailKey])) {
                    $buyerEmail = bv_notification_normalize_email((string) $order[$emailKey]);
                    if ($buyerEmail !== '') {
                        break;
                    }
                }
            }
            if ($buyerName === '') {
                foreach (['buyer_name', 'customer_name', 'full_name', 'name'] as $nameKey) {
                    if (!empty($order[$nameKey])) {
                        $buyerName = trim((string) $order[$nameKey]);
                        if ($buyerName !== '') {
                            break;
                        }
                    }
                }
            }
        }

        if ($buyerEmail !== '') {
            $result[] = [
                'type' => 'buyer',
                'email' => $buyerEmail,
                'name' => $buyerName,
                'context' => $normalizedContext,
            ];
        }

        $sellerMap = [];
        if (($eventKey === 'refund.request.created' || $eventKey === 'refund.completed') && isset($normalizedContext['refund_items']) && is_array($normalizedContext['refund_items'])) {
            foreach ($normalizedContext['refund_items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $sellerEmail = '';
                if (!empty($item['seller_email'])) {
                    $sellerEmail = bv_notification_normalize_email((string) $item['seller_email']);
                }

                if ($sellerEmail === '') {
                    $listingId = 0;
                    foreach (['listing_id', 'product_id', 'item_listing_id'] as $key) {
                        if (isset($item[$key]) && (int) $item[$key] > 0) {
                            $listingId = (int) $item[$key];
                            break;
                        }
                    }

                    if ($listingId > 0) {
                        $sellerEmail = bv_notification_find_listing_seller_email($listingId);
                    }
                }

                if ($sellerEmail === '') {
                    continue;
                }

                $lower = strtolower($sellerEmail);
                if (!isset($sellerMap[$lower])) {
                    $sellerMap[$lower] = [
                        'email' => $sellerEmail,
                        'name' => 'Seller',
                    ];
                }
            }
        }

        if (!empty($sellerMap)) {
            foreach (array_values($sellerMap) as $sellerRecipient) {
                $result[] = [
                    'type' => 'seller',
                    'email' => (string) ($sellerRecipient['email'] ?? ''),
                    'name' => (string) ($sellerRecipient['name'] ?? 'Seller'),
                    'context' => $normalizedContext,
                ];
            }
        }

        $adminEmail = bv_notification_admin_email();
        if ($adminEmail !== '') {
            $result[] = [
                'type' => 'admin',
                'email' => $adminEmail,
                'name' => 'Admin',
                'context' => $normalizedContext,
            ];
        }

        return $result;
    }
}
