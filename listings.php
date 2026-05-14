<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cart_lib.php';
require_once __DIR__ . '/includes/seller_discount.php';

// Listing reviews — safe load
$_bvReviewLibLs = __DIR__ . '/includes/listing_reviews.php';
if (is_file($_bvReviewLibLs)) {
    require_once $_bvReviewLibLs;
}
unset($_bvReviewLibLs);

if (!function_exists('bv_home_card_review_html')) {
    function bv_home_card_review_html(int $listingId): string
    {
        static $cache = [];
        if ($listingId <= 0) {
            return '';
        }
        if (array_key_exists($listingId, $cache)) {
            return $cache[$listingId];
        }
        if (!function_exists('bv_listing_review_summary_for_listing')
            || !function_exists('bv_listing_review_table_exists')
            || !bv_listing_review_table_exists('listing_reviews')) {
            return $cache[$listingId] = '';
        }
        try {
            $s     = bv_listing_review_summary_for_listing($listingId);
            $count = (int) ($s['total_reviews'] ?? 0);
            $avg   = (float) ($s['avg_rating']   ?? 0);
        } catch (Throwable $e) {
            return $cache[$listingId] = '';
        }
        if ($count <= 0) {
            return $cache[$listingId] = '';
        }
        $filled  = max(0, min(5, (int) round($avg)));
        $stars   = str_repeat('★', $filled) . str_repeat('☆', 5 - $filled);
        $avgFmt  = htmlspecialchars(number_format($avg, 1), ENT_QUOTES, 'UTF-8');
        $label   = htmlspecialchars($count . ' review' . ($count === 1 ? '' : 's'), ENT_QUOTES, 'UTF-8');
        $html    = '<div class="listing-card-rating">'
                 . '<span class="listing-card-stars" aria-hidden="true">' . $stars . '</span>'
                 . '<span class="listing-card-avg">' . $avgFmt . '</span>'
                 . '<span class="listing-card-count">(' . $label . ')</span>'
                 . '</div>';
        return $cache[$listingId] = $html;
    }
}

$pageTitle = 'Betta Fish for Sale | Premium Betta Marketplace | Bettavaro';
$metaDescription = 'Explore premium betta fish for sale from trusted breeders on Bettavaro. Find Halfmoon, Koi, Giant, Fancy, wild type, auction, fixed-price, and best-offer bettas for collectors worldwide.';
$canonicalUrl = defined('APP_URL') ? rtrim((string) APP_URL, '/') . '/listings.php' : 'https://www.bettavaro.com/listings.php';
$metaRobots = 'index,follow,max-image-preview:large';
$ogImage = 'https://www.bettavaro.com/assets/img/og-listings.jpg';

if (!function_exists('bv_e')) {
    function bv_e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bv_public_listing_url')) {
    function bv_public_listing_url(array $row): string
    {
        $slug = trim((string)($row['slug'] ?? ''));
        if ($slug !== '') {
            return '/listing.php?slug=' . rawurlencode($slug);
        }

        $id = (int)($row['id'] ?? 0);
        return $id > 0 ? '/listing.php?id=' . $id : '/listings.php';
    }
}

