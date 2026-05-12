<?php
/**
 * /public_html/member/refund_request.php
 *
 * Mobile-first UI page that lets a buyer submit a refund request.
 * All business logic lives in the existing tested endpoint:
 *   POST /api/mobile/v1/buyer_request_refund.php
 *
 * This page only:
 *   1. Authenticates the buyer (web session)
 *   2. Loads order + items from DB for display
 *   3. Resolves a Bearer token (reuse active token or create 90-day token)
 *   4. Renders the UI and hands off to JavaScript
 *
 * Do NOT add refund-creation logic here.
 */
declare(strict_types=1);

require_once __DIR__ . '/_listing_bootstrap.php';

// ── Auth ─────────────────────────────────────────────────────────────────────
// bv_member_require_login() returns the current user array or redirects.
// If your project uses a different function name, adjust here only.
$pdo  = bv_member_pdo();
$user = bv_member_require_login($pdo);

// ── Input ────────────────────────────────────────────────────────────────────
$orderId = max(0, (int)($_GET['order_id'] ?? 0));
if ($orderId <= 0) {
    bv_member_redirect('/member/orders.php');
}

// ── Eligible statuses (must mirror buyer_request_refund.php logic) ───────────
$refundEligiblePayment = ['paid'];
$refundIneligibleOrder = ['cancelled', 'canceled', 'refunded', 'refund_rejected'];

// ── Fetch order ───────────────────────────────────────────────────────────────
$order      = [];
$orderItems = [];
$pageError  = '';

