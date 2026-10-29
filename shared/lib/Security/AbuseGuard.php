<?php
declare(strict_types=1);
namespace YoloTask\Security;

/** Light-weight sign-up abuse checks: honeypot, form timing and throwaway domains. */
final class AbuseGuard
{
    private const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com',
        'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'yopmail.com', 'throwawaymail.com', 'trashmail.com',
        'getnada.com', 'dispostable.com', 'maildrop.cc', 'fakeinbox.com', 'mintemail.com', 'mohmal.com',
        'emailondeck.com', 'tempail.com', 'moakt.com', 'burnermail.io', 'mailnesia.com', 'spamgourmet.com',
        'mytemp.email', 'tempr.email', 'discard.email', 'emailfake.com', 'inboxkitten.com', 'mail.tm',
    ];

    /** Issue a signed form stamp to embed in a hidden field. */
    public static function formStamp(string $form): string
    {
        $t = (string) time();
        return $t . '.' . substr(Crypto::hmac('form|' . $form . '|' . $t . '|' . session_id()), 0, 24);
    }

    /**
     * Returns null when the submission looks human, otherwise a generic error.
     * Humans never fill the hidden "website" field and take a few seconds to type.
     */
    public static function check(string $form, array $post, int $minSeconds = 3): ?string
    {
        if (trim((string) ($post['website'] ?? '')) !== '') return 'We could not process that request.';
        $stamp = (string) ($post['_stamp'] ?? '');
        if (!preg_match('/^(\d{9,11})\.([a-f0-9]{24})$/', $stamp, $m)) return 'Your form expired. Refresh the page and try again.';
        $expected = substr(Crypto::hmac('form|' . $form . '|' . $m[1] . '|' . session_id()), 0, 24);
        if (!hash_equals($expected, $m[2])) return 'Your form expired. Refresh the page and try again.';
        $age = time() - (int) $m[1];
        if ($age < $minSeconds) return 'That was quick. Please check your details and submit again.';
        if ($age > 7200) return 'Your form expired. Refresh the page and try again.';
        return null;
    }

    public static function isDisposableEmail(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '') return false;
        foreach (self::DISPOSABLE as $bad) {
            if ($domain === $bad || str_ends_with($domain, '.' . $bad)) return true;
        }
        return false;
    }
}
