<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$root = __DIR__;

require_once $root . '/includes/listing_offers.php';

$cartLibFile = $root . '/includes/cart_lib.php';
if (is_file($cartLibFile)) {
    require_once $cartLibFile;
}

if (!function_exists('bv_offer_accept_checkout_h')) {
    function bv_offer_accept_checkout_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bv_offer_accept_checkout_redirect')) {
    function bv_offer_accept_checkout_redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('bv_offer_accept_checkout_url')) {
    function bv_offer_accept_checkout_url(string $path, array $params = []): string
    {
        $path = trim($path);
        if ($path === '') {
            $path = '/';
        }

        $query = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $query[$key] = $value;
        }

        if (!$query) {
            return $path;
        }

        return $path . (strpos($path, '?') === false ? '?' : '&') . http_build_query($query);
    }
}

if (!function_exists('bv_offer_accept_checkout_flash_set')) {
    function bv_offer_accept_checkout_flash_set(string $status, string $message, array $extra = []): void
    {
        $_SESSION['offer_accept_checkout_flash'] = array_merge([
            'status' => $status,
            'message' => $message,
        ], $extra);
    }
}

if (!function_exists('bv_offer_accept_checkout_login_url')) {
    function bv_offer_accept_checkout_login_url(string $returnUrl): string
    {
        $candidates = [
            '/login.php',
            '/member/login.php',
            'login.php',
            'member/login.php',
        ];

        foreach ($candidates as $candidate) {
            $fsPath = $candidate[0] === '/' ? ($GLOBALS['root'] ?? __DIR__) . $candidate : ($GLOBALS['root'] ?? __DIR__) . '/' . $candidate;
            if (is_file($fsPath)) {
                return $candidate . '?redirect=' . rawurlencode($returnUrl);
            }
        }

        return '/login.php?redirect=' . rawurlencode($returnUrl);
    }
}

