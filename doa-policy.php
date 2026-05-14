<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
if (!function_exists('h')) { function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
$page_title       = "DOA Policy | Bettavaro";
$page_description = "Bettavaro DOA (Dead On Arrival) policy for live betta fish shipments: proof requirements, claim timing, exclusions, and next steps.";
$page_canonical   = "https://www.bettavaro.com/doa-policy.php";
$page_type        = "website";
$meta_robots      = "index, follow";
$inc_head   = __DIR__ . "/head.php";
$inc_menu   = __DIR__ . "/menu.php";
$inc_footer = __DIR__ . "/footer.php";
$steps = [
  ["no"=>1,"title"=>"Keep the Bag Sealed","desc"=>"If a fish appears deceased, do not open, cut, or re-bag anything before taking evidence.","bullets"=>["No cutting or opening the bag","Evidence must show the fish clearly inside the sealed bag"]],
  ["no"=>2,"title"=>"Take Clear Proof","desc"=>"Take clear photos and, ideally, a short continuous video showing the package label and the sealed fish bag.","bullets"=>["Photo of outer label or tracking","Photo or video of the sealed bag and fish","Use good lighting and avoid blurry images"]],
  ["no"=>3,"title"=>"Report Quickly","desc"=>"Claims should be reported as soon as possible after delivery so the evidence remains fair and reviewable.","bullets"=>["Submit fast after delivery","Include your order reference if available"]],
  ["no"=>4,"title"=>"Refund Scope","desc"=>"If approved, the usual refund scope is the affected fish price only. Shipping and external fees are generally not refundable.","bullets"=>["Fish price only for approved DOA","Shipping is generally non-refundable"]],
  ["no"=>5,"title"=>"What Is Not Covered","desc"=>"Claims may be declined if proof is unclear, the bag was opened, or the claim is too delayed to verify fairly.","bullets"=>["Opened bag","No usable proof","Late or incomplete claim"]],
  ["no"=>6,"title"=>"Delays and Weather","desc"=>"Customs delays, transit disruption, or weather may increase risk. Bettavaro still needs the same proof steps to review the case properly.","bullets"=>["Message us quickly if tracking shows a serious delay","Do not discard packaging immediately"]],
  ["no"=>7,"title"=>"Wrong Fish or Order Issue","desc"=>"If you believe the shipment contains the wrong fish, keep the bag sealed and contact Bettavaro before doing anything else.","bullets"=>["Keep sealed until reviewed","Send photos of the fish, bag, and label"]],
  ["no"=>8,"title"=>"How to Submit","desc"=>"Use the Bettavaro contact channel and send your order details together with all proof in one message.","bullets"=>["Order number or listing reference","Photos or video evidence","Delivery time and any tracking info"]]
];
$cta_contact = "/contact.php";
$faq_items = [
  ["q"=>"How fast should I report a DOA?","a"=>"As soon as possible after delivery. Fast reporting keeps evidence stronger and easier to verify fairly."],
  ["q"=>"Can I open the bag first?","a"=>"No. If the bag is opened before review, the claim may be rejected because the original condition cannot be verified."],
  ["q"=>"What is usually refunded?","a"=>"For an approved DOA case, the typical refund scope is the affected fish price only."],
  ["q"=>"What proof should I send?","a"=>"Send clear photos or a video showing the delivery label and the fish still inside the sealed bag."],
];
$faq_ld = ["@context"=>"https://schema.org","@type"=>"FAQPage","mainEntity"=>array_map(function($it){return ["@type"=>"Question","name"=>$it["q"],"acceptedAnswer"=>["@type"=>"Answer","text"=>$it["a"]]];},$faq_items)];
$page_ld = ["@context"=>"https://schema.org","@type"=>"WebPage","name"=>$page_title,"description"=>$page_description,"url"=>$page_canonical,"isPartOf"=>["@type"=>"WebSite","name"=>"Bettavaro","url"=>"https://www.bettavaro.com/"]];
?>
<!doctype html>
<html lang="en">
<head>

  <?php if (is_file($inc_head)) { include $inc_head; } else { ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($page_title) ?></title>
    <meta name="description" content="<?= h($page_description) ?>">
    <link rel="canonical" href="<?= h($page_canonical) ?>">
    <meta name="robots" content="<?= h($meta_robots) ?>">
  <?php } ?>
  <meta name="description" content="<?= h($page_description) ?>">
  <link rel="canonical" href="<?= h($page_canonical) ?>">
  <script type="application/ld+json"><?= json_encode($page_ld, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?></script>
  <script type="application/ld+json"><?= json_encode($faq_ld, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?></script>
  <style>
    :root{--af-bg:#0b1110;--af-card:#141c18;--af-soft:#101815;--af-text:#f2f5f3;--af-muted:#9aa5a0;--af-gold:#c6a35c;--af-gold-soft:#e7cf95;--af-line:rgba(198,163,92,.35);--radius:18px;}
    body{ background:var(--af-bg); color:var(--af-text); }
    a{ color:inherit; text-decoration:none; }
    .af-wrap{ max-width:1180px; margin:0 auto; padding:24px 18px 70px; }
    .af-kicker{ display:inline-flex; gap:10px; align-items:center; color:var(--af-gold-soft); font-weight:700; letter-spacing:.3px; }
    .af-kicker .dot{ width:10px; height:10px; border-radius:999px; background:var(--af-gold); box-shadow:0 0 0 5px rgba(198,163,92,.15); }
    .af-hero{background:linear-gradient(180deg, rgba(198,163,92,.12), transparent 70%); border:1px solid var(--af-line); border-radius: calc(var(--radius) + 6px); overflow:hidden; margin-top:18px;}
    .hero-content{ padding:26px 24px 24px; }
    h1{ margin:10px 0 10px; font-size:clamp(26px, 3vw, 40px); line-height:1.15; }
    p.sub{ color:var(--af-muted); margin:0 0 14px; font-size:1.02rem; line-height:1.6; }
    .pill-row{ display:flex; flex-wrap:wrap; gap:10px; margin-top:14px; }
    .pill{border:1px solid var(--af-line);background:rgba(20,28,24,.65);padding:9px 12px; border-radius:999px;font-weight:700;color:var(--af-gold-soft);transition: transform .14s ease, background .14s ease;}
    .pill:hover{ transform:translateY(-1px); background:rgba(198,163,92,.10); }
    .af-grid-3{ display:grid; grid-template-columns: repeat(3, 1fr); gap:14px; margin-top:16px; }
    .mini{background:rgba(20,28,24,.72);border:1px solid rgba(198,163,92,.22);border-radius:var(--radius);padding:14px 14px 13px;}
    .mini b{ color:var(--af-gold-soft); }.mini p{ margin:8px 0 0; color:var(--af-muted); line-height:1.55; font-size:.98rem; }
    .af-section{ margin-top:26px; }.af-section h2{ font-size:1.65rem; margin:0 0 10px; }.af-section .lead{ color:var(--af-muted); margin:0 0 16px; line-height:1.65; }
    .af-steps{ display:grid; grid-template-columns: repeat(2, 1fr); gap:16px; }
    .step-card{background:linear-gradient(180deg, rgba(20,28,24,.92), rgba(20,28,24,.65));border:1px solid rgba(198,163,92,.22);border-radius: calc(var(--radius) + 4px);overflow:hidden;box-shadow: 0 10px 24px rgba(0,0,0,.28);}
    .step-body{ padding:18px 18px 16px; }.step-title{ display:flex; gap:10px; align-items:flex-start; margin:0 0 8px; font-size:1.2rem; }
    .badge{flex:0 0 auto;width:34px; height:34px; border-radius:12px;display:flex; align-items:center; justify-content:center;background:rgba(198,163,92,.16);border:1px solid rgba(198,163,92,.40);color:var(--af-gold-soft);font-weight:900;}
    .step-body p{ margin:0 0 10px; color:var(--af-muted); line-height:1.62; }.step-body ul{ margin:0; padding-left:18px; color:#d7ddd9; }.step-body li{ margin:6px 0; }
    .callout{margin-top:18px;background:rgba(198,163,92,.10);border:1px solid rgba(198,163,92,.28);border-radius: calc(var(--radius) + 4px);padding:16px 16px 14px;}
    .callout h3{ margin:0 0 8px; }.callout p{ margin:0; color:var(--af-muted); line-height:1.65; }
    .faq{ margin-top:18px; display:grid; gap:10px; } details{background:rgba(20,28,24,.72);border:1px solid rgba(198,163,92,.22);border-radius:var(--radius);padding:12px 14px;} summary{ cursor:pointer; font-weight:800; color:var(--af-gold-soft); } details p{ margin:10px 0 0; color:var(--af-muted); line-height:1.65; }
    .af-video-head{display:flex; gap:10px; align-items:center; justify-content:space-between;padding:14px 16px;border-bottom:1px solid rgba(198,163,92,.18);background:rgba(10,15,13,.55);} .af-video-head .title{font-weight:900; letter-spacing:.2px; color:var(--af-gold-soft);margin:0;} .af-video-head .hint{ color:var(--af-muted); font-size:.92rem; margin:0; }
    .af-video{width:100%;aspect-ratio:16/9;background:#0a0f0d;display:block;} .af-video iframe, .af-video video{width:100%; height:100%;display:block;border:0;}
    @media (max-width: 920px){.af-grid-3{ grid-template-columns: 1fr; }.af-steps{ grid-template-columns: 1fr; }}
  </style>
</head>
<body>
<?php
include __DIR__ . '/includes/head.php';
include __DIR__ . '/includes/menu.php';
?>
  <?php if (is_file($inc_menu)) { include $inc_menu; } ?>
  <main class="af-wrap">
    <section class="af-hero">
      <div class="hero-content">
        <div class="af-kicker"><span class="dot"></span> Bettavaro • Live Fish Policy • Clear Claim Rules</div>
        <h1>DOA Policy <span style="color:var(--af-gold); font-weight:900;">(Dead On Arrival)</span></h1>
        <p class="sub">This page explains how Bettavaro reviews DOA claims for live fish shipments. The big rule is simple: <strong>keep the bag sealed, take clear proof, and report quickly</strong>.</p>
        <div class="pill-row">
          <a class="pill" href="#steps">See claim steps</a>
          <a class="pill" href="#faq">Read FAQ</a>
          <a class="pill" href="<?= h($cta_contact) ?>">Contact Bettavaro</a>
        </div>
        <div class="af-grid-3" style="margin-top:16px;">
          <div class="mini"><b>Keep sealed</b><p>Do not open the fish bag before evidence is captured.</p></div>
          <div class="mini"><b>Report quickly</b><p>Fast claims are easier to review fairly.</p></div>
          <div class="mini"><b>Refund scope</b><p>Approved DOA refunds usually cover the fish price only.</p></div>
        </div>
    </section>
    <section class="af-section" id="steps">
      <h2>DOA Claim Steps</h2>
      <p class="lead">Follow these steps in order. With live fish claims, evidence is king and guesswork is trash.</p>
      <div class="af-steps"><?php foreach ($steps as $s): ?><article class="step-card"><div class="step-body"><h3 class="step-title"><span class="badge"><?= (int)$s['no'] ?></span><span><?= $s['title'] ?></span></h3><p><?= $s['desc'] ?></p><?php if (!empty($s['bullets'])): ?><ul><?php foreach ($s['bullets'] as $b): ?><li><?= $b ?></li><?php endforeach; ?></ul><?php endif; ?></div></article><?php endforeach; ?></div>
      <div class="callout"><h3>Best practice</h3><p>Before opening the package, record a short video showing the outer label, the sealed bag, and the fish inside. That tiny habit saves huge nonsense later.</p></div>
    </section>
    <section class="af-section" id="faq"><h2>FAQ</h2><p class="lead">Quick answers for the common DOA questions.</p><div class="faq"><?php foreach ($faq_items as $it): ?><details><summary><?= h($it['q']) ?></summary><p><?= h($it['a']) ?></p></details><?php endforeach; ?></div></section>
  </main>
  <?php if (is_file($inc_footer)) { include $inc_footer; } ?>
<?php
include __DIR__ . '/includes/footer.php';
?>  
</body>
</html>
