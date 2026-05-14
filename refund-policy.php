<?php
$page_title = "Refund Policy | Bettavaro";
$page_description = "Bettavaro refund policy for live betta fish orders, including DOA claims, proof requirements, and non-refundable shipping.";
$page_canonical = "https://www.bettavaro.com/refund-policy.php";
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
      <span>Refund Policy</span>
    </nav>

    <h1 class="legal-title">Refund Policy</h1>
    <p class="legal-sub">
      Because Bettavaro works with live fish, refunds need to be clear and strict. This page explains what qualifies,
      what proof is required, and what is not refundable.
    </p>

    <div class="legal-chiprow">
      <span class="legal-chip">Live Fish Orders</span>
      <span class="legal-chip">DOA Claims</span>
      <span class="legal-chip">Proof Required</span>
      <span class="legal-chip">Shipping Not Refunded</span>
    </div>
  </div>
</header>

<main class="legal-main">
  <div class="legal-wrap legal-grid">
    <article class="legal-panel">
      <div class="panel-head">
        <h2>Refund Rules</h2>
        <p>Effective Date: <strong><?php echo htmlspecialchars($effective_date); ?></strong></p>
      </div>

      <div class="legal-body">
        <h3>1. General Rule</h3>
        <p>
          All Bettavaro sales involving live fish should be treated as final unless a verified DOA claim is approved under this policy.
        </p>

        <h3>2. DOA Eligibility</h3>
        <ul>
          <li>The package should be opened promptly on arrival</li>
          <li>The customer should record clear photos or a continuous unboxing video</li>
          <li>If a fish appears deceased, the fish bag must remain sealed for claim review</li>
          <li>The claim should be submitted as quickly as possible after delivery</li>
          <li>Order details and evidence must be complete enough to verify the case fairly</li>
        </ul>

        <h3>3. Refund Scope</h3>
        <ul>
          <li>Approved refunds generally cover the affected fish price only</li>
          <li>Shipping, handling, import fees, and third-party costs are normally non-refundable</li>
          <li>Refunds, where approved, should be returned through the original payment method when possible</li>
        </ul>

        <h3>4. What Is Usually Not Refundable</h3>
        <ul>
          <li>Claims submitted without clear proof</li>
          <li>Claims where the bag was opened before review</li>
          <li>Delays caused by customs, import issues, carrier handling, or weather beyond Bettavaro control</li>
          <li>Buyer's remorse or change-of-mind situations for live fish purchases</li>
        </ul>

        <h3>5. Chargebacks and Disputes</h3>
        <p>
          Customers are expected to contact Bettavaro first before filing a chargeback. Where appropriate,
          Bettavaro may contest abusive or unsupported disputes using order records, delivery confirmation, and policy acceptance records.
        </p>

        <h3>6. Liability Limit</h3>
        <p>
          To the extent allowed by law, Bettavaro liability for an approved claim should not exceed the affected fish purchase amount.
        </p>

        <h3>7. Contact</h3>
        <p>
          Refund or DOA questions can be directed through <a href="/contact.php">the contact page</a>.
        </p>
      </div>
    </article>

    <aside class="legal-cardstack">
      <div class="legal-card">
        <div class="k">Fast Checklist</div>
        <div class="v">
          Open promptly<br>
          Record proof<br>
          Keep bag sealed if DOA<br>
          Report quickly
        </div>
      </div>

      <div class="legal-callout">
        <strong>Practical rule:</strong> film first, decide second. Live-fish disputes with no evidence are basically a fistfight with fog.
        <div class="legal-cta">
          <a class="af-btn" href="/doa-policy.php">View DOA Policy</a>
          <a class="af-btn secondary" href="/contact.php">Contact</a>
        </div>
      </div>
    </aside>
  </div>
</main>

<script type="application/ld+json">
{
  "@context":"https://schema.org",
  "@graph":[
    {"@type":"WebPage","name":"Refund Policy | Bettavaro","url":"<?php echo htmlspecialchars($page_canonical); ?>","description":"<?php echo htmlspecialchars($page_description); ?>","inLanguage":"en"},
    {"@type":"BreadcrumbList","itemListElement":[
      {"@type":"ListItem","position":1,"name":"Home","item":"https://www.bettavaro.com/"},
      {"@type":"ListItem","position":2,"name":"Legal","item":"https://www.bettavaro.com/legal.php"},
      {"@type":"ListItem","position":3,"name":"Refund Policy","item":"<?php echo htmlspecialchars($page_canonical); ?>"}
    ]}
  ]
}
</script>

<?php include("footer.php"); ?>
