<?php
declare(strict_types=1);

/** View helpers for the Jobs pages (migration 0023): advertiser, earner, reviewer and admin. */

require_once __DIR__ . '/tasks-helpers.php';

use YoloTask\Jobs\Attempts;
use YoloTask\Jobs\JobAppeals;
use YoloTask\Jobs\Jobs;

function yolotask_job_badge(array $job): string
{
    [$label, $class] = Jobs::statusLabel((string) $job['status'], (int) ($job['admin_paused'] ?? 0) === 1);
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

function yolotask_attempt_badge(array $a): string
{
    [$label, $class] = Attempts::statusLabel($a);
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

function yolotask_job_appeal_badge(array $ap): string
{
    [$label, $class] = JobAppeals::statusLabel($ap);
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

/** Online / Offline badge, with the location for offline jobs. */
function yolotask_job_mode(array $job): string
{
    if ((string) $job['mode'] === 'offline') {
        return '<span class="badge badge-warning" data-job-mode="offline">Offline</span>' . ($job['location'] ? ' <span class="text-xs text-faint text-break" data-job-location>📍 ' . h((string) $job['location']) . '</span>' : '');
    }
    return '<span class="badge badge-info" data-job-mode="online">Online</span>';
}

/** "Screenshot / image · Text answer" for a step. */
function yolotask_job_kinds(array $step): string
{
    $labels = array_map(static fn (string $k): string => Jobs::PROOF_KINDS[$k], Jobs::kinds((string) $step['proof_kinds']));
    return '<span class="text-xs text-faint">Proof: ' . h(implode(' + ', $labels)) . '</span>';
}

/** The saved proof of one step (text, link, image, file); files go through job-proof.php. */
function yolotask_job_step_proof(array $step, ?array $proof): string
{
    if ($proof === null) return '<p class="text-xs text-faint">No proof yet.</p>';
    $html = '<div class="job-proof stack" data-step-proof="' . (int) $step['id'] . '">';
    if (trim((string) ($proof['text_answer'] ?? '')) !== '') $html .= '<div class="instructions text-sm text-break">' . nl2br(h((string) $proof['text_answer'])) . '</div>';
    if (trim((string) ($proof['link_url'] ?? '')) !== '') {
        $safe = yolotask_safe_task_url((string) $proof['link_url']);
        $html .= $safe !== null ? '<p class="text-sm text-break"><a href="' . h($safe) . '" target="_blank" rel="noopener noreferrer nofollow">' . h($safe) . ' ↗</a></p>' : '<p class="text-sm text-break text-faint">' . h((string) $proof['link_url']) . '</p>';
    }
    if ($proof['image_file_id'] !== null) {
        $url = 'job-proof.php?id=' . (int) $proof['image_file_id'];
        $html .= '<a class="proof-thumb" href="' . h($url) . '" target="_blank" rel="noopener"><img src="' . h($url) . '" alt="Step ' . (int) $step['position'] . ' screenshot" loading="lazy" decoding="async"></a>';
    }
    if ($proof['file_file_id'] !== null) {
        $html .= '<p class="text-sm"><a class="btn btn-ghost btn-sm" href="job-proof.php?id=' . (int) $proof['file_file_id'] . '">Download ' . h((string) ($proof['file_name'] ?? 'file'))
            . ($proof['file_bytes'] !== null ? ' (' . number_format((int) $proof['file_bytes'] / 1048576, 2) . ' MB)' : '') . '</a></p>';
    }
    $html .= '<p class="text-xs text-faint">Saved ' . h(yolotask_wat((string) $proof['updated_at'])) . ((int) ($proof['edits'] ?? 0) > 0 ? ' · edited' : '') . '</p>';
    return $html . '</div>';
}

/** Every step with its instructions and saved proof, for reviewers of an attempt (advertiser, reviewer, admin). */
function yolotask_job_review_steps(array $steps, array $proofs, ?array $attempt = null): string
{
    $html = '';
    foreach ($steps as $s) {
        $sid = (int) $s['id'];
        $isBack = $attempt !== null && (int) ($attempt['sent_back_step_id'] ?? 0) === $sid && in_array((string) $attempt['status'], ['sent_back', 'submitted'], true) && (int) $attempt['send_back_count'] > 0;
        $html .= '<section class="card" data-review-step="' . (int) $s['position'] . '"><div class="card-header"><h2 class="text-break">Step ' . (int) $s['position'] . ' · ' . h((string) $s['title']) . '</h2>'
            . ($isBack ? '<span class="badge badge-warning">Sent back</span>' : (Attempts::stepDone($s, $proofs[$sid] ?? null) ? '<span class="badge badge-success">Done</span>' : '<span class="badge badge-info">Not done</span>')) . '</div>'
            . '<details><summary class="text-sm">Instructions</summary><div class="instructions text-sm text-break">' . nl2br(h((string) $s['instructions'])) . '</div></details>'
            . yolotask_job_kinds($s) . yolotask_job_step_proof($s, $proofs[$sid] ?? null) . '</section>';
    }
    return $html;
}

/** Send-back history list. */
function yolotask_job_send_backs(array $backs): string
{
    if ($backs === []) return '';
    $html = '<ul class="tx-list">';
    foreach ($backs as $b) {
        $html .= '<li class="tx-row"><div class="tx-meta"><strong>Round ' . (int) $b['round'] . ': step ' . (int) $b['position'] . ' · ' . h((string) $b['step_title']) . '</strong>'
            . '<span class="text-sm text-break">“' . h((string) $b['note']) . '”</span>'
            . '<span class="text-xs text-faint">Sent ' . h(yolotask_wat((string) $b['created_at'])) . ($b['resubmitted_at'] ? ' · resubmitted ' . h(yolotask_wat((string) $b['resubmitted_at'])) : ' · fix by ' . h(yolotask_wat((string) $b['fix_deadline_at']))) . '</span></div></li>';
    }
    return $html . '</ul>';
}
