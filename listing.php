<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/seller_discount.php';

$listingReviewsFile = __DIR__ . '/includes/listing_reviews.php';
if (is_file($listingReviewsFile)) {
    require_once $listingReviewsFile;
}

// ── Ranking helper (optional – page works fine if absent) ────────────────────
$rankingHelper = __DIR__ . '/includes/listing_ranking.php';
if (is_file($rankingHelper)) {
    require_once $rankingHelper;
}
unset($rankingHelper);

$cartLibFile = __DIR__ . '/includes/cart_lib.php';
if (is_file($cartLibFile)) {
    require_once $cartLibFile;
}

if (!function_exists('bv_e')) {
    function bv_e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bv_has_table')) {
    function bv_has_table(PDO $pdo, string $tableName): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$tableName]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('bv_csrf_token')) {
    function bv_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }
}

if (!function_exists('bv_consume_flash')) {
    function bv_consume_flash(string $key): string
    {
        $value = $_SESSION[$key] ?? '';
        unset($_SESSION[$key]);
        return (string) $value;
    }
}

if (!function_exists('bv_old_input')) {
    function bv_old_input(string $key): string
    {
        return (string) ($_SESSION['reserve_old'][$key] ?? '');
    }
}

if (!function_exists('bv_cart_form_token')) {
    function bv_cart_form_token(): string
    {
        if (function_exists('btv_cart_csrf_token')) {
            return (string) btv_cart_csrf_token();
        }
        return bv_csrf_token();
    }
}

if (!function_exists('bv_cart_flash_group')) {
    function bv_cart_flash_group(): array
    {
        if (function_exists('btv_cart_flash_get')) {
            $flash = btv_cart_flash_get();
            if (is_array($flash) && !empty($flash['message'])) {
                return [
                    $flash['type'] === 'success' ? (string) $flash['message'] : '',
                    $flash['type'] === 'error' ? (string) $flash['message'] : '',
                ];
            }
        }
        return ['', ''];
    }
}

if (!function_exists('bv_listing_url_from_row')) {
    function bv_listing_url_from_row(array $listing): string
    {
        $slug = trim((string) ($listing['slug'] ?? ''));
        $id = (int) ($listing['id'] ?? 0);
        return $slug !== '' ? '/listing.php?slug=' . rawurlencode($slug) : '/listing.php?id=' . $id;
    }
}

if (!function_exists('bv_public_detail_statuses')) {
    function bv_public_detail_statuses(): array
    {
        return ['active', 'available', 'published', 'reserved', 'sold'];
    }
}

if (!function_exists('bv_can_reserve_statuses')) {
    function bv_can_reserve_statuses(): array
    {
        return ['available'];
    }
}

if (!function_exists('bv_status_badge')) {
    function bv_status_badge(string $status): array
    {
        $status = strtolower(trim($status));
        switch ($status) {
            case 'published':
            case 'available':
            case 'active':
                return ['Available', '#166534', '#dcfce7'];
            case 'reserved':
                return ['Reserved', '#92400e', '#fef3c7'];
            case 'sold':
                return ['Sold', '#991b1b', '#fee2e2'];
            default:
                return [ucfirst($status !== '' ? $status : 'Unknown'), '#374151', '#e5e7eb'];
        }
    }
}

if (!function_exists('bv_format_price')) {
    function bv_format_price($price, ?string $currency = null): string
    {
        if ($price === null || $price === '') {
            return 'Contact for price';
        }
        if (function_exists('money') && is_numeric($price)) {
            return money((float) $price, (string) ($currency ?: 'USD'));
        }
        if (is_numeric($price)) {
            return strtoupper((string) ($currency ?: 'USD')) . ' ' . number_format((float) $price, 2);
        }
        return (string) $price;
    }
}

if (!function_exists('bv_effective_sale_status')) {
    function bv_effective_sale_status(?string $saleStatus, ?string $status): string
    {
        $saleStatus = strtolower(trim((string) $saleStatus));
        $status = strtolower(trim((string) $status));

        if (in_array($saleStatus, ['available', 'reserved', 'sold'], true)) {
            return $saleStatus;
        }
        if (in_array($status, ['reserved', 'sold'], true)) {
            return $status;
        }
        if (in_array($status, ['active', 'available', 'published'], true)) {
            return 'available';
        }
        return $status !== '' ? $status : 'unknown';
    }
}

if (!function_exists('bv_derive_stock')) {
    function bv_derive_stock(array $row): array
    {
        $total = 1;
        foreach (['stock_total', 'stock', 'quantity', 'qty'] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                $total = max(0, (int) $row[$k]);
                break;
            }
        }

        $sold = 0;
        foreach (['stock_sold', 'sold_qty', 'qty_sold'] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                $sold = max(0, (int) $row[$k]);
                break;
            }
        }

        $available = null;
        foreach (['stock_available', 'available_qty', 'qty_available'] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                $available = max(0, (int) $row[$k]);
                break;
            }
        }

        $saleStatus = strtolower(trim((string) ($row['sale_status'] ?? '')));
        $status = strtolower(trim((string) ($row['status'] ?? '')));

        if ($available === null) {
            if (in_array($saleStatus, ['sold', 'completed', 'shipped'], true) || in_array($status, ['sold'], true)) {
                $available = 0;
                if ($sold === 0 && $total > 0) {
                    $sold = $total;
                }
            } else {
                $available = max(0, $total - $sold);
            }
        }

        if ($sold > $total && $total > 0) {
            $sold = $total;
        }
        if (($sold + $available) > $total && $total >= 0) {
            $available = max(0, $total - $sold);
        }

        return [
            'total' => $total,
            'sold' => $sold,
            'available' => $available,
        ];
    }
}

if (!function_exists('bv_stock_sellable_now')) {
    function bv_stock_sellable_now(array $stock): bool
    {
        return ((int) ($stock['available'] ?? 0)) > 0;
    }
}

if (!function_exists('bv_public_sale_state')) {
    function bv_public_sale_state(string $displayStatus): array
    {
        $displayStatus = strtolower(trim($displayStatus));

        if (in_array($displayStatus, ['sold', 'completed'], true)) {
            return [
                'text' => 'This fish has already been sold. Contact us and we will help source a similar fish.',
                'class' => 'status-sold',
                'show_checkout' => false,
                'show_similar' => true,
                'show_inquiry' => true,
                'cta_label' => 'Contact Us for Similar Fish',
                'secondary_label' => 'Browse More Listings',
            ];
        }

        if (in_array($displayStatus, ['reserved', 'awaiting_payment', 'paid'], true)) {
            return [
                'text' => 'This fish is currently on hold pending payment. You can still contact us as a backup buyer or ask for similar options.',
                'class' => 'status-hold',
                'show_checkout' => false,
                'show_similar' => false,
                'show_inquiry' => true,
                'cta_label' => 'Contact Us',
                'secondary_label' => 'Browse Similar Fish',
            ];
        }

        return [
            'text' => 'Available now. Instant checkout is open for this listing.',
            'class' => 'status-available',
            'show_checkout' => true,
            'show_similar' => false,
            'show_inquiry' => true,
            'cta_label' => 'Check Out This Fish',
            'secondary_label' => 'Add to Cart',
        ];
    }
}

if (!function_exists('bv_consume_flash_group')) {
    function bv_consume_flash_group(): array
    {
        $success = bv_consume_flash('flash_success');
        $error = bv_consume_flash('flash_error');

        if ($success === '') {
            $success = bv_consume_flash('reservation_flash_success');
        }
        if ($error === '') {
            $error = bv_consume_flash('reservation_flash_error');
        }

        $legacyType = bv_consume_flash('reservation_flash_type');
        $legacyMessage = bv_consume_flash('reservation_flash_message');
        if ($success === '' && $error === '' && $legacyMessage !== '') {
            if ($legacyType === 'success') {
                $success = $legacyMessage;
            } else {
                $error = $legacyMessage;
            }
        }

        return [$success, $error];
    }
}

if (!function_exists('bv_resolve_asset_url')) {
    function bv_resolve_asset_url(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $path)) {
            return $path;
        }
        if (strpos($path, '//') === 0) {
            return 'https:' . $path;
        }

        $candidates = [];
        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        $candidates[] = '/' . $normalized;
        $candidates[] = '/uploads/' . $normalized;
        $candidates[] = '/storage/' . $normalized;
        $candidates[] = '/public/' . $normalized;

        if (strpos($normalized, 'uploads/') === 0 || strpos($normalized, 'storage/') === 0 || strpos($normalized, 'public/') === 0) {
            array_unshift($candidates, '/' . $normalized);
        }

        $seen = [];
        foreach ($candidates as $candidate) {
            $candidate = preg_replace('~/+~', '/', $candidate);
            if (!isset($seen[$candidate])) {
                $seen[$candidate] = true;
                return $candidate;
            }
        }

        return '/' . $normalized;
    }
}

if (!function_exists('bv_detect_media_type')) {
    function bv_detect_media_type(array $row): string
    {
        $type = strtolower(trim((string) ($row['media_type'] ?? $row['type'] ?? '')));
        if (in_array($type, ['image', 'video'], true)) {
            return $type;
        }

        $mime = strtolower(trim((string) ($row['mime_type'] ?? '')));
        if (strpos($mime, 'video/') === 0) {
            return 'video';
        }
        if (strpos($mime, 'image/') === 0) {
            return 'image';
        }

        $probe = strtolower(trim((string) ($row['storage_path'] ?? $row['image_path'] ?? $row['image_url'] ?? $row['video'] ?? $row['video_path'] ?? $row['video_url'] ?? $row['cover_image'] ?? '')));
        $ext = pathinfo(parse_url($probe, PHP_URL_PATH) ?: $probe, PATHINFO_EXTENSION);
        if (in_array($ext, ['mp4', 'webm', 'mov', 'm4v', 'ogg'], true)) {
            return 'video';
        }
        return 'image';
    }
}

if (!function_exists('bv_media_poster_from_path')) {
    function bv_media_poster_from_path(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }
        $path = preg_replace('~\.(mp4|webm|mov|m4v|ogg)$~i', '.jpg', $path);
        return bv_resolve_asset_url($path);
    }
}

if (!function_exists('bv_add_media_item')) {
    function bv_add_media_item(array &$items, array &$seen, string $type, string $src, string $thumb, bool $isCover, string $label = '', string $poster = ''): void
    {
        $src = trim($src);
        if ($src === '') {
            return;
        }
        $key = strtolower($type . '|' . $src);
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $items[] = [
            'type' => $type,
            'src' => $src,
            'thumb' => $thumb !== '' ? $thumb : $src,
            'poster' => $poster,
            'is_cover' => $isCover,
            'label' => $label !== '' ? $label : ($type === 'video' ? 'Video' : 'Photo'),
        ];
    }
}

if (!function_exists('bv_offer_form_h')) {
    function bv_offer_form_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bv_offer_form_csrf_token')) {
    function bv_offer_form_csrf_token(string $scope = 'offer_create_form'): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (empty($_SESSION['_csrf_offer_create'][$scope]) || !is_string($_SESSION['_csrf_offer_create'][$scope])) {
            $_SESSION['_csrf_offer_create'][$scope] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_offer_create'][$scope];
    }
}

if (!function_exists('bv_offer_form_started_at')) {
    function bv_offer_form_started_at(string $scope = 'offer_create_form'): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $value = time();
        $_SESSION['_offer_create_started_at'][$scope] = $value;
        return $value;
    }
}

$slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($slug === '' && $id <= 0) {
    http_response_code(404);
    exit('Listing not found.');
}

$publicStatuses = bv_public_detail_statuses();
$placeholders = implode(',', array_fill(0, count($publicStatuses), '?'));

try {
    if ($slug !== '') {
        $sql = "SELECT * FROM listings WHERE slug = ? AND status IN ($placeholders) LIMIT 1";
        $params = array_merge([$slug], $publicStatuses);
    } else {
        $sql = "SELECT * FROM listings WHERE id = ? AND status IN ($placeholders) LIMIT 1";
        $params = array_merge([$id], $publicStatuses);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $listing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$listing) {
        http_response_code(404);
        exit('Listing not found or not public.');
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('Error loading listing.');
}

$listing = seller_discount_apply_to_listing($listing, $pdo);

$listingId = (int) ($listing['id'] ?? 0);

// ── Ranking cache lookup ─────────────────────────────────────────────────────
// $bvRankingCacheOk  – true when the cache table exists and PDO is available
// $bvRankingRow      – associative array from listing_ranking_cache, or []
$bvRankingCacheOk = false;
$bvRankingRow     = [];

if (defined('BV_LISTING_RANKING_LOADED') && $listingId > 0) {
    try {
        $_rankPdo = bv_listing_ranking_db();
        $bvRankingCacheOk = bv_listing_ranking_table_exists($_rankPdo, 'listing_ranking_cache');
        if ($bvRankingCacheOk) {
            $stmtRank = $_rankPdo->prepare(
                'SELECT avg_rating, review_count, verified_review_count,
                        low_rating_count, five_star_count, ranking_score
                 FROM listing_ranking_cache
                 WHERE listing_id = ? LIMIT 1'
            );
            $stmtRank->execute([$listingId]);
            $bvRankingRow = $stmtRank->fetch(PDO::FETCH_ASSOC) ?: [];

            // Auto-rebuild if this listing has no cache row yet
            if (empty($bvRankingRow)) {
                try {
                    $rebuildResult = bv_listing_ranking_rebuild_cache($listingId);
                    if (!empty($rebuildResult['ok'])) {
                        $stmtRank->execute([$listingId]);
                        $bvRankingRow = $stmtRank->fetch(PDO::FETCH_ASSOC) ?: [];
                    }
                } catch (Throwable) {
                    // Never break the detail page on rebuild failure
                }
            }
        }
        unset($stmtRank, $_rankPdo);
    } catch (Throwable) {
        $bvRankingCacheOk = false;
        $bvRankingRow     = [];
    }
}

// Convenience scalars (safe defaults when cache is absent)
$bvRankAvg    = isset($bvRankingRow['avg_rating'])   ? (float)$bvRankingRow['avg_rating']   : 0.0;
$bvRankCount  = isset($bvRankingRow['review_count'])  ? (int)$bvRankingRow['review_count']  : 0;
$bvRankScore  = isset($bvRankingRow['ranking_score']) ? (float)$bvRankingRow['ranking_score'] : 0.0;
$bvIsTopRated = $bvRankCount >= 3 && $bvRankAvg >= 4.8;
$bvCreatedAt  = (string)($listing['created_at'] ?? '');
$bvIsRising   = $bvRankCount >= 1
    && $bvCreatedAt !== ''
    && (time() - strtotime($bvCreatedAt)) < 30 * 86400;
$title = (string) ($listing['title'] ?? ($listing['name'] ?? 'Untitled Fish'));
$slugValue = trim((string) ($listing['slug'] ?? ''));
$status = strtolower(trim((string) ($listing['status'] ?? 'published')));
$saleStatus = strtolower(trim((string) ($listing['sale_status'] ?? '')));
$price = $listing['price'] ?? null;
$currency = (string) ($listing['currency'] ?? 'USD');
$tier = trim((string) ($listing['tier'] ?? $listing['grade'] ?? $listing['product_item'] ?? ''));
$sex = trim((string) ($listing['sex'] ?? ''));
$age = trim((string) ($listing['age'] ?? $listing['age_months'] ?? ''));
$color = trim((string) ($listing['color'] ?? ''));
$breed = trim((string) ($listing['breed'] ?? $listing['variety'] ?? $listing['strain'] ?? ''));
$size = trim((string) ($listing['size'] ?? ''));
$origin = trim((string) ($listing['origin'] ?? $listing['breeder'] ?? ''));
$country = trim((string) ($listing['country'] ?? ''));
$city = trim((string) ($listing['city'] ?? ''));
$description = trim((string) ($listing['description'] ?? $listing['details'] ?? ''));
$shortDescription = trim((string) ($listing['short_description'] ?? ''));
$coverImage = bv_resolve_asset_url($listing['cover_image'] ?? '');
$createdAt = (string) ($listing['created_at'] ?? '');
$sku = trim((string) ($listing['sku'] ?? ''));
$species = trim((string) ($listing['species'] ?? ''));
$strain = trim((string) ($listing['strain'] ?? $breed ?? ''));
$grade = trim((string) ($listing['grade'] ?? $tier ?? ''));
$stock = bv_derive_stock($listing);
$stockTotal = (int) ($stock['total'] ?? 0);
$stockSold = (int) ($stock['sold'] ?? 0);
$stockAvailable = (int) ($stock['available'] ?? 0);

$basePrice = is_numeric($price) ? round((float)$price, 2) : null;
$sellerDiscountPercent = isset($listing['seller_discount_percent']) ? (float)$listing['seller_discount_percent'] : 0.0;
$sellerDiscountAmount = isset($listing['seller_discount_amount']) ? (float)$listing['seller_discount_amount'] : 0.0;
$finalPrice = isset($listing['final_price']) && is_numeric($listing['final_price'])
    ? round((float)$listing['final_price'], 2)
    : (is_numeric($price) ? round((float)$price, 2) : null);
$hasSellerDiscount = !empty($listing['has_seller_discount']) && $sellerDiscountAmount > 0;

$displayStatus = bv_effective_sale_status($saleStatus, $status);
if ($stockAvailable <= 0 && $stockTotal > 0) {
    $displayStatus = 'sold';
}
list($statusLabel, $statusColor, $statusBg) = bv_status_badge($displayStatus);
$publicState = bv_public_sale_state($displayStatus);
if ($stockTotal > 0) {
    if ($stockAvailable <= 0) {
        $publicState['text'] = 'Sold out for now. This listing has no remaining stock at the moment.';
        $publicState['class'] = 'status-sold';
        $publicState['show_reserve'] = false;
        $publicState['show_similar'] = true;
        $publicState['show_inquiry'] = true;
        $publicState['cta_label'] = 'Contact Us for Similar Fish';
        $publicState['secondary_label'] = 'Browse More Listings';
    } elseif ($stockAvailable < $stockTotal) {
        $publicState['text'] = 'Available now. Limited remaining stock: ' . $stockAvailable . ' of ' . $stockTotal . ' left.';
    }
}
$canCheckout = !empty($publicState['show_checkout']) && in_array($displayStatus, bv_can_reserve_statuses(), true) && bv_stock_sellable_now($stock);

$offerCurrentUserId = 0;
if (!empty($_SESSION['user']['id']) && is_numeric($_SESSION['user']['id'])) {
    $offerCurrentUserId = (int) $_SESSION['user']['id'];
} elseif (!empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $offerCurrentUserId = (int) $_SESSION['user_id'];
}

$listingSellerUserId = (int) ($listing['seller_id'] ?? 0);
$isOwnListing = $offerCurrentUserId > 0 && $listingSellerUserId > 0 && $offerCurrentUserId === $listingSellerUserId;

$canOffer = (
    $stockAvailable > 0
    && $displayStatus === 'available'
    && !$isOwnListing
    && is_file(__DIR__ . '/offer_create.php')
);

$offerSuggestedPrice = '';
if (is_numeric($finalPrice) && (float) $finalPrice > 0) {
    $offerSuggestedPrice = number_format(round(((float) $finalPrice) * 0.90, 2), 2, '.', '');
} elseif (is_numeric($basePrice) && (float) $basePrice > 0) {
    $offerSuggestedPrice = number_format(round(((float) $basePrice) * 0.90, 2), 2, '.', '');
}

$offerReturnUrl = (string) ($_SERVER['REQUEST_URI'] ?? bv_listing_url_from_row($listing));

$offerFlash = $_SESSION['offer_create_flash'] ?? [];
unset($_SESSION['offer_create_flash']);

$offerFlashStatus = is_array($offerFlash) ? (string) ($offerFlash['status'] ?? '') : '';
$offerFlashMessage = is_array($offerFlash) ? (string) ($offerFlash['message'] ?? '') : '';
$offerFlashErrors = is_array($offerFlash) && isset($offerFlash['errors']) && is_array($offerFlash['errors']) ? $offerFlash['errors'] : [];
$offerFlashOld = is_array($offerFlash) && isset($offerFlash['old']) && is_array($offerFlash['old']) ? $offerFlash['old'] : [];

$offerOldPrice = (string) ($offerFlashOld['offer_price'] ?? '');
$offerOldMessage = (string) ($offerFlashOld['message_text'] ?? '');
$offerPriceValue = $offerOldPrice !== '' ? $offerOldPrice : $offerSuggestedPrice;
$offerMessageValue = $offerOldMessage;
$offerCsrfToken = bv_offer_form_csrf_token('offer_create_form');
$offerStartedAt = bv_offer_form_started_at('offer_create_form');

$mediaItems = [];
$seenMedia = [];

if (bv_has_table($pdo, 'listing_media')) {
    try {
        $mediaStmt = $pdo->prepare("SELECT * FROM listing_media WHERE listing_id = ? AND status = 'active' ORDER BY is_cover DESC, sort_order ASC, id ASC");
        $mediaStmt->execute([$listingId]);
        foreach (($mediaStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $type = bv_detect_media_type($row);
            $rawSrc = (string) ($row['storage_path'] ?? $row['file_name'] ?? '');
            $src = bv_resolve_asset_url($rawSrc);
            $thumb = $type === 'video'
                ? bv_media_poster_from_path($rawSrc)
                : bv_resolve_asset_url($rawSrc);
            $poster = $type === 'video' ? $thumb : '';
            $isCover = (int) ($row['is_cover'] ?? 0) === 1;
            bv_add_media_item($mediaItems, $seenMedia, $type, $src, $thumb, $isCover, $type === 'video' ? 'Video' : 'Photo', $poster);
        }
    } catch (Throwable $e) {
    }
}

if (empty($mediaItems) && $coverImage !== '') {
    bv_add_media_item($mediaItems, $seenMedia, 'image', $coverImage, $coverImage, true, 'Photo');
}

if (bv_has_table($pdo, 'listing_images')) {
    try {
        $imgStmt = $pdo->prepare('SELECT * FROM listing_images WHERE listing_id = ? ORDER BY sort_order ASC, id ASC');
        $imgStmt->execute([$listingId]);
        foreach (($imgStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $img = bv_resolve_asset_url($row['image_path'] ?? ($row['image_url'] ?? ''));
            bv_add_media_item($mediaItems, $seenMedia, 'image', $img, $img, false, 'Photo');
        }
    } catch (Throwable $e) {
    }
}

foreach (['video', 'video_path', 'video_url'] as $videoKey) {
    $videoCandidate = bv_resolve_asset_url($listing[$videoKey] ?? '');
    if ($videoCandidate !== '') {
        $poster = $coverImage !== '' ? $coverImage : bv_media_poster_from_path((string) ($listing[$videoKey] ?? ''));
        bv_add_media_item($mediaItems, $seenMedia, 'video', $videoCandidate, $poster, false, 'Video', $poster);
        break;
    }
}

if (empty($mediaItems)) {
    $placeholder = function_exists('asset_url') ? asset_url('images/placeholder-fish.jpg') : '/images/placeholder-fish.jpg';
    bv_add_media_item($mediaItems, $seenMedia, 'image', $placeholder, $placeholder, true, 'Photo');
}

$mainMedia = $mediaItems[0];
$imageCount = 0;
$videoCount = 0;
foreach ($mediaItems as $item) {
    if ($item['type'] === 'video') {
        $videoCount++;
    } else {
        $imageCount++;
    }
}

$relatedListings = [];
try {
    $relatedStmt = $pdo->prepare("SELECT id, seller_id, title, slug, cover_image, price, currency, city, country, status, sale_status FROM listings WHERE id <> ? AND status IN ('active','available','published') ORDER BY featured DESC, created_at DESC, id DESC LIMIT 3");
    $relatedStmt->execute([$listingId]);
    $relatedListings = $relatedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $relatedListings = seller_discount_apply_to_listing_rows($relatedListings, $pdo);
} catch (Throwable $e) {
    $relatedListings = [];
}

$selfUrl = APP_URL . bv_listing_url_from_row($listing);
if (!function_exists('bv_listing_seo_clean_text')) {
    function bv_listing_seo_clean_text($value): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)));
        return $text;
    }
}
if (!function_exists('bv_listing_seo_absolute_url')) {
    function bv_listing_seo_absolute_url(?string $url, string $baseUrl): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        return $baseUrl . '/' . ltrim($url, '/');
    }
}
if (!function_exists('bv_listing_seo_first_non_empty')) {
    function bv_listing_seo_first_non_empty(...$values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }
}
if (!function_exists('bv_listing_seo_availability_url')) {
    function bv_listing_seo_availability_url(?string $saleStatus, ?string $status): string
    {
        $value = strtolower(trim((string) ($saleStatus !== null && trim((string) $saleStatus) !== '' ? $saleStatus : $status)));
        if (in_array($value, ['sold', 'out_of_stock'], true)) {
            return 'https://schema.org/SoldOut';
        }
        if (in_array($value, ['reserved', 'hold', 'on_hold'], true)) {
            return 'https://schema.org/LimitedAvailability';
        }
        if (in_array($value, ['preorder', 'pre-order', 'pre_order'], true)) {
            return 'https://schema.org/PreOrder';
        }
        return 'https://schema.org/InStock';
    }
}
$baseUrl = defined('APP_URL') ? rtrim((string) APP_URL, '/') : 'https://www.bettavaro.com';
$listingTitleSeo = bv_listing_seo_first_non_empty($title, $listing['title'] ?? '', 'Premium Betta Fish');
$listingTitleLower = strtolower($listingTitleSeo);
if (strpos($listingTitleLower, 'betta fish for sale') !== false) {
    $pageTitle = $listingTitleSeo . ' | Bettavaro';
} elseif (strpos($listingTitleLower, 'betta fish') !== false) {
    $pageTitle = $listingTitleSeo . ' for Sale | Bettavaro';
} else {
    $pageTitle = $listingTitleSeo . ' Betta Fish for Sale | Bettavaro';
}
$pageTitle = trim(preg_replace('/\s+/', ' ', $pageTitle));
$productTypeSeo = bv_listing_seo_first_non_empty($strain, $species, 'premium');
$metaDescription = 'Buy ' . $listingTitleSeo . ' on Bettavaro. Premium ' . $productTypeSeo . ' betta fish from trusted breeders, ready for collectors worldwide. View price, photos, details, and availability.';
$metaDescription = function_exists('mb_substr') ? mb_substr(bv_listing_seo_clean_text($metaDescription), 0, 160) : substr(bv_listing_seo_clean_text($metaDescription), 0, 160);
$canonicalUrl = $slug !== '' ? ($baseUrl . '/listing.php?slug=' . rawurlencode((string) $slug)) : ($baseUrl . '/listing.php?id=' . (int) $listingId);
$metaRobots = 'index,follow,max-image-preview:large';
$ogImage = bv_listing_seo_absolute_url($coverImage, $baseUrl);
if ($ogImage === '') {
    $ogImage = $baseUrl . '/assets/img/og-listing.jpg';
}
$locationLabel = trim($city . ', ' . $country, ', ');
list($flashSuccess, $flashError) = bv_consume_flash_group();
list($cartFlashSuccess, $cartFlashError) = bv_cart_flash_group();
if ($flashSuccess === '' && $cartFlashSuccess !== '') { $flashSuccess = $cartFlashSuccess; }
if ($flashError === '' && $cartFlashError !== '') { $flashError = $cartFlashError; }

