<?php
declare(strict_types=1);

use YoloTask\Campaigns\Campaigns;
use YoloTask\Campaigns\Interests;
use YoloTask\Campaigns\Pricing;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Core\Settings;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Csrf;
use YoloTask\Security\Throttle;

require_once __DIR__ . '/tasks-helpers.php';

/* Advertiser: create a campaign draft, or edit a draft (?id=). */
if (yolotask_role() !== 'advertiser') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('campaign_new.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];
$editId = (int) ($_GET['id'] ?? 0);
$draft = null;
if ($editId > 0) {
    try {
        $draft = Campaigns::ownedBy($pdo, $editId, $uid);
    } catch (RuntimeException) {
        yolotask_error_page(404);
    }
    if ((string) $draft['status'] !== 'draft') yolotask_redirect('campaign.php?id=' . $editId);
}
$self = 'campaign-new.php' . ($editId > 0 ? '?id=' . $editId : '');
$fields = ['title', 'platform_id', 'task_type_id', 'tier', 'link_url', 'instructions', 'proof_requirements', 'quantity', 'ends_at', 'custom_platform_name', 'custom_task_name', 'custom_price'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_csrf_or_back($self);
    $in = [];
    foreach ($fields as $f) $in[$f] = yolotask_post_str($f);
    $in['interests'] = is_array($_POST['interests'] ?? null) ? array_values(array_filter($_POST['interests'], 'is_string')) : (is_string($_POST['interests'] ?? null) ? [$_POST['interests']] : []);
    try {
        if ($draft === null) {
            if (Throttle::tooMany($pdo, 'campaign_create', (string) $uid, 20, 3600)) throw new RuntimeException('You have created many campaigns in the last hour. Please wait a little.');
            $id = Campaigns::create($pdo, $user, $in);
            Throttle::hit($pdo, 'campaign_create', (string) $uid);
            yolotask_flash('Draft saved. Check the cost, then fund it to send it for approval.');
        } else {
            Campaigns::updateDraft($pdo, $user, $editId, $in);
            $id = $editId;
            yolotask_flash('Draft updated.');
        }
        unset($_SESSION['_campaign_form']);
        yolotask_redirect('campaign.php?id=' . $id);
    } catch (RuntimeException $e) {
        $_SESSION['_campaign_form'] = $in;
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('campaign_new.save_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        $_SESSION['_campaign_form'] = $in;
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect($self);
}

$old = is_array($_SESSION['_campaign_form'] ?? null) ? $_SESSION['_campaign_form'] : null;
unset($_SESSION['_campaign_form']);
$val = static function (string $k) use ($old, $draft): string {
    if ($old !== null) return (string) ($old[$k] ?? '');
    if ($draft !== null) {
        if ($k === 'ends_at') return $draft['ends_at'] ? yolotask_wat((string) $draft['ends_at'], 'Y-m-d') : '';
        if ($k === 'custom_price') return $draft['custom_task_name'] !== null ? (string) intdiv((int) $draft['unit_price_kobo'], 100) : '';
        return (string) ($draft[$k] ?? '');
    }
    return $k === 'tier' ? 'basic' : '';
};
$interestOptions = Interests::options($pdo);
if ($old !== null) {
    $chosenInterests = is_array($old['interests'] ?? null) ? $old['interests'] : [];
} elseif ($draft !== null) {
    $chosenInterests = array_keys(Interests::forCampaign($pdo, (int) $draft['id']));
} else {
    $chosenInterests = [];
}
$custom = Pricing::customRules($pdo);
$platforms = Pricing::platforms($pdo);
$types = Pricing::taskTypes($pdo);
if (!$custom['enabled']) {
    $platforms = array_values(array_filter($platforms, static fn (array $p): bool => !Pricing::isCustom($p)));
    $types = array_values(array_filter($types, static fn (array $t): bool => !Pricing::isCustom($t)));
}
$wallet = Wallet::forUser($pdo, $uid);
$maxQty = Settings::int($pdo, 'campaigns.max_quantity');
$minQty = Settings::int($pdo, 'campaigns.min_quantity');
$csrf = Csrf::token();
$minDate = (new DateTimeImmutable('tomorrow', new DateTimeZone('Africa/Lagos')))->format('Y-m-d');
ob_start();
?>
<div class="wallet-grid">
  <?php if (empty($user['email_verified_at'])): ?>
    <section class="card verify-banner" role="status"><span class="verify-icon"><?= yolotask_nav_icon('bell') ?></span>
      <div><strong>Confirm your email first</strong><p class="text-sm text-faint">Campaigns can be created once your email address is confirmed.</p></div><a class="btn btn-primary btn-sm" href="verify.php">Confirm now</a></section>
  <?php endif; ?>
  <section class="card" aria-labelledby="form-title">
    <div class="card-header"><h2 id="form-title"><?= $draft ? 'Edit draft' : 'New campaign' ?></h2><span class="badge badge-info">Wallet <?= h(yolotask_money($wallet['available_kobo'])) ?></span></div>
    <form method="post" action="<?= h($self) ?>" class="stack" data-campaign-form>
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <div class="field"><label class="label" for="title">Campaign title</label>
        <input class="input" id="title" name="title" required minlength="3" maxlength="120" value="<?= h($val('title')) ?>" placeholder="Follow our Instagram page"></div>
      <div class="grid grid-2 form-pair">
        <div class="field"><label class="label" for="platform_id">Platform</label>
          <select class="input" id="platform_id" name="platform_id" required>
            <option value="">Choose…</option>
            <?php foreach ($platforms as $p): ?><option value="<?= (int) $p['id'] ?>" data-domains="<?= h((string) $p['domains']) ?>"<?= Pricing::isCustom($p) ? ' data-custom="1"' : '' ?><?= $val('platform_id') === (string) $p['id'] ? ' selected' : '' ?>><?= h((string) $p['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label class="label" for="task_type_id">Task</label>
          <select class="input" id="task_type_id" name="task_type_id" required>
            <option value="">Choose…</option>
            <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= Pricing::isCustom($t) ? ' data-custom="1"' : '' ?> data-base="<?= (int) $t['base_price_kobo'] ?>" data-premium="<?= (int) $t['premium_price_kobo'] ?>" data-hint="<?= h((string) $t['proof_hint']) ?>"<?= $val('task_type_id') === (string) $t['id'] ? ' selected' : '' ?>><?= h((string) $t['name']) ?><?= Pricing::isCustom($t) ? '' : ' · ' . h(Money::format((int) $t['base_price_kobo'])) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <div class="field" data-custom-platform hidden><label class="label" for="custom_platform_name">App or website name</label>
        <input class="input" id="custom_platform_name" name="custom_platform_name" maxlength="60" value="<?= h($val('custom_platform_name')) ?>" placeholder="e.g. Nifelux App">
        <p class="input-hint">Earners see this name on the task card. Any https:// link is accepted.</p></div>
      <div class="field" data-custom-task hidden><label class="label" for="custom_task_name">Your task</label>
        <input class="input" id="custom_task_name" name="custom_task_name" maxlength="80" value="<?= h($val('custom_task_name')) ?>" placeholder="e.g. Download and sign up">
        <p class="input-hint">A few words. Explain the steps in the instructions below.</p></div>
      <div class="field" data-custom-task hidden><label class="label" for="custom_price">Your price per task (₦)</label>
        <input class="input" id="custom_price" name="custom_price" type="number" min="<?= intdiv($custom['min'], 100) ?>" max="<?= intdiv(Pricing::MAX_PRICE_KOBO, 100) ?>" step="1" inputmode="numeric" value="<?= h($val('custom_price')) ?>" placeholder="<?= intdiv($custom['min'], 100) ?>">
        <p class="input-hint">At least <?= h(Money::format($custom['min'])) ?>. Earners receive <?= (int) $custom['percent'] ?>% of it. Premium does not change your price.</p></div>
      <fieldset class="field tier-choice"><legend class="label">Audience</legend>
        <label class="radio-card"><input type="radio" name="tier" value="basic"<?= $val('tier') !== 'targeted' ? ' checked' : '' ?>><span><strong>Standard</strong><span class="text-xs text-faint">Any verified earner can take it.</span></span></label>
        <label class="radio-card"><input type="radio" name="tier" value="targeted"<?= $val('tier') === 'targeted' ? ' checked' : '' ?>><span><strong>Premium</strong><span class="text-xs text-faint">Shown first in the feed, higher reward for earners. 1.5× price.</span></span></label>
      </fieldset>
      <?= yolotask_interest_chips($interestOptions, $chosenInterests, 'Audience interests', 'Pick 1 to ' . Interests::MAX . '. Premium tasks are shown only to earners who share at least one of these.', 'ci') ?>
      <div class="field"><label class="label" for="link_url">Link</label>
        <input class="input" id="link_url" name="link_url" type="url" required maxlength="500" inputmode="url" value="<?= h($val('link_url')) ?>" placeholder="https://instagram.com/yourpage">
        <p class="input-hint">Must be an https:// link on the platform you chose.</p></div>
      <div class="field"><label class="label" for="instructions">Instructions for earners</label>
        <textarea class="input" id="instructions" name="instructions" rows="4" required minlength="10" maxlength="2000" placeholder="Follow the page, then like the latest post."><?= h($val('instructions')) ?></textarea></div>
      <div class="field"><label class="label" for="proof_requirements">Proof you need</label>
        <input class="input" id="proof_requirements" name="proof_requirements" required minlength="5" maxlength="500" value="<?= h($val('proof_requirements')) ?>" placeholder="Screenshot of the profile showing you follow it."></div>
      <div class="grid grid-2 form-pair">
        <div class="field"><label class="label" for="quantity">How many people</label>
          <input class="input" id="quantity" name="quantity" type="number" min="<?= $minQty ?>" max="<?= $maxQty ?>" required value="<?= h($val('quantity')) ?>" placeholder="100"></div>
        <div class="field"><label class="label" for="ends_at">End date (optional)</label>
          <input class="input" id="ends_at" name="ends_at" type="date" min="<?= h($minDate) ?>" value="<?= h($val('ends_at')) ?>"></div>
      </div>
      <div class="cost-preview card" aria-live="polite"><span class="text-sm text-faint">Estimated cost</span><strong data-cost>—</strong><span class="text-xs text-faint" data-cost-note>Choose a task and quantity.</span></div>
      <button class="btn btn-primary btn-block" type="submit"<?= empty($user['email_verified_at']) ? ' disabled' : '' ?>><?= $draft ? 'Save draft' : 'Save and review cost' ?></button>
      <p class="input-hint text-center">No money moves yet. You fund the campaign on the next screen.</p>
    </form>
  </section>
  <p class="text-center text-sm"><a href="<?= $draft ? 'campaign.php?id=' . (int) $draft['id'] : 'campaigns.php' ?>">Back</a></p>
</div>
<style>[data-custom-platform][hidden],[data-custom-task][hidden]{display:none}</style>
<script>
(function () {
  var f = document.querySelector('[data-campaign-form]'); if (!f) return;
  var out = f.querySelector('[data-cost]'), note = f.querySelector('[data-cost-note]');
  var pct = <?= (int) $custom['percent'] ?>;
  function sel(s) { return s.options[s.selectedIndex] || {}; }
  function toggle() {
    var cp = !!(sel(f.platform_id).dataset || {}).custom, ct = !!(sel(f.task_type_id).dataset || {}).custom;
    f.querySelectorAll('[data-custom-platform]').forEach(function (el) { el.hidden = !cp; });
    f.querySelectorAll('[data-custom-task]').forEach(function (el) { el.hidden = !ct; });
    f.custom_platform_name.required = cp; f.custom_task_name.required = ct; f.custom_price.required = ct;
    return ct;
  }
  var fmt = function (k) { return '\u20A6' + (k / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function update() {
    var opt = f.task_type_id.options[f.task_type_id.selectedIndex];
    var q = parseInt(f.quantity.value, 10);
    var tier = (f.querySelector('input[name=tier]:checked') || {}).value;
    if (!opt || !opt.value || !q || q < 1) { toggle(); out.textContent = '\u2014'; return; }
    var ct = toggle();
    var unit = ct ? (parseInt(f.custom_price.value, 10) || 0) * 100 : parseInt(tier === 'targeted' ? opt.dataset.premium : opt.dataset.base, 10);
    if (!unit) { out.textContent = '\u2014'; note.textContent = 'Enter your price per task.'; return; }
    out.textContent = fmt(unit * q);
    note.textContent = fmt(unit) + ' per task \u00D7 ' + q.toLocaleString('en-NG') + (ct ? ' (earner gets ' + fmt(Math.floor(unit * pct / 100)) + ')' : '') + '. Unused money comes back to your wallet.';
    if (!f.proof_requirements.value && opt.dataset.hint) f.proof_requirements.placeholder = opt.dataset.hint;
  }
  f.addEventListener('input', update); f.addEventListener('change', update); update();
})();
</script>
<?php
yolotask_render_app($user, $draft ? 'Edit campaign' : 'New campaign', (string) ob_get_clean(), 'campaigns');
