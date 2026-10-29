<?php
declare(strict_types=1);
$docTitle = 'Privacy Policy';
$docUpdated = 'Last updated: October 2026';
ob_start();
?>
<h2>1. Information We Collect</h2>
      <ul>
        <li><strong>Account data:</strong> name, email, phone, gender.</li>
        <li><strong>Financial data:</strong> bank details required for payouts (processed securely by our licensed payment partners).</li>
        <li><strong>Task data:</strong> social handles and proof links submitted for verification.</li>
      </ul>

      <h2>2. How We Use It</h2>
      <ul>
        <li>Processing transactions, withdrawals and campaign funding.</li>
        <li>Matching earners to targeted campaigns via interests and demographics.</li>
        <li>Sending announcements, security alerts and support messages.</li>
      </ul>

      <h2>3. Data Sharing</h2>
      <p>We do not sell personal data. We share only what is necessary with:</p>
      <ul>
        <li><strong>Advertisers:</strong> proof links and handles needed to verify tasks.</li>
        <li><strong>Licensed payment partners:</strong> secure payment collection and payouts.</li>
        <li><strong>Law enforcement:</strong> when required by Nigerian law or to prevent fraud.</li>
      </ul>

      <h2>4. Data Security</h2>
      <p>Industry-standard TLS encryption, hashed passwords, and role-restricted database access.</p>

      <h2>5. Your Rights</h2>
      <p>Request a copy of your data or account deletion via support. Financial records are retained for 5 years as required by Nigerian regulation.</p>

      <h2>6. Contact</h2>
      <p>support@nifelux.com</p>
<?php
$docBody = (string) ob_get_clean();
require YOLOTASK_APP_ROOT . '/shared/views/layouts/document.php';