$reviewFlash = $_SESSION['listing_review_flash'] ?? null;
unset($_SESSION['listing_review_flash']);

$reviewTablesReady = false;
$currentReviewUserId = function_exists('bv_listing_review_current_user_id') ? bv_listing_review_current_user_id() : 0;
$reviewSummary = [
    'total_reviews' => 0,
    'avg_rating' => 0.0,
    'stars' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
];
$approvedReviews = [];
$reviewHasReviewed = false;
$reviewCsrfToken = '';
$reviewFormStartedAt = time();
$reviewReturnUrl = (string) ($_SERVER['REQUEST_URI'] ?? bv_listing_url_from_row($listing));
$reviewFlashStatus = is_array($reviewFlash) ? (string) ($reviewFlash['status'] ?? '') : '';
$reviewFlashMessage = is_array($reviewFlash) ? (string) ($reviewFlash['message'] ?? '') : '';
$reviewFlashErrors = is_array($reviewFlash) && isset($reviewFlash['errors']) && is_array($reviewFlash['errors']) ? $reviewFlash['errors'] : [];
$reviewFlashOld = is_array($reviewFlash) && isset($reviewFlash['old']) && is_array($reviewFlash['old']) ? $reviewFlash['old'] : [];
$reviewOldRating = (string) ($reviewFlashOld['rating'] ?? '');
$reviewOldTitle = (string) ($reviewFlashOld['title'] ?? '');
$reviewOldComment = (string) ($reviewFlashOld['comment'] ?? '');

try {
    if (function_exists('bv_listing_reviews_require_tables')) {
        $reviewTablesReady = bv_listing_reviews_require_tables();
        $reviewDebug[] = 'bv_listing_reviews_require_tables()=' . ($reviewTablesReady ? 'true' : 'false');
    } else {
        $reviewDebug[] = 'bv_listing_reviews_require_tables() missing';
    }

    if (function_exists('bv_listing_review_table_exists')) {
        $reviewDebug[] = 'table_listing_reviews=' . (bv_listing_review_table_exists('listing_reviews') ? 'yes' : 'no');
        $reviewDebug[] = 'table_listing_review_status_logs=' . (bv_listing_review_table_exists('listing_review_status_logs') ? 'yes' : 'no');
    } else {
        $reviewDebug[] = 'function_bv_listing_review_table_exists=no';
    }

    if ($reviewTablesReady) {
        if (function_exists('bv_listing_review_get_summary')) {
            $reviewSummary = bv_listing_review_get_summary($listingId);
            $reviewDebug[] = 'summary_loaded=yes';
            $reviewDebug[] = 'summary_total_reviews=' . (string)($reviewSummary['total_reviews'] ?? 'n/a');
            $reviewDebug[] = 'summary_avg_rating=' . (string)($reviewSummary['avg_rating'] ?? 'n/a');
        }

        if (function_exists('bv_listing_review_get_approved')) {
            $approvedReviews = bv_listing_review_get_approved($listingId, 20);
            $reviewDebug[] = 'approved_reviews_count=' . count($approvedReviews);
        }

        if ($currentReviewUserId > 0 && function_exists('bv_listing_review_user_has_reviewed')) {
            $reviewHasReviewed = bv_listing_review_user_has_reviewed($listingId, $currentReviewUserId);
            $reviewDebug[] = 'reviewHasReviewed=' . ($reviewHasReviewed ? 'yes' : 'no');
        } else {
            $reviewDebug[] = 'currentReviewUserId=' . $currentReviewUserId;
        }

        if (function_exists('bv_listing_review_csrf_token')) {
            $reviewCsrfToken = bv_listing_review_csrf_token('listing_review_form');
            $reviewDebug[] = 'csrf_token_loaded=' . ($reviewCsrfToken !== '' ? 'yes' : 'no');
        } else {
            $reviewCsrfToken = bv_csrf_token();
            $reviewDebug[] = 'csrf_token_loaded=fallback_bv_csrf_token';
        }

        if (function_exists('bv_listing_review_form_started_at')) {
            $reviewFormStartedAt = bv_listing_review_form_started_at('listing_review_form');
            $reviewDebug[] = 'form_started_at=' . (string)$reviewFormStartedAt;
        }
    }
} catch (Throwable $e) {
    $reviewTablesReady = false;
    $reviewDebug[] = 'EXCEPTION=' . $e->getMessage();
    $reviewDebug[] = 'EXCEPTION_FILE=' . $e->getFile();
    $reviewDebug[] = 'EXCEPTION_LINE=' . $e->getLine();
}

