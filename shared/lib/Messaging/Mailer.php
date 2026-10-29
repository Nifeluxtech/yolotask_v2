<?php
declare(strict_types=1);
namespace YoloTask\Messaging;

use YoloTask\Core\Logger;

/**
 * Transactional email. Driver "mail" uses the host's sendmail (cPanel),
 * driver "log" writes messages to a file for local testing.
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
