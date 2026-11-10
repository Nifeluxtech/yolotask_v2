<?php
// php tests/Unit/Mail/mail_budget_test.php — outbound mail budget caps bursts.
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/yt-mailbudget-' . getmypid();
@mkdir($tmp, 0700, true);
$cfg = ['mail' => ['driver' => 'log', 'budget_in_log' => true, 'max_per_15min' => 5, 'max_per_hour' => 8,
    'log_file' => $tmp . '/mail.log', 'from' => 'no-reply@yolotask.com.ng'], 'storage' => ['private_root' => $tmp]];
function yolotask_config(): array { global $cfg; return $cfg; }
require __DIR__ . '/../../../shared/autoload.php';
use YoloTask\Messaging\Mailer;
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond) use (&$ok, &$fail) { if ($cond) { $ok++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } };
$sent = 0;
for ($i = 0; $i < 7; $i++) if (Mailer::send("u{$i}@example.com", 'Hi', 'Hi', ['x'])) $sent++;
$check('15-minute cap stops the 6th send', $sent === 5);
file_put_contents($tmp . '/cache/mail-budget.json', json_encode(array_fill(0, 8, time() - 1800)));
$check('hourly cap blocks when 8 sent in the hour', !Mailer::send('a@example.com', 'Hi', 'Hi', ['x']));
file_put_contents($tmp . '/cache/mail-budget.json', json_encode(array_fill(0, 8, time() - 4000)));
$check('old sends expire', Mailer::send('a@example.com', 'Hi', 'Hi', ['x']));
$cfg['mail']['budget_in_log'] = false;
$all = true; for ($i = 0; $i < 20; $i++) $all = Mailer::send('b@example.com', 'Hi', 'Hi', ['x']) && $all;
$check('log driver without budget_in_log is unthrottled (tests)', $all);
$lines = count(file($tmp . '/mail.log'));
$check('only allowed mail was written', $lines === 5 + 1 + 20);
exec('rm -rf ' . escapeshellarg($tmp));
echo "mail budget: {$ok}/" . ($ok + $fail) . " passed\n";
exit($fail ? 1 : 0);