if (!function_exists('bv_resolve_image_url')) {
    function bv_resolve_image_url(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $path)) {
            return $path;
        }
        if (strpos($path, '//') === 0) {
            return 'https:' . $path;
        }
        if ($path[0] === '/') {
            return $path;
        }
        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('bv_has_column')) {
    function bv_has_column(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
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

if (!function_exists('bv_effective_sale_status')) {
    function bv_effective_sale_status(?string $saleStatus, ?string $status): string
    {
        $saleStatus = strtolower(trim((string)$saleStatus));
        $status = strtolower(trim((string)$status));

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

if (!function_exists('bv_public_sale_state')) {
    function bv_public_sale_state(string $displayStatus): array
    {
        $displayStatus = strtolower(trim($displayStatus));

        if (in_array($displayStatus, ['sold', 'completed'], true)) {
            return [
                'text' => 'Sold · Contact us for similar fish',
                'class' => 'status-sold',
                'pill' => 'Sold',
                'action_label' => 'View Similar Options',
                'show_reserve' => false,
                'show_similar' => true,
            ];
        }

        if (in_array($displayStatus, ['reserved', 'awaiting_payment', 'paid'], true)) {
            return [
                'text' => 'Reserved / on hold pending payment',
                'class' => 'status-hold',
                'pill' => 'On Hold',
                'action_label' => 'View Details',
                'show_reserve' => false,
                'show_similar' => false,
            ];
        }

        return [
            'text' => 'Available now · Ready to reserve',
            'class' => 'status-available',
            'pill' => 'Available',
            'action_label' => 'View & Reserve',
            'show_reserve' => true,
            'show_similar' => false,
        ];
    }
}

if (!function_exists('bv_money_safe')) {
    function bv_money_safe($price, ?string $currency = null): string
    {
        if ($price === null || $price === '') {
            return 'Contact for price';
        }
        if (function_exists('money') && is_numeric($price)) {
            return money((float)$price, (string)($currency ?: 'USD'));
        }
        if (is_numeric($price)) {
            return strtoupper((string)($currency ?: 'USD')) . ' ' . number_format((float)$price, 2);
        }
        return (string)$price;
    }
}

$fallbackImage = function_exists('asset_url') ? asset_url('images/placeholder-fish.jpg') : '/assets/images/placeholder-fish.jpg';
$colStockTotal = bv_has_column($pdo, 'listings', 'stock_total') ? 'stock_total' : (bv_has_column($pdo, 'listings', 'stock') ? 'stock' : (bv_has_column($pdo, 'listings', 'quantity') ? 'quantity' : (bv_has_column($pdo, 'listings', 'qty') ? 'qty' : null)));
$colStockSold = bv_has_column($pdo, 'listings', 'stock_sold') ? 'stock_sold' : (bv_has_column($pdo, 'listings', 'sold_qty') ? 'sold_qty' : (bv_has_column($pdo, 'listings', 'qty_sold') ? 'qty_sold' : null));
$colStockAvailable = bv_has_column($pdo, 'listings', 'stock_available') ? 'stock_available' : (bv_has_column($pdo, 'listings', 'available_qty') ? 'available_qty' : (bv_has_column($pdo, 'listings', 'qty_available') ? 'qty_available' : null));

$listings = [];
$counts = [
    'all' => 0,
    'available' => 0,
    'reserved' => 0,
    'sold' => 0,
    'stock_total' => 0,
    'stock_sold' => 0,
    'stock_available' => 0,
];

try {
    $sqlSelect = [
        'id',
        'seller_id',
        'title',
        'slug',
        'short_description',
        'species',
        'strain',
        'grade',
        'price',
        'currency',
        'country',
        'city',
        'cover_image',
        'featured',
        'status',
        'sale_status',
        $colStockTotal ? '`' . $colStockTotal . '` AS stock_total' : 'NULL AS stock_total',
        $colStockSold ? '`' . $colStockSold . '` AS stock_sold' : 'NULL AS stock_sold',
        $colStockAvailable ? '`' . $colStockAvailable . '` AS stock_available' : 'NULL AS stock_available',
        'created_at',
    ];

    $sql = "
        SELECT
            " . implode(",
            ", $sqlSelect) . "
        FROM listings
        WHERE status IN ('active', 'sold')
        ORDER BY featured DESC, created_at DESC, id DESC
    ";

    $stmt = $pdo->query($sql);
    $listings = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $listings = [];
}

$listings = seller_discount_apply_to_listing_rows($listings, $pdo);

foreach ($listings as $row) {
    $counts['all']++;
    $displayStatus = bv_effective_sale_status($row['sale_status'] ?? '', $row['status'] ?? '');
    $stock = bv_derive_stock($row);
    $counts['stock_total'] += (int) ($stock['total'] ?? 0);
    $counts['stock_sold'] += (int) ($stock['sold'] ?? 0);
    $counts['stock_available'] += (int) ($stock['available'] ?? 0);

    if (
        $displayStatus === 'sold'
        || (
            ($stock['available'] ?? 0) <= 0
            && ($stock['total'] ?? 0) > 0
            && !in_array($displayStatus, ['reserved', 'awaiting_payment', 'paid'], true)
        )
    ) {
        $displayStatus = 'sold';
    } elseif (in_array($displayStatus, ['reserved', 'awaiting_payment', 'paid'], true)) {
        $displayStatus = 'reserved';
    } else {
        $displayStatus = 'available';
    }

    if (isset($counts[$displayStatus])) {
        $counts[$displayStatus]++;
    }
}

$listingsJsonLd = json_encode([
    [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => 'Betta Fish for Sale',
        'url' => $canonicalUrl,
        'description' => $metaDescription,
        'isPartOf' => [
            '@type' => 'WebSite',
            'name' => 'Bettavaro',
            'url' => 'https://www.bettavaro.com/',
        ],
        'about' => [
            '@type' => 'Thing',
            'name' => 'Betta fish',
        ],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Home',
                'item' => 'https://www.bettavaro.com/',
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => 'Betta Fish for Sale',
                'item' => $canonicalUrl,
            ],
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<?php include __DIR__ . '/includes/head.php'; ?>
<?php include __DIR__ . '/includes/menu.php'; ?>
<?php if (!empty($listingsJsonLd)): ?>
<script type="application/ld+json">
<?= $listingsJsonLd . "\n" ?>
</script>
<?php endif; ?>
<style>
:root{
    --bv-bg:#07110c;
    --bv-bg-2:#0d1b14;
    --bv-card:#ffffff;
    --bv-line:#e5e7eb;
    --bv-ink:#0f172a;
    --bv-soft:#64748b;
    --bv-gold:#d8be73;
    --bv-gold-2:#f4ead0;
    --bv-green:#204c31;
    --bv-green-2:#e8f6ed;
    --bv-shadow:0 18px 45px rgba(0,0,0,.10);
}
body{
    background:radial-gradient(circle at top, #102118 0%, var(--bv-bg) 34%, #050906 100%);
}
.listings-shell{max-width:1320px;margin:0 auto;padding:26px 16px 64px}
.market-hero{
    position:relative;overflow:hidden;border-radius:30px;padding:34px 30px;margin-bottom:24px;
    background:linear-gradient(135deg, rgba(255,255,255,.12), rgba(255,255,255,.06)), linear-gradient(135deg, #13271c, #0c1510 58%, #1f2f25);
    border:1px solid rgba(255,255,255,.12);box-shadow:0 24px 70px rgba(0,0,0,.18);color:#fff;
}
.market-hero:before{
    content:'';position:absolute;right:-70px;top:-70px;width:220px;height:220px;border-radius:999px;background:rgba(216,190,115,.12);filter:blur(4px)
}
.market-hero-grid{position:relative;display:grid;grid-template-columns:minmax(0,1.15fr) minmax(280px,.85fr);gap:24px;align-items:end}
.market-eyebrow{display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:999px;background:rgba(216,190,115,.15);border:1px solid rgba(216,190,115,.26);color:#f8efcf;font-size:13px;font-weight:800;letter-spacing:.03em;text-transform:uppercase}
.market-title{margin:14px 0 10px;font-size:54px;line-height:1.02;letter-spacing:-.04em}
.market-subtitle{max-width:760px;margin:0;color:#d9e6df;font-size:18px;line-height:1.8}
.market-points{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.market-point{display:inline-flex;align-items:center;padding:10px 14px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.10);font-size:14px;font-weight:700;color:#f8fafc}
.market-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.stock-stat-box strong{color:#f8efcf}
.stat-box{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:18px 18px 16px;backdrop-filter:blur(8px)}
.stat-box strong{display:block;font-size:32px;line-height:1;font-weight:900;color:#fff}
.stat-box span{display:block;margin-top:8px;color:#d1ddd7;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.controls-wrap{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center;margin-bottom:18px}
.search-box{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid rgba(255,255,255,.2);border-radius:18px;padding:0 16px;min-height:58px;box-shadow:var(--bv-shadow)}
.search-box input{width:100%;border:none;outline:none;background:transparent;font-size:16px;color:#0f172a}
.filter-chips{display:flex;gap:10px;flex-wrap:wrap}
.filter-chip{border:none;border-radius:999px;padding:11px 16px;background:#edf1ee;color:#204c31;font-weight:800;cursor:pointer}
.filter-chip.is-active{background:#204c31;color:#fff;box-shadow:0 10px 24px rgba(32,76,49,.24)}
.results-meta{display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:18px;color:#d3ddd7}
.results-meta strong{color:#fff}
.listing-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(295px,1fr));gap:22px}
.listing-card{position:relative;background:rgba(255,255,255,.98);border:1px solid rgba(255,255,255,.55);border-radius:24px;overflow:hidden;box-shadow:0 18px 45px rgba(0,0,0,.12);transition:transform .2s ease, box-shadow .2s ease}
.listing-card:hover{transform:translateY(-4px);box-shadow:0 28px 60px rgba(0,0,0,.17)}
.listing-card a{text-decoration:none}
.listing-card-media{position:relative;background:#f4f4f5}
.listing-card-media img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;background:#f3f4f6}
.listing-pill{position:absolute;left:14px;top:14px;z-index:2;display:inline-flex;align-items:center;padding:9px 12px;border-radius:999px;font-size:12px;font-weight:900;letter-spacing:.03em;text-transform:uppercase;box-shadow:0 8px 18px rgba(0,0,0,.12)}
.pill-featured{right:14px;left:auto;background:#1f2937;color:#fff}
.listing-body{padding:20px}
.listing-meta{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:13px;color:#64748b;margin-bottom:10px}
.listing-title{margin:0 0 10px;font-size:24px;line-height:1.22;letter-spacing:-.02em}
.listing-title a{color:#0f172a}
.listing-copy{margin:0;color:#475569;line-height:1.7;min-height:48px}
.listing-card-rating{display:flex;align-items:center;gap:5px;margin:6px 0 10px;font-size:13px;line-height:1}
.listing-card-stars{color:#f59e0b;letter-spacing:1px;font-size:14px}
.listing-card-avg{color:#166534;font-weight:700}
.listing-card-count{color:#64748b;font-size:12px}
.stock-strip{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}
.stock-chip{border:1px solid var(--bv-line);border-radius:14px;padding:10px 10px 9px;background:linear-gradient(180deg,#fcfcfc,#f4f4f5)}
.stock-chip strong{display:block;font-size:20px;line-height:1;color:#0f172a;font-weight:900}
.stock-chip span{display:block;margin-top:6px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;font-weight:800}
.stock-note{margin-top:10px;font-size:13px;color:#475569;font-weight:700}
.price-row{display:flex;justify-content:space-between;gap:12px;align-items:end;margin-top:14px}
.price-block{display:flex;flex-direction:column;gap:4px}
.price{font-size:28px;font-weight:900;color:#203a28;line-height:1.1}
.price-original{font-size:14px;font-weight:700;color:#64748b;text-decoration:line-through}
.discount-pill{display:inline-flex;align-items:center;margin-top:6px;padding:6px 10px;border-radius:999px;background:#ecfdf5;color:#065f46;font-size:12px;font-weight:900}
.quick-tag{font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
.listing-card-status{margin:14px 0 0;padding:11px 13px;border-radius:14px;font-size:14px;font-weight:800;line-height:1.45;border:1px solid transparent}
.status-available{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.status-hold{background:#fff7ed;border-color:#fdba74;color:#9a3412}
.status-sold{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
.listing-card-actions{margin-top:16px;display:flex;gap:10px;flex-wrap:wrap}
.listing-card-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 15px;border-radius:14px;font-weight:800;text-decoration:none;transition:transform .16s ease, box-shadow .16s ease}
.listing-card-btn:hover{transform:translateY(-1px)}
.listing-card-btn.primary{background:#203a28;color:#fff;box-shadow:0 10px 24px rgba(32,58,40,.18)}
.listing-card-btn.alt{background:#fff;color:#0f172a;border:1px solid #cbd5e1}
.empty-state{background:#fff;border:1px solid rgba(255,255,255,.45);border-radius:22px;padding:26px;box-shadow:var(--bv-shadow)}
.empty-state h3{margin-top:0}
.sale-trust{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:26px}
.stock-stats-wide{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.trust-box{background:#fff;border:1px solid rgba(255,255,255,.5);border-radius:20px;padding:18px 18px 16px;box-shadow:var(--bv-shadow)}
.trust-box h3{margin:0 0 8px;font-size:18px;color:#0f172a}
.trust-box p{margin:0;color:#475569;line-height:1.7}
.hidden-card{display:none !important}
@media (max-width:980px){
    .market-hero-grid{grid-template-columns:1fr}
    .market-title{font-size:42px}
    .controls-wrap{grid-template-columns:1fr}
}
@media (max-width:720px){
    .listings-shell{padding:18px 12px 44px}
    .market-hero{padding:24px 18px}
    .market-title{font-size:34px}
    .market-subtitle{font-size:16px}
    .market-stats{grid-template-columns:1fr 1fr}
    .sale-trust,.stock-strip{grid-template-columns:1fr}
}
.listing-card-btn.primary {
    background: linear-gradient(135deg, #1f2937, #000);
    color: #fff;
    font-weight: 900;
}
.listing-card-btn.alt {
    background: #fff;
    border: 1px solid #111827;
    color: #111827;
}
.listing-card-btn:hover {
    transform: translateY(-2px);
}
</style>


<main class="listings-shell">
    <section class="market-hero">
        <div class="market-hero-grid">
            <div>
                <div class="market-eyebrow">Premium Betta Marketplace</div>
                 <h1 class="market-title">Betta Fish for Sale</h1>
                <p class="market-subtitle">
                    Browse available, reserved, and sold betta fish with clear status, strong presentation, and an easier path from discovery to reservation.
                </p>
                <div class="market-points">
                    <span class="market-point">Collector-focused</span>
                    <span class="market-point">Clear availability</span>
                    <span class="market-point">International shipping support</span>
                </div>
            </div>
            <div class="market-stats">
                <div class="stat-box stock-stat-box">
                    <strong><?= (int)$counts['stock_total']; ?></strong>
                    <span>Total Stock</span>
                </div>
                <div class="stat-box stock-stat-box">
                    <strong><?= (int)$counts['stock_available']; ?></strong>
                    <span>Remaining</span>
                </div>
                <div class="stat-box stock-stat-box">
                    <strong><?= (int)$counts['stock_sold']; ?></strong>
                    <span>Sold</span>
                </div>
                <div class="stat-box">
                    <strong><?= (int)$counts['all']; ?></strong>
                    <span>Total Listings</span>
                </div>
            </div>
        </div>
    </section>

    <div class="controls-wrap">
        <label class="search-box" aria-label="Search listings">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
            <input type="text" id="listingSearch" placeholder="Search by title, strain, location, grade...">
        </label>
        <div class="filter-chips" id="statusFilters">
            <button class="filter-chip is-active" type="button" data-filter="all">All (<?= (int)$counts['all']; ?>)</button>
            <button class="filter-chip" type="button" data-filter="available">Available (<?= (int)$counts['available']; ?>)</button>
            <button class="filter-chip" type="button" data-filter="reserved">Reserved (<?= (int)$counts['reserved']; ?>)</button>
            <button class="filter-chip" type="button" data-filter="sold">Sold (<?= (int)$counts['sold']; ?>)</button>
        </div>
    </div>

    <div class="results-meta">
        <div><strong id="visibleCount"><?= (int)$counts['all']; ?></strong> listing(s) showing</div>
        <div>Tip: start with listings that still show remaining stock for the fastest route to reserve.</div>
    </div>

    <?php if (!$listings): ?>
        <div class="empty-state">
            <h3>No listings yet</h3>
            <p>The marketplace is being prepared. New premium listings will appear soon.</p>
        </div>
    <?php else: ?>
        <div class="listing-grid" id="listingGrid">
            <?php foreach ($listings as $item): ?>
                <?php
                $listingUrl = bv_public_listing_url($item);
                $stock = bv_derive_stock($item);
                $stockTotal = (int) ($stock['total'] ?? 0);
                $stockSold = (int) ($stock['sold'] ?? 0);
                $stockAvailable = (int) ($stock['available'] ?? 0);

                $displayStatus = bv_effective_sale_status($item['sale_status'] ?? '', $item['status'] ?? '');
                if (
                    $displayStatus === 'sold'
                    || (
                        $stockAvailable <= 0
                        && $stockTotal > 0
                        && !in_array($displayStatus, ['reserved', 'awaiting_payment', 'paid'], true)
                    )
                ) {
                    $displayStatus = 'sold';
                } elseif (in_array($displayStatus, ['reserved', 'awaiting_payment', 'paid'], true)) {
                    $displayStatus = 'reserved';
                } else {
                    $displayStatus = 'available';
                }

                $publicState = bv_public_sale_state($displayStatus);
                if ($displayStatus === 'reserved') {
                    $publicState['text'] = 'Reserved right now · Temporarily on hold';
                } elseif ($stockAvailable > 0 && $stockAvailable < $stockTotal) {
                    $publicState['text'] = 'Available now · Remaining ' . $stockAvailable . ' of ' . $stockTotal;
                } elseif ($displayStatus === 'sold' || ($stockAvailable <= 0 && $stockTotal > 0)) {
                    $publicState['text'] = 'Sold out · No remaining stock right now';
                }

                $location = trim(((string)($item['city'] ?? '')) . ', ' . ((string)($item['country'] ?? '')), ', ');
                $imageUrl = bv_resolve_image_url($item['cover_image'] ?? '');
                if ($imageUrl === '') {
                    $imageUrl = $fallbackImage;
                }

                $gradeOrTier = trim((string)($item['grade'] ?? ''));
                $searchBlob = strtolower(trim(implode(' ', [
                    (string)($item['title'] ?? ''),
                    (string)($item['short_description'] ?? ''),
                    (string)($item['species'] ?? ''),
                    (string)($item['strain'] ?? ''),
                    (string)($item['grade'] ?? ''),
                    (string)($item['city'] ?? ''),
                    (string)($item['country'] ?? ''),
                ])));

                $hasSellerDiscount = !empty($item['has_seller_discount']) && (float)($item['seller_discount_amount'] ?? 0) > 0;
                $basePrice = $item['price'] ?? null;
                $finalPrice = $item['final_price'] ?? $basePrice;
                $discountPercent = (float)($item['seller_discount_percent'] ?? 0);
                ?>
                <article
                    class="listing-card"
                    data-status="<?= bv_e($displayStatus); ?>"
                    data-search="<?= bv_e($searchBlob); ?>"
                >
                    <div class="listing-card-media">
                        <span class="listing-pill <?= bv_e((string)$publicState['class']); ?>"><?= bv_e((string)$publicState['pill']); ?></span>
                        <?php if ((int)($item['featured'] ?? 0) === 1): ?>
                            <span class="listing-pill pill-featured">Featured</span>
                        <?php endif; ?>
                        <a href="<?= bv_e($listingUrl); ?>">
                            <img
                                src="<?= bv_e($imageUrl); ?>"
                                alt="<?= bv_e((string)($item['title'] ?? 'Listing')); ?>"
                                loading="lazy"
                                onerror="this.onerror=null;this.src='<?= bv_e($fallbackImage); ?>';"
                            >
                        </a>
                    </div>

                    <div class="listing-body">
                        <div class="listing-meta">
                            <span><?= bv_e($location !== '' ? $location : 'International'); ?></span>
                            <span><?= bv_e($gradeOrTier !== '' ? $gradeOrTier : 'Premium Listing'); ?></span>
                        </div>

                        <h2 class="listing-title">
                            <a href="<?= bv_e($listingUrl); ?>">
                                <?= bv_e((string)($item['title'] ?? 'Untitled Listing')); ?>
                            </a>
                        </h2>
                        <?= bv_home_card_review_html((int)($item['id'] ?? 0)) ?>

                        <p class="listing-copy">
                            <?= bv_e(!empty($item['short_description']) ? (string)$item['short_description'] : 'Collector-grade betta listing with clear details and a direct path to reserve or inquire.'); ?>
                        </p>

                        <div class="price-row">
                            <div class="price-block">
                                <div class="quick-tag">Price</div>
                                <div class="price"><?= bv_e(bv_money_safe($hasSellerDiscount ? $finalPrice : $basePrice, (string)($item['currency'] ?? 'USD'))); ?></div>
                                <?php if ($hasSellerDiscount && is_numeric($basePrice)): ?>
                                    <div class="price-original"><?= bv_e(bv_money_safe($basePrice, (string)($item['currency'] ?? 'USD'))); ?></div>
                                    <div class="discount-pill">Save <?= bv_e(number_format($discountPercent, 2)); ?>%</div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($item['strain'])): ?>
                                <div class="quick-tag"><?= bv_e((string)$item['strain']); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="stock-strip">
                            <div class="stock-chip">
                                <strong><?= $stockTotal; ?></strong>
                                <span>Total</span>
                            </div>
                            <div class="stock-chip">
                                <strong><?= $stockSold; ?></strong>
                                <span>Sold</span>
                            </div>
                            <div class="stock-chip">
                                <strong><?= $stockAvailable; ?></strong>
                                <span>Remaining</span>
                            </div>
                        </div>

                        <div class="stock-note">
                            <?= $stockAvailable > 0 ? 'Ready to reserve now: ' . $stockAvailable . ' item(s).' : 'Currently no stock left in this listing.'; ?>
                        </div>

                        <div class="listing-card-status <?= bv_e((string)$publicState['class']); ?>">
                            <?= bv_e((string)$publicState['text']); ?>
                        </div>

                        <div class="listing-card-actions">
                            <?php if ($displayStatus === 'available' && $stockAvailable > 0): ?>
                                <form method="post" action="/cart_add.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(btv_cart_csrf_token()) ?>">
                                    <input type="hidden" name="listing_id" value="<?= (int)$item['id']; ?>">
                                    <input type="hidden" name="qty" value="1">
                                    <input type="hidden" name="redirect" value="/checkout.php">
                                    <button class="listing-card-btn primary" type="submit">
                                        Checkout Now
                                    </button>
                                </form>

                                <form method="post" action="/cart_add.php" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(btv_cart_csrf_token()) ?>">
                                    <input type="hidden" name="listing_id" value="<?= (int)$item['id']; ?>">
                                    <input type="hidden" name="qty" value="1">
                                    <input type="hidden" name="redirect" value="/cart.php">
                                    <button class="listing-card-btn alt" type="submit">
                                        Add to Cart
                                    </button>
                                </form>
                            <?php else: ?>
                                <a class="listing-card-btn alt" href="/contact.php?topic=similar-fish&amp;listing_id=<?= (int)$item['id']; ?>">
                                    Find Similar
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="sale-trust">
        <article class="trust-box">
            <h3>Clearer buying flow</h3>
            <p>Visitors can immediately see whether a fish is available, on hold, or sold without guessing from mixed statuses.</p>
        </article>
        <article class="trust-box">
            <h3>Stronger sales presentation</h3>
            <p>Cards are more premium, more visual, and give a direct path to details and reservation instead of making buyers hunt around.</p>
        </article>
        <article class="trust-box">
            <h3>Better shopper control</h3>
            <p>Search and status filters help serious buyers move quickly. Less scrolling, less confusion, more action. ปลาไม่ควรขายยากเพราะ UI งง 😄</p>
        </article>
    </section>
</main>

<script>
(function(){
    var grid = document.getElementById('listingGrid');
    if (!grid) return;

    var cards = Array.prototype.slice.call(grid.querySelectorAll('.listing-card'));
    var chips = Array.prototype.slice.call(document.querySelectorAll('#statusFilters .filter-chip'));
    var searchInput = document.getElementById('listingSearch');
    var visibleCount = document.getElementById('visibleCount');
    var activeFilter = 'all';

    function applyFilters() {
        var keyword = searchInput ? String(searchInput.value || '').toLowerCase().trim() : '';
        var visible = 0;

        cards.forEach(function(card){
            var status = String(card.getAttribute('data-status') || '');
            var search = String(card.getAttribute('data-search') || '');
            var statusMatch = activeFilter === 'all' ? true : status === activeFilter;
            var searchMatch = keyword === '' ? true : search.indexOf(keyword) !== -1;
            var show = statusMatch && searchMatch;
            card.classList.toggle('hidden-card', !show);
            if (show) visible++;
        });

        if (visibleCount) {
            visibleCount.textContent = String(visible);
        }
    }

    chips.forEach(function(chip){
        chip.addEventListener('click', function(){
            chips.forEach(function(item){ item.classList.remove('is-active'); });
            chip.classList.add('is-active');
            activeFilter = String(chip.getAttribute('data-filter') || 'all');
            applyFilters();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    applyFilters();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>