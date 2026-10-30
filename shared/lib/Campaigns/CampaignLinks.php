<?php
declare(strict_types=1);
namespace YoloTask\Campaigns;

use PDO;
use RuntimeException;

/**
 * Campaign link rules: https only, a public host that belongs to the chosen
 * platform, no credentials, no IP addresses. Links are normalised (lower-case
 * host, no www./m., no tracking parameters, no fragment or trailing slash)
 * and hashed, so the same target cannot run twice for the same task type
 * while an earlier campaign is still open, and an earner who already did a
 * task for a target never sees a duplicate campaign for it.
 */
final class CampaignLinks
{
    private const TRACKING = ['fbclid', 'gclid', 'igshid', 'igsh', 'si', 'feature', 'ref', 'ref_src', 's', 't', 'mibextid', 'is_from_webapp', 'sender_device', 'share_id'];

    /** @return list<string> lower-case domains from a comma/space separated list */
    public static function parseDomains(string $domains): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower($domains)) ?: [] as $d) {
            $d = trim($d, ". \t");
            if ($d !== '' && preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $d) && strlen($d) <= 60) $out[$d] = $d;
        }
        return array_values($out);
    }

    /**
     * Validate a link for a platform.
     * @return array{url:string,normalized:string,hash:string}
     */
    public static function validate(string $url, array $platform): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500) throw new RuntimeException('Paste the full link (up to 500 characters).');
        if (preg_match('/[\s<>"\'\\\\]/', $url)) throw new RuntimeException('The link contains characters that are not allowed.');
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('Use a full https:// link, for example https://instagram.com/yourpage.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) throw new RuntimeException('The link must not contain a username, password or port.');
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (filter_var($host, FILTER_VALIDATE_IP) || str_starts_with($host, '[') || !preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $host)) {
            throw new RuntimeException('The link must use a normal web address, not an IP address.');
        }
        $domains = self::parseDomains((string) ($platform['domains'] ?? ''));
        if ($domains !== []) {
            $ok = false;
            foreach ($domains as $d) {
                if ($host === $d || str_ends_with($host, '.' . $d)) { $ok = true; break; }
            }
            if (!$ok) throw new RuntimeException('That link is not a ' . $platform['name'] . ' link. Use a link on ' . implode(', ', $domains) . '.');
        }
        $normalized = self::normalize($parts, $host);
        return ['url' => $url, 'normalized' => $normalized, 'hash' => hash('sha256', $normalized)];
    }

    public static function normalize(array $parts, string $host): string
    {
        $host = (string) preg_replace('/^(?:www\.|m\.|mobile\.|web\.)/', '', $host);
        if ($host === 'twitter.com') $host = 'x.com';
        if ($host === 'youtu.be') {
            $id = trim((string) ($parts['path'] ?? ''), '/');
            return 'youtube.com/watch?v=' . $id;
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        // Usernames are case-insensitive on these hosts; invite codes and video ids elsewhere are not.
        if (in_array($host, ['instagram.com', 'tiktok.com', 'x.com', 'threads.net', 'threads.com', 'facebook.com'], true)) $path = strtolower($path);
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (array_keys($query) as $k) {
            if (str_starts_with(strtolower((string) $k), 'utm_') || in_array(strtolower((string) $k), self::TRACKING, true)) unset($query[$k]);
        }
        ksort($query);
        return $host . $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /** True when another open campaign already targets this link with this task type. */
    public static function duplicateOpen(PDO $pdo, string $hash, int $taskTypeId, ?int $exceptCampaignId = null): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM campaigns WHERE link_hash = :h AND task_type_id = :t AND status IN ('pending_approval', 'live', 'paused') AND id <> :id");
        $stmt->execute(['h' => $hash, 't' => $taskTypeId, 'id' => $exceptCampaignId ?? 0]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