try {
    // Ownership check: user_id must match the authenticated buyer
    $stmtOrder = $pdo->prepare(
        'SELECT * FROM orders WHERE id = :id AND user_id = :uid LIMIT 1'
    );
    $stmtOrder->execute([':id' => $orderId, ':uid' => (int)$user['id']]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC) ?: [];

    if (empty($order)) {
        bv_member_redirect('/member/orders.php');
    }

    $orderStatus   = strtolower(trim((string)($order['status']         ?? '')));
    $paymentStatus = strtolower(trim((string)($order['payment_status'] ?? '')));

    if (!in_array($paymentStatus, $refundEligiblePayment, true)) {
        $pageError = 'This order has not been paid and is not eligible for a refund request.';
    } elseif (in_array($orderStatus, $refundIneligibleOrder, true)) {
        $pageError = 'This order cannot accept a refund request in its current status.';
    }

    // Fetch items regardless of eligibility so we can at least render the order
    $stmtItems = $pdo->prepare(
        'SELECT * FROM order_items WHERE order_id = :oid ORDER BY id ASC'
    );
    $stmtItems->execute([':oid' => $orderId]);
    $orderItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (Throwable $e) {
    $pageError = 'Unable to load order details. Please try again later.';
}

// ── Resolve Bearer token for JS → API call ───────────────────────────────────
// Strategy (patch: reuse-first, schema-adaptive, 90-day expiry):
//
//   1. Read the actual column list from INFORMATION_SCHEMA so every INSERT
//      and SELECT is built against columns that really exist — no silent
//      failures from guessed column names.
//
//   2. Reuse the most recent un-expired, un-revoked token for this user if
//      the table stores the raw value in a `token` column.  This avoids
//      growing mobile_auth_tokens on every page load.
//
//   3. If no reusable token is found, create a fresh one (90-day expiry).
//      `expires_at` is always set when the column exists, which is the fix
//      for the "session expired" error: a NULL expires_at makes MySQL's
//      `expires_at > UTC_TIMESTAMP()` return FALSE, causing auth to fail.
//
//   Raw token → JS via HTTPS only.  Only SHA-256 hash is the DB lookup key.
$apiBearerToken = '';
if (!$pageError) {
    $userId = (int)$user['id'];

    // ── Probe real column set of mobile_auth_tokens ──────────────────────────
    $tokenTableCols = [];
    try {
        $colStmt = $pdo->prepare(
            'SELECT COLUMN_NAME
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = \'mobile_auth_tokens\''
        );
        $colStmt->execute();
        foreach ($colStmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
            $tokenTableCols[strtolower((string)$col)] = true;
        }
    } catch (Throwable $e) { /* proceed without map; inserts will adapt below */ }

    $hasCol = static function (string $col) use ($tokenTableCols): bool {
        return isset($tokenTableCols[strtolower($col)]);
    };

    try {
        // ── 1. Reuse existing active token ───────────────────────────────────
        // Only possible when the table stores the raw value in a `token` column.
        $reused = false;
        if ($hasCol('token')) {
            $reuseWhere  = ['user_id = :uid', 'token IS NOT NULL', "token != ''"];
            if ($hasCol('expires_at')) {
                $reuseWhere[] = '(expires_at IS NULL OR expires_at > UTC_TIMESTAMP())';
            }
            if ($hasCol('revoked_at')) {
                $reuseWhere[] = 'revoked_at IS NULL';
            }
            if ($hasCol('is_active')) {
                $reuseWhere[] = 'is_active = 1';
            }
            try {
                $stmtReuse = $pdo->prepare(
                    'SELECT token FROM mobile_auth_tokens WHERE '
                    . implode(' AND ', $reuseWhere)
                    . ' ORDER BY id DESC LIMIT 1'
                );
                $stmtReuse->execute([':uid' => $userId]);
                $existingRaw = $stmtReuse->fetchColumn();
                if ($existingRaw && strlen((string)$existingRaw) >= 32) {
                    $apiBearerToken = (string)$existingRaw;
                    $reused = true;
                }
            } catch (Throwable $e) { /* fall through to create */ }
        }

        // ── 2. Create new token ──────────────────────────────────────────────
        if (!$reused) {
            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 90 * 86400);  // 90 days

            $insertCols   = ['`user_id`', '`token_hash`'];
            $insertVals   = [':uid',      ':hash'];
            $insertParams = [':uid' => $userId, ':hash' => $tokenHash];

            // expires_at — always set when column exists (NULL caused auth failures)
            if ($hasCol('expires_at')) {
                $insertCols[]         = '`expires_at`';
                $insertVals[]         = ':exp';
                $insertParams[':exp'] = $expiresAt;
            }
            // token — store raw value to enable future reuse (step 1 above)
            if ($hasCol('token')) {
                $insertCols[]         = '`token`';
                $insertVals[]         = ':tok';
                $insertParams[':tok'] = $rawToken;
            }
            // optional housekeeping columns
            if ($hasCol('is_active'))  { $insertCols[] = '`is_active`';  $insertVals[] = '1'; }
            if ($hasCol('created_at')) { $insertCols[] = '`created_at`'; $insertVals[] = 'UTC_TIMESTAMP()'; }
            if ($hasCol('updated_at')) { $insertCols[] = '`updated_at`'; $insertVals[] = 'UTC_TIMESTAMP()'; }

            $pdo->prepare(
                'INSERT INTO mobile_auth_tokens ('
                . implode(', ', $insertCols) . ') VALUES ('
                . implode(', ', $insertVals) . ')'
            )->execute($insertParams);

            $apiBearerToken = $rawToken;
        }

    } catch (Throwable $e) {
        $pageError = 'Could not initialise a secure session for this request. Please try again.';
    }
}

// ── Normalise order data for JavaScript ──────────────────────────────────────
$jsOrder = [];
$jsItems = [];

if (!empty($order)) {
    $jsOrder = [
        'id'         => (int)($order['id'] ?? $orderId),
        'order_code' => (string)($order['order_code'] ?? '#' . $orderId),
        'currency'   => strtoupper(trim((string)($order['currency'] ?? 'THB'))),
        'status'     => $orderStatus,
    ];

    foreach ($orderItems as $item) {
        $qty       = max(1, (int)($item['qty'] ?? $item['quantity'] ?? 1));
        $unitPrice = round((float)($item['unit_price'] ?? $item['price'] ?? 0), 2);
        $title     = trim((string)($item['title'] ?? $item['listing_title'] ?? 'Item #' . ($item['id'] ?? '')));
        $cur       = strtoupper(trim((string)($item['currency'] ?? $jsOrder['currency'])));

        $jsItems[] = [
            'id'         => (int)$item['id'],
            'title'      => $title !== '' ? $title : 'Item #' . (int)$item['id'],
            'qty'        => $qty,
            'unit_price' => $unitPrice,
            'line_total' => round($unitPrice * $qty, 2),
            'currency'   => $cur,
        ];
    }
}

// JSON-encode for safe inline JS injection (HEX_TAG prevents XSS via </script>)
$jsOrderJson = json_encode($jsOrder, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$jsItemsJson = json_encode($jsItems, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$jsTokenJson = json_encode($apiBearerToken);   // raw token — HTTPS only

// ── Page ─────────────────────────────────────────────────────────────────────
bv_member_page_begin('Request Refund | Bettavaro', 'Submit a refund request for your Bettavaro order.');
?>
<style>
/* ── Layout ───────────────────────────────────────────────────────────────── */
.rr-wrap{max-width:480px;margin:0 auto;padding:24px 16px 64px;font-family:system-ui,-apple-system,sans-serif}
.rr-header{display:flex;align-items:center;gap:12px;padding:0 0 18px;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:22px}
.rr-back{background:none;border:none;cursor:pointer;padding:6px;display:flex;align-items:center;color:#f3efe6;border-radius:10px;transition:.15s}
.rr-back:hover{background:rgba(255,255,255,.06)}
.rr-header-text{}
.rr-title{font-size:18px;font-weight:500;color:#f3efe6;margin:0 0 2px}
.rr-order-code{font-size:12px;color:#8ea29a;margin:0}

/* ── Section label ────────────────────────────────────────────────────────── */
.rr-label{font-size:11px;color:#8ea29a;letter-spacing:.06em;text-transform:uppercase;font-weight:500;margin:0 0 8px}

/* ── Mode toggle ──────────────────────────────────────────────────────────── */
.rr-mode-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:22px}
.rr-mode-btn{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);border-radius:14px;padding:14px 12px;cursor:pointer;text-align:left;width:100%;transition:background .15s,border-color .15s}
.rr-mode-btn.active{background:rgba(216,181,107,.12);border-color:#d8b56b}
.rr-mode-btn .rr-mode-icon{font-size:20px;color:#8ea29a;display:block;margin-bottom:7px}
.rr-mode-btn.active .rr-mode-icon{color:#d8b56b}
.rr-mode-btn .rr-mode-name{font-size:14px;font-weight:500;color:#f3efe6;display:block;margin-bottom:2px}
.rr-mode-btn .rr-mode-sub{font-size:12px;color:#8ea29a;display:block}

/* ── Item list ────────────────────────────────────────────────────────────── */
.rr-item-list{border-radius:14px;overflow:hidden;border:0.5px solid rgba(255,255,255,.09);margin-bottom:10px}
.rr-item-row{display:flex;align-items:center;gap:11px;padding:13px 14px;background:rgba(255,255,255,.04);border-bottom:0.5px solid rgba(255,255,255,.07);transition:background .12s}
.rr-item-row:last-child{border-bottom:none}
.rr-item-row.selectable{cursor:pointer}
.rr-item-row.selected{background:rgba(216,181,107,.08)}
.rr-checkbox{width:20px;height:20px;flex-shrink:0;border-radius:6px;border:1.5px solid #8ea29a;display:flex;align-items:center;justify-content:center;transition:all .12s;background:transparent}
.rr-checkbox.checked{border-color:#d8b56b;background:#d8b56b}
.rr-checkbox.checked i{display:block}
.rr-checkbox i{display:none;font-size:13px;color:#182018}
.rr-dot{width:6px;height:6px;border-radius:50%;background:#d8b56b;flex-shrink:0}
.rr-item-meta{flex:1;min-width:0}
.rr-item-title{font-size:14px;color:#f3efe6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin:0 0 2px}
.rr-item-qty{font-size:12px;color:#8ea29a;margin:0}
.rr-item-price{font-size:14px;font-weight:500;color:#f2dfb0;flex-shrink:0}

/* ── Amount summary ───────────────────────────────────────────────────────── */
.rr-amount-row{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:rgba(216,181,107,.07);border-radius:10px;border:0.5px solid rgba(216,181,107,.18);margin-bottom:22px}
.rr-amount-label{font-size:13px;color:#8ea29a}
.rr-amount-value{font-size:15px;font-weight:500;color:#d8b56b}

/* ── Fields ───────────────────────────────────────────────────────────────── */
.rr-field{margin-bottom:16px}
.rr-select,.rr-textarea{width:100%;padding:12px 14px;border-radius:12px;background:rgba(255,255,255,.045);border:0.5px solid rgba(255,255,255,.09);color:#f3efe6;font-size:14px;font-family:inherit;box-sizing:border-box;outline:none;transition:border-color .15s}
.rr-select{appearance:none;cursor:pointer}
.rr-select:focus,.rr-textarea:focus{border-color:rgba(216,181,107,.5)}
.rr-select option{background:#0f2419;color:#f3efe6}
.rr-select option:disabled{color:#8ea29a}
.rr-textarea{resize:vertical;min-height:90px;line-height:1.6}
.rr-char-count{font-size:11px;color:#8ea29a;text-align:right;margin-top:4px}

/* ── Alerts ───────────────────────────────────────────────────────────────── */
.rr-alert{padding:12px 14px;border-radius:10px;font-size:13px;line-height:1.6;margin-bottom:16px;display:flex;align-items:flex-start;gap:8px}
.rr-alert-error{background:rgba(248,113,113,.10);border:0.5px solid rgba(248,113,113,.25);color:#f87171}
.rr-alert-info{background:rgba(216,181,107,.09);border:0.5px solid rgba(216,181,107,.22);color:#f2dfb0}
.rr-alert i{font-size:16px;flex-shrink:0;margin-top:1px}

/* ── Submit button ────────────────────────────────────────────────────────── */
.rr-submit{width:100%;padding:15px;border-radius:14px;background:#d8b56b;border:none;color:#182018;font-weight:700;font-size:15px;font-family:inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:opacity .15s}
.rr-submit:disabled{opacity:.55;cursor:wait}
.rr-submit i{font-size:17px}
@keyframes rr-spin{to{transform:rotate(360deg)}}
.rr-spinner{display:inline-block;width:16px;height:16px;border:2px solid rgba(24,32,24,.3);border-top-color:#182018;border-radius:50%;animation:rr-spin .7s linear infinite;flex-shrink:0}

/* ── Success screen ───────────────────────────────────────────────────────── */
#rr-success{display:none;text-align:center;padding:16px 0 0}
.rr-success-icon{width:72px;height:72px;border-radius:50%;background:rgba(74,222,128,.12);border:1.5px solid rgba(74,222,128,.30);display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
.rr-success-icon i{font-size:36px;color:#4ade80}
.rr-success-title{font-size:20px;font-weight:500;color:#f3efe6;margin:0 0 8px}
.rr-success-sub{font-size:14px;color:#8ea29a;margin:0 auto 28px;max-width:300px;line-height:1.7}
.rr-success-card{background:rgba(255,255,255,.04);border:0.5px solid rgba(255,255,255,.09);border-radius:16px;padding:20px 18px;margin-bottom:24px;text-align:left}
.rr-detail-row{display:flex;justify-content:space-between;align-items:center;padding-bottom:12px;margin-bottom:12px;border-bottom:0.5px solid rgba(255,255,255,.08)}
.rr-detail-row:last-child{padding-bottom:0;margin-bottom:0;border-bottom:none}
.rr-detail-label{font-size:13px;color:#8ea29a}
.rr-detail-value{font-size:14px;color:#f3efe6;font-weight:400}
.rr-detail-value.hi{color:#f2dfb0;font-weight:500}

/* ── Page-level error ─────────────────────────────────────────────────────── */
.rr-page-error{padding:16px;background:rgba(248,113,113,.10);border:0.5px solid rgba(248,113,113,.25);border-radius:14px;color:#f87171;font-size:14px;line-height:1.65;margin-bottom:20px}
</style>

<div class="rr-wrap">

  <!-- Back + title -->
  <div class="rr-header">
    <button class="rr-back" onclick="window.history.back()" aria-label="Go back">
      <i class="ti ti-arrow-left" style="font-size:22px" aria-hidden="true"></i>
    </button>
    <div class="rr-header-text">
      <p class="rr-title">Request Refund</p>
      <p class="rr-order-code">
        <?php if (!empty($order['order_code'])): ?>
          Order <?= bv_member_e((string)$order['order_code']) ?>
        <?php else: ?>
          Order #<?= (int)$orderId ?>
        <?php endif; ?>
      </p>
    </div>
  </div>

  <?php if ($pageError !== ''): ?>
    <!-- Page-level error — form is hidden, nothing to submit -->
    <div class="rr-page-error">
      <i class="ti ti-alert-circle" style="margin-right:6px;font-size:15px" aria-hidden="true"></i>
      <?= bv_member_e($pageError) ?>
    </div>
    <a href="/member/orders.php"
       style="display:inline-flex;align-items:center;gap:6px;color:#d8b56b;font-size:14px;text-decoration:none;">
      <i class="ti ti-arrow-left" style="font-size:15px" aria-hidden="true"></i> Back to Orders
    </a>

  <?php else: ?>

    <!-- ── Success screen (shown after API responds ok:true) ───────────── -->
    <div id="rr-success" role="status" aria-live="polite">
      <div class="rr-success-icon" aria-hidden="true">
        <i class="ti ti-circle-check"></i>
      </div>
      <h2 class="rr-success-title">Request Submitted</h2>
      <p class="rr-success-sub">
        Pending seller review. You'll be notified once a decision is made.
      </p>
      <div class="rr-success-card">
        <div class="rr-detail-row">
          <span class="rr-detail-label">Refund code</span>
          <span class="rr-detail-value hi" id="s-refund-code">—</span>
        </div>
        <div class="rr-detail-row">
          <span class="rr-detail-label">Status</span>
          <span class="rr-detail-value">Pending Approval</span>
        </div>
        <div class="rr-detail-row">
          <span class="rr-detail-label">Order</span>
          <span class="rr-detail-value" id="s-order-code">—</span>
        </div>
        <div class="rr-detail-row">
          <span class="rr-detail-label">Request type</span>
          <span class="rr-detail-value" id="s-request-type">—</span>
        </div>
        <div class="rr-detail-row">
          <span class="rr-detail-label">Refund amount</span>
          <span class="rr-detail-value hi" id="s-refund-amount">—</span>
        </div>
      </div>
      <a href="/member/orders.php"
         style="display:flex;align-items:center;justify-content:center;width:100%;padding:15px;border-radius:14px;background:#d8b56b;color:#182018;font-weight:700;font-size:15px;text-decoration:none;">
        Back to Orders
      </a>
    </div>

    <!-- ── Form ────────────────────────────────────────────────────────── -->
    <div id="rr-form">

      <!-- Mode toggle -->
      <div class="rr-field">
        <p class="rr-label">Refund Type</p>
        <div class="rr-mode-grid">

          <button id="btn-full" class="rr-mode-btn active"
                  onclick="rrSetMode('full')" type="button" aria-pressed="true">
            <i class="ti ti-refresh rr-mode-icon" aria-hidden="true"></i>
            <span class="rr-mode-name">Full Refund</span>
            <span class="rr-mode-sub">All items</span>
          </button>

          <button id="btn-partial" class="rr-mode-btn"
                  onclick="rrSetMode('partial')" type="button" aria-pressed="false">
            <i class="ti ti-list-check rr-mode-icon" aria-hidden="true"></i>
            <span class="rr-mode-name">Partial Refund</span>
            <span class="rr-mode-sub">Select items</span>
          </button>

        </div>
      </div>

      <!-- Item list -->
      <div class="rr-field">
        <p class="rr-label" id="rr-items-label">Items in Order</p>
        <div class="rr-item-list" id="rr-item-list">
          <?php foreach ($orderItems as $item):
            $itemId    = (int)($item['id'] ?? 0);
            $itemTitle = bv_member_e(trim((string)($item['title'] ?? $item['listing_title'] ?? 'Item #' . $itemId)));
            $qty       = max(1, (int)($item['qty'] ?? $item['quantity'] ?? 1));
            $unitPrice = round((float)($item['unit_price'] ?? $item['price'] ?? 0), 2);
            $cur       = strtoupper(trim((string)($item['currency'] ?? ($jsOrder['currency'] ?? 'THB'))));
          ?>
          <div class="rr-item-row" id="rr-row-<?= $itemId ?>"
               data-item-id="<?= $itemId ?>"
               data-line-total="<?= round($unitPrice * $qty, 2) ?>"
               data-currency="<?= bv_member_e($cur) ?>">
            <!-- dot shown in full mode, checkbox in partial -->
            <span class="rr-dot" id="rr-dot-<?= $itemId ?>" aria-hidden="true"></span>
            <div class="rr-checkbox" id="rr-cb-<?= $itemId ?>" style="display:none" aria-hidden="true">
              <i class="ti ti-check" aria-hidden="true"></i>
            </div>
            <div class="rr-item-meta">
              <p class="rr-item-title"><?= $itemTitle ?></p>
              <p class="rr-item-qty">Qty <?= $qty ?></p>
            </div>
            <span class="rr-item-price"><?= bv_member_e(rrFmtCurrency($unitPrice * $qty, $cur)) ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Running total -->
        <div class="rr-amount-row">
          <span class="rr-amount-label" id="rr-sel-label">Full order</span>
          <span class="rr-amount-value" id="rr-sel-amount">
            <?php
              $fullTotal = array_reduce($orderItems, function ($carry, $item) {
                  return $carry + round((float)($item['unit_price'] ?? $item['price'] ?? 0) * max(1, (int)($item['qty'] ?? $item['quantity'] ?? 1)), 2);
              }, 0.0);
              $mainCur = strtoupper(trim((string)($order['currency'] ?? ($orderItems[0]['currency'] ?? 'THB'))));
              echo bv_member_e(rrFmtCurrency($fullTotal, $mainCur));
            ?>
          </span>
        </div>
      </div>

      <!-- Reason -->
      <div class="rr-field">
        <label for="rr-reason-code" class="rr-label">Reason *</label>
        <select id="rr-reason-code" class="rr-select" aria-required="true">
          <option value="" disabled selected>Select a reason…</option>
          <option value="buyer_changed_mind">Changed my mind</option>
          <option value="item_not_as_described">Item not as described</option>
          <option value="item_damaged">Item arrived damaged</option>
          <option value="item_not_received">Item not received</option>
          <option value="duplicate_order">Duplicate order</option>
          <option value="seller_cancelled">Seller cancelled</option>
          <option value="other">Other reason</option>
        </select>
      </div>

      <!-- Details -->
      <div class="rr-field">
        <label for="rr-reason-text" class="rr-label">Additional Details</label>
        <textarea id="rr-reason-text" class="rr-textarea"
                  maxlength="1000"
                  placeholder="Describe the issue in more detail (optional)…"
                  oninput="document.getElementById('rr-char-count').textContent = this.value.length + '/1000'"></textarea>
        <div class="rr-char-count" id="rr-char-count">0/1000</div>
      </div>

      <!-- Inline error (validation + API errors) -->
      <div id="rr-error" class="rr-alert rr-alert-error" style="display:none" role="alert" aria-live="assertive">
        <i class="ti ti-alert-circle" aria-hidden="true"></i>
        <span id="rr-error-msg"></span>
      </div>

      <!-- Submit -->
      <button id="rr-submit-btn" class="rr-submit" type="button" onclick="rrSubmit()">
        <i class="ti ti-send" aria-hidden="true"></i>
        Submit Refund Request
      </button>

    </div><!-- /#rr-form -->

  <?php endif; ?>

</div><!-- /.rr-wrap -->

<?php
// ── PHP helper: currency formatter (server-side, used above for initial render)
function rrFmtCurrency(float $amount, string $currency): string
{
    return strtoupper($currency) . ' ' . number_format($amount, 0, '.', ',');
}
?>

<script>
(function () {
  'use strict';

  /* ── Config injected by PHP ─────────────────────────────────────────────── */
  var ORDER       = <?= $jsOrderJson ?>;   // { id, order_code, currency, status }
  var ITEMS       = <?= $jsItemsJson ?>;   // [{ id, title, qty, unit_price, line_total, currency }]
  var BEARER      = <?= $jsTokenJson ?>;   // SHA-256 hashed and stored by PHP
  var API_URL     = '/api/mobile/v1/buyer_request_refund.php';

  /* ── State ──────────────────────────────────────────────────────────────── */
  var mode     = 'full';             // 'full' | 'partial'
  var selected = {};                 // { [item_id]: true }

  /* ── Helpers ────────────────────────────────────────────────────────────── */
  function $(id) { return document.getElementById(id); }

  function fmtCurrency(amount, currency) {
    try {
      return new Intl.NumberFormat('th-TH', {
        style: 'currency', currency: currency || ORDER.currency || 'THB',
        minimumFractionDigits: 0, maximumFractionDigits: 0,
      }).format(amount);
    } catch (e) {
      return (currency || 'THB') + ' ' + Math.round(amount).toLocaleString();
    }
  }

  function selectedItemIds() {
    return Object.keys(selected).filter(function (k) { return selected[k]; }).map(Number);
  }

  /* ── Mode toggle ────────────────────────────────────────────────────────── */
  window.rrSetMode = function (newMode) {
    mode     = newMode;
    selected = {};

    /* Toggle button styles */
    $('btn-full').classList.toggle('active', mode === 'full');
    $('btn-full').setAttribute('aria-pressed', mode === 'full' ? 'true' : 'false');
    $('btn-partial').classList.toggle('active', mode === 'partial');
    $('btn-partial').setAttribute('aria-pressed', mode === 'partial' ? 'true' : 'false');

    /* Items label */
    $('rr-items-label').textContent = mode === 'partial'
      ? 'Select Items to Refund'
      : 'Items in Order';

    /* Toggle dot / checkbox for each item row */
    ITEMS.forEach(function (item) {
      var row = $('rr-row-' + item.id);
      var dot = $('rr-dot-' + item.id);
      var cb  = $('rr-cb-'  + item.id);
      if (!row) return;

      dot.style.display = mode === 'full'    ? '' : 'none';
      cb.style.display  = mode === 'partial' ? '' : 'none';
      cb.classList.remove('checked');
      row.classList.remove('selected', 'selectable');

      if (mode === 'partial') {
        row.classList.add('selectable');
        row.setAttribute('role', 'checkbox');
        row.setAttribute('aria-checked', 'false');
        row.onclick = function () { rrToggleItem(item.id); };
      } else {
        row.removeAttribute('role');
        row.removeAttribute('aria-checked');
        row.onclick = null;
      }
    });

    rrUpdateSummary();
    rrHideError();
  };

  /* ── Item toggle (partial mode) ─────────────────────────────────────────── */
  function rrToggleItem(itemId) {
    selected[itemId] = !selected[itemId];

    var cb  = $('rr-cb-'  + itemId);
    var row = $('rr-row-' + itemId);
    if (cb)  cb.classList.toggle('checked', !!selected[itemId]);
    if (row) {
      row.classList.toggle('selected', !!selected[itemId]);
      row.setAttribute('aria-checked', selected[itemId] ? 'true' : 'false');
    }

    rrUpdateSummary();
    rrHideError();
  }

  /* ── Running total ──────────────────────────────────────────────────────── */
  function rrUpdateSummary() {
    var total = 0;
    var cur   = ORDER.currency || 'THB';
    var count = 0;

    ITEMS.forEach(function (item) {
      if (mode === 'full' || selected[item.id]) {
        total += item.line_total;
        cur    = item.currency || cur;
        count++;
      }
    });

    $('rr-sel-amount').textContent = fmtCurrency(total, cur);

    if (mode === 'partial') {
      $('rr-sel-label').textContent = count + ' item' + (count !== 1 ? 's' : '') + ' selected';
    } else {
      $('rr-sel-label').textContent = 'Full order';
    }
  }

  /* ── Error helpers ──────────────────────────────────────────────────────── */
  function rrShowError(msg) {
    $('rr-error-msg').textContent = msg;
    $('rr-error').style.display  = 'flex';
    $('rr-error').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function rrHideError() {
    $('rr-error').style.display = 'none';
    $('rr-error-msg').textContent = '';
  }

  /* ── Client-side validation ─────────────────────────────────────────────── */
  function rrValidate() {
    var reasonCode = $('rr-reason-code').value;
    if (!reasonCode) {
      rrShowError('Please select a reason for your refund request.');
      return false;
    }
    if (mode === 'partial' && selectedItemIds().length === 0) {
      rrShowError('Please select at least one item for a partial refund.');
      return false;
    }
    return true;
  }

  /* ── Submit ─────────────────────────────────────────────────────────────── */
  window.rrSubmit = function () {
    rrHideError();

    if (!rrValidate()) return;

    /* Build payload — matches buyer_request_refund.php input contract exactly */
    var payload = {
      order_id:       ORDER.id,
      request_mode:   mode,
      order_item_ids: mode === 'partial' ? selectedItemIds() : [],
      reason_code:    $('rr-reason-code').value,
      reason_text:    ($('rr-reason-text').value || '').trim(),
    };

    rrSetLoading(true);

    fetch(API_URL, {
      method:  'POST',
      headers: {
        'Content-Type':  'application/json',
        'Authorization': 'Bearer ' + BEARER,
        'Accept':        'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(payload),
    })
    .then(function (res) {
      /* Always parse as JSON — buyer_request_refund.php outputs JSON only */
      return res.json().then(function (json) {
        return { status: res.status, json: json };
      });
    })
    .then(function (result) {
      rrSetLoading(false);

      var json   = result.json;
      var status = result.status;

      if (json.ok === true && json.data && json.data.refund) {
        /* ── Success ── */
        rrShowSuccess(json.data);
      } else {
        /* ── API error ── */
        var errCode = (json.error && json.error.code)    ? json.error.code    : '';
        var errMsg  = (json.error && json.error.message) ? json.error.message : 'Something went wrong. Please try again.';

        if (errCode === 'refund_already_requested') {
          errMsg = 'A refund request for this order already exists. Please check your order details.';
        } else if (errCode === 'unauthorized') {
          errMsg = 'Your session has expired. Please refresh the page and try again.';
        }

        rrShowError(errMsg);
      }
    })
    .catch(function () {
      rrSetLoading(false);
      rrShowError('Network error. Please check your connection and try again.');
    });
  };

  /* ── Loading state ──────────────────────────────────────────────────────── */
  function rrSetLoading(loading) {
    var btn = $('rr-submit-btn');
    btn.disabled = loading;

    if (loading) {
      btn.innerHTML =
        '<span class="rr-spinner" aria-hidden="true"></span> Submitting\u2026';
    } else {
      btn.innerHTML =
        '<i class="ti ti-send" aria-hidden="true"></i> Submit Refund Request';
    }
  }

  /* ── Success screen ─────────────────────────────────────────────────────── */
  function rrShowSuccess(data) {
    var refund = data.refund || {};
    var cur    = (refund.currency || ORDER.currency || 'THB');
    var amt    = typeof refund.requested_refund_amount === 'number'
               ? fmtCurrency(refund.requested_refund_amount, cur)
               : '—';
    var type   = data.request_mode === 'partial' ? 'Partial Refund' : 'Full Refund';

    $('s-refund-code').textContent  = refund.refund_code || '—';
    $('s-order-code').textContent   = data.order_code    || ORDER.order_code || '—';
    $('s-request-type').textContent = type;
    $('s-refund-amount').textContent = amt;

    $('rr-form').style.display    = 'none';
    $('rr-success').style.display = 'block';

    /* Scroll to top of the success card */
    $('rr-success').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /* ── Init ───────────────────────────────────────────────────────────────── */
  rrUpdateSummary();

}());
</script>

<?php bv_member_page_end(); ?>