if ($reviewTablesReady) {
    try {
        if (function_exists('bv_listing_review_get_summary')) {
            $reviewSummary = bv_listing_review_get_summary($listingId);
        }
        if (function_exists('bv_listing_review_get_approved')) {
            $approvedReviews = bv_listing_review_get_approved($listingId, 20);
        }
        if ($currentReviewUserId > 0 && function_exists('bv_listing_review_user_has_reviewed')) {
            $reviewHasReviewed = bv_listing_review_user_has_reviewed($listingId, $currentReviewUserId);
        }
        if (function_exists('bv_listing_review_csrf_token')) {
            $reviewCsrfToken = bv_listing_review_csrf_token('listing_review_form');
        } else {
            $reviewCsrfToken = bv_csrf_token();
        }
        if (function_exists('bv_listing_review_form_started_at')) {
            $reviewFormStartedAt = bv_listing_review_form_started_at('listing_review_form');
        }
    } catch (Throwable $e) {
        $reviewTablesReady = false;
    }
}

$breadcrumbSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        [
            '@type' => 'ListItem',
            'position' => 1,
            'name' => 'Home',
			'item' => $baseUrl . '/',
        ],
        [
            '@type' => 'ListItem',
            'position' => 2,
            'name' => 'Betta Fish for Sale',
            'item' => $baseUrl . '/listings.php',
        ],
        [
            '@type' => 'ListItem',
            'position' => 3,
             'name' => $listingTitleSeo,
            'item' => $canonicalUrl,
        ],
    ],
];

$productSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $listingTitleSeo,
    'description' => $metaDescription,
    'brand' => ['@type' => 'Brand', 'name' => 'Bettavaro'],
    'category' => 'Betta Fish',	
    'url' => $canonicalUrl,
    'image' => [$ogImage],	
];

if ($sku !== '' || $listingId > 0) {
    $productSchema['sku'] = $sku !== '' ? $sku : (string) $listingId;
}
if ($strain !== '') {
    $productSchema['additionalProperty'][] = [
        '@type' => 'PropertyValue',
        'name' => 'Strain',
        'value' => $strain,
    ];
}
if ($grade !== '') {
    $productSchema['additionalProperty'][] = [
        '@type' => 'PropertyValue',
        'name' => 'Grade',
        'value' => $grade,
    ];
}

