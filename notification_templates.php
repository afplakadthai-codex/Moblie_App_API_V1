<?php
declare(strict_types=1);

if (!function_exists('bv_template_app_name')) {
    function bv_template_app_name(): string
    {
        if (defined('APP_NAME') && is_string(APP_NAME) && APP_NAME !== '') {
            return APP_NAME;
        }

        return 'Bettavaro';
    }
}

if (!function_exists('bv_template_app_url')) {
    function bv_template_app_url(): string
    {
        if (defined('APP_URL') && is_string(APP_URL) && APP_URL !== '') {
            return rtrim(APP_URL, '/');
        }

        return '';
    }
}

if (!function_exists('bv_template_html_escape')) {
    function bv_template_html_escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bv_template_order_code')) {
    function bv_template_order_code(array $context): string
    {
        $code = '';

        if (isset($context['order_code']) && $context['order_code'] !== '') {
            $code = (string) $context['order_code'];
        } elseif (isset($context['order']['code']) && $context['order']['code'] !== '') {
            $code = (string) $context['order']['code'];
        } elseif (isset($context['order_id']) && $context['order_id'] !== '') {
            $code = (string) $context['order_id'];
        }

        return trim($code) !== '' ? trim($code) : '-';
    }
}

if (!function_exists('bv_template_money')) {
    function bv_template_money(array $context): string
    {
        $amount = 0.0;

        if (isset($context['amount'])) {
            $amount = (float) $context['amount'];
        } elseif (isset($context['refund_amount'])) {
            $amount = (float) $context['refund_amount'];
        } elseif (isset($context['payment_amount'])) {
            $amount = (float) $context['payment_amount'];
        } elseif (isset($context['order_total'])) {
            $amount = (float) $context['order_total'];
        } elseif (isset($context['order']['total'])) {
            $amount = (float) $context['order']['total'];
        }

        $currency = 'USD';
        if (isset($context['currency']) && is_string($context['currency']) && $context['currency'] !== '') {
            $currency = strtoupper(trim($context['currency']));
        } elseif (isset($context['currency_code']) && is_string($context['currency_code']) && $context['currency_code'] !== '') {
            $currency = strtoupper(trim($context['currency_code']));
        }

        return $currency . ' ' . number_format($amount, 2, '.', ',');
    }
}

if (!function_exists('bv_template_action_link')) {
    function bv_template_action_link(array $context, string $path = '/account/orders'): string
    {
        if (isset($context['action_url']) && is_string($context['action_url']) && $context['action_url'] !== '') {
            return (string) $context['action_url'];
        }

        if (isset($context['order_url']) && is_string($context['order_url']) && $context['order_url'] !== '') {
            return (string) $context['order_url'];
        }

        if (isset($context['refund_url']) && is_string($context['refund_url']) && $context['refund_url'] !== '') {
            return (string) $context['refund_url'];
        }

        $appUrl = bv_template_app_url();
        if ($appUrl === '') {
            return '';
        }

        return $appUrl . $path;
    }
}

if (!function_exists('bv_template_layout')) {
    function bv_template_layout(string $title, array $rows, string $actionLabel, string $actionUrl, string $closingLine): array
    {
        $appName = bv_template_app_name();
        $safeTitle = bv_template_html_escape($title);
        $safeAppName = bv_template_html_escape($appName);

        $htmlRows = '';
        $textRows = [];

        foreach ($rows as $label => $value) {
            $labelText = (string) $label;
            $valueText = (string) $value;

            $htmlRows .= '<tr>'
                . '<td style="padding:8px 0;color:#666;font-size:14px;vertical-align:top;width:140px;">' . bv_template_html_escape($labelText) . '</td>'
                . '<td style="padding:8px 0;color:#111;font-size:14px;vertical-align:top;">' . bv_template_html_escape($valueText) . '</td>'
                . '</tr>';

            $textRows[] = $labelText . ': ' . $valueText;
        }

        $safeActionUrl = bv_template_html_escape($actionUrl);
        $safeActionLabel = bv_template_html_escape($actionLabel);
        $safeClosing = bv_template_html_escape($closingLine);

        $actionHtml = '';
        $actionText = '';
        if ($actionUrl !== '') {
            $actionHtml = '<p style="margin:20px 0 10px;">'
                . '<a href="' . $safeActionUrl . '" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:10px 16px;border-radius:4px;font-size:14px;">' . $safeActionLabel . '</a>'
                . '</p>';
            $actionText = $actionLabel . ': ' . $actionUrl . "\n";
        }

        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f6f7f9;font-family:Arial,sans-serif;color:#111;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f9;padding:20px 0;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:24px;">'
            . '<tr><td style="font-size:20px;font-weight:bold;color:#111;padding-bottom:12px;">' . $safeTitle . '</td></tr>'
            . '<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $htmlRows . '</table></td></tr>'
            . '<tr><td>' . $actionHtml . '</td></tr>'
            . '<tr><td style="font-size:14px;color:#444;line-height:1.5;padding-top:8px;">' . $safeClosing . '</td></tr>'
            . '<tr><td style="font-size:12px;color:#888;line-height:1.5;padding-top:16px;">— ' . $safeAppName . '</td></tr>'
            . '</table>'
            . '</td></tr></table></body></html>';

        $text = $title . "\n\n"
            . implode("\n", $textRows) . "\n\n"
            . $actionText
            . $closingLine . "\n\n"
            . '- ' . $appName;

        return [
            'html' => $html,
            'text' => $text,
        ];
    }
}

