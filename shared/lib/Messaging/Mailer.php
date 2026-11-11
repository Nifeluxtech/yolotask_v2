<?php
declare(strict_types=1);
namespace YoloTask\Messaging;

use YoloTask\Core\Logger;
use YoloTask\Support\Storage;

/**
 * Transactional email. Driver "mail" uses the host's sendmail (cPanel),
 * driver "log" writes messages to a file for local testing.
 *
 * Every real send passes a site-wide outbound budget first (default 25 per
 * 15 minutes and 60 per hour, config mail.max_per_15min / mail.max_per_hour).
 * Shared hosting suspends accounts that send bulk mail, so the app must never
 * be able to do that, whether through a script, a bot on a form, or a burst
 * of review notifications. Mail over the budget is dropped and logged.
 * Announcements to many users belong in a bulk email service, not here.
 */
final class Mailer
{
    /**
     * @param array{label:string,url:string}|null $button
     */
    public static function send(string $to, string $subject, string $heading, array $paragraphs, ?array $button = null, ?string $code = null, ?string $footnote = null): bool
    {
        $config = function_exists('yolotask_config') ? (yolotask_config()['mail'] ?? []) : [];
        $from = (string) ($config['from'] ?? 'no-reply@yolotask.com.ng');
        $fromName = (string) ($config['from_name'] ?? 'YOLOTASK');
        $driver = (string) ($config['driver'] ?? 'mail');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
        $subject = str_replace(["\r", "\n"], ' ', $subject);

        if (($driver !== 'log' || !empty($config['budget_in_log'])) && !self::withinBudget($config)) {
            Logger::error('mail.budget_exceeded', ['subject' => mb_substr($subject, 0, 80)]);
            return false;
        }

        $html = self::renderHtml($heading, $paragraphs, $button, $code, $footnote);
        $text = self::renderText($heading, $paragraphs, $button, $code, $footnote);
        $boundary = 'yt' . bin2hex(random_bytes(12));
        $headers = [
            'From: ' . self::encodeName($fromName) . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Auto-Response-Suppress: All',
            'Auto-Submitted: auto-generated',
        ];
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--{$boundary}--\r\n";

        if ($driver === 'log') {
            $file = (string) ($config['log_file'] ?? sys_get_temp_dir() . '/yolotask-mail.log');
            $entry = json_encode(['time' => gmdate('c'), 'to' => $to, 'subject' => $subject, 'text' => $text], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) !== false;
        }

        $smtpHeaders = array_merge(['To: ' . $to, 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=', 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000'], $headers);
        if ($driver === 'sendmail') {
            $bin = (string) ($config['sendmail_path'] ?? '/usr/sbin/sendmail');
            if (function_exists('proc_open')) {
                try {
                    $proc = proc_open([$bin, '-t', '-i', '-f', $from], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    if (is_resource($proc)) {
                        fwrite($pipes[0], implode("\n", $smtpHeaders) . "\n\n" . str_replace("\r\n", "\n", $body));
                        fclose($pipes[0]);
                        $err = stream_get_contents($pipes[2]);
                        fclose($pipes[1]); fclose($pipes[2]);
                        $code = proc_close($proc);
                        if ($code === 0) return true;
                        Logger::error('mail.sendmail_failed', ['code' => $code, 'stderr' => substr((string) $err, 0, 300)]);
                    }
                } catch (\Throwable $e) {
                    Logger::error('mail.sendmail_error', ['message' => $e->getMessage()]);
                }
            } else {
                Logger::error('mail.sendmail_unavailable', ['reason' => 'proc_open() is disabled']);
            }
            if (empty($config['smtp_host'])) return false;
            $driver = 'smtp';
        }
        if ($driver === 'smtp') {
            try {
                self::smtp($config, $from, $to, array_merge(['To: ' . $to, 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=', 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000', 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . substr(strrchr($from, '@') ?: '@yolotask.com.ng', 1) . '>'], $headers), $body);
                return true;
            } catch (\Throwable $e) {
                Logger::error('mail.smtp_failed', ['message' => $e->getMessage()]);
                return false;
            }
        }

        if (!function_exists('mail')) {
            Logger::error('mail.unavailable', ['reason' => 'mail() is disabled on this host']);
            return false;
        }
        try {
            $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers), '-f' . $from);
        } catch (\Throwable $e) {
            Logger::error('mail.send_error', ['exception' => get_class($e), 'message' => $e->getMessage()]);
            return false;
        }
        if (!$ok) Logger::error('mail.send_failed', ['subject' => $subject]);
        return $ok;
    }

    /** Minimal authenticated SMTP client (implicit TLS on 465, or STARTTLS on 587). */
    private static function smtp(array $c, string $from, string $to, array $headers, string $body): void
    {
        $host = (string) ($c['smtp_host'] ?? 'localhost');
        $port = (int) ($c['smtp_port'] ?? 465);
        $verify = (bool) ($c['smtp_verify_peer'] ?? true);
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify]]);
        $scheme = $port === 465 ? 'ssl://' : 'tcp://';
        $plain = $port === 25 && !empty($c['smtp_plain']);
        $fp = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new \RuntimeException("connect {$host}:{$port} failed: {$errstr}");
        stream_set_timeout($fp, 20);
        $read = static function () use ($fp): string {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) { $out .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
            return $out;
        };
        $cmd = static function (?string $line, array $ok) use ($fp, $read): string {
            if ($line !== null) fwrite($fp, $line . "\r\n");
            $resp = $read();
            if (!in_array((int) substr($resp, 0, 3), $ok, true)) throw new \RuntimeException('SMTP ' . trim($resp));
            return $resp;
        };
        try {
            $cmd(null, [220]);
            $helo = 'yolotask.com.ng';
            $cmd('EHLO ' . $helo, [250]);
            if ($port !== 465 && !$plain) {
                $cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new \RuntimeException('STARTTLS failed');
                $cmd('EHLO ' . $helo, [250]);
            }
            if ((string) ($c['smtp_pass'] ?? '') !== '') {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode((string) ($c['smtp_user'] ?? $from)), [334]);
                $cmd(base64_encode((string) $c['smtp_pass']), [235]);
            }
            $cmd('MAIL FROM:<' . $from . '>', [250]);
            $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $cmd('DATA', [354]);
            $data = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/^\./m', '..', $body);
            $cmd($data . "\r\n.", [250]);
            fwrite($fp, "QUIT\r\n");
        } finally {
            fclose($fp);
        }
    }

    /**
     * Records one send against the site-wide budget and says whether it fits.
     * Uses a locked file under private runtime storage so web requests, cron
     * and CLI scripts share one count. Fails closed if storage is unusable.
     */
    private static function withinBudget(array $config): bool
    {
        $per15 = max(1, (int) ($config['max_per_15min'] ?? 25));
        $perHour = max($per15, (int) ($config['max_per_hour'] ?? 60));
        $dir = Storage::dir('cache');
        if ($dir === null) {
            Logger::error('mail.budget_storage_unavailable', []);
            return false;
        }
        $fp = @fopen($dir . '/mail-budget.json', 'c+');
        if ($fp === false) {
            Logger::error('mail.budget_storage_unavailable', []);
            return false;
        }
        try {
            if (!flock($fp, LOCK_EX)) return false;
            $raw = stream_get_contents($fp);
            $now = time();
            $stamps = array_values(array_filter(
                is_array($d = json_decode((string) $raw, true)) ? $d : [],
                static fn($t) => is_int($t) && $t > $now - 3600 && $t <= $now + 60
            ));
            $recent = count(array_filter($stamps, static fn(int $t) => $t > $now - 900));
            if ($recent >= $per15 || count($stamps) >= $perHour) return false;
            $stamps[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($stamps));
            fflush($fp);
            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * The branded HTML and plain-text bodies, for senders outside this class
     * (the Brevo announcement script).
     * @return array{html:string,text:string}
     */
    public static function render(string $heading, array $paragraphs, ?array $button = null, ?string $footnote = null): array
    {
        return ['html' => self::renderHtml($heading, $paragraphs, $button, null, $footnote), 'text' => self::renderText($heading, $paragraphs, $button, null, $footnote)];
    }

    private static function encodeName(string $name): string
    {
        return preg_match('/^[A-Za-z0-9 ._-]+$/', $name) ? '"' . $name . '"' : '=?UTF-8?B?' . base64_encode($name) . '?=';
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function renderText(string $heading, array $paragraphs, ?array $button, ?string $code, ?string $footnote): string
    {
        $out = $heading . "\n\n";
        foreach ($paragraphs as $p) $out .= $p . "\n\n";
        if ($code !== null) $out .= "Your code: {$code}\n\n";
        if ($button !== null) $out .= $button['label'] . ': ' . $button['url'] . "\n\n";
        if ($footnote !== null) $out .= $footnote . "\n\n";
        return $out . "— YOLOTASK\nThis is an automated message. Please do not reply.";
    }

    private static function renderHtml(string $heading, array $paragraphs, ?array $button, ?string $code, ?string $footnote): string
    {
        $p = '';
        foreach ($paragraphs as $para) {
            $p .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#C9D6EA">' . self::e($para) . '</p>';
        }
        $codeHtml = $code === null ? '' :
            '<div style="margin:8px 0 24px;padding:18px;border-radius:14px;background:#0A1628;border:1px solid #1E3A5F;text-align:center;font:800 32px/1 \'Courier New\',monospace;letter-spacing:10px;color:#00D4FF">' . self::e($code) . '</div>';
        $btn = $button === null ? '' :
            '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:8px 0 24px"><tr><td style="border-radius:12px;background:#00B8E6;background-image:linear-gradient(90deg,#00D4FF,#4338CA)">'
            . '<a href="' . self::e($button['url']) . '" style="display:inline-block;padding:14px 28px;font:700 15px Arial,sans-serif;color:#ffffff;text-decoration:none;border-radius:12px">' . self::e($button['label']) . '</a></td></tr></table>'
            . '<p style="margin:0 0 16px;font-size:12px;line-height:1.5;color:#7F93B2;word-break:break-all">Or paste this link into your browser: ' . self::e($button['url']) . '</p>';
        $foot = $footnote === null ? '' : '<p style="margin:0;font-size:13px;line-height:1.5;color:#7F93B2">' . self::e($footnote) . '</p>';
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#060E1C;font-family:Arial,Helvetica,sans-serif">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#060E1C;padding:32px 12px"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px">'
            . '<tr><td style="padding:0 4px 18px;font:900 22px Arial,sans-serif;letter-spacing:.5px;color:#00D4FF">YOLOTASK</td></tr>'
            . '<tr><td style="background:#0F1F38;border:1px solid #1E3A5F;border-radius:20px;padding:32px 28px">'
            . '<div style="height:3px;width:64px;border-radius:3px;background:#00D4FF;background-image:linear-gradient(90deg,#00D4FF,#4338CA);margin-bottom:22px"></div>'
            . '<h1 style="margin:0 0 16px;font:800 24px/1.25 Arial,sans-serif;color:#FFFFFF">' . self::e($heading) . '</h1>'
            . $p . $codeHtml . $btn . $foot
            . '</td></tr>'
            . '<tr><td style="padding:18px 4px 0;font-size:12px;line-height:1.5;color:#5E7393">You received this because of activity on your YOLOTASK account. YOLOTASK will never ask for your password.<br>© ' . gmdate('Y') . ' YOLOTASK by Nifelux</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
