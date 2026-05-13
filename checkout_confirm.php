<?php
require_once __DIR__ . '/includes/cart_lib.php';
require_once __DIR__ . '/includes/order_mailer.php';

$offerHelperFile = __DIR__ . '/includes/listing_offers.php';
if (is_file($offerHelperFile)) {
    require_once $offerHelperFile;
}

$offerNotificationsFile = __DIR__ . '/includes/offer_notifications.php';
if (is_file($offerNotificationsFile)) {
    require_once $offerNotificationsFile;
}

if (is_file(__DIR__ . '/includes/auction_engine.php')) {
    require_once __DIR__ . '/includes/auction_engine.php';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: checkout.php');
    exit;
}

$csrfToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
if (!btv_cart_verify_csrf($csrfToken)) {
    btv_cart_flash_set('error', 'Security token invalid. Please try again.');
    header('Location: checkout.php');
    exit;
}

function btv_checkout_confirm_offer_context_map()
{
    $map = $_SESSION['offer_cart_context'] ?? [];
    return is_array($map) ? $map : array();
}

function btv_checkout_confirm_offer_context_for_listing($listingId)
{
    $listingId = (int) $listingId;
    if ($listingId <= 0) {
        return null;
    }

    $map = btv_checkout_confirm_offer_context_map();
    if (!isset($map[$listingId]) || !is_array($map[$listingId])) {
        return null;
    }

    $ctx = $map[$listingId];
    $agreedPrice = isset($ctx['agreed_price']) && is_numeric($ctx['agreed_price'])
        ? round((float) $ctx['agreed_price'], 2)
        : 0.0;

    if ($agreedPrice <= 0) {
        return null;
    }

    return $ctx;
}

function btv_checkout_confirm_item_qty(array $item)
{
    $keys = array('qty', 'quantity', 'count');

    foreach ($keys as $key) {
        if (isset($item[$key]) && is_numeric($item[$key])) {
            $qty = (int) $item[$key];
            return $qty > 0 ? $qty : 1;
        }
    }

    return 1;
}

function btv_checkout_confirm_is_offer_item(array $item)
{
    if (btv_checkout_is_auction_item($item)) {
        return false;
    }

    $listingId = (int) ($item['listing_id'] ?? 0);
    return is_array(btv_checkout_confirm_offer_context_for_listing($listingId));
}

function btv_checkout_confirm_item_price(array $item)
{
    if (btv_checkout_is_auction_item($item)) {
        if (isset($item['winner_bid_amount']) && is_numeric($item['winner_bid_amount'])) {
            return round((float) $item['winner_bid_amount'], 2);
        }
    } else {
        $offerContext = btv_checkout_confirm_offer_context_for_listing((int) ($item['listing_id'] ?? 0));
        if (is_array($offerContext)) {
            $offerPrice = isset($offerContext['agreed_price']) && is_numeric($offerContext['agreed_price'])
                ? round((float) $offerContext['agreed_price'], 2)
                : 0.0;

            if ($offerPrice > 0) {
                return $offerPrice;
            }
        }
    }

    $raw = $item['price'] ?? $item['unit_price'] ?? 0;
    return is_numeric($raw) ? round((float) $raw, 2) : 0.0;
}

function btv_checkout_confirm_item_currency(array $item, $fallback = 'USD')
{
    if (!btv_checkout_is_auction_item($item)) {
        $offerContext = btv_checkout_confirm_offer_context_for_listing((int) ($item['listing_id'] ?? 0));
        if (is_array($offerContext)) {
            $currency = strtoupper(trim((string) ($offerContext['currency'] ?? '')));
            if ($currency !== '') {
                return $currency;
            }
        }
    }

    $currency = strtoupper(trim((string) ($item['currency'] ?? '')));
    return $currency !== '' ? $currency : $fallback;
}

function btv_checkout_confirm_enrich_items(array $items, $fallbackCurrency = 'USD')
{
    $result = array();

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $qty = btv_checkout_confirm_item_qty($item);
        if (btv_checkout_is_auction_item($item)) {
            $qty = 1;
        }

        $displayPrice = btv_checkout_confirm_item_price($item);
        $displayCurrency = btv_checkout_confirm_item_currency($item, $fallbackCurrency);
        $lineTotal = round($displayPrice * $qty, 2);
        $isOfferPriced = btv_checkout_confirm_is_offer_item($item);

        $item['__qty'] = $qty;
        $item['__display_price'] = $displayPrice;
        $item['__display_currency'] = $displayCurrency;
        $item['__line_total'] = $lineTotal;
        $item['__is_offer_priced'] = $isOfferPriced;
        $item['__offer_context'] = $isOfferPriced
            ? btv_checkout_confirm_offer_context_for_listing((int) ($item['listing_id'] ?? 0))
            : null;

        $result[] = $item;
    }

    return $result;
}

function btv_checkout_confirm_compute_totals(array $items, $fallbackCurrency = 'USD')
{
    $subtotal = 0.0;
    $itemsCount = 0;
    $currency = $fallbackCurrency;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $qty = (int) ($item['__qty'] ?? 1);
        if ($qty <= 0) {
            $qty = 1;
        }

        $lineTotal = isset($item['__line_total']) && is_numeric($item['__line_total'])
            ? (float) $item['__line_total']
            : 0.0;

        $subtotal += $lineTotal;
        $itemsCount += $qty;

        $itemCurrency = strtoupper(trim((string) ($item['__display_currency'] ?? '')));
        if ($itemCurrency !== '') {
            $currency = $itemCurrency;
        }
    }

    $subtotal = round($subtotal, 2);

    return array(
        'items_count' => $itemsCount,
        'subtotal'    => $subtotal,
        'discount'    => 0.0,
        'shipping'    => 0.0,
        'total'       => $subtotal,
        'currency'    => $currency !== '' ? $currency : 'USD',
    );
}

function btv_checkout_confirm_money($amount, $currency)
{
    if (function_exists('btv_cart_money')) {
        return btv_cart_money((float) $amount, $currency);
    }
    return strtoupper((string) $currency) . ' ' . number_format((float) $amount, 2);
}

$cartItems = btv_cart_items();
$rawTotals = btv_cart_totals();
$fallbackCurrency = isset($rawTotals['currency']) && $rawTotals['currency'] !== ''
    ? (string) $rawTotals['currency']
    : 'USD';

$cartItems = btv_checkout_confirm_enrich_items($cartItems, $fallbackCurrency);
$totals = btv_checkout_confirm_compute_totals($cartItems, $fallbackCurrency);