if (!function_exists('bv_template_refund_request_buyer')) {
    function bv_template_refund_request_buyer(array $context): array
    {
        $orderCode = bv_template_order_code($context);
        $amount = bv_template_money($context);
        $subject = 'Your Refund Request Has Been Submitted – Order #' . $orderCode;

        $layout = bv_template_layout(
            'Refund Request Submitted',
            [
                'Order' => '#' . $orderCode,
                'Refund Amount' => $amount,
                'Status' => 'Under Review',
            ],
            'View Refund Status',
            bv_template_action_link($context, '/account/refunds'),
            'We received your request and will notify you when the review is complete.'
        );

        return [
            'subject' => $subject,
            'html' => $layout['html'],
            'text' => $layout['text'],
        ];
    }
}

if (!function_exists('bv_template_refund_request_seller')) {
    function bv_template_refund_request_seller(array $context): array
    {
        $orderCode = bv_template_order_code($context);
        $amount = bv_template_money($context);
        $subject = 'New Refund Request – Order #' . $orderCode;

        $layout = bv_template_layout(
            'New Refund Request',
            [
                'Order' => '#' . $orderCode,
                'Refund Amount' => $amount,
                'Action Needed' => 'Review and respond',
            ],
            'Review Refund Request',
            bv_template_action_link($context, '/seller/refunds'),
            'A buyer has requested a refund. Please review the request details and respond promptly.'
        );

        return [
            'subject' => $subject,
            'html' => $layout['html'],
            'text' => $layout['text'],
        ];
    }
}

if (!function_exists('bv_template_refund_completed_buyer')) {
    function bv_template_refund_completed_buyer(array $context): array
    {
        $orderCode = bv_template_order_code($context);
        $amount = bv_template_money($context);
        $subject = 'Refund Completed – Order #' . $orderCode;

        $layout = bv_template_layout(
            'Refund Completed',
            [
                'Order' => '#' . $orderCode,
                'Refund Amount' => $amount,
                'Status' => 'Completed',
            ],
            'View Order Details',
            bv_template_action_link($context, '/account/orders'),
            'Your refund has been processed successfully. The funds may take a short time to appear based on your payment provider.'
        );

        return [
            'subject' => $subject,
            'html' => $layout['html'],
            'text' => $layout['text'],
        ];
    }
}

if (!function_exists('bv_template_refund_completed_seller')) {
    function bv_template_refund_completed_seller(array $context): array
    {
        $orderCode = bv_template_order_code($context);
        $amount = bv_template_money($context);
        $subject = 'Refund Processed – Order #' . $orderCode;

        $layout = bv_template_layout(
            'Refund Processed',
            [
                'Order' => '#' . $orderCode,
                'Refund Amount' => $amount,
                'Status' => 'Processed',
            ],
            'View Transaction',
            bv_template_action_link($context, '/seller/orders'),
            'The refund has been processed and recorded for this order.'
        );

        return [
            'subject' => $subject,
            'html' => $layout['html'],
            'text' => $layout['text'],
        ];
    }
}

if (!function_exists('bv_template_order_payment_received_buyer')) {
    function bv_template_order_payment_received_buyer(array $context): array
    {
        $orderCode = bv_template_order_code($context);
        $amount = bv_template_money($context);
        $subject = 'Payment Received – Order #' . $orderCode;

        $layout = bv_template_layout(
            'Payment Received',
            [
                'Order' => '#' . $orderCode,
                'Amount' => $amount,
                'Status' => 'Paid',
            ],
            'Track Your Order',
            bv_template_action_link($context, '/account/orders'),
            'Your payment was received successfully and your order is now being processed.'
        );

        return [
            'subject' => $subject,
            'html' => $layout['html'],
            'text' => $layout['text'],
        ];
    }
}

if (!function_exists('bv_notification_template_build')) {
    function bv_notification_template_build(string $eventKey, string $recipientType, array $context): array
    {
        $type = strtolower(trim($recipientType));

        if ($eventKey === 'refund.request.created') {
            if ($type === 'seller' || $type === 'merchant' || $type === 'vendor') {
                return bv_template_refund_request_seller($context);
            }
            return bv_template_refund_request_buyer($context);
        }

        if ($eventKey === 'refund.completed') {
            if ($type === 'seller' || $type === 'merchant' || $type === 'vendor') {
                return bv_template_refund_completed_seller($context);
            }
            return bv_template_refund_completed_buyer($context);
        }

        if ($eventKey === 'order.payment.received') {
            return bv_template_order_payment_received_buyer($context);
        }

        $orderCode = bv_template_order_code($context);
        $subject = 'Notification – Order #' . $orderCode;
        $layout = bv_template_layout(
            'Order Update',
            [
                'Order' => '#' . $orderCode,
                'Event' => $eventKey,
            ],
            'View Details',
            bv_template_action_link($context, '/account/orders'),
            'There is an update available for your order.'
        );

        return [
            'subject' => $subject,
            'html' => $layout['html'],
            'text' => $layout['text'],
        ];
    }
}
