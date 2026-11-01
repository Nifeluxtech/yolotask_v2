<?php
declare(strict_types=1);

/** Level cards shared by the earner levels page and the public levels page (Phase 7). */

/**
 * @param list<array> $levels active levels in order (Growth\Levels::all($pdo, true))
 * @param ?int $currentId the viewer's current level id, or null (public page / no level yet)
 */
function yolotask_levels_list(array $levels, ?int $currentId = null): string
{
    $html = '<ol class="level-list">';
    foreach ($levels as $i => $l) {
        $isCurrent = $currentId !== null && (int) $l['id'] === $currentId;
        $slug = strtolower((string) preg_replace('/[^a-z]/i', '', (string) $l['name']));
        $req = (int) $l['min_approved'] === 0 && (int) $l['min_approval_rate'] === 0
            ? 'Where everyone starts'
            : number_format((int) $l['min_approved']) . '+ approved task' . ((int) $l['min_approved'] === 1 ? '' : 's')
                . ((int) $l['min_approval_rate'] > 0 ? ' · ' . (int) $l['min_approval_rate'] . '%+ approval rate' : '');
        $html .= '<li class="level-item' . ($isCurrent ? ' is-current' : '') . '"' . ($isCurrent ? ' aria-current="true" data-current-level' : '') . '>'
            . '<div class="level-item-head"><span class="level-badge level-' . h($slug) . '">' . yolotask_nav_icon('sparkle') . '<span>' . h((string) $l['name']) . '</span></span>'
            . '<span class="text-xs text-faint">Level ' . ($i + 1) . '</span>'
            . ($isCurrent ? '<span class="badge badge-success">Your level</span>' : '') . '</div>'
            . '<dl class="level-facts">'
            . '<div><dt>Requirements</dt><dd>' . h($req) . '</dd></div>'
            . '<div><dt>Tasks held at once</dt><dd>Up to ' . (int) $l['max_active_reservations'] . '</dd></div>'
            . '<div><dt>Premium tasks</dt><dd>' . ((int) $l['premium_access'] === 1 ? 'Unlocked' : 'Not included') . '</dd></div>'
            . '</dl></li>';
    }
    return $html . '</ol>';
}
