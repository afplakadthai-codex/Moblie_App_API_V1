<?php
$page_title = "Shipping Policy | Bettavaro";
$page_description = "Bettavaro shipping policy for live betta fish orders, including packing standards, transit estimates, and import responsibility.";
$page_canonical = "https://www.bettavaro.com/shipping-policy.php";
$effective_date = "March 26, 2026";

include("head.php");
include("menu.php");
?>

<style>
  :root{
    --bv-bg:#0b1110;
    --bv-card:#121b18;
    --bv-soft:#17221f;
    --bv-line:rgba(198,163,92,.22);
    --bv-line-strong:rgba(198,163,92,.36);
    --bv-text:#eef3ef;
    --bv-muted:#a4b0aa;
    --bv-gold:#c6a35c;
    --bv-gold-soft:#e7cf95;
    --bv-max:1180px;
    --bv-radius:20px;
    --bv-shadow:0 20px 40px rgba(0,0,0,.28);
  }
  body{background:var(--bv-bg);color:var(--bv-text);}
  .legal-wrap{max-width:var(--bv-max);margin:0 auto;padding:0 18px;}
  .legal-hero{padding:28px 0 18px;background:linear-gradient(180deg,rgba(198,163,92,.12),transparent 72%);border-bottom:1px solid rgba(198,163,92,.12);}
  .legal-breadcrumb{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;font-size:.95rem;color:var(--bv-muted);}
  .legal-breadcrumb a{color:var(--bv-gold-soft);text-decoration:none;}
  .crumb-dot{width:5px;height:5px;border-radius:999px;background:rgba(198,163,92,.7);display:inline-block;}
  .legal-title{margin:0;font-size:clamp(2rem,4vw,3.4rem);line-height:1.08;color:#fff;}
  .legal-sub{max-width:820px;margin:12px 0 0;color:var(--bv-muted);font-size:1.04rem;line-height:1.7;}
  .legal-chiprow{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px;}
  .legal-chip{padding:9px 13px;border-radius:999px;background:rgba(18,27,24,.76);border:1px solid var(--bv-line);color:var(--bv-gold-soft);font-weight:700;font-size:.92rem;}
  .legal-main{padding:28px 0 68px;}
  .legal-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(290px,.8fr);gap:20px;align-items:start;}
  .legal-panel,.legal-card,.legal-callout{background:linear-gradient(180deg,rgba(18,27,24,.92),rgba(18,27,24,.76));border:1px solid var(--bv-line);border-radius:calc(var(--bv-radius) + 2px);box-shadow:var(--bv-shadow);}
  .panel-head{padding:20px 22px 16px;border-bottom:1px solid rgba(198,163,92,.14);display:flex;justify-content:space-between;gap:16px;align-items:end;flex-wrap:wrap;}
  .panel-head h2{margin:0;font-size:1.35rem;color:var(--bv-gold-soft);}
  .panel-head p{margin:0;color:var(--bv-muted);}
  .legal-body{padding:22px;}
  .legal-body h3{margin:0 0 10px;color:#fff;font-size:1.05rem;}
  .legal-body p,.legal-body li{color:var(--bv-muted);line-height:1.75;}
  .legal-body p{margin:0 0 18px;}
  .legal-body ul{margin:0 0 20px;padding-left:20px;}
  .legal-body a,.legal-card a,.legal-callout a{color:var(--bv-gold-soft);}
  .legal-cardstack{display:grid;gap:16px;}
  .legal-card{padding:18px;}
  .legal-card .k{color:var(--bv-gold-soft);font-weight:800;margin-bottom:8px;letter-spacing:.2px;}
  .legal-card .v{color:var(--bv-muted);line-height:1.7;}
  .legal-callout{padding:18px;}
  .legal-callout strong{color:#fff;}
  .legal-note{margin-top:18px;padding:14px 16px;border-radius:16px;background:rgba(198,163,92,.08);border:1px solid rgba(198,163,92,.18);color:var(--bv-muted);line-height:1.7;}
  .legal-cta{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;}
  .af-btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 16px;border-radius:999px;background:var(--bv-gold);color:#111;text-decoration:none;font-weight:800;border:1px solid rgba(198,163,92,.45);}
  .af-btn.secondary{background:transparent;color:var(--bv-gold-soft);border-color:rgba(198,163,92,.28);}
  .policy-index{margin:0;padding-left:18px;}
  .policy-index li{margin:0 0 12px;}
  @media (max-width: 900px){
    .legal-grid{grid-template-columns:1fr;}
    .panel-head{align-items:start;}
  }
</style>


<header class="legal-hero">
  <div class="legal-wrap">
    <nav class="legal-breadcrumb">
      <a href="/">Home</a><span class="crumb-dot"></span>
      <a href="/legal.php">Legal</a><span class="crumb-dot"></span>
      <span>Shipping Policy</span>
    </nav>

    <h1 class="legal-title">Shipping Policy</h1>
    <p class="legal-sub">
      Bettavaro shipping is built around safe presentation and realistic expectations. This page explains how live fish are packed,
      what transit timing usually looks like, and where buyer responsibility begins.
    </p>

    <div class="legal-chiprow">
      <span class="legal-chip">Packing Standards</span>
      <span class="legal-chip">Transit Estimates</span>
      <span class="legal-chip">Import Responsibility</span>
      <span class="legal-chip">Live Fish Care</span>
    </div>
  </div>
</header>

<main class="legal-main">
  <div class="legal-wrap legal-grid">
    <article class="legal-panel">
      <div class="panel-head">
        <h2>Shipping & Handling</h2>
        <p>Effective Date: <strong><?php echo htmlspecialchars($effective_date); ?></strong></p>
      </div>

      <div class="legal-body">
        <h3>1. Packing</h3>
        <ul>
          <li>Fish may be packed individually in sealed bags with appropriate preparation for transit</li>
          <li>Protective materials may be used depending on route, weather, and shipment type</li>
          <li>Shipment timing may be adjusted when conditions are unsafe for live fish</li>
        </ul>

        <h3>2. Processing Time</h3>
        <p>
          Bettavaro may dispatch orders only after payment review, seller readiness, weather review, and other operational checks.
          Shipment timing may vary based on the nature of the listing and export route.
        </p>

        <h3>3. Transit Estimates</h3>
        <ul>
          <li>Domestic Thailand: often around 1–2 business days depending on route</li>
          <li>International: often around 5–7 business days, but not guaranteed</li>
        </ul>

        <h3>4. Customs and Import Rules</h3>
        <p>
          International customers are responsible for permits, broker arrangements, customs clearance, taxes, fees,
          and compliance with import law in their own country.
        </p>

        <h3>5. Risk and Delivery</h3>
        <p>
          Risk associated with transit may pass once the shipment is handed to the carrier or trans-shipping route,
          depending on the order structure and agreed delivery method.
        </p>

        <h3>6. DOA and Refund Claims</h3>
        <p>
          DOA-related questions are governed together with the <a href="/refund-policy.php">Refund Policy</a>
          and <a href="/doa-policy.php">DOA Policy</a>.
        </p>

        <h3>7. Contact</h3>
        <p>
          For shipping support, use <a href="/contact.php">the Bettavaro contact page</a>.
        </p>
      </div>
    </article>

    <aside class="legal-cardstack">
      <div class="legal-card">
        <div class="k">Quick Summary</div>
        <div class="v">
          Packing varies by route<br>
          Transit time is estimated, not guaranteed<br>
          Buyer handles import compliance
        </div>
      </div>

      <div class="legal-callout">
        <strong>Collector tip:</strong> pick a delivery window where someone can receive and inspect the shipment immediately.
        <div class="legal-cta">
          <a class="af-btn" href="/refund-policy.php">Refund Policy</a>
          <a class="af-btn secondary" href="/contact.php">Ask Shipping</a>
        </div>
      </div>
    </aside>
  </div>
</main>

<script type="application/ld+json">
{
  "@context":"https://schema.org",
  "@graph":[
    {"@type":"WebPage","name":"Shipping Policy | Bettavaro","url":"<?php echo htmlspecialchars($page_canonical); ?>","description":"<?php echo htmlspecialchars($page_description); ?>","inLanguage":"en"},
    {"@type":"BreadcrumbList","itemListElement":[
      {"@type":"ListItem","position":1,"name":"Home","item":"https://www.bettavaro.com/"},
      {"@type":"ListItem","position":2,"name":"Legal","item":"https://www.bettavaro.com/legal.php"},
      {"@type":"ListItem","position":3,"name":"Shipping Policy","item":"<?php echo htmlspecialchars($page_canonical); ?>"}
    ]}
  ]
}
</script>

<?php include("footer.php"); ?>