if (is_numeric($finalPrice)) {
    $productSchema['offers'] = [
        '@type' => 'Offer',
        'url' => $canonicalUrl,
        'priceCurrency' => strtoupper((string) ($currency ?: 'USD')),
        'price' => number_format((float) $finalPrice, 2, '.', ''),
        'availability' => bv_listing_seo_availability_url($saleStatus, $status),
        'itemCondition' => 'https://schema.org/NewCondition',
        'seller' => [
            '@type' => 'Organization',
            'name' => 'Bettavaro',
        ],
    ];
}
if ((float) ($reviewSummary['avg_rating'] ?? 0) > 0 && (int) ($reviewSummary['total_reviews'] ?? 0) > 0) {
    $productSchema['aggregateRating'] = [
        '@type' => 'AggregateRating',
        'ratingValue' => (float) $reviewSummary['avg_rating'],
        'reviewCount' => (int) $reviewSummary['total_reviews'],
    ];
}
$listingJsonLd = json_encode([$productSchema, $breadcrumbSchema], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<?php include __DIR__ . '/includes/head.php'; ?>
<?php include __DIR__ . '/includes/menu.php'; ?>
<?php if (!empty($listingJsonLd)): ?>
<script type="application/ld+json">
<?= $listingJsonLd . "\n" ?>
</script>
<?php endif; ?>
<style>
:root{
    --bv-bg:#06110b;
    --bv-bg-2:#0b1911;
    --bv-card:#f8f8f8;
    --bv-ink:#0f172a;
    --bv-gold:#d7bc6b;
    --bv-gold-soft:#f6edd1;
    --bv-line:#d8dadd;
    --bv-green:#1f6a42;
    --bv-green-soft:#e8f8ef;
    --bv-shadow:0 22px 70px rgba(0,0,0,.24);
}
body{background:radial-gradient(circle at top, #0b1c13 0%, var(--bv-bg) 44%, #040b07 100%)}
.listing-wrap{max-width:1320px;margin:0 auto;padding:28px 16px 90px}
.listing-breadcrumbs{font-size:14px;color:#a6b2aa;margin-bottom:18px}
.listing-breadcrumbs a{color:var(--bv-gold);text-decoration:none}
.topbar-note{margin-bottom:16px;padding:14px 16px;border-radius:16px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.08);color:#d5e3db}
.listing-grid-detail{display:grid;grid-template-columns:minmax(0,1.06fr) minmax(360px,.94fr);gap:24px;align-items:start}
.listing-panel{background:rgba(255,255,255,.96);border:1px solid rgba(255,255,255,.35);border-radius:24px;box-shadow:var(--bv-shadow);overflow:hidden;color:var(--bv-ink);backdrop-filter:blur(8px)}
.listing-panel-content{padding:26px}
.media-stage{padding:16px;background:linear-gradient(180deg,#0c1720,#10151d)}
.media-frame{position:relative;border-radius:22px;overflow:hidden;background:#0b1220;border:1px solid rgba(255,255,255,.1);box-shadow:inset 0 0 0 1px rgba(255,255,255,.04)}
.media-viewer{position:relative;aspect-ratio:4/3;background:#0b1220}
.media-slide{position:absolute;inset:0;opacity:0;visibility:hidden;transition:opacity .35s ease, transform .35s ease;transform:scale(.985)}
.media-slide.is-active{opacity:1;visibility:visible;transform:scale(1)}
.media-slide img,.media-slide video{width:100%;height:100%;object-fit:cover;display:block;background:#0b1220}
.media-chip{position:absolute;top:14px;left:14px;z-index:3;padding:8px 12px;border-radius:999px;background:rgba(17,24,39,.88);color:#fff;font-size:12px;font-weight:800;letter-spacing:.02em;backdrop-filter:blur(8px)}
.media-zoom{position:absolute;right:14px;top:14px;z-index:3;display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border:none;border-radius:999px;background:rgba(17,24,39,.82);color:#fff;cursor:pointer;backdrop-filter:blur(8px);box-shadow:0 6px 18px rgba(0,0,0,.25)}
.media-nav{position:absolute;top:50%;transform:translateY(-50%);z-index:3;border:none;width:46px;height:46px;border-radius:999px;background:rgba(17,24,39,.78);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;backdrop-filter:blur(8px);box-shadow:0 10px 24px rgba(0,0,0,.22)}
.media-nav[disabled]{opacity:.35;cursor:default}
.media-nav-prev{left:14px}
.media-nav-next{right:14px}
.media-caption{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 2px 2px;color:#d7e0e9;font-size:13px}
.media-caption strong{color:#fff}
.media-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(92px,1fr));gap:10px;margin-top:14px}
.media-thumb{position:relative;display:block;border:2px solid transparent;border-radius:16px;overflow:hidden;background:#111827;cursor:pointer;padding:0;box-shadow:0 8px 18px rgba(0,0,0,.18)}
.media-thumb.is-active{border-color:var(--bv-gold);transform:translateY(-1px)}
.media-thumb img,.media-thumb video{width:100%;aspect-ratio:1/1;object-fit:cover;display:block;opacity:.96;background:#0b1220}
.media-thumb video{pointer-events:none}
.media-thumb-badge{position:absolute;left:8px;top:8px;padding:5px 8px;border-radius:999px;background:rgba(17,24,39,.84);color:#fff;font-size:11px;font-weight:800;letter-spacing:.02em}
.media-thumb-play{position:absolute;inset:auto 8px 8px auto;width:30px;height:30px;border-radius:999px;background:rgba(255,255,255,.92);color:#111827;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 18px rgba(0,0,0,.18)}
.media-thumb-play svg,.media-video-center svg{display:block}
.media-video-center{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);z-index:2;width:84px;height:84px;border-radius:999px;background:rgba(255,255,255,.14);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;color:#fff;pointer-events:none;box-shadow:0 16px 28px rgba(0,0,0,.28)}
.sell-box{position:sticky;top:18px}
.listing-eyebrow{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
.listing-badge{display:inline-flex;align-items:center;padding:8px 13px;border-radius:999px;font-size:13px;font-weight:800}
.listing-title{margin:0 0 8px;font-size:54px;line-height:1.04;color:#0f172a;letter-spacing:-.03em}
.listing-price-wrap{margin:10px 0 18px}
.listing-price{font-size:34px;font-weight:900;color:#243c2b;letter-spacing:.01em}
.listing-price-original{margin-top:6px;font-size:16px;font-weight:700;color:#64748b;text-decoration:line-through}
.listing-discount-pill{display:inline-flex;align-items:center;gap:8px;margin-top:10px;padding:8px 12px;border-radius:999px;background:#ecfdf3;color:#166534;font-size:13px;font-weight:900}
.listing-discount-note{margin-top:10px;padding:12px 14px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-size:13px;line-height:1.7}
.public-sale-state{margin:12px 0 16px;padding:13px 16px;border-radius:16px;font-size:14px;font-weight:800;line-height:1.5;border:1px solid transparent}
.status-available{background:var(--bv-green-soft);border-color:#9ae6b4;color:#0f6a39}
.status-hold{background:#fff5eb;border-color:#fdba74;color:#9a3412}
.status-sold{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
.listing-summary{margin:0 0 18px;color:#334155;font-size:18px;line-height:1.7}
.trust-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:20px}
.stock-trio{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:0 0 18px}
.stock-box{border:1px solid var(--bv-line);border-radius:16px;padding:14px 12px;background:linear-gradient(180deg,#fcfcfc,#f4f4f5)}
.stock-box strong{display:block;font-size:24px;line-height:1;color:#0f172a;font-weight:900}
.stock-box span{display:block;margin-top:8px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;font-weight:800}
.stock-note{margin:-6px 0 18px;color:#64748b;font-size:13px;line-height:1.7}
.trust-pill{border:1px solid var(--bv-line);border-radius:16px;padding:12px 12px;background:linear-gradient(180deg,#fcfcfc,#f4f4f5);font-size:13px;font-weight:800;color:#334155}
.cta-stack{display:grid;gap:12px;margin:18px 0 6px}
.cta-primary,.cta-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:54px;border-radius:16px;font-weight:900;text-decoration:none;border:none;cursor:pointer;padding:0 18px;transition:transform .18s ease, box-shadow .18s ease}
.cta-primary{background:#253726;color:#fff;box-shadow:0 10px 26px rgba(37,55,38,.22)}
.cta-secondary{background:#fff;color:#253726;border:1px solid #253726}
.cta-primary:hover,.cta-secondary:hover{transform:translateY(-1px)}
.micro-note{font-size:13px;color:#64748b;line-height:1.7}
.listing-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:18px 0 22px}
.listing-meta-item{border:1px solid var(--bv-line);border-radius:16px;padding:14px 15px;background:linear-gradient(180deg,#fcfcfc,#f4f4f5)}
.listing-meta-label{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:4px;font-weight:800}
.listing-meta-value{font-size:15px;font-weight:700;color:#111827}
.listing-section{
    margin-top:22px;
    padding-top:18px;
    border-top:1px solid var(--bv-line);
    position:relative;
    z-index:1;
}
.listing-section h2{margin:0 0 12px;font-size:18px;color:#0f172a}
.listing-desc{color:#334155;white-space:pre-line;line-height:1.8}
.media-summary-box{background:linear-gradient(180deg,#fffdf6,#fff);border:1px solid #eee2b8;border-radius:18px;padding:16px 16px 14px}
.sales-points{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.sales-point{background:#fff;border:1px solid var(--bv-line);border-radius:18px;padding:16px}
.sales-point h3{margin:0 0 8px;font-size:16px;color:#0f172a}
.sales-point p{margin:0;color:#475569;line-height:1.7;font-size:14px}
.listing-note{margin-top:12px;font-size:14px;color:#6b7280}
.listing-reserve-box{margin-top:26px;background:rgba(255,255,255,.97)}
.listing-reserve-form{display:grid;gap:14px}
.listing-field-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.listing-field{display:flex;flex-direction:column;gap:6px}
.listing-field label{font-size:14px;font-weight:800;color:#111827}
.listing-field input,.listing-field textarea,.review-field input,.review-field textarea,.review-field select{width:100%;border:1px solid #d1d5db;border-radius:14px;padding:13px 14px;font:inherit;background:#fff;color:#111827}
.listing-field textarea,.review-field textarea{min-height:128px;resize:vertical}
.form-tip{padding:12px 14px;border-radius:14px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-size:14px;line-height:1.7}
.listing-policy{margin-top:26px;font-size:14px;color:#4b5563;background:#fafaf8;border:1px solid #ece8d7;border-radius:16px;padding:15px 16px}
.listing-flash{margin:0 0 18px;padding:12px 14px;border-radius:12px;font-weight:800}
.listing-flash-success{background:#dcfce7;color:#166534}
.listing-flash-error{background:#fee2e2;color:#991b1b}
.gallery-help{margin-top:10px;color:#cbd5e1;font-size:13px}
.related-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.related-card{background:#fff;border:1px solid rgba(255,255,255,.45);border-radius:20px;overflow:hidden;box-shadow:0 14px 34px rgba(0,0,0,.10)}
.related-card img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;background:#f3f4f6}
.related-body{padding:16px}
.related-body h3{margin:0 0 8px;font-size:18px;line-height:1.3}
.related-body h3 a{color:#0f172a;text-decoration:none}
.related-meta{font-size:13px;color:#64748b;margin-bottom:10px}
.related-price-wrap{margin-top:2px}
.related-price{font-size:22px;font-weight:900;color:#243c2b}
.related-price-original{margin-top:4px;font-size:13px;font-weight:700;color:#64748b;text-decoration:line-through}
.related-discount-pill{display:inline-flex;align-items:center;margin-top:8px;padding:6px 10px;border-radius:999px;background:#ecfdf3;color:#166534;font-size:12px;font-weight:900}
.related-link{display:inline-flex;margin-top:12px;min-height:42px;align-items:center;justify-content:center;padding:0 14px;border-radius:12px;background:#253726;color:#fff;font-weight:800;text-decoration:none}
.lightbox{position:fixed;inset:0;background:rgba(2,6,23,.92);display:none;align-items:center;justify-content:center;padding:24px;z-index:9999}
.lightbox.is-open{display:flex}
.lightbox-inner{position:relative;width:min(1200px,96vw);max-height:94vh;background:rgba(15,23,42,.72);border:1px solid rgba(255,255,255,.12);border-radius:24px;padding:22px;box-shadow:0 32px 90px rgba(0,0,0,.45);backdrop-filter:blur(12px)}
.lightbox-close{position:absolute;top:14px;right:14px;width:42px;height:42px;border:none;border-radius:999px;background:rgba(255,255,255,.12);color:#fff;cursor:pointer}
.lightbox-stage{position:relative;aspect-ratio:16/10;background:#020617;border-radius:18px;overflow:hidden}
.lightbox-item{position:absolute;inset:0;opacity:0;visibility:hidden;transition:opacity .28s ease}
.lightbox-item.is-active{opacity:1;visibility:visible}
.lightbox-item img,.lightbox-item video{width:100%;height:100%;object-fit:contain;background:#020617}
.lightbox-nav{position:absolute;top:50%;transform:translateY(-50%);border:none;width:48px;height:48px;border-radius:999px;background:rgba(255,255,255,.14);color:#fff;cursor:pointer;z-index:3}
.lightbox-prev{left:16px}.lightbox-next{right:16px}
.sticky-mobile-cta{display:none}
.review-summary-grid{display:grid;grid-template-columns:220px 1fr;gap:18px;align-items:start}
.review-summary-box{border:1px solid var(--bv-line);border-radius:18px;padding:18px;background:linear-gradient(180deg,#ffffff,#f8fafc)}
.review-summary-score{font-size:42px;font-weight:900;color:#0f172a;line-height:1}
.review-summary-count{margin-top:8px;font-size:14px;color:#64748b;font-weight:700}
.review-stars-large .bv-review-star,.review-stars-inline .bv-review-star{font-size:20px;line-height:1;margin-right:2px}
.review-stars-inline .bv-review-star{font-size:16px}
.bv-review-star-filled{color:#f59e0b}
.bv-review-star-empty{color:#d1d5db}
.review-bars{display:grid;gap:10px}
.review-bar-row{display:grid;grid-template-columns:48px 1fr 44px;gap:10px;align-items:center}
.review-bar-track{height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden}
.review-bar-fill{height:100%;background:linear-gradient(90deg,#d7bc6b,#f59e0b)}
.review-form-wrap{
    margin-top:18px;
    border:1px solid var(--bv-line);
    border-radius:18px;
    padding:18px;
    background:#ffffff;
    position:relative;
    z-index:5;
    overflow:hidden;
}
.review-form-grid{display:grid;grid-template-columns:220px 1fr;gap:14px}
.review-field{display:flex;flex-direction:column;gap:6px}
.review-field label{font-size:14px;font-weight:800;color:#111827}
.review-form-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:14px}
.review-note{font-size:13px;color:#64748b;line-height:1.7}
.review-login-box,.review-empty-box,.review-disabled-box{padding:16px 18px;border-radius:18px;border:1px solid var(--bv-line);background:#f8fafc;color:#475569;line-height:1.7}
.review-list{display:grid;gap:14px;margin-top:18px}
.review-card{border:1px solid var(--bv-line);border-radius:18px;padding:18px;background:linear-gradient(180deg,#ffffff,#fafafa)}
.review-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.review-card-name{font-size:16px;font-weight:900;color:#0f172a}
.review-card-meta{font-size:13px;color:#64748b;margin-top:4px}
.review-verified{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#ecfdf3;color:#166534;font-size:12px;font-weight:900;margin-top:8px}
.review-card-title{margin:12px 0 8px;font-size:17px;font-weight:900;color:#0f172a}
.review-card-comment{color:#334155;line-height:1.8;white-space:pre-line}
.review-error-list{margin:10px 0 0;padding-left:18px;color:#b91c1c;font-size:13px;font-weight:700}
.review-flash{margin:14px 0 0;padding:12px 14px;border-radius:12px;font-weight:800}
.review-flash-success{background:#dcfce7;color:#166534}
.review-flash-error{background:#fee2e2;color:#991b1b}
.review-hidden-field{position:absolute !important;left:-9999px !important;top:auto !important;width:1px !important;height:1px !important;overflow:hidden !important}
@media (max-width:1080px){
    .listing-grid-detail{grid-template-columns:1fr}
    .listing-title{font-size:42px}
    .sell-box{position:relative;top:auto}
    .related-grid,.sales-points,.trust-row,.stock-trio,.review-summary-grid,.review-form-grid{grid-template-columns:1fr 1fr}
}
@media (max-width:720px){
    .listing-wrap{padding:18px 12px 110px}
    .media-stage{padding:12px}
    .listing-panel-content{padding:20px}
    .listing-title{font-size:34px}
    .listing-price{font-size:28px}
    .listing-summary{font-size:17px}
    .listing-meta,.listing-field-grid,.related-grid,.sales-points,.trust-row,.stock-trio,.review-summary-grid,.review-form-grid{grid-template-columns:1fr}
    .media-strip{grid-template-columns:repeat(4,minmax(0,1fr));overflow:auto}
    .media-nav{width:42px;height:42px}
    .media-video-center{width:64px;height:64px}
    .sticky-mobile-cta{display:flex;position:fixed;left:12px;right:12px;bottom:12px;z-index:999;gap:10px;padding:10px;background:rgba(15,23,42,.92);border-radius:18px;box-shadow:0 18px 35px rgba(0,0,0,.35)}
    .sticky-mobile-cta a,.sticky-mobile-cta button{flex:1;min-height:48px;border-radius:14px;text-decoration:none;font-weight:900;display:flex;align-items:center;justify-content:center;padding:0 12px}
    .sticky-mobile-cta .primary{background:#d7bc6b;color:#111827}
    .sticky-mobile-cta .secondary{background:#fff;color:#253726}
}

.review-form-wrap {
    background: #ffffff;
    position: relative;
    z-index: 5;
    border-radius: 12px;
    padding: 16px;
}

.listing-section {
    position: relative;
    z-index: 1;
}

/* ── Ranking badges (listing_ranking.php) ─────────────── */
.bvrank-badge-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:6px 0 10px}
.bvrank-rating-pill{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:999px;background:#fefce8;border:1px solid #fde68a;color:#78350f;font-size:14px;font-weight:800}
.bvrank-count{font-weight:600;color:#92400e}
.bvrank-badge{display:inline-flex;align-items:center;padding:6px 11px;border-radius:999px;font-size:13px;font-weight:800}
.bvrank-top{background:#ecfdf3;border:1px solid #86efac;color:#14532d}
.bvrank-rising{background:#eff6ff;border:1px solid #93c5fd;color:#1e3a8a}
.bvrank-no-reviews{font-size:13px;color:#9ca3af;font-style:italic}

</style>
<?php include __DIR__ . '/includes/header.php'; ?>

<main class="listing-wrap">
    <div class="listing-breadcrumbs">
        <a href="/">Home</a> / <a href="/listings.php">Listings</a> / <?= bv_e($title); ?>
    </div>

    <div class="topbar-note">
        Collector-grade presentation with clear availability, strong media, and a direct path to checkout or cart.
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="listing-flash listing-flash-success"><?= bv_e($flashSuccess); ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="listing-flash listing-flash-error"><?= bv_e($flashError); ?></div>
    <?php endif; ?>

    <div class="listing-grid-detail">
        <div class="listing-panel">
            <div class="media-stage">
                <div class="media-frame" id="premium-gallery" data-total="<?= count($mediaItems); ?>">
                    <div class="media-viewer">
                        <?php foreach ($mediaItems as $index => $item): ?>
                            <div class="media-slide <?= $index === 0 ? 'is-active' : ''; ?>" data-index="<?= $index; ?>" data-type="<?= bv_e($item['type']); ?>">
                                <div class="media-chip"><?= bv_e($item['label']); ?></div>
                                <?php if ($item['type'] === 'video'): ?>
                                    <div class="media-video-center" aria-hidden="true">
                                        <svg width="34" height="34" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"></path></svg>
                                    </div>
                                    <video preload="metadata" controls playsinline poster="<?= bv_e($item['poster'] ?: $item['thumb']); ?>">
                                        <source src="<?= bv_e($item['src']); ?>">
                                    </video>
                                <?php else: ?>
                                    <img src="<?= bv_e($item['src']); ?>" alt="<?= bv_e($title); ?> media <?= $index + 1; ?>" loading="<?= $index === 0 ? 'eager' : 'lazy'; ?>">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php if (count($mediaItems) > 1): ?>
                            <button type="button" class="media-nav media-nav-prev" data-gallery-prev aria-label="Previous media">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"></path></svg>
                            </button>
                            <button type="button" class="media-nav media-nav-next" data-gallery-next aria-label="Next media">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"></path></svg>
                            </button>
                        <?php endif; ?>

                        <button type="button" class="media-zoom" data-open-lightbox aria-label="Open fullscreen gallery">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6"></path><path d="M9 21H3v-6"></path><path d="M21 3l-7 7"></path><path d="M3 21l7-7"></path></svg>
                        </button>
                    </div>

                    <div class="media-caption">
                        <div><strong id="gallery-counter">1 / <?= count($mediaItems); ?></strong></div>
                        <div><?= $videoCount > 0 ? bv_e($imageCount . ' photos · ' . $videoCount . ' video' . ($videoCount > 1 ? 's' : '')) : bv_e($imageCount . ' photos'); ?></div>
                    </div>

                    <?php if (count($mediaItems) > 1): ?>
                        <div class="gallery-help">Tap a thumbnail below to switch photos and videos. Good media sells fish. Muddy media sells headaches 😄</div>
                        <div class="media-strip" role="tablist" aria-label="Listing media thumbnails">
                            <?php foreach ($mediaItems as $index => $item): ?>
                                <button
                                    type="button"
                                    class="media-thumb <?= $index === 0 ? 'is-active' : ''; ?>"
                                    data-thumb-index="<?= $index; ?>"
                                    aria-label="View <?= bv_e(strtolower($item['label'])); ?> <?= $index + 1; ?>"
                                >
                                    <?php if ($item['type'] === 'video'): ?>
                                        <video preload="metadata" muted playsinline poster="<?= bv_e($item['poster'] ?: $item['thumb'] ?: $coverImage); ?>">
                                            <source src="<?= bv_e($item['src']); ?>">
                                        </video>
                                    <?php else: ?>
                                        <img src="<?= bv_e($item['thumb']); ?>" alt="<?= bv_e($title); ?> thumbnail <?= $index + 1; ?>" loading="lazy">
                                    <?php endif; ?>
                                    <span class="media-thumb-badge"><?= bv_e($item['label']); ?></span>
                                    <?php if ($item['type'] === 'video'): ?>
                                        <span class="media-thumb-play" aria-hidden="true">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"></path></svg>
                                        </span>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="sell-box">
            <div class="listing-panel">
                <div class="listing-panel-content">
                    <div class="listing-eyebrow">
                        <?php if ($tier !== ''): ?>
                            <span class="listing-badge" style="background:var(--bv-gold-soft);color:#7a5a00;"><?= bv_e($tier); ?></span>
                        <?php endif; ?>
                        <span class="listing-badge" style="background:<?= bv_e($statusBg); ?>;color:<?= bv_e($statusColor); ?>;"><?= bv_e($statusLabel); ?></span>
                        <?php if ($locationLabel !== ''): ?>
                            <span class="listing-badge" style="background:#f3f4f6;color:#334155;"><?= bv_e($locationLabel); ?></span>
                        <?php endif; ?>
                    </div>

                    <h1 class="listing-title"><?= bv_e($title); ?></h1>

                    <?php if ($bvRankingCacheOk && $bvRankCount > 0): ?>
                    <div class="bvrank-badge-row">
                        <span class="bvrank-rating-pill">
                            ⭐ <?= number_format($bvRankAvg, 2); ?>
                            <span class="bvrank-count">(<?= number_format($bvRankCount); ?> <?= $bvRankCount === 1 ? 'review' : 'reviews'; ?>)</span>
                        </span>
                        <?php if ($bvIsTopRated): ?>
                            <span class="bvrank-badge bvrank-top">🏆 Top Rated</span>
                        <?php endif; ?>
                        <?php if ($bvIsRising && !$bvIsTopRated): ?>
                            <span class="bvrank-badge bvrank-rising">🚀 Rising</span>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($bvRankingCacheOk && $bvRankCount === 0): ?>
                    <div class="bvrank-badge-row">
                        <span class="bvrank-no-reviews">No reviews yet</span>
                    </div>
                    <?php endif; ?>

                    <div class="listing-price-wrap">
                        <div class="listing-price"><?= bv_e(bv_format_price($hasSellerDiscount ? $finalPrice : $price, $currency)); ?></div>

                        <?php if ($hasSellerDiscount && is_numeric($basePrice)): ?>
                            <div class="listing-price-original"><?= bv_e(bv_format_price($basePrice, $currency)); ?></div>
                            <div class="listing-discount-pill">
                                Seller Discount <?= bv_e(number_format($sellerDiscountPercent, 2)); ?>% · Save <?= bv_e(bv_format_price($sellerDiscountAmount, $currency)); ?>
                            </div>
                            <div class="listing-discount-note">
                                This seller is currently offering a discount on this fish. Admin commission is calculated from the net price after discount.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="public-sale-state <?= bv_e((string) $publicState['class']); ?>">
                        <?= bv_e((string) $publicState['text']); ?>
                    </div>

                    <div class="stock-trio">
                        <div class="stock-box">
                            <strong><?= $stockTotal; ?></strong>
                            <span>Total</span>
                        </div>
                        <div class="stock-box">
                            <strong><?= $stockSold; ?></strong>
                            <span>Sold</span>
                        </div>
                        <div class="stock-box">
                            <strong><?= $stockAvailable; ?></strong>
                            <span>Remaining</span>
                        </div>
                    </div>
                    <div class="stock-note">
                        <?= $stockAvailable > 0 ? 'Ready to sell now: ' . $stockAvailable . ' item(s).' : 'No sellable stock remaining right now.'; ?>
                    </div>

                    <?php if ($shortDescription !== ''): ?>
                        <p class="listing-summary"><?= bv_e($shortDescription); ?></p>
                    <?php endif; ?>

                    <div class="trust-row">
                        <div class="trust-pill">Clear availability</div>
                        <div class="trust-pill">Media-first presentation</div>
                        <div class="trust-pill">International shipping support</div>
                    </div>

                    <div class="cta-stack">
                        <?php if ($canCheckout): ?>
                            <form method="post" action="/cart_add.php" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= bv_e(bv_cart_form_token()); ?>">
                                <input type="hidden" name="listing_id" value="<?= $listingId; ?>">
                                <input type="hidden" name="redirect" value="/checkout.php">
                                <button type="submit" class="cta-primary" style="width:100%;"><?= bv_e((string)$publicState['cta_label']); ?></button>
                            </form>
                            <form method="post" action="/cart_add.php" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= bv_e(bv_cart_form_token()); ?>">
                                <input type="hidden" name="listing_id" value="<?= $listingId; ?>">
                                <input type="hidden" name="redirect" value="/cart.php">
                                <button type="submit" class="cta-secondary" style="width:100%;"><?= bv_e((string)$publicState['secondary_label']); ?></button>
                            </form>
                        <?php elseif (!empty($publicState['show_similar'])): ?>
                            <a href="/contact.php?topic=similar-fish&amp;listing_id=<?= $listingId; ?>" class="cta-primary"><?= bv_e((string)$publicState['cta_label']); ?></a>
                            <a href="/listings.php" class="cta-secondary"><?= bv_e((string)$publicState['secondary_label']); ?></a>
                        <?php else: ?>
                            <a href="/contact.php?listing_id=<?= $listingId; ?>" class="cta-primary"><?= bv_e((string)$publicState['cta_label']); ?></a>
                            <a href="/listings.php" class="cta-secondary"><?= bv_e((string)$publicState['secondary_label']); ?></a>
                        <?php endif; ?>
                    </div>

                    <div class="micro-note">
                        Listing ID: #<?= $listingId; ?>
                        <?php if ($createdAt !== ''): ?> · Added <?= bv_e($createdAt); ?><?php endif; ?>
                    </div>

                    <div class="listing-meta">
                        <?php foreach ([
                            'Sex' => $sex,
                            'Age' => $age,
                            'Color' => $color,
                            'Breed / Variety' => $breed,
                            'Size' => $size,
                            'Origin / Breeder' => $origin,
                            'Stock Remaining' => (string) $stockAvailable,
                        ] as $label => $value): ?>
                            <?php if ($value !== ''): ?>
                                <div class="listing-meta-item">
                                    <div class="listing-meta-label"><?= bv_e($label); ?></div>
                                    <div class="listing-meta-value"><?= bv_e($value); ?></div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="listing-section">
                        <h2>Why collectors choose Bettavaro</h2>
                        <div class="sales-points">
                            <div class="sales-point">
                                <h3>Fast decision path</h3>
                                <p>Price, status, media, and checkout CTA sit together instead of making the buyer scroll around and lose intent.</p>
                            </div>
                            <div class="sales-point">
                                <h3>Cleaner trust signals</h3>
                                <p>Availability and shipping support are obvious at first glance, which reduces hesitation for serious buyers.</p>
                            </div>
                            <div class="sales-point">
                                <h3>Better mobile buying</h3>
                                <p>Sticky mobile CTA keeps the action buttons visible. Because hidden CTA is basically silent sabotage.</p>
                            </div>
                        </div>
                    </div>

                    <?php if ($description !== ''): ?>
                        <div class="listing-section">
                            <h2>Description</h2>
                            <div class="listing-desc"><?= nl2br(bv_e($description)); ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="listing-section">
                        <h2>Photo & Video Gallery</h2>
                        <div class="media-summary-box">
                            <div class="listing-desc">
                                This listing currently has <strong><?= count($mediaItems); ?> media item<?= count($mediaItems) > 1 ? 's' : ''; ?></strong>,
                                including <strong><?= $imageCount; ?> photo<?= $imageCount > 1 ? 's' : ''; ?></strong>
                                <?php if ($videoCount > 0): ?>and <strong><?= $videoCount; ?> video<?= $videoCount > 1 ? 's' : ''; ?></strong><?php endif; ?>.
                            </div>
                        </div>
                    </div>

<div class="listing-section" id="listing-reviews">
    <h2>Reviews & Ratings</h2>

    <?php if ($reviewFlashMessage !== ''): ?>
        <div class="review-flash <?= in_array($reviewFlashStatus, ['submitted', 'success'], true) ? 'review-flash-success' : 'review-flash-error'; ?>">
            <?= bv_e($reviewFlashMessage); ?>
        </div>
    <?php endif; ?>

    <?php if ($reviewTablesReady): ?>
        <?php
        $reviewTotal = (int) ($reviewSummary['total_reviews'] ?? 0);
        $reviewAverage = (float) ($reviewSummary['avg_rating'] ?? 0);
        $reviewStars = is_array($reviewSummary['stars'] ?? null) ? $reviewSummary['stars'] : [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        ?>
        <div class="review-summary-grid">
            <div class="review-summary-box">
                <div class="review-summary-score"><?= number_format($reviewAverage, 1); ?></div>
                <div class="review-stars-large"><?= bv_listing_review_render_stars((int) round($reviewAverage)); ?></div>
                <div class="review-summary-count"><?= number_format($reviewTotal); ?> review<?= $reviewTotal === 1 ? '' : 's'; ?></div>
            </div>

            <div class="review-summary-box">
                <div class="review-bars">
                    <?php for ($star = 5; $star >= 1; $star--): ?>
                        <?php
                        $starCount = (int) ($reviewStars[$star] ?? 0);
                        $starPercent = $reviewTotal > 0 ? ($starCount / $reviewTotal) * 100 : 0;
                        ?>
                        <div class="review-bar-row">
                            <div><?= $star; ?> ★</div>
                            <div class="review-bar-track">
                                <div class="review-bar-fill" style="width:<?= number_format($starPercent, 2, '.', ''); ?>%;"></div>
                            </div>
                            <div><?= $starCount; ?></div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <div class="review-form-wrap">
            <h3 style="margin:0 0 10px;font-size:18px;color:#0f172a;">How to leave a review</h3>
            <div class="review-disabled-box">
                Verified-buyer reviews can be submitted from your completed order page.
            </div>
        </div>

        <div class="review-list">
            <?php if (!$approvedReviews): ?>
                <div class="review-empty-box">
                    No verified reviews yet.                   
                </div>
            <?php else: ?>
                <?php foreach ($approvedReviews as $review): ?>
                    <?php
                    $reviewName = function_exists('bv_listing_review_display_name') ? bv_listing_review_display_name($review) : 'Customer';
                    $reviewDate = (string) ($review['submitted_at'] ?? $review['approved_at'] ?? '');
                    ?>
                    <article class="review-card">
                        <div class="review-card-head">
                            <div>
                                <div class="review-card-name"><?= bv_e($reviewName); ?></div>
                                <div class="review-card-meta"><?= bv_e($reviewDate); ?></div>
                                <?php if (!empty($review['is_verified_buyer'])): ?>
                                    <div class="review-verified">Verified Buyer</div>
                                <?php endif; ?>
                            </div>
                            <div class="review-stars-inline"><?= bv_listing_review_render_stars((int) ($review['rating'] ?? 0)); ?></div>
                        </div>

                        <?php if (trim((string) ($review['title'] ?? '')) !== ''): ?>
                            <div class="review-card-title"><?= bv_e((string) $review['title']); ?></div>
                        <?php endif; ?>

                        <div class="review-card-comment"><?= nl2br(bv_e((string) ($review['comment'] ?? ''))); ?></div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="review-disabled-box">
            <strong>Review system is not ready yet.</strong><br>
            Please finish the review tables and helper files first.
            <hr style="margin:12px 0;border:none;border-top:1px solid #d1d5db;">
            <div style="font-size:13px;line-height:1.7;color:#334155;">

            </div>
        </div>
    <?php endif; ?>
</div>

                    <div class="listing-policy">
                        <strong>Shipping note:</strong>
                        We can help coordinate shipping based on destination and availability. For international orders,
                        customer may need to arrange a transhipper in their country.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canCheckout): ?>
        <div class="listing-panel listing-reserve-box" id="checkout-box">
            <div class="listing-panel-content">
                <h2 style="margin-top:0;">Quick Checkout</h2>
                <p style="margin-top:0;color:#4b5563;line-height:1.7;">
                    One click sends this fish into your cart and jumps straight to checkout.
                    No more reserve detour. เดินตรงเข้าทางซื้อเลยครับ 😄
                </p>

                <?php if ($hasSellerDiscount): ?>
                    <div class="form-tip" style="margin-bottom:12px;">
                        Discount applied: <?= bv_e(number_format($sellerDiscountPercent, 2)); ?>%
                        · You save <?= bv_e(bv_format_price($sellerDiscountAmount, $currency)); ?>
                        · Net price <?= bv_e(bv_format_price($finalPrice, $currency)); ?>
                    </div>
                <?php endif; ?>

                <div class="form-tip">
                    Primary button = add this fish to cart and open checkout immediately.
                    Secondary button = add to cart and keep shopping.
                </div>

                <div class="cta-stack">
                    <form method="post" action="/cart_add.php" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= bv_e(bv_cart_form_token()); ?>">
                        <input type="hidden" name="listing_id" value="<?= $listingId; ?>">
                        <input type="hidden" name="redirect" value="/checkout.php">
                        <button type="submit" class="cta-primary" style="width:100%;">Check Out This Fish</button>
                    </form>

                    <form method="post" action="/cart_add.php" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= bv_e(bv_cart_form_token()); ?>">
                        <input type="hidden" name="listing_id" value="<?= $listingId; ?>">
                        <input type="hidden" name="redirect" value="/cart.php">
                        <button type="submit" class="cta-secondary" style="width:100%;">Add to Cart</button>
                    </form>

                    <?php if ($canOffer): ?>
                        <div class="offer-box" style="margin-top:12px;padding:18px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;">
                            <h3 style="margin:0 0 10px;font-size:20px;color:#0f172a;">Make an Offer</h3>
                            <p style="margin:0 0 14px;color:#64748b;font-size:14px;line-height:1.7;">
                                Send your offer directly to the seller for this fish.
                            </p>

                            <?php if ($offerFlashMessage !== ''): ?>
                                <div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;background:<?= in_array($offerFlashStatus, ['created', 'success'], true) ? '#ecfdf3' : '#fef2f2'; ?>;color:<?= in_array($offerFlashStatus, ['created', 'success'], true) ? '#166534' : '#b91c1c'; ?>;border:1px solid <?= in_array($offerFlashStatus, ['created', 'success'], true) ? '#bbf7d0' : '#fecaca'; ?>;">
                                    <?= bv_offer_form_h($offerFlashMessage); ?>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="/offer_create.php" style="margin:0;" novalidate>
                                <input type="hidden" name="listing_id" value="<?= $listingId; ?>">
                                <input type="hidden" name="csrf_token" value="<?= bv_offer_form_h($offerCsrfToken); ?>">
                                <input type="hidden" name="form_started_at" value="<?= (int) $offerStartedAt; ?>">
                                <input type="hidden" name="return_url" value="<?= bv_offer_form_h($offerReturnUrl); ?>">

                                <div style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
                                    <label for="offer-website">Website</label>
                                    <input type="text" name="website" id="offer-website" value="" tabindex="-1" autocomplete="off">
                                </div>

                                <div class="listing-field" style="margin-bottom:10px;">
                                    <label for="offer_price">Your Offer Price</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        id="offer_price"
                                        name="offer_price"
                                        inputmode="decimal"
                                        placeholder="e.g. 120.00"
                                        value="<?= bv_offer_form_h($offerPriceValue); ?>"
                                        required
                                    >
                                    <?php if (!empty($offerFlashErrors['offer_price'])): ?>
                                        <div style="margin-top:6px;color:#b91c1c;font-size:13px;">
                                            <?= bv_offer_form_h($offerFlashErrors['offer_price']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="listing-field" style="margin-bottom:12px;">
                                    <label for="message_text">Short Message to Seller</label>
                                    <textarea
                                        id="message_text"
                                        name="message_text"
                                        placeholder="Hi, I’m interested in this fish. Would you consider my offer?"
                                        style="min-height:92px;"
                                        required
                                    ><?= bv_offer_form_h($offerMessageValue); ?></textarea>
                                    <?php if (!empty($offerFlashErrors['message_text'])): ?>
                                        <div style="margin-top:6px;color:#b91c1c;font-size:13px;">
                                            <?= bv_offer_form_h($offerFlashErrors['message_text']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($offerFlashErrors['form'])): ?>
                                    <div style="margin-bottom:10px;color:#b91c1c;font-size:13px;">
                                        <?= bv_offer_form_h($offerFlashErrors['form']); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($offerFlashErrors['csrf'])): ?>
                                    <div style="margin-bottom:10px;color:#b91c1c;font-size:13px;">
                                        <?= bv_offer_form_h($offerFlashErrors['csrf']); ?>
                                    </div>
                                <?php endif; ?>

                                <button type="submit" class="cta-secondary" style="width:100%;">Send Offer</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($relatedListings)): ?>
        <div class="listing-section" style="border-top:none;margin-top:28px;padding-top:0;">
            <h2 style="color:#fff;margin-bottom:14px;">More fish you may like</h2>
            <div class="related-grid">
                <?php foreach ($relatedListings as $related): ?>
                    <?php
                    $relatedUrl = bv_listing_url_from_row($related);
                    $relatedImage = bv_resolve_asset_url($related['cover_image'] ?? '');
                    if ($relatedImage === '') {
                        $relatedImage = function_exists('asset_url') ? asset_url('images/placeholder-fish.jpg') : '/images/placeholder-fish.jpg';
                    }
                    $relatedLocation = trim(((string)($related['city'] ?? '')) . ', ' . ((string)($related['country'] ?? '')), ', ');
                    $relatedHasDiscount = !empty($related['has_seller_discount']) && (float)($related['seller_discount_amount'] ?? 0) > 0;
                    $relatedBasePrice = $related['price'] ?? null;
                    $relatedFinalPrice = $related['final_price'] ?? $relatedBasePrice;
                    $relatedDiscountPercent = (float)($related['seller_discount_percent'] ?? 0);
                    ?>
                    <article class="related-card">
                        <a href="<?= bv_e($relatedUrl); ?>"><img src="<?= bv_e($relatedImage); ?>" alt="<?= bv_e((string)($related['title'] ?? 'Related Listing')); ?>" loading="lazy"></a>
                        <div class="related-body">
                            <div class="related-meta"><?= bv_e($relatedLocation !== '' ? $relatedLocation : 'International'); ?></div>
                            <h3><a href="<?= bv_e($relatedUrl); ?>"><?= bv_e((string)($related['title'] ?? 'Untitled Listing')); ?></a></h3>

                            <div class="related-price-wrap">
                                <div class="related-price"><?= bv_e(bv_format_price($relatedHasDiscount ? $relatedFinalPrice : $relatedBasePrice, (string)($related['currency'] ?? 'USD'))); ?></div>
                                <?php if ($relatedHasDiscount && is_numeric($relatedBasePrice)): ?>
                                    <div class="related-price-original"><?= bv_e(bv_format_price($relatedBasePrice, (string)($related['currency'] ?? 'USD'))); ?></div>
                                    <div class="related-discount-pill">Save <?= bv_e(number_format($relatedDiscountPercent, 2)); ?>%</div>
                                <?php endif; ?>
                            </div>

                            <a class="related-link" href="<?= bv_e($relatedUrl); ?>">View Listing</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</main>

<div class="lightbox" id="gallery-lightbox" aria-hidden="true">
    <div class="lightbox-inner">
        <button type="button" class="lightbox-close" data-close-lightbox aria-label="Close fullscreen gallery">✕</button>
        <div class="lightbox-stage">
            <?php foreach ($mediaItems as $index => $item): ?>
                <div class="lightbox-item <?= $index === 0 ? 'is-active' : ''; ?>" data-lightbox-index="<?= $index; ?>">
                    <?php if ($item['type'] === 'video'): ?>
                        <video preload="metadata" controls playsinline poster="<?= bv_e($item['poster'] ?: $item['thumb']); ?>">
                            <source src="<?= bv_e($item['src']); ?>">
                        </video>
                    <?php else: ?>
                        <img src="<?= bv_e($item['src']); ?>" alt="<?= bv_e($title); ?> fullscreen <?= $index + 1; ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (count($mediaItems) > 1): ?>
                <button type="button" class="lightbox-nav lightbox-prev" data-lightbox-prev aria-label="Previous media">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"></path></svg>
                </button>
                <button type="button" class="lightbox-nav lightbox-next" data-lightbox-next aria-label="Next media">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"></path></svg>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($canCheckout): ?>
    <div class="sticky-mobile-cta">
        <form method="post" action="/cart_add.php" style="flex:1;margin:0;">
            <input type="hidden" name="csrf_token" value="<?= bv_e(bv_cart_form_token()); ?>">
            <input type="hidden" name="listing_id" value="<?= $listingId; ?>">
            <input type="hidden" name="redirect" value="/checkout.php">
            <button type="submit" class="primary" style="width:100%;border:none;cursor:pointer;">Checkout</button>
        </form>
        <form method="post" action="/cart_add.php" style="flex:1;margin:0;">
            <input type="hidden" name="csrf_token" value="<?= bv_e(bv_cart_form_token()); ?>">
            <input type="hidden" name="listing_id" value="<?= $listingId; ?>">
            <input type="hidden" name="redirect" value="/cart.php">
            <button type="submit" class="secondary" style="width:100%;border:none;cursor:pointer;">Add to Cart</button>
        </form>
    </div>
<?php endif; ?>

<script>
(function(){
    var gallery = document.getElementById('premium-gallery');
    if (!gallery) return;

    var slides = Array.prototype.slice.call(gallery.querySelectorAll('.media-slide'));
    var thumbs = Array.prototype.slice.call(gallery.querySelectorAll('.media-thumb'));
    var counter = document.getElementById('gallery-counter');
    var lightbox = document.getElementById('gallery-lightbox');
    var lightboxSlides = lightbox ? Array.prototype.slice.call(lightbox.querySelectorAll('.lightbox-item')) : [];
    var activeIndex = 0;

    function pauseVideos(scope) {
        var root = scope || document;
        Array.prototype.slice.call(root.querySelectorAll('video')).forEach(function(video){
            try { video.pause(); } catch (e) {}
        });
    }

    function showIndex(index) {
        if (!slides.length) return;
        if (index < 0) index = slides.length - 1;
        if (index >= slides.length) index = 0;
        activeIndex = index;

        slides.forEach(function(slide, i){
            slide.classList.toggle('is-active', i === index);
        });
        thumbs.forEach(function(thumb, i){
            thumb.classList.toggle('is-active', i === index);
        });
        if (counter) {
            counter.textContent = (index + 1) + ' / ' + slides.length;
        }

        pauseVideos(gallery);
    }

    function showLightbox(index) {
        if (!lightbox || !lightboxSlides.length) return;
        lightboxSlides.forEach(function(slide, i){
            slide.classList.toggle('is-active', i === index);
        });
        pauseVideos(lightbox);
        var activeVideo = lightboxSlides[index] ? lightboxSlides[index].querySelector('video') : null;
        if (activeVideo) {
            try { activeVideo.play(); } catch (e) {}
        }
    }

    thumbs.forEach(function(thumb, index){
        thumb.addEventListener('click', function(){
            showIndex(index);
        });
    });

    var prev = gallery.querySelector('[data-gallery-prev]');
    var next = gallery.querySelector('[data-gallery-next]');
    if (prev) prev.addEventListener('click', function(){ showIndex(activeIndex - 1); });
    if (next) next.addEventListener('click', function(){ showIndex(activeIndex + 1); });

    var openLightbox = gallery.querySelector('[data-open-lightbox]');
    if (openLightbox && lightbox) {
        openLightbox.addEventListener('click', function(){
            lightbox.classList.add('is-open');
            lightbox.setAttribute('aria-hidden', 'false');
            showLightbox(activeIndex);
            document.documentElement.style.overflow = 'hidden';
        });
    }

    if (lightbox) {
        var close = lightbox.querySelector('[data-close-lightbox]');
        var lbPrev = lightbox.querySelector('[data-lightbox-prev]');
        var lbNext = lightbox.querySelector('[data-lightbox-next]');

        function closeLightbox() {
            lightbox.classList.remove('is-open');
            lightbox.setAttribute('aria-hidden', 'true');
            pauseVideos(lightbox);
            document.documentElement.style.overflow = '';
        }

        if (close) close.addEventListener('click', closeLightbox);
        lightbox.addEventListener('click', function(e){
            if (e.target === lightbox) closeLightbox();
        });
        if (lbPrev) lbPrev.addEventListener('click', function(){
            activeIndex = activeIndex <= 0 ? lightboxSlides.length - 1 : activeIndex - 1;
            showIndex(activeIndex);
            showLightbox(activeIndex);
        });
        if (lbNext) lbNext.addEventListener('click', function(){
            activeIndex = activeIndex >= lightboxSlides.length - 1 ? 0 : activeIndex + 1;
            showIndex(activeIndex);
            showLightbox(activeIndex);
        });

        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && lightbox.classList.contains('is-open')) {
                closeLightbox();
                return;
            }
            if (e.key === 'ArrowLeft') {
                if (lightbox.classList.contains('is-open')) {
                    activeIndex = activeIndex <= 0 ? lightboxSlides.length - 1 : activeIndex - 1;
                    showIndex(activeIndex);
                    showLightbox(activeIndex);
                } else {
                    showIndex(activeIndex - 1);
                }
            }
            if (e.key === 'ArrowRight') {
                if (lightbox.classList.contains('is-open')) {
                    activeIndex = activeIndex >= lightboxSlides.length - 1 ? 0 : activeIndex + 1;
                    showIndex(activeIndex);
                    showLightbox(activeIndex);
                } else {
                    showIndex(activeIndex + 1);
                }
            }
        });
    }

    showIndex(0);
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof gtag !== 'function') return;

  gtag('event', 'view_item', {
    currency: <?= json_encode((string) ($currency ?: 'USD')) ?>,
    value: <?= json_encode(is_numeric($finalPrice) ? (float) $finalPrice : 0) ?>,
    items: [{
      item_id: <?= json_encode((string) $listingId) ?>,
      item_name: <?= json_encode((string) $title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      item_category: <?= json_encode((string) ($species ?: 'Betta Fish'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      price: <?= json_encode(is_numeric($finalPrice) ? (float) $finalPrice : 0) ?>
    }]
  });

  console.log('GA4 view_item fired for listing:', <?= json_encode((string) $listingId) ?>);
});
</script>

<?php unset($_SESSION['reserve_old']); ?>
<?php
// ── Ranking debug comment (invisible in browser, visible in View Source) ─────
$_bvRankDebugHelper    = defined('BV_LISTING_RANKING_LOADED') ? 'yes' : 'no';
$_bvRankDebugCacheTable = ($bvRankingCacheOk) ? 'yes' : 'no';
echo '<!-- listing_ranking_debug: helper=' . $_bvRankDebugHelper
   . ', cache_table=' . $_bvRankDebugCacheTable . ' -->' . "\n";
unset($_bvRankDebugHelper, $_bvRankDebugCacheTable);
?>
<?php include __DIR__ . '/includes/footer.php'; ?>