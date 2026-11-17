<?php
declare(strict_types=1);

use YoloTask\Campaigns\Campaigns;
use YoloTask\Core\Database;
use YoloTask\Core\Logger;
use YoloTask\Jobs\Jobs;
use YoloTask\Money\Money;
use YoloTask\Money\Wallet;
use YoloTask\Security\Csrf;
use YoloTask\Security\Throttle;

require_once __DIR__ . '/jobs-helpers.php';

/*
 * Advertiser: post a job (migration 0023). The advertiser writes everything:
 * name, overall instructions, optional link, online/offline (+ location),
 * 2..jobs.max_steps steps with their proof kinds, pay per earner, slots, time
 * limit and review window. Publishing locks pay x slots from the wallet and
 * the job is live at once (no admin approval).
 */
if (yolotask_role() !== 'advertiser') yolotask_error_page(404);
yolotask_private_headers();
try {
    $pdo = Database::connection();
    $user = yolotask_require_user($pdo);
} catch (Throwable $e) {
    Logger::error('job_new.load_failed', ['exception' => get_class($e)]);
    yolotask_error_page(500);
}
$uid = (int) $user['id'];
$fields = ['title', 'instructions', 'link_url', 'mode', 'location', 'pay', 'slots', 'time_limit', 'time_unit', 'review_hours'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    yolotask_csrf_or_back('job-new.php');
    $in = [];
    foreach ($fields as $f) $in[$f] = yolotask_post_str($f);
    $in['steps'] = [];
    foreach (is_array($_POST['steps'] ?? null) ? $_POST['steps'] : [] as $s) {
        if (!is_array($s)) continue;
        $in['steps'][] = ['title' => is_string($s['title'] ?? null) ? $s['title'] : '', 'instructions' => is_string($s['instructions'] ?? null) ? $s['instructions'] : '',
            'kinds' => is_array($s['kinds'] ?? null) ? array_values(array_filter($s['kinds'], 'is_string')) : []];
    }
    try {
        if (Throttle::tooMany($pdo, 'job_create', (string) $uid, 20, 3600)) throw new RuntimeException('You have posted many jobs in the last hour. Please wait a little.');
        $id = Jobs::publish($pdo, $user, $in);
        Throttle::hit($pdo, 'job_create', (string) $uid);
        unset($_SESSION['_job_form']);
        yolotask_flash('Job published and live. Earners can start it now.');
        yolotask_redirect('job.php?id=' . $id);
    } catch (RuntimeException $e) {
        $_SESSION['_job_form'] = $in;
        yolotask_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        Logger::error('job_new.publish_failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        $_SESSION['_job_form'] = $in;
        yolotask_flash('Something went wrong. Please try again.', 'error');
    }
    yolotask_redirect('job-new.php');
}

$old = is_array($_SESSION['_job_form'] ?? null) ? $_SESSION['_job_form'] : null;
unset($_SESSION['_job_form']);
$val = static fn (string $k, string $default = ''): string => $old !== null ? (string) ($old[$k] ?? $default) : $default;
$oldSteps = $old !== null && is_array($old['steps'] ?? null) ? array_values($old['steps']) : [];
$enabled = Jobs::enabled($pdo);
$maxSteps = Jobs::maxSteps($pdo);
$minPay = Jobs::minPay($pdo);
$percent = Jobs::earnerPercent($pdo);
$tl = Jobs::timeLimitBounds($pdo);
$rb = Campaigns::autoApproveBounds($pdo);
$reviewDefault = (string) max($rb['min'], min($rb['max'], Campaigns::advertiserAutoApproveHours($pdo, $uid)));
$wallet = Wallet::forUser($pdo, $uid);
$shown = max(2, count($oldSteps));
$csrf = Csrf::token();
ob_start();
?>
<div class="wallet-grid">
  <?php if (!$enabled): ?>
    <section class="card notice-card" role="status"><div><strong>Posting jobs is paused</strong><p class="text-sm text-faint">YOLOTASK has switched jobs off for now. Your existing jobs keep running.</p></div></section>
  <?php elseif (empty($user['email_verified_at'])): ?>
    <section class="card verify-banner" role="status"><span class="verify-icon"><?= yolotask_nav_icon('bell') ?></span>
      <div><strong>Confirm your email first</strong><p class="text-sm text-faint">Jobs can be posted once your email address is confirmed.</p></div><a class="btn btn-primary btn-sm" href="verify.php">Confirm now</a></section>
  <?php endif; ?>
  <form method="post" action="job-new.php" class="stack" data-job-form>
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <section class="card" aria-labelledby="jf-title">
      <div class="card-header"><h2 id="jf-title">About the job</h2><span class="badge badge-info">Wallet <?= h(yolotask_money($wallet['available_kobo'])) ?></span></div>
      <div class="field"><label class="label" for="title">Job name</label>
        <input class="input" id="title" name="title" required minlength="3" maxlength="120" value="<?= h($val('title')) ?>" placeholder="Review our app and record a short video"></div>
      <div class="field"><label class="label" for="instructions">Overall instructions</label>
        <textarea class="input" id="instructions" name="instructions" rows="4" required minlength="10" maxlength="5000" placeholder="What the job is about, who should take it and anything earners must know before they start."><?= h($val('instructions')) ?></textarea></div>
      <div class="field"><label class="label" for="link_url">Link (optional)</label>
        <input class="input" id="link_url" name="link_url" type="url" maxlength="500" inputmode="url" value="<?= h($val('link_url')) ?>" placeholder="https://"></div>
      <div class="grid grid-2 form-pair">
        <div class="field"><label class="label" for="mode">Where is the work done?</label>
          <select class="input" id="mode" name="mode"><option value="online"<?= $val('mode') !== 'offline' ? ' selected' : '' ?>>Online</option><option value="offline"<?= $val('mode') === 'offline' ? ' selected' : '' ?>>Offline (in person)</option></select></div>
        <div class="field" data-job-location><label class="label" for="location">Location (offline jobs)</label>
          <input class="input" id="location" name="location" maxlength="160" value="<?= h($val('location')) ?>" placeholder="State, city or area, e.g. Ikeja, Lagos">
          <p class="input-hint">Earners see this before they start.</p></div>
      </div>
    </section>

    <section class="card" aria-labelledby="js-title">
      <div class="card-header"><h2 id="js-title">Steps</h2><span class="badge badge-info" data-step-count><?= $shown ?> of <?= $maxSteps ?></span></div>
      <p class="text-sm text-faint">Between 2 and <?= $maxSteps ?> steps. Earners do them in order and send proof for each; step 2 opens once step 1 has its proof. Every proof kind you tick is required.</p>
      <?php for ($i = 0; $i < $maxSteps; $i++): $s = $oldSteps[$i] ?? ['title' => '', 'instructions' => '', 'kinds' => []]; $kinds = is_array($s['kinds'] ?? null) ? $s['kinds'] : []; ?>
        <fieldset class="card job-step-field stack" data-step="<?= $i + 1 ?>"<?= $i >= $shown ? ' data-extra' : '' ?>>
          <legend class="fw-bold">Step <?= $i + 1 ?><?= $i < 2 ? '' : ' (optional)' ?></legend>
          <div class="field"><label class="label" for="st<?= $i ?>">Step title</label>
            <input class="input" id="st<?= $i ?>" name="steps[<?= $i ?>][title]" maxlength="120" value="<?= h((string) ($s['title'] ?? '')) ?>"<?= $i < 2 ? ' required' : '' ?>></div>
          <div class="field"><label class="label" for="si<?= $i ?>">What to do</label>
            <textarea class="input" id="si<?= $i ?>" name="steps[<?= $i ?>][instructions]" rows="3" maxlength="3000"<?= $i < 2 ? ' required' : '' ?>><?= h((string) ($s['instructions'] ?? '')) ?></textarea></div>
          <div class="field"><span class="label">Proof needed</span>
            <div class="chip-row">
              <?php foreach (Jobs::PROOF_KINDS as $k => $label): ?>
                <label class="check-chip"><input type="checkbox" name="steps[<?= $i ?>][kinds][]" value="<?= h($k) ?>"<?= in_array($k, $kinds, true) ? ' checked' : '' ?>> <?= h($label) ?></label>
              <?php endforeach; ?>
            </div></div>
        </fieldset>
      <?php endfor; ?>
      <div class="hero-actions"><button class="btn btn-secondary btn-sm" type="button" data-add-step>Add a step</button><button class="btn btn-ghost btn-sm" type="button" data-remove-step>Remove last step</button></div>
    </section>

    <section class="card" aria-labelledby="jp-title">
      <div class="card-header"><h2 id="jp-title">Pay and timing</h2></div>
      <div class="grid grid-2 form-pair">
        <div class="field"><label class="label" for="pay">Pay per earner (₦)</label>
          <input class="input" id="pay" name="pay" type="number" min="<?= intdiv($minPay, 100) ?>" step="1" inputmode="numeric" required value="<?= h($val('pay')) ?>" placeholder="<?= intdiv($minPay, 100) ?>">
          <p class="input-hint">At least <?= h(Money::format($minPay)) ?>. The earner receives <?= $percent ?>%; YOLOTASK keeps <?= 100 - $percent ?>%.</p></div>
        <div class="field"><label class="label" for="slots">Slots (earners needed)</label>
          <input class="input" id="slots" name="slots" type="number" min="1" max="<?= Jobs::MAX_SLOTS ?>" required value="<?= h($val('slots')) ?>" placeholder="10"></div>
      </div>
      <div class="grid grid-2 form-pair">
        <div class="field"><label class="label" for="time_limit">Time to finish once started</label>
          <div class="copy-field"><input class="input" id="time_limit" name="time_limit" type="number" min="1" required value="<?= h($val('time_limit', '24')) ?>">
            <select class="input" name="time_unit" aria-label="Unit"><option value="hours"<?= $val('time_unit', 'hours') !== 'days' ? ' selected' : '' ?>>hours</option><option value="days"<?= $val('time_unit') === 'days' ? ' selected' : '' ?>>days</option></select></div>
          <p class="input-hint">Between <?= h(Jobs::hoursLabel($tl['min'])) ?> and <?= h(Jobs::hoursLabel($tl['max'])) ?>. Unfinished attempts end and the slot opens again.</p></div>
        <div class="field"><label class="label" for="review_hours">Your review window (hours)</label>
          <input class="input" id="review_hours" name="review_hours" type="number" min="<?= $rb['min'] ?>" max="<?= $rb['max'] ?>" required value="<?= h($val('review_hours', $reviewDefault)) ?>">
          <p class="input-hint"><?= $rb['min'] ?> to <?= $rb['max'] ?> hours. Submissions you do not review in time are approved automatically.</p></div>
      </div>
      <div class="cost-preview card" aria-live="polite"><span class="text-sm text-faint">Locked from your wallet now</span><strong data-cost>—</strong><span class="text-xs text-faint" data-cost-note>Enter the pay and slots.</span></div>
      <button class="btn btn-primary btn-block" type="submit"<?= !$enabled || empty($user['email_verified_at']) ? ' disabled' : '' ?>>Publish job</button>
      <p class="input-hint text-center">The job goes live at once. You can pause it, add slots or close it any time; unused money comes back to your wallet when you close it.</p>
    </section>
  </form>
  <p class="text-center text-sm"><a href="jobs.php">Back to jobs</a></p>
</div>
<style>.job-step-field[hidden],[data-job-location][hidden]{display:none}.chip-row{display:flex;flex-wrap:wrap;gap:.5rem}.check-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .6rem;border:1px solid var(--border);border-radius:999px;font-size:.85rem}</style>
<script>
(function () {
  var f = document.querySelector('[data-job-form]'); if (!f) return;
  var steps = Array.prototype.slice.call(f.querySelectorAll('[data-step]')), max = steps.length;
  var shown = <?= (int) $shown ?>, count = f.querySelector('[data-step-count]');
  function render() { steps.forEach(function (el, i) { el.hidden = i >= shown; }); count.textContent = shown + ' of ' + max; }
  f.querySelector('[data-add-step]').addEventListener('click', function () { if (shown < max) { shown++; render(); steps[shown - 1].querySelector('input').focus(); } });
  f.querySelector('[data-remove-step]').addEventListener('click', function () {
    if (shown <= 2) return;
    var el = steps[shown - 1]; el.querySelectorAll('input[type=text],input:not([type]),textarea').forEach(function (x) { x.value = ''; });
    el.querySelectorAll('input[type=checkbox]').forEach(function (x) { x.checked = false; }); shown--; render();
  });
  var loc = f.querySelector('[data-job-location]');
  function mode() { var off = f.mode.value === 'offline'; loc.hidden = !off; f.location.required = off; }
  var pct = <?= (int) $percent ?>, out = f.querySelector('[data-cost]'), note = f.querySelector('[data-cost-note]');
  var fmt = function (k) { return '\u20A6' + (k / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function cost() {
    var pay = (parseInt(f.pay.value, 10) || 0) * 100, n = parseInt(f.slots.value, 10) || 0;
    if (!pay || !n) { out.textContent = '\u2014'; return; }
    out.textContent = fmt(pay * n);
    note.textContent = fmt(pay) + ' \u00D7 ' + n.toLocaleString('en-NG') + ' slot' + (n === 1 ? '' : 's') + '. Each earner gets ' + fmt(Math.floor(pay * pct / 100)) + '.';
  }
  f.addEventListener('input', cost); f.addEventListener('change', function () { mode(); cost(); });
  render(); mode(); cost();
})();
</script>
<?php
yolotask_render_app($user, 'Post a job', (string) ob_get_clean(), 'jobs');
