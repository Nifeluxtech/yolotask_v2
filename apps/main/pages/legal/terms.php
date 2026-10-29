<?php
declare(strict_types=1);
$docTitle = 'Terms of Service';
$docUpdated = 'Last updated: October 2026';
ob_start();
?>
<div class="card card-glow legal-note"><p>IMPORTANT: YOLOTASK is a digital marketing and promotional marketplace. It is NOT an investment platform, Ponzi scheme, or money-doubling program. You earn by completing digital tasks — never from returns on deposits.</p></div>

      <h2>1. Acceptance of Terms</h2>
      <p>By accessing or using YOLOTASK ("the Platform"), you agree to be bound by these Terms. If you do not agree, do not use the Platform.</p>

      <h2>2. User Accounts</h2>
      <ul>
        <li>You must be at least 18 years old to use this Platform.</li>
        <li>You are responsible for the confidentiality of your account and password.</li>
        <li>One person = one account. Duplicate accounts are suspended and forfeit balances.</li>
      </ul>

      <h2>3. Earner Obligations</h2>
      <ul>
        <li>Complete tasks exactly as instructed by the Advertiser.</li>
        <li>Fake proofs, bots or automation = immediate termination and forfeiture of funds.</li>
        <li>Withdrawals are subject to the published minimum and processing windows.</li>
      </ul>

      <h2>4. Advertiser Obligations</h2>
      <ul>
        <li>Campaign budgets are locked in escrow before going LIVE.</li>
        <li>Review submissions within your approval window; unreviewed tasks auto-approve or escalate per your settings.</li>
        <li>No illegal, fraudulent or adult content on the Platform.</li>
      </ul>

      <h2>5. Prohibited Activities</h2>
      <p>Money laundering, harassment, platform exploitation, referral abuse, or any activity violating Nigerian law.</p>

      <h2>6. Limitation of Liability</h2>
      <p>YOLOTASK acts solely as an intermediary. Our total liability for any transaction is limited to the fees collected on that transaction.</p>

      <h2>7. Governing Law</h2>
      <p>These Terms are governed by the laws of the Federal Republic of Nigeria; disputes resolve in Lagos State courts.</p>
<?php
$docBody = (string) ob_get_clean();
require YOLOTASK_APP_ROOT . '/shared/views/layouts/document.php';