if (empty($cartItems)) {
    btv_cart_flash_set('error', 'Your cart is empty.');
    header('Location: cart.php');
    exit;
}

function btv_checkout_clean($key, $maxLen = 1000)
{
    $value = isset($_POST[$key]) && is_scalar($_POST[$key]) ? trim((string) $_POST[$key]) : '';
    if ($maxLen > 0 && strlen($value) > $maxLen) {
        $value = substr($value, 0, $maxLen);
    }
    return $value;
}

function btv_checkout_validate_email($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
}

function btv_checkout_build_payload()
{
    $payload = array(
        'buyer_name'     => btv_checkout_clean('buyer_name', 190),
        'buyer_email'    => btv_checkout_validate_email(btv_checkout_clean('buyer_email', 190)),
        'buyer_phone'    => btv_checkout_clean('buyer_phone', 80),
        'buyer_line_id'  => btv_checkout_clean('buyer_line_id', 120),
        'buyer_whatsapp' => btv_checkout_clean('buyer_whatsapp', 120),
        'country'        => btv_checkout_clean('country', 120),
        'ship_name'      => btv_checkout_clean('ship_name', 190),
        'ship_address'   => btv_checkout_clean('ship_address', 4000),
        'ship_phone'     => btv_checkout_clean('ship_phone', 80),
        'ship_email'     => btv_checkout_validate_email(btv_checkout_clean('ship_email', 190)),
        'trans_shipper'  => btv_checkout_clean('trans_shipper', 190),
        'note'           => btv_checkout_clean('note', 8000),
    );

    if ($payload['ship_email'] === '' && $payload['buyer_email'] !== '') {
        $payload['ship_email'] = $payload['buyer_email'];
    }

    return $payload;
}

function btv_checkout_validate_payload(array $payload)
{
    $errors = array();

    if ($payload['buyer_name'] === '') {
        $errors[] = 'Buyer full name is required.';
    }
    if ($payload['buyer_email'] === '') {
        $errors[] = 'A valid buyer email is required.';
    }
    if ($payload['country'] === '') {
        $errors[] = 'Country is required.';
    }
    if ($payload['ship_name'] === '') {
        $errors[] = 'Receiver name is required.';
    }
    if ($payload['ship_address'] === '') {
        $errors[] = 'Shipping address is required.';
    }
    if ($payload['ship_phone'] === '') {
        $errors[] = 'Receiver phone is required.';
    }

    return $errors;
}

function btv_checkout_order_code(PDO $pdo)
{
    $prefix = 'BTV-' . date('Ymd') . '-';
    for ($i = 0; $i < 10; $i++) {
        $candidate = $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $stmt = $pdo->prepare('SELECT id FROM orders WHERE order_code = :order_code LIMIT 1');
        $stmt->execute(array(':order_code' => $candidate));
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            return $candidate;
        }
    }
    return $prefix . strtoupper(uniqid());
}

