<?php
$page_title = "Privacy Policy | Bettavaro";
$page_description = "Bettavaro privacy policy for customer data, website activity, order handling, and support communications.";
$page_canonical = "https://www.bettavaro.com/privacy-policy.php";
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
      <span>Privacy Policy</span>
    </nav>

    <h1 class="legal-title">Privacy Policy</h1>
    <p class="legal-sub">
      Bettavaro respects customer privacy. This page explains what information we collect, why we collect it,
      and how we protect it when customers browse listings, contact us, or place an order.
    </p>

    <div class="legal-chiprow">
      <span class="legal-chip">Customer Data</span>
      <span class="legal-chip">Order Records</span>
      <span class="legal-chip">Site Analytics</span>
      <span class="legal-chip">Security</span>
    </div>
  </div>
</header>

<main class="legal-main">
  <div class="legal-wrap legal-grid">
    <article class="legal-panel">
      <div class="panel-head">
        <h2>Policy Overview</h2>
        <p>Effective Date: <strong><?php echo htmlspecialchars($effective_date); ?></strong></p>
      </div>

      <div class="legal-body">
        <h3>1. Scope</h3>
        <p>
          This Privacy Policy applies to Bettavaro website visitors, customers, and inquiry submissions.
          It covers information collected through the website, contact forms, order processes, and customer support communication.
        </p>

        <h3>2. Information We May Collect</h3>
        <ul>
          <li>Identity and contact details such as name, email, phone number, and shipping address</li>
          <li>Order-related information such as listing details, transaction status, and internal order references</li>
          <li>Technical information such as IP address, browser type, device information, and access logs</li>
          <li>Usage data such as page visits, clicks, and analytics events used to improve the site</li>
        </ul>

        <h3>3. How We Use Information</h3>
        <ul>
          <li>To answer inquiries and provide customer support</li>
          <li>To process orders, reservations, payments, and shipping coordination</li>
          <li>To maintain site security and reduce fraud or abusive activity</li>
          <li>To improve content, listings, and website performance</li>
          <li>To comply with applicable legal, tax, or business record requirements</li>
        </ul>

        <h3>4. Payment Data</h3>
        <p>
          Bettavaro does not intentionally store full credit or debit card numbers on its website.
          Payments are expected to be processed through external payment providers or approved payment methods,
          and only limited non-sensitive references may be kept for reconciliation and support.
        </p>

        <h3>5. Sharing of Information</h3>
        <p>We may share limited information only where necessary, such as with:</p>
        <ul>
          <li>Payment providers</li>
          <li>Shipping or logistics partners</li>
          <li>Hosting, IT, or infrastructure providers</li>
          <li>Authorities or legal advisors when required by law or dispute handling</li>
        </ul>

        <h3>6. Data Retention</h3>
        <p>
          We keep information only for as long as reasonably needed for order handling, support, fraud prevention,
          bookkeeping, legal compliance, or dispute management.
        </p>

        <h3>7. Security</h3>
        <ul>
          <li>Restricted system access</li>
          <li>Reasonable use of SSL/TLS where available</li>
          <li>Access logging and operational monitoring</li>
          <li>Internal review of suspicious activity when needed</li>
        </ul>

        <h3>8. Cookies and Analytics</h3>
        <p>
          Bettavaro may use cookies, analytics tags, or event tracking to understand site usage and improve the user experience.
          Visitors can also control many cookie settings through their own browser preferences.
        </p>

        <h3>9. Your Choices</h3>
        <p>
          You may contact us to request correction or deletion of personal data where appropriate and legally possible.
          Some records may still need to be kept for order history, tax, fraud prevention, or dispute reasons.
        </p>

        <h3>10. Contact</h3>
        <p>
          For privacy questions, please contact Bettavaro through the website contact page:
          <a href="/contact.php">/contact.php</a>
        </p>

        <div class="legal-note">
          This version avoids hard-coding company details so it fits Bettavaro immediately.
          Once your final entity name, address, and privacy contact email are locked in, drop them into this page and it becomes properly finished—not just pretty 😄
        </div>
      </div>
    </article>

    <aside class="legal-cardstack">
      <div class="legal-card">
        <div class="k">Quick Links</div>
        <div class="v">
          <a href="/refund-policy.php">Refund Policy</a><br>
          <a href="/shipping-policy.php">Shipping Policy</a><br>
          <a href="/terms-conditions.php">Terms &amp; Conditions</a><br>
          <a href="/doa-policy.php">DOA Policy</a>
        </div>
      </div>

      <div class="legal-callout">
        <strong>Launch-ready note:</strong> This page is visually aligned to Bettavaro and safe to publish as a working draft.
        <div class="legal-cta">
          <a class="af-btn" href="/legal.php">All Legal Pages</a>
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
    {"@type":"WebPage","name":"Privacy Policy | Bettavaro","url":"<?php echo htmlspecialchars($page_canonical); ?>","description":"<?php echo htmlspecialchars($page_description); ?>","inLanguage":"en"},
    {"@type":"BreadcrumbList","itemListElement":[
      {"@type":"ListItem","position":1,"name":"Home","item":"https://www.bettavaro.com/"},
      {"@type":"ListItem","position":2,"name":"Legal","item":"https://www.bettavaro.com/legal.php"},
      {"@type":"ListItem","position":3,"name":"Privacy Policy","item":"<?php echo htmlspecialchars($page_canonical); ?>"}
    ]}
  ]
}
</script>

<?php include("footer.php"); ?>