if (!function_exists('bv_offer_accept_checkout_cart_csrf_token')) {
    function bv_offer_accept_checkout_cart_csrf_token(): string
    {
        if (function_exists('btv_cart_csrf_token')) {
            return (string) btv_cart_csrf_token();
        }

        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('bv_offer_accept_checkout_listing_url')) {
    function bv_offer_accept_checkout_listing_url(array $listing): string
    {
        $slug = trim((string) ($listing['slug'] ?? ''));
        $id   = (int) ($listing['id'] ?? 0);

        if ($slug !== '') {
            return '/listing.php?slug=' . rawurlencode($slug);
        }

        return '/listing.php?id=' . $id;
    }
}

if (!function_exists('bv_offer_accept_checkout_offer_url')) {
    function bv_offer_accept_checkout_offer_url(int $offerId, array $extra = []): string
    {
        return bv_offer_accept_checkout_url('/offer.php', array_merge(['id' => $offerId > 0 ? $offerId : null], $extra));
    }
}

if (!function_exists('bv_offer_accept_checkout_money')) {
    function bv_offer_accept_checkout_money($amount, string $currency = 'USD'): string
    {
        if (!is_numeric($amount)) {
            return '';
        }

        if (function_exists('bv_offer_format_money')) {
            try {
                return (string) bv_offer_format_money($amount, $currency);
            } catch (Throwable $e) {
            }
        }

        return strtoupper(trim($currency) !== '' ? trim($currency) : 'USD') . ' ' . number_format((float) $amount, 2);
    }
}

if (!function_exists('bv_offer_accept_checkout_find_active_token_for_offer')) {
    function bv_offer_accept_checkout_find_active_token_for_offer(array $offer, int $currentUserId): ?array
    {
        $offerId = (int) ($offer['id'] ?? 0);
        if ($offerId <= 0) {
            return null;
        }

        $token = bv_offer_get_active_checkout_token($offerId);
        if (!$token) {
            return null;
        }

        $tokenValue = trim((string) ($token['token'] ?? ''));
        if ($tokenValue === '') {
            return null;
        }

        return bv_offer_validate_checkout_token($tokenValue, $currentUserId);
    }
}

if (!function_exists('bv_offer_accept_checkout_build_payload')) {
    function bv_offer_accept_checkout_build_payload(array $offer, array $token, ?array $listing, int $currentUserId): array
    {
        $listingId   = (int) ($token['listing_id'] ?? $offer['listing_id'] ?? 0);
        $offerId     = (int) ($offer['id'] ?? 0);
        $tokenId     = (int) ($token['id'] ?? 0);
        $buyerUserId = (int) ($offer['buyer_user_id'] ?? $token['buyer_user_id'] ?? 0);
        $sellerUserId = (int) ($offer['seller_user_id'] ?? $token['seller_user_id'] ?? 0);

        $currency = strtoupper(trim((string) ($token['currency'] ?? $offer['currency'] ?? 'USD')));
        if ($currency === '') {
            $currency = 'USD';
        }

        $agreedPrice = round((float) ($token['agreed_price'] ?? $offer['agreed_price'] ?? 0), 2);
        $listingTitle = '';
        if (is_array($listing)) {
            $listingTitle = trim((string) (($listing['title'] ?? '') ?: ($listing['name'] ?? '')));
        }
        if ($listingTitle === '') {
            $listingTitle = 'Listing #' . $listingId;
        }

        return [
            'offer_id' => $offerId,
            'offer_token_id' => $tokenId,
            'offer_token' => (string) ($token['token'] ?? ''),
            'listing_id' => $listingId,
            'buyer_user_id' => $buyerUserId,
            'seller_user_id' => $sellerUserId,
            'agreed_price' => $agreedPrice,
            'currency' => $currency,
            'listing_title' => $listingTitle,
            'expires_at' => (string) ($token['expires_at'] ?? ''),
            'source' => 'offer',
            'accepted_by_buyer_user_id' => $currentUserId,
            'accepted_at' => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('bv_offer_accept_checkout_store_context')) {
    function bv_offer_accept_checkout_store_context(array $context): void
    {
        $_SESSION['offer_cart_context'] = $context;

        $listingId = (int) ($context['listing_id'] ?? 0);
        if ($listingId > 0) {
            $_SESSION['offer_cart_context_by_listing'][$listingId] = $context;
        }

        $offerId = (int) ($context['offer_id'] ?? 0);
        if ($offerId > 0) {
            $_SESSION['offer_cart_context_by_offer'][$offerId] = $context;
        }
    }
}

if (!function_exists('bv_offer_accept_checkout_render_error_page')) {
    function bv_offer_accept_checkout_render_error_page(string $title, string $message, string $backUrl, string $backLabel = 'Back to Offer'): void
    {
        http_response_code(400);
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= bv_offer_accept_checkout_h($title); ?></title>
    <style>
        body{
            margin:0;
            min-height:100vh;
            font-family:Inter,Arial,sans-serif;
            background:linear-gradient(180deg,#06110c 0%, #040b08 100%);
            color:#0f172a;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:24px;
        }
        .card{
            width:min(100%, 720px);
            background:#fff;
            border-radius:24px;
            padding:28px;
            box-shadow:0 24px 70px rgba(0,0,0,.22);
        }
        .eyebrow{
            display:inline-flex;
            align-items:center;
            padding:8px 12px;
            border-radius:999px;
            background:#fee2e2;
            color:#991b1b;
            font-size:12px;
            font-weight:900;
            letter-spacing:.04em;
            text-transform:uppercase;
        }
        h1{
            margin:16px 0 10px;
            font-size:34px;
            line-height:1.05;
        }
        p{
            margin:0 0 18px;
            color:#475569;
            line-height:1.8;
            font-size:15px;
        }
        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:48px;
            padding:0 18px;
            border-radius:14px;
            background:#253726;
            color:#fff;
            text-decoration:none;
            font-weight:900;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="eyebrow">Offer Checkout</div>
        <h1><?= bv_offer_accept_checkout_h($title); ?></h1>
        <p><?= bv_offer_accept_checkout_h($message); ?></p>
        <a class="btn" href="<?= bv_offer_accept_checkout_h($backUrl); ?>"><?= bv_offer_accept_checkout_h($backLabel); ?></a>
    </div>
</body>
</html>
<?php
        exit;
    }
}

if (!bv_listing_offers_require_tables()) {
    bv_offer_accept_checkout_render_error_page(
        'Offer tables are not ready',
        'The offer checkout tables are not ready yet. Please try again after the offer system migration is completed.',
        '/member/index.php',
        'Go to Dashboard'
    );
}

$currentUserId   = bv_offer_current_user_id();
$currentUserRole = bv_offer_current_user_role();

$tokenValue = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$offerId    = isset($_GET['offer_id']) && is_numeric($_GET['offer_id']) ? (int) $_GET['offer_id'] : 0;
if ($offerId <= 0) {
    $offerId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
}
if ($offerId <= 0) {
    $offerId = isset($_POST['offer_id']) && is_numeric($_POST['offer_id']) ? (int) $_POST['offer_id'] : 0;
}

$selfReturnUrl = bv_offer_accept_checkout_url('/offer_accept_checkout.php', [
    'token' => $tokenValue !== '' ? $tokenValue : null,
    'offer_id' => $offerId > 0 ? $offerId : null,
]);

if ($currentUserId <= 0) {
    bv_offer_accept_checkout_flash_set('error', 'Please log in before continuing to checkout.');
    bv_offer_accept_checkout_redirect(bv_offer_accept_checkout_login_url($selfReturnUrl));
}

$offer = null;
$token = null;

if ($tokenValue !== '') {
    $token = bv_offer_validate_checkout_token($tokenValue, $currentUserId);
    if (!$token) {
        bv_offer_accept_checkout_render_error_page(
            'Checkout link is invalid',
            'This offer checkout link is invalid, expired, already used, or belongs to another buyer.',
            '/member/offers.php',
            'Back to My Offers'
        );
    }

    $offer = bv_offer_get_by_id((int) ($token['offer_id'] ?? 0));
    if (!$offer) {
        bv_offer_accept_checkout_render_error_page(
            'Offer not found',
            'The offer record behind this checkout link no longer exists.',
            '/member/offers.php',
            'Back to My Offers'
        );
    }
} else {
    if ($offerId <= 0) {
        bv_offer_accept_checkout_render_error_page(
            'Missing offer',
            'No offer token or offer ID was provided.',
            '/member/offers.php',
            'Back to My Offers'
        );
    }

    $offer = bv_offer_get_by_id($offerId);
    if (!$offer) {
        bv_offer_accept_checkout_render_error_page(
            'Offer not found',
            'We could not find the requested offer.',
            '/member/offers.php',
            'Back to My Offers'
        );
    }

    if (!bv_offer_current_user_can_view($offer, $currentUserId, $currentUserRole)) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }

    if (!bv_offer_can_checkout($offer, $currentUserId)) {
        bv_offer_accept_checkout_render_error_page(
            'Offer is not ready for checkout',
            'This offer is not currently ready for checkout. It may still be open, expired, cancelled, or already completed.',
            bv_offer_accept_checkout_offer_url((int) ($offer['id'] ?? 0)),
            'Back to Offer'
        );
    }

    $token = bv_offer_accept_checkout_find_active_token_for_offer($offer, $currentUserId);
    if (!$token) {
        bv_offer_accept_checkout_render_error_page(
            'Checkout token is missing',
            'This offer has no active checkout token right now. Please ask the seller to accept the offer again if needed.',
            bv_offer_accept_checkout_offer_url((int) ($offer['id'] ?? 0)),
            'Back to Offer'
        );
    }
}

if (!bv_offer_current_user_can_view($offer, $currentUserId, $currentUserRole)) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if (!bv_offer_can_checkout($offer, $currentUserId)) {
    bv_offer_accept_checkout_render_error_page(
        'Offer is not ready for checkout',
        'This offer cannot be converted to checkout at the moment.',
        bv_offer_accept_checkout_offer_url((int) ($offer['id'] ?? 0)),
        'Back to Offer'
    );
}

$listingId = (int) ($token['listing_id'] ?? $offer['listing_id'] ?? 0);
$listing   = $listingId > 0 ? bv_offer_get_listing_by_id($listingId) : null;

if (!$listing) {
    bv_offer_accept_checkout_render_error_page(
        'Listing not found',
        'The listing attached to this offer could not be found.',
        bv_offer_accept_checkout_offer_url((int) ($offer['id'] ?? 0)),
        'Back to Offer'
    );
}

$payload = bv_offer_accept_checkout_build_payload($offer, $token, $listing, $currentUserId);
bv_offer_accept_checkout_store_context($payload);

$cartAddPath = '/cart_add.php';
$checkoutPath = '/checkout.php';
$listingPath = bv_offer_accept_checkout_listing_url($listing);

$cartCsrfToken = bv_offer_accept_checkout_cart_csrf_token();
$agreedPriceDisplay = bv_offer_accept_checkout_money(
    (float) ($payload['agreed_price'] ?? 0),
    (string) ($payload['currency'] ?? 'USD')
);

$offerBackUrl = bv_offer_accept_checkout_offer_url((int) ($offer['id'] ?? 0));
$title = trim((string) ($payload['listing_title'] ?? 'Listing #' . $listingId));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="refresh" content="8;url=<?= bv_offer_accept_checkout_h($offerBackUrl); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preparing Offer Checkout | Bettavaro</title>
    <style>
        :root{
            --bg:#06110c;
            --card:#ffffff;
            --line:#dbe2ea;
            --ink:#0f172a;
            --muted:#64748b;
            --green:#166534;
            --green-soft:#ecfdf3;
            --gold:#d7bc6b;
            --shadow:0 24px 80px rgba(0,0,0,.22);
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            min-height:100vh;
            font-family:Inter,Arial,sans-serif;
            background:
                radial-gradient(circle at top, rgba(20,55,37,.30), transparent 32%),
                linear-gradient(180deg,#06110c 0%, #040a07 100%);
            color:var(--ink);
            display:flex;
            align-items:center;
            justify-content:center;
            padding:24px;
        }
        .card{
            width:min(100%, 760px);
            background:rgba(255,255,255,.98);
            border-radius:26px;
            padding:30px;
            box-shadow:var(--shadow);
            border:1px solid rgba(255,255,255,.4);
        }
        .pill{
            display:inline-flex;
            align-items:center;
            padding:8px 12px;
            border-radius:999px;
            background:var(--green-soft);
            color:var(--green);
            font-size:12px;
            font-weight:900;
            text-transform:uppercase;
            letter-spacing:.05em;
        }
        h1{
            margin:16px 0 10px;
            font-size:38px;
            line-height:1.03;
            letter-spacing:-.03em;
        }
        p{
            margin:0 0 14px;
            font-size:15px;
            line-height:1.8;
            color:var(--muted);
        }
        .box{
            margin-top:18px;
            border:1px solid var(--line);
            border-radius:18px;
            padding:18px;
            background:linear-gradient(180deg,#fff,#f8fafc);
        }
        .row{
            display:grid;
            grid-template-columns:180px 1fr;
            gap:12px;
            padding:8px 0;
            border-bottom:1px dashed #e2e8f0;
        }
        .row:last-child{border-bottom:none}
        .label{
            font-size:12px;
            font-weight:900;
            color:#64748b;
            letter-spacing:.06em;
            text-transform:uppercase;
        }
        .value{
            font-size:15px;
            font-weight:700;
            color:#111827;
        }
        .actions{
            margin-top:20px;
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }
        .btn, .btn-soft{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:48px;
            padding:0 18px;
            border-radius:14px;
            text-decoration:none;
            font-weight:900;
        }
        .btn{
            background:#253726;
            color:#fff;
        }
        .btn-soft{
            background:#fff;
            color:#253726;
            border:1px solid #253726;
        }
        .tiny{
            margin-top:12px;
            font-size:13px;
            color:#64748b;
        }
        .spinner{
            width:22px;
            height:22px;
            border-radius:999px;
            border:3px solid rgba(37,55,38,.16);
            border-top-color:#253726;
            animation:spin .9s linear infinite;
            display:inline-block;
            vertical-align:middle;
            margin-right:10px;
        }
        @keyframes spin{
            to{transform:rotate(360deg)}
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="pill">Offer Checkout</div>
        <h1><span class="spinner"></span>Preparing your accepted offer</h1>
        <p>
            We are moving this accepted offer into the standard checkout flow now.
            No funny business, no price drift, no wandering fish. 🙂
        </p>

        <div class="box">
            <div class="row">
                <div class="label">Listing</div>
                <div class="value"><?= bv_offer_accept_checkout_h($title); ?></div>
            </div>
            <div class="row">
                <div class="label">Offer ID</div>
                <div class="value">#<?= (int) ($payload['offer_id'] ?? 0); ?></div>
            </div>
            <div class="row">
                <div class="label">Accepted Price</div>
                <div class="value"><?= bv_offer_accept_checkout_h($agreedPriceDisplay); ?></div>
            </div>
            <div class="row">
                <div class="label">Buyer</div>
                <div class="value">User #<?= (int) ($payload['buyer_user_id'] ?? 0); ?></div>
            </div>
            <div class="row">
                <div class="label">Token</div>
                <div class="value">Active and ready</div>
            </div>
        </div>

        <form id="offerCheckoutBridgeForm" method="post" action="<?= bv_offer_accept_checkout_h($cartAddPath); ?>" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?= bv_offer_accept_checkout_h($cartCsrfToken); ?>">
            <input type="hidden" name="listing_id" value="<?= (int) $listingId; ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="redirect" value="<?= bv_offer_accept_checkout_h($checkoutPath); ?>">
            <input type="hidden" name="checkout_source" value="offer">
            <input type="hidden" name="offer_id" value="<?= (int) ($payload['offer_id'] ?? 0); ?>">
            <input type="hidden" name="offer_token" value="<?= bv_offer_accept_checkout_h((string) ($payload['offer_token'] ?? '')); ?>">
            <input type="hidden" name="offer_token_id" value="<?= (int) ($payload['offer_token_id'] ?? 0); ?>">
        </form>

        <div class="actions">
            <button class="btn" type="submit" form="offerCheckoutBridgeForm">Continue to Checkout</button>
            <a class="btn-soft" href="<?= bv_offer_accept_checkout_h($offerBackUrl); ?>">Back to Offer</a>
            <a class="btn-soft" href="<?= bv_offer_accept_checkout_h($listingPath); ?>">View Listing</a>
        </div>

        <div class="tiny">
            Auto-submit will run in a moment. If it does not, use the button above.
            Token consumption should still happen after order creation succeeds in <code>checkout_confirm.php</code>, not here. :contentReference[oaicite:3]{index=3} :contentReference[oaicite:4]{index=4}
        </div>
    </div>

    <script>
        (function () {
            var form = document.getElementById('offerCheckoutBridgeForm');
            if (!form) return;
            setTimeout(function () {
                form.submit();
            }, 250);
        })();
    </script>
</body>
</html>