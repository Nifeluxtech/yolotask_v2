<?php
declare(strict_types=1);

use YoloTask\Campaigns\Interests;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Security\Csrf;

require_once __DIR__ . '/tasks-helpers.php';

/* Earner: choose 1-3 interests (account settings, onboarding after sign-up, dashboard prompt). */
if (yolotask_role() !== 'earner') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('interests.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];
$welcome = ($_GET['welcome'] ?? '') === '1';
$self = 'interests.php' . ($welcome ? '?welcome=1' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_csrf_or_back($self);
    try {
        Interests::saveForEarner($pdo, $user, $_POST['interests'] ?? null);
        yolotask_flash($welcome ? 'Interests saved. Premium tasks that match them will show in your feed.' : 'Interests saved.');
        yolotask_redirect($welcome ? 'dashboard.php' : 'interests.php');
    } catch (RuntimeException $e) {
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('interests.save_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($self);
}

$mine = Interests::forUser($pdo, $uid);
$csrf = Csrf::token();
ob_start();
?>
<div class="wallet-grid">
  <section class="card card-glow dash-hero" aria-labelledby="in-title">
    <p class="text-xs fw-bold text-primary"><?= $welcome ? 'ONE LAST STEP' : 'YOUR INTERESTS' ?></p>
    <h2 id="in-title"><?= $welcome ? 'What are you into?' : 'Your interests' ?></h2>
    <p class="text-faint">Pick <?= Interests::MIN ?> to <?= Interests::MAX ?>. Premium tasks pay more and are matched to these, so you only see premium work you care about. Standard tasks are open to everyone.</p>
  </section>
  <section class="card" aria-label="Choose interests">
    <form method="post" action="<?= h($self) ?>" class="stack">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <?= yolotask_interest_chips(Interests::options($pdo), array_keys($mine), 'Interests', $mine === [] ? 'You have not chosen any yet.' : 'You can change these any time.', 'ei') ?>
      <button class="btn btn-primary btn-block" type="submit">Save interests</button>
      <?php if ($welcome): ?><p class="text-center text-sm"><a href="dashboard.php">Skip for now</a></p><?php endif; ?>
    </form>
  </section>
  <?php if (!$welcome): ?><p class="text-center text-sm"><a href="account.php">Back to account</a></p><?php endif; ?>
</div>
<?php
yolotask_render_app($user, 'Interests', (string) ob_get_clean(), 'account');