function btv_checkout_session_token()
{
    if (!empty($_COOKIE[session_name()])) {
        return hash('sha256', (string) $_COOKIE[session_name()]);
    }
    return hash('sha256', session_id() . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function btv_checkout_user_id()
{
    if (function_exists('btv_cart_user_id')) {
        $uid = (int) btv_cart_user_id();
        if ($uid > 0) {
            return $uid;
        }
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        if (!empty($_SESSION['user']['id'])) {
            return (int) $_SESSION['user']['id'];
        }
        if (!empty($_SESSION['user']['user_id'])) {
            return (int) $_SESSION['user']['user_id'];
        }
    }

    if (!empty($_SESSION['user_id'])) {
        return (int) $_SESSION['user_id'];
    }

    return 0;
}

function btv_checkout_is_auction_item(array $item)
{
    $source = strtolower(trim((string) ($item['source'] ?? $item['order_source'] ?? '')));
    $saleFormat = strtolower(trim((string) ($item['sale_format'] ?? '')));

    if ($source === 'auction' || $saleFormat === 'auction') {
        return true;
    }

    if (!empty($item['winner_user_id']) || !empty($item['winner_bid_id']) || !empty($item['winner_payment_due_at'])) {
        return true;
    }

    return false;
}

function btv_checkout_get_table_columns(PDO $pdo, $table)
{
    static $cache = array();

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $cols = array();

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`', '', (string)$table) . "`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['Field'])) {
                $cols[(string)$row['Field']] = true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $cache[$table] = $cols;
    return $cols;
}

function btv_checkout_fetch_listing_row(PDO $pdo, $listingId)
{
    $stmt = $pdo->prepare('SELECT * FROM listings WHERE id = ? LIMIT 1');
    $stmt->execute(array((int)$listingId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function btv_checkout_revalidate_fixed_item(array $item)
{
    $listingId = isset($item['listing_id']) ? (int) $item['listing_id'] : 0;
    if ($listingId <= 0) {
        return 'Invalid listing found in cart.';
    }

    if (btv_checkout_confirm_is_offer_item($item)) {
        $offerContext = btv_checkout_confirm_offer_context_for_listing($listingId);
        if (!is_array($offerContext)) {
            return 'Offer checkout context is missing for listing #' . $listingId . '.';
        }

        $agreedPrice = isset($offerContext['agreed_price']) && is_numeric($offerContext['agreed_price'])
            ? (float) $offerContext['agreed_price']
            : 0.0;

        if ($agreedPrice <= 0) {
            return 'Offer agreed price is invalid for listing #' . $listingId . '.';
        }

        return null;
    }

    $live = btv_cart_fetch_listing($listingId);
    $error = btv_cart_validate_listing_for_cart($live ?: array());
    if ($error !== null) {
        return 'Checkout stopped because listing #' . $listingId . ' is no longer available: ' . $error;
    }

    return null;
}

function btv_checkout_revalidate_auction_item(PDO $pdo, array $item)
{
    $listingId = isset($item['listing_id']) ? (int)$item['listing_id'] : 0;
    if ($listingId <= 0) {
        return 'Invalid auction listing found in cart.';
    }

    $listing = btv_checkout_fetch_listing_row($pdo, $listingId);
    if (!$listing) {
        return 'Auction listing #' . $listingId . ' was not found.';
    }

    $currentUserId = btv_checkout_user_id();
    $winnerUserId = (int)($item['winner_user_id'] ?? 0);
    if ($winnerUserId <= 0 && !empty($listing['auction_winner_user_id'])) {
        $winnerUserId = (int)$listing['auction_winner_user_id'];
    }

    if ($currentUserId <= 0) {
        return 'Please sign in again before paying for this auction.';
    }

    if ($winnerUserId > 0 && $currentUserId !== $winnerUserId) {
        return 'Auction listing #' . $listingId . ' belongs to another winner account.';
    }

    $saleFormat = strtolower(trim((string)($listing['sale_format'] ?? '')));
    if ($saleFormat !== 'auction') {
        return 'Listing #' . $listingId . ' is no longer marked as auction.';
    }

    $auctionStatus = strtolower(trim((string)($listing['auction_status'] ?? '')));
    $saleStatus = strtolower(trim((string)($listing['sale_status'] ?? '')));

    if (!in_array($auctionStatus, array('awaiting_payment', 'paid'), true)) {
        return 'Auction listing #' . $listingId . ' is not waiting for payment anymore.';
    }

    if (!in_array($saleStatus, array('reserved', 'sold'), true)) {
        return 'Auction listing #' . $listingId . ' is not reserved for the winner.';
    }

    $dueAt = trim((string)($listing['winner_payment_due_at'] ?? ($item['winner_payment_due_at'] ?? '')));
    if ($dueAt !== '' && $dueAt !== '0000-00-00 00:00:00') {
        $dueTs = strtotime($dueAt);
        if ($dueTs !== false && time() > $dueTs && $auctionStatus !== 'paid') {
            return 'Auction payment window expired for listing #' . $listingId . '.';
        }
    }

    return null;
}

function btv_checkout_revalidate_items(PDO $pdo, array $items)
{
    foreach ($items as $item) {
        if (btv_checkout_is_auction_item($item)) {
            $error = btv_checkout_revalidate_auction_item($pdo, $item);
        } else {
            $error = btv_checkout_revalidate_fixed_item($item);
        }

        if ($error !== null) {
            return $error;
        }
    }

    return null;
}

function btv_checkout_find_existing_open_order(PDO $pdo, array $item)
{
    $listingId = (int)($item['listing_id'] ?? 0);
    $userId = btv_checkout_user_id();

    if ($listingId <= 0 || $userId <= 0) {
        return null;
    }

    $orderCols = btv_checkout_get_table_columns($pdo, 'orders');
    if (!$orderCols || !isset($orderCols['status']) || !isset($orderCols['user_id'])) {
        return null;
    }

    if (isset($orderCols['auction_listing_id'])) {
        $listingClause = '`auction_listing_id` = :listing_id';
    } elseif (isset($orderCols['listing_id'])) {
        $listingClause = '`listing_id` = :listing_id';
    } else {
        return null;
    }

    $sql = "
        SELECT *
        FROM orders
        WHERE `user_id` = :user_id
          AND {$listingClause}
          AND `status` IN ('pending_payment','pending','awaiting_payment','paid','processing','confirmed','completed')
        ORDER BY id DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':user_id' => $userId,
        ':listing_id' => $listingId,
    ));

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function btv_checkout_validate_duplicate_auction_orders(PDO $pdo, array $items)
{
    foreach ($items as $item) {
        if (!btv_checkout_is_auction_item($item)) {
            continue;
        }

        $existing = btv_checkout_find_existing_open_order($pdo, $item);
        if ($existing) {
            $status = trim((string)($existing['status'] ?? ''));
            $code = trim((string)($existing['order_code'] ?? ('#' . (int)$existing['id'])));
            return 'Auction order already exists for listing #' . (int)$item['listing_id'] . ' (' . $code . ', status: ' . ($status !== '' ? $status : 'unknown') . ').';
        }
    }

    return null;
}

function btv_checkout_validate_duplicate_offer_orders(PDO $pdo, array $items)
{
    foreach ($items as $item) {
        if (empty($item['__is_offer_priced'])) {
            continue;
        }

        $ctx = is_array($item['__offer_context'] ?? null) ? $item['__offer_context'] : [];
        $offerId = (int) ($ctx['offer_id'] ?? 0);

        if ($offerId <= 0) {
            continue;
        }

        $stmt = $pdo->prepare("
            SELECT id, order_code, status
            FROM orders
            WHERE order_source = 'offer'
              AND meta_json LIKE :offer_id
              AND status IN ('pending_payment','pending','awaiting_payment','paid','processing','confirmed','completed')
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->execute([
            ':offer_id' => '%"offer_id":' . $offerId . '%'
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $code = $row['order_code'] ?? ('#' . (int)$row['id']);
            $status = $row['status'] ?? 'unknown';
            return "Offer order already exists for offer #{$offerId} ({$code}, status: {$status})";
        }
    }

    return null;
}

function btv_checkout_build_order_meta(array $payload, array $items, array $totals)
{
    $auctionContext = array();
    $offerContext = array();
    $hasAuction = false;
    $hasOffer = false;

    foreach ($items as $item) {
        if (btv_checkout_is_auction_item($item)) {
            $hasAuction = true;
            $auctionContext[] = array(
                'listing_id' => (int)($item['listing_id'] ?? 0),
                'winner_user_id' => (int)($item['winner_user_id'] ?? 0),
                'winner_bid_id' => (int)($item['winner_bid_id'] ?? 0),
                'winner_bid_amount' => (string)($item['winner_bid_amount'] ?? $item['price'] ?? '0.00'),
                'winner_payment_due_at' => (string)($item['winner_payment_due_at'] ?? ''),
                'locked_price' => !empty($item['locked_price']) ? 1 : 0,
                'locked_qty' => !empty($item['locked_qty']) ? 1 : 0,
            );
            continue;
        }

        if (!empty($item['__is_offer_priced'])) {
            $hasOffer = true;
            $offerCtx = is_array($item['__offer_context'] ?? null) ? $item['__offer_context'] : array();
            $offerContext[] = array(
                'listing_id' => (int)($item['listing_id'] ?? 0),
                'offer_id' => (int)($offerCtx['offer_id'] ?? 0),
                'offer_token_id' => (int)($offerCtx['offer_token_id'] ?? 0),
                'buyer_user_id' => (int)($offerCtx['buyer_user_id'] ?? 0),
                'seller_user_id' => (int)($offerCtx['seller_user_id'] ?? 0),
                'agreed_price' => number_format((float)($item['__display_price'] ?? $item['price'] ?? 0), 2, '.', ''),
                'currency' => (string)($item['__display_currency'] ?? $item['currency'] ?? ''),
                'expires_at' => (string)($offerCtx['expires_at'] ?? ''),
            );
        }
    }

    $cartType = 'fixed';
    if ($hasAuction && $hasOffer) {
        $cartType = 'auction_offer_mixed';
    } elseif ($hasAuction) {
        $cartType = 'auction_mixed';
    } elseif ($hasOffer) {
        $cartType = 'offer_mixed';
    }

    return array(
        'source' => 'bettavaro_checkout_phase_c_mail',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'created_via' => 'checkout_confirm.php',
        'created_at' => date('Y-m-d H:i:s'),
        'cart_type' => $cartType,
        'auction_context' => $auctionContext,
        'offer_context' => $offerContext,
    );
}

function btv_checkout_insert_order_schema_aware(PDO $pdo, array $row)
{
    $cols = btv_checkout_get_table_columns($pdo, 'orders');
    if (!$cols) {
        throw new RuntimeException('Orders table columns could not be loaded.');
    }

    $insertCols = array();
    $placeholders = array();
    $params = array();

    foreach ($row as $col => $value) {
        if (isset($cols[$col])) {
            $insertCols[] = "`{$col}`";
            $placeholders[] = ':' . $col;
            $params[':' . $col] = $value;
        }
    }

    if (!$insertCols) {
        throw new RuntimeException('No compatible columns were found for orders insert.');
    }

    $sql = "INSERT INTO orders (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}
function btv_checkout_insert_order_item_schema_aware(PDO $pdo, array $row)
{
    $listingId = (int)($row['listing_id'] ?? 0);
    if ($listingId <= 0) {
        throw new RuntimeException('Invalid listing_id for seller assignment.');
    }
    $stmt = $pdo->prepare("SELECT seller_id FROM listings WHERE id = :listing_id LIMIT 1");
    $stmt->execute([':listing_id' => $listingId]);
    $listing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!isset($listing['seller_id']) || (int)$listing['seller_id'] <= 0) {
        throw new RuntimeException('Invalid seller_id for listing_id ' . $listingId . '.');
    }
    $sellerId = (int)$listing['seller_id'];

    $row['seller_id'] = $sellerId;
    if (array_key_exists('seller_user_id', $row)) {
        $row['seller_user_id'] = $sellerId;
    }
    error_log('[ORDER_ITEM_SELLER] listing_id=' . $listingId . ' seller_id=' . $sellerId);

    $cols = btv_checkout_get_table_columns($pdo, 'order_items');
    if (!$cols) {
        throw new RuntimeException('Order items table columns could not be loaded.');
    }

    $insertCols = array();
    $placeholders = array();
    $params = array();

    foreach ($row as $col => $value) {
        if (isset($cols[$col])) {
            $insertCols[] = "`{$col}`";
            $placeholders[] = ':' . $col;
            $params[':' . $col] = $value;
        }
    }

    if (!$insertCols) {
        throw new RuntimeException('No compatible columns were found for order_items insert.');
    }

    $sql = "INSERT INTO order_items (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

function btv_checkout_listing_seller_id(PDO $pdo, $listingId)
{
    static $cache = array();
    static $stmt = null;

    $listingId = (int)$listingId;
    if ($listingId <= 0) {
        return 0;
    }

    if (isset($cache[$listingId])) {
        return (int)$cache[$listingId];
    }

    if ($stmt === null) {
        $stmt = $pdo->prepare("SELECT seller_id FROM listings WHERE id = :listing_id LIMIT 1");
    }

    $stmt->execute(array(':listing_id' => $listingId));
    $sellerId = (int)$stmt->fetchColumn();
    if ($sellerId < 0) {
        $sellerId = 0;
    }

    $cache[$listingId] = $sellerId;
    return $sellerId;
}

function btv_checkout_build_order_row(PDO $pdo, array $payload, array $items, array $totals)
{
    $userId = btv_checkout_user_id();
    $sessionToken = btv_checkout_session_token();
    $orderCode = btv_checkout_order_code($pdo);
    $now = date('Y-m-d H:i:s');
    $meta = btv_checkout_build_order_meta($payload, $items, $totals);

    $hasAuction = false;
    $firstAuctionListingId = null;
    $firstAuctionSellerId = null;

    foreach ($items as $item) {
        if (btv_checkout_is_auction_item($item)) {
            $hasAuction = true;
            $firstAuctionListingId = (int)($item['listing_id'] ?? 0);
            $firstAuctionSellerId = (int)($item['seller_id'] ?? 0);
            break;
        }
    }

    $row = array(
        'user_id' => $userId > 0 ? $userId : null,
        'session_token' => $sessionToken,
        'order_code' => $orderCode,
        'status' => 'pending_payment',
        'currency' => strtoupper((string)$totals['currency']),
        'subtotal' => (float)$totals['subtotal'],
        'discount_amount' => 0.00,
        'shipping_amount' => 0.00,
        'total' => (float)$totals['total'],
        'buyer_name' => $payload['buyer_name'],
        'buyer_email' => $payload['buyer_email'],
        'buyer_phone' => $payload['buyer_phone'],
        'buyer_line_id' => $payload['buyer_line_id'],
        'buyer_whatsapp' => $payload['buyer_whatsapp'],
        'country' => $payload['country'],
        'ship_name' => $payload['ship_name'],
        'ship_address' => $payload['ship_address'],
        'ship_phone' => $payload['ship_phone'],
        'ship_email' => $payload['ship_email'],
        'trans_shipper' => $payload['trans_shipper'],
        'payment_method' => null,
        'payment_status' => 'pending_payment',
        'note' => $payload['note'],
        'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => $now,
        'updated_at' => $now,
    );

    // ── Offer source detection ───────────────────────────────────────────────
    // Offer checkout is marked at order level so paid handler/finalizers can
    // close the accepted offer after payment. Auction remains higher priority.
    $hasOffer = false;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $isOfferItem = (
            !empty($item['__is_offer_priced'])
            || (isset($item['source'])       && (string) $item['source']       === 'offer')
            || (isset($item['order_source']) && (string) $item['order_source'] === 'offer')
            || (
                is_array($item['__offer_context'] ?? null)
                && (int) ($item['__offer_context']['offer_id'] ?? 0) > 0
            )
        );
        if ($isOfferItem) {
            $hasOffer = true;
            break;
        }
    }

    if ($hasOffer && !$hasAuction) {
        $row['order_source'] = 'offer';

        // Load the orders column list (statically cached — no extra DB round-trip).
        $ordersCols = btv_checkout_get_table_columns($pdo, 'orders');

        // Set `source` only when that column actually exists in the orders table.
        if (isset($ordersCols['source'])) {
            $row['source'] = 'offer';
        }

        // Collect all unique offer_ids and offer_token_ids from the enriched items.
        $seenOfferIds = array();
        $seenTokenIds = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $isOfferItem = (
                !empty($item['__is_offer_priced'])
                || (isset($item['source'])       && (string) $item['source']       === 'offer')
                || (isset($item['order_source']) && (string) $item['order_source'] === 'offer')
                || (
                    is_array($item['__offer_context'] ?? null)
                    && (int) ($item['__offer_context']['offer_id'] ?? 0) > 0
                )
            );
            if (!$isOfferItem) {
                continue;
            }
            // Item-level: __offer_context first, then offer_context as fallback.
            $ctx = null;
            if (is_array($item['__offer_context'] ?? null)) {
                $ctx = $item['__offer_context'];
            } elseif (is_array($item['offer_context'] ?? null)) {
                $ctx = $item['offer_context'];
            }
            if (is_array($ctx)) {
                $oid = isset($ctx['offer_id'])       ? (int) $ctx['offer_id']       : 0;
                $tid = isset($ctx['offer_token_id']) ? (int) $ctx['offer_token_id'] : 0;
                if ($oid > 0) { $seenOfferIds[$oid] = true; }
                if ($tid > 0) { $seenTokenIds[$tid] = true; }
            }
        }

        // Session-level fallback: used when item-level context is missing or
        // does not carry valid IDs. Supports both a flat context array and the
        // standard listing-keyed map stored by the offer cart flow.
        if ((empty($seenOfferIds) || empty($seenTokenIds))
            && isset($_SESSION['offer_cart_context'])
            && is_array($_SESSION['offer_cart_context'])
        ) {
            $sessMap = $_SESSION['offer_cart_context'];
            // Flat context: ['offer_id' => N, 'offer_token_id' => M, ...]
            if (isset($sessMap['offer_id'])) {
                $sessEntries = array($sessMap);
            } else {
                // Map context keyed by listing_id
                $sessEntries = array();
                foreach ($sessMap as $v) {
                    if (is_array($v)) { $sessEntries[] = $v; }
                }
            }
            foreach ($sessEntries as $sessEntry) {
                $sOid = isset($sessEntry['offer_id'])       ? (int) $sessEntry['offer_id']       : 0;
                $sTid = isset($sessEntry['offer_token_id']) ? (int) $sessEntry['offer_token_id'] : 0;
                // Only fill gaps — do not override IDs already found at item level.
                if ($sOid > 0 && empty($seenOfferIds)) { $seenOfferIds[$sOid] = true; }
                if ($sTid > 0 && empty($seenTokenIds)) { $seenTokenIds[$sTid] = true; }
            }
        }

        // Save offer_id only if the column exists AND exactly one offer is present.
        // Multiple offer IDs in one order are not auto-guessed — skip silently.
        if (isset($ordersCols['offer_id'])) {
            $offerIdKeys = array_keys($seenOfferIds);
            if (count($offerIdKeys) === 1) {
                $row['offer_id'] = (int) $offerIdKeys[0];
            }
        }

        // Save offer_token_id only if the column exists AND exactly one token.
        if (isset($ordersCols['offer_token_id'])) {
            $tokenIdKeys = array_keys($seenTokenIds);
            if (count($tokenIdKeys) === 1) {
                $row['offer_token_id'] = (int) $tokenIdKeys[0];
            }
        }
    }

    if ($hasAuction) {
        $row['order_source'] = 'auction';
        $row['source'] = 'auction';
        $row['listing_id'] = $firstAuctionListingId;
        $row['auction_listing_id'] = $firstAuctionListingId;
        $row['seller_user_id'] = $firstAuctionSellerId > 0 ? $firstAuctionSellerId : null;
    }

    return array(
        'row' => $row,
        'order_code' => $orderCode,
        'created_at' => $now,
    );
}
function btv_checkout_build_order_item_row(PDO $pdo, array $item, array $totals, $orderId, $now)
{
    $isAuction = btv_checkout_is_auction_item($item);
    $isOfferPriced = !empty($item['__is_offer_priced']);
    $listingId = (int)($item['listing_id'] ?? 0);
    $sellerId = btv_checkout_listing_seller_id($pdo, $listingId);
    $price = isset($item['__display_price']) && is_numeric($item['__display_price'])
        ? (float)$item['__display_price']
        : (float)($item['price'] ?? 0);

    $qty = $isAuction ? 1 : (int)($item['__qty'] ?? $item['qty'] ?? $item['quantity'] ?? 1);
    if ($qty <= 0) {
        $qty = 1;
    }

    if ($isAuction && isset($item['winner_bid_amount']) && is_numeric($item['winner_bid_amount'])) {
        $price = (float)$item['winner_bid_amount'];
    }

    $snapshot = array(
        'listing_id' => $listingId,
        'seller_id' => $sellerId,
        'title' => (string) ($item['title'] ?? ''),
        'slug' => (string) ($item['slug'] ?? ''),
        'price' => (float) $price,
        'regular_price' => isset($item['price']) && is_numeric($item['price']) ? (float) $item['price'] : null,
        'currency' => (string) ($item['__display_currency'] ?? $item['currency'] ?? ''),
        'cover_path' => (string) ($item['cover_path'] ?? $item['cover_image'] ?? ''),
        'cover_image' => (string) ($item['cover_image'] ?? $item['cover_path'] ?? ''),
        'strain' => (string) ($item['strain'] ?? ''),
        'species' => (string) ($item['species'] ?? ''),
        'seller_name' => (string) ($item['seller_name'] ?? ''),
        'added_at' => (string) ($item['added_at'] ?? ''),
        'source' => $isAuction ? 'auction' : ($isOfferPriced ? 'offer' : 'listing'),
        'sale_format' => (string)($item['sale_format'] ?? ($isAuction ? 'auction' : 'fixed')),
        'winner_user_id' => (int)($item['winner_user_id'] ?? 0),
        'winner_bid_id' => (int)($item['winner_bid_id'] ?? 0),
        'winner_bid_amount' => $isAuction ? number_format($price, 2, '.', '') : null,
        'winner_payment_due_at' => (string)($item['winner_payment_due_at'] ?? ''),
        'locked_price' => !empty($item['locked_price']) ? 1 : 0,
        'locked_qty' => !empty($item['locked_qty']) ? 1 : 0,
        'offer_context' => $isOfferPriced && is_array($item['__offer_context'] ?? null) ? $item['__offer_context'] : null,
    );

    return array(
        'order_id' => (int)$orderId,
        'listing_id' => $listingId,
        'seller_id' => $sellerId,
        'seller_user_id' => $sellerId,
        'item_type' => 'listing',
        'item_title' => (string) ($item['title'] ?? ''),
        'item_name' => (string) ($item['title'] ?? ''),
        'item_slug' => (string) ($item['slug'] ?? ''),
        'item_ref' => 'LISTING-' . $listingId, 
        'title_snapshot' => (string) ($item['title'] ?? ''),
        'listing_title' => (string) ($item['title'] ?? ''),
        'strain_snapshot' => (string) ($item['strain'] ?? ''),
        'species_snapshot' => (string) ($item['species'] ?? ''),
        'cover_image_snapshot' => (string) ($item['cover_path'] ?? $item['cover_image'] ?? ''),
        'currency' => strtoupper((string) ($item['__display_currency'] ?? $item['currency'] ?? $totals['currency'])),
        'qty' => $qty,
        'quantity' => $qty,
        'unit_price' => $price,
        'line_total' => $price * $qty,
        'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'source' => $isAuction ? 'auction' : ($isOfferPriced ? 'offer' : 'listing'),
        'order_source' => $isAuction ? 'auction' : ($isOfferPriced ? 'offer' : 'listing'),
        'sale_format' => $isAuction ? 'auction' : (string)($item['sale_format'] ?? 'fixed'),
        'winner_user_id' => $isAuction ? (int)($item['winner_user_id'] ?? 0) : null,
        'winner_bid_id' => $isAuction ? (int)($item['winner_bid_id'] ?? 0) : null,
        'winner_bid_amount' => $isAuction ? number_format($price, 2, '.', '') : null,
        'locked_price' => !empty($item['locked_price']) ? 1 : 0,
        'locked_qty' => !empty($item['locked_qty']) ? 1 : 0,
        'created_at' => $now,
    );
}

function btv_checkout_clear_cart_after_create()
{
    if (defined('BETTAVARO_CART_SESSION_KEY')) {
        $_SESSION[BETTAVARO_CART_SESSION_KEY] = array();
    } else {
        $_SESSION['bettavaro_cart'] = array();
        $_SESSION['cart'] = array();
    }

    unset($_SESSION['auction_checkout_context']);
    unset($_SESSION['offer_cart_context']);
    unset($_SESSION['offer_checkout']);
}


function btv_checkout_finalize_offer_items_after_create($orderId, array $items)
{
    $orderId = (int) $orderId;
    if ($orderId <= 0 || empty($items)) {
        return;
    }

    if (!function_exists('bv_offer_mark_checkout_token_used')) {
        return;
    }

    $actorUserId = function_exists('btv_checkout_user_id') ? (int) btv_checkout_user_id() : 0;
    $actorRole = 'buyer';

    if (function_exists('bv_offer_current_user_role')) {
        $role = strtolower(trim((string) bv_offer_current_user_role()));
        if ($role !== '') {
            $actorRole = $role;
        }
    }

    $doneOfferIds = array();
    $doneTokenIds = array();

    foreach ($items as $item) {
        if (!is_array($item) || empty($item['__is_offer_priced'])) {
            continue;
        }

        $offerContext = isset($item['__offer_context']) && is_array($item['__offer_context'])
            ? $item['__offer_context']
            : array();

        $offerId = isset($offerContext['offer_id']) ? (int) $offerContext['offer_id'] : 0;
        $tokenId = isset($offerContext['offer_token_id']) ? (int) $offerContext['offer_token_id'] : 0;

        if ($tokenId > 0 && empty($doneTokenIds[$tokenId])) {
            // กันใช้ซ้ำแบบ best effort
            if (function_exists('bv_offer_get_checkout_token_by_id')) {
                $tokenRow = bv_offer_get_checkout_token_by_id($tokenId);
                if (is_array($tokenRow)) {
                    $status = strtolower(trim((string) ($tokenRow['status'] ?? '')));
                    if ($status !== 'active') {
                        $doneTokenIds[$tokenId] = true;
                    } else {
                        try {
                            bv_offer_mark_checkout_token_used(
                                $tokenId,
                                $orderId,
                                $actorUserId > 0 ? $actorUserId : null,
                                $actorRole
                            );
                        } catch (Throwable $e) {
                            // best effort only
                        }
                        $doneTokenIds[$tokenId] = true;
                    }
                } else {
                    try {
                        bv_offer_mark_checkout_token_used(
                            $tokenId,
                            $orderId,
                            $actorUserId > 0 ? $actorUserId : null,
                            $actorRole
                        );
                    } catch (Throwable $e) {
                        // best effort only
                    }
                    $doneTokenIds[$tokenId] = true;
                }
            } else {
                try {
                    bv_offer_mark_checkout_token_used(
                        $tokenId,
                        $orderId,
                        $actorUserId > 0 ? $actorUserId : null,
                        $actorRole
                    );
                } catch (Throwable $e) {
                    // best effort only
                }
                $doneTokenIds[$tokenId] = true;
            }
        }

        if ($offerId > 0 && empty($doneOfferIds[$offerId]) && function_exists('bv_offer_mark_completed')) {
            try {
                bv_offer_mark_completed(
                    $offerId,
                    $orderId,
                    $actorUserId > 0 ? $actorUserId : null,
                    $actorRole
                );
            } catch (Throwable $e) {
                // best effort only
            }
            $doneOfferIds[$offerId] = true;
        }
    }
}

function btv_checkout_create_order(PDO $pdo, array $payload, array $items, array $totals)
{
    $orderBuild = btv_checkout_build_order_row($pdo, $payload, $items, $totals);
    $row = $orderBuild['row'];
    $orderCode = $orderBuild['order_code'];
    $now = $orderBuild['created_at'];

    $orderId = btv_checkout_insert_order_schema_aware($pdo, $row);

  foreach ($items as $item) {
        $itemRow = btv_checkout_build_order_item_row($pdo, $item, $totals, $orderId, $now);
        error_log(
            '[checkout_confirm] order_item seller ownership: listing_id='
            . (int)($itemRow['listing_id'] ?? 0)
            . ' seller_id=' . (int)($itemRow['seller_id'] ?? 0)
            . ' order_id=' . (int)$orderId
        );
        btv_checkout_insert_order_item_schema_aware($pdo, $itemRow);
    }

    btv_checkout_clear_cart_after_create();

    return array(
        'order_id' => $orderId,
        'order_code' => $orderCode,
        'created_at' => $now,
    );
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : 'review';
$payload = btv_checkout_build_payload();
$errors = btv_checkout_validate_payload($payload);

try {
    $pdoValidate = btv_cart_get_pdo();
    $revalidateError = btv_checkout_revalidate_items($pdoValidate, $cartItems);
    if ($revalidateError !== null) {
        $errors[] = $revalidateError;
    }

    $duplicateAuctionError = btv_checkout_validate_duplicate_auction_orders($pdoValidate, $cartItems);
    if ($duplicateAuctionError !== null) {
        $errors[] = $duplicateAuctionError;
    }
	$duplicateOfferError = btv_checkout_validate_duplicate_offer_orders($pdoValidate, $cartItems);
	if ($duplicateOfferError !== null) {
    $errors[] = $duplicateOfferError;
	}
	
} catch (Throwable $e) {
    $errors[] = 'Checkout validation failed: ' . $e->getMessage();
}

$pageTitle = 'Confirm Order';

if ($action === 'place_order' && empty($errors)) {
    try {
        $pdo = btv_cart_get_pdo();
        $pdo->beginTransaction();
        $result = btv_checkout_create_order($pdo, $payload, $cartItems, $totals);
        $pdo->commit();

        btv_checkout_finalize_offer_items_after_create((int) $result['order_id'], $cartItems);

        if (function_exists('bv_offer_notify_seller_offer_completed')) {
            try {
                $doneOfferNotify = array();

                foreach ($cartItems as $item) {
                    if (!is_array($item) || empty($item['__is_offer_priced'])) {
                        continue;
                    }

                    $offerContext = isset($item['__offer_context']) && is_array($item['__offer_context'])
                        ? $item['__offer_context']
                        : array();

                    $offerId = isset($offerContext['offer_id']) ? (int) $offerContext['offer_id'] : 0;
                    if ($offerId <= 0 || isset($doneOfferNotify[$offerId])) {
                        continue;
                    }

                    bv_offer_notify_seller_offer_completed($offerId, (int) $result['order_id']);
                    $doneOfferNotify[$offerId] = true;
                }
            } catch (Throwable $offerNotifyError) {
            }
        }

        try {
            btv_order_mail_send_new_order($pdo, (int)$result['order_id']);
        } catch (Throwable $mailError) {
            if (function_exists('mail_engine_log')) {
                mail_engine_log('order_mail_send_after_create_failed', [
                    'order_id' => (int)$result['order_id'],
                    'order_code' => (string)$result['order_code'],
                    'error' => $mailError->getMessage(),
                ]);
            }
        }

        header('Location: /checkout_create_session.php?order_id=' . (int)$result['order_id']);
        exit;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Order could not be created: ' . $e->getMessage();
        $action = 'review';
    }
}

@include_once __DIR__ . '/includes/head.php';
@include_once __DIR__ . '/includes/menu.php';
?>
<style>
.btv-confirm-shell{max-width:1180px;margin:30px auto;padding:0 16px;color:#e5e7eb}
.btv-confirm-top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.btv-confirm-top h1{margin:0 0 6px;font-size:34px;line-height:1.1;color:#fff}
.btv-confirm-sub{color:#cbd5e1;font-size:15px}
.btv-confirm-link{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 16px;border:1px solid rgba(255,255,255,.28);border-radius:12px;text-decoration:none;background:rgba(255,255,255,.03);color:#fff;font-weight:700}
.btv-confirm-link:hover{background:rgba(255,255,255,.08)}
.btv-confirm-error{margin-bottom:16px;padding:14px 16px;border-radius:14px;border:1px solid #fda4af;background:#fff1f2;color:#be123c}
.btv-confirm-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:24px;align-items:start}
.btv-confirm-blocks{display:grid;gap:18px}
.btv-confirm-panel,.btv-confirm-summary{background:linear-gradient(180deg,#ffffff,#f8fafc);color:#111827;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 14px 36px rgba(0,0,0,.14)}
.btv-confirm-panel{padding:20px}
.btv-confirm-panel h2,.btv-confirm-summary h2{margin:0 0 14px;color:#111827}
.btv-confirm-details{display:grid;grid-template-columns:220px 1fr;gap:10px 16px;color:#111827}
.btv-confirm-label{color:#64748b}
.btv-confirm-details > div:not(.btv-confirm-label){color:#111827}
.btv-confirm-details strong{color:#111827 !important}
.btv-confirm-summary{padding:20px;position:sticky;top:18px}
.btv-confirm-summary-list{display:grid;gap:14px;color:#334155}
.btv-confirm-summary-item{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.btv-confirm-summary-item strong{color:#111827 !important}
.btv-confirm-summary-meta{color:#64748b;font-size:13px}
.btv-confirm-summary-sub{margin-top:4px;font-size:12px;color:#166534;font-weight:800}
.btv-confirm-summary-regular{margin-top:4px;font-size:12px;color:#64748b;text-decoration:line-through;font-weight:700}
.btv-confirm-summary hr{border:none;border-top:1px solid #e5e7eb;margin:2px 0}
.btv-confirm-row{display:flex;justify-content:space-between;gap:12px}
.btv-confirm-row span{color:#334155}
.btv-confirm-row strong{color:#111827 !important}
.btv-confirm-total{font-size:20px;font-weight:900}
.btv-confirm-total strong:first-child{color:#111827 !important}
.btv-confirm-total strong:last-child{color:#065f46 !important}
.btv-confirm-btn{width:100%;padding:14px 16px;border:0;border-radius:12px;background:#111827;color:#fff;text-decoration:none;font-weight:800;cursor:pointer}
.btv-confirm-btn[disabled]{background:#9ca3af;cursor:not-allowed}
.btv-confirm-btn-secondary{display:block;text-align:center;padding:12px 14px;border-radius:10px;border:1px solid #d1d5db;text-decoration:none;color:#111827;background:#fff}
.btv-confirm-note{margin-top:12px;font-size:13px;color:#64748b;line-height:1.6}
.btv-auction-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800;background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe;margin-top:6px}
.btv-offer-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800;background:#ecfdf3;color:#166534;border:1px solid #86efac;margin-top:6px}
@media (max-width: 900px){.btv-confirm-grid{grid-template-columns:1fr}.btv-confirm-summary{position:relative;top:auto}}
@media (max-width: 680px){.btv-confirm-shell{padding:0 12px}.btv-confirm-details{grid-template-columns:1fr}}
</style>
<div class="btv-confirm-shell">
    <div class="btv-confirm-top">
        <div>
            <h1>Confirm Order</h1>
            <div class="btv-confirm-sub">One last look before the fish leaves the dock.</div>
        </div>
        <a href="checkout.php" class="btv-confirm-link">Back to Checkout</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="btv-confirm-error">
            <strong style="display:block;margin-bottom:8px;color:#be123c;">Please fix these before placing the order:</strong>
            <ul style="margin:0;padding-left:18px;line-height:1.7;color:#be123c;">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="btv-confirm-grid">
        <div class="btv-confirm-blocks">
            <section class="btv-confirm-panel">
                <h2>Buyer Contact</h2>
                <div class="btv-confirm-details">
                    <div class="btv-confirm-label">Full Name</div><div><strong><?= htmlspecialchars($payload['buyer_name']) ?></strong></div>
                    <div class="btv-confirm-label">Email</div><div><?= htmlspecialchars($payload['buyer_email']) ?></div>
                    <div class="btv-confirm-label">Phone</div><div><?= htmlspecialchars($payload['buyer_phone'] !== '' ? $payload['buyer_phone'] : '-') ?></div>
                    <div class="btv-confirm-label">LINE ID</div><div><?= htmlspecialchars($payload['buyer_line_id'] !== '' ? $payload['buyer_line_id'] : '-') ?></div>
                    <div class="btv-confirm-label">WhatsApp</div><div><?= htmlspecialchars($payload['buyer_whatsapp'] !== '' ? $payload['buyer_whatsapp'] : '-') ?></div>
                    <div class="btv-confirm-label">Country</div><div><?= htmlspecialchars($payload['country']) ?></div>
                </div>
            </section>

            <section class="btv-confirm-panel">
                <h2>Shipping / Receiving Details</h2>
                <div class="btv-confirm-details">
                    <div class="btv-confirm-label">Receiver Name</div><div><strong><?= htmlspecialchars($payload['ship_name']) ?></strong></div>
                    <div class="btv-confirm-label">Receiver Email</div><div><?= htmlspecialchars($payload['ship_email'] !== '' ? $payload['ship_email'] : '-') ?></div>
                    <div class="btv-confirm-label">Receiver Phone</div><div><?= htmlspecialchars($payload['ship_phone']) ?></div>
                    <div class="btv-confirm-label">Trans-shipper</div><div><?= htmlspecialchars($payload['trans_shipper'] !== '' ? $payload['trans_shipper'] : '-') ?></div>
                    <div class="btv-confirm-label">Shipping Address</div><div style="white-space:pre-line;"><?= htmlspecialchars($payload['ship_address']) ?></div>
                    <div class="btv-confirm-label">Order Note</div><div style="white-space:pre-line;"><?= htmlspecialchars($payload['note'] !== '' ? $payload['note'] : '-') ?></div>
                </div>
            </section>
        </div>

        <aside class="btv-confirm-summary">
            <h2>Order Summary</h2>
            <div class="btv-confirm-summary-list">
                <?php foreach ($cartItems as $item): ?>
                    <?php
                    $isAuctionItem = btv_checkout_is_auction_item($item);
                    $isOfferItem = !empty($item['__is_offer_priced']);
                    $displayPrice = isset($item['__display_price']) && is_numeric($item['__display_price'])
                        ? (float) $item['__display_price']
                        : (float) ($item['price'] ?? 0);
                    $displayCurrency = (string) ($item['__display_currency'] ?? ($item['currency'] ?? 'USD'));
                    $regularPrice = isset($item['price']) && is_numeric($item['price']) ? (float) $item['price'] : 0.0;
                    ?>
                    <div class="btv-confirm-summary-item">
                        <div style="min-width:0;">
                            <div style="font-weight:700;word-break:break-word;"><?= htmlspecialchars($item['title']) ?></div>
                            <div class="btv-confirm-summary-meta">Listing #<?= (int) $item['listing_id'] ?></div>
                            <?php if ($isAuctionItem): ?>
                                <div class="btv-auction-badge">Auction Winner Price Locked</div>
                            <?php elseif ($isOfferItem): ?>
                                <div class="btv-offer-badge">Accepted Offer Price Applied</div>
                            <?php endif; ?>
                            <?php if ($isOfferItem && $regularPrice > 0 && abs($regularPrice - $displayPrice) > 0.00001): ?>
                                <div class="btv-confirm-summary-regular"><?= htmlspecialchars(btv_checkout_confirm_money($regularPrice, $displayCurrency)) ?></div>
                            <?php endif; ?>
                        </div>
                        <strong><?= htmlspecialchars(btv_checkout_confirm_money($displayPrice, $displayCurrency)) ?></strong>
                    </div>
                <?php endforeach; ?>
                <hr>
                <div class="btv-confirm-row">
                    <span>Items</span>
                    <strong><?= (int) $totals['items_count'] ?></strong>
                </div>
                <div class="btv-confirm-row">
                    <span>Subtotal</span>
                    <strong><?= htmlspecialchars(btv_checkout_confirm_money($totals['subtotal'], $totals['currency'])) ?></strong>
                </div>
                <div class="btv-confirm-row">
                    <span>Shipping</span>
                    <span>Pending</span>
                </div>
                <div class="btv-confirm-row btv-confirm-total">
                    <strong>Total</strong>
                    <strong><?= htmlspecialchars(btv_checkout_confirm_money($totals['total'], $totals['currency'])) ?></strong>
                </div>
            </div>

            <form method="post" action="checkout_confirm.php" style="margin-top:18px;display:grid;gap:10px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="place_order">
                <?php foreach ($payload as $key => $value): ?>
                    <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>">
                <?php endforeach; ?>
                <button type="submit" <?= !empty($errors) ? 'disabled' : '' ?> class="btv-confirm-btn">Place Order</button>
                <a href="checkout.php" class="btv-confirm-btn-secondary">Edit Details</a>
            </form>
            <div class="btv-confirm-note">
                When you click <strong>Place Order</strong>, the order will be created first, then buyer/admin/seller notification emails will be sent after commit.
            </div>
        </aside>
    </div>
</div>
<?php @include_once __DIR__ . '/includes/footer.php'; ?>