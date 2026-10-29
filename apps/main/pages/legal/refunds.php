<?php
declare(strict_types=1);
$docTitle = 'Refund Policy';
$docUpdated = 'Last updated: October 2026';
ob_start();
?>
<div class="disclaimer-box" style="background:var(--accent-glow); border-color:var(--accent); color:var(--accent);">Core principle: Advertisers only pay for verified work. Earners only get paid for verified work. YOLOTASK holds funds in escrow to guarantee both.</div>

      <h2>1. Advertiser Refunds</h2>
      <ul>
        <li><strong>Pre-LIVE cancellation:</strong> 100% of budget returns to your wallet.</li>
        <li><strong>Unfilled campaigns:</strong> remaining locked budget auto-refunds at expiry.</li>
        <li><strong>Rejected tasks:</strong> funds for correctly-rejected submissions return to your wallet.</li>
      </ul>
      <p><em>Refunds are credited to your YOLOTASK wallet (not reversed to card) to protect against chargeback abuse.</em></p>

      <h2>2. Earner Disputes</h2>
      <ul>
        <li>Unfair rejection? Open a dispute via Support with your proof link.</li>
        <li>Our Reviewer team inspects manually; correct work is paid from platform reserves.</li>
      </ul>

      <h2>3. Withdrawal Disputes</h2>
      <ul>
        <li>Failed transfers due to wrong bank details return to your wallet within 48 hours.</li>
        <li>YOLOTASK is not liable for Nigerian banking network or payment partner processing delays.</li>
      </ul>

      <h2>4. Contact Support</h2>
      <p>support@nifelux.com — include your User ID and screenshots for fastest resolution.</p>
<?php
$docBody = (string) ob_get_clean();
require YOLOTASK_APP_ROOT . '/shared/views/layouts/document.php';
