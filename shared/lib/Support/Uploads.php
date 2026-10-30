<?php
declare(strict_types=1);
namespace YoloTask\Support;

use RuntimeException;

/**
 * Image upload validation for proof screenshots.
 *
 * Accepts JPEG, PNG and WebP up to a size limit. The type is decided from
 * the file's bytes (finfo + getimagesize), never from the name or the
 * browser's Content-Type. When GD is available the image is decoded and
 * re-encoded, which strips metadata and anything appended to the pixels.
 */
final class Uploads
{
    public const MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_PIXELS = 40000000; // 40 MP, guards GD memory
    private const MAX_SIDE = 10000;

    /**
     * Validate an entry of $_FILES and return the cleaned image bytes.
     * @return array{bytes:string,mime:string,ext:string,width:int,height:int,upload_sha256:string}
     */
    public static function image(mixed $file, int $maxBytes): array
    {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size']) || is_array($file['error'])) {
            throw new RuntimeException('Attach one screenshot of your proof.');
        }
        $err = (int) $file['error'];
        if ($err === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Attach a screenshot of your proof.');
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException(self::tooBig($maxBytes));
        if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('The upload did not finish. Please try again.');
        $tmp = (string) $file['tmp_name'];
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) throw new RuntimeException('The upload did not finish. Please try again.');
        $size = (int) @filesize($tmp);
        if ($size <= 0) throw new RuntimeException('The file is empty.');
        if ($size > $maxBytes) throw new RuntimeException(self::tooBig($maxBytes));
        $raw = (string) file_get_contents($tmp);
        return self::fromBytes($raw, $maxBytes);
    }

    /** Same checks for bytes already in memory (used by image() and tests). */
    public static function fromBytes(string $raw, int $maxBytes): array
    {
        if ($raw === '') throw new RuntimeException('The file is empty.');
        if (strlen($raw) > $maxBytes) throw new RuntimeException(self::tooBig($maxBytes));
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        if (!isset(self::MIME_EXT[$mime])) throw new RuntimeException('Upload a JPEG, PNG or WebP screenshot.');
        $info = @getimagesizefromstring($raw);
        if (!is_array($info) || ($info['mime'] ?? '') !== $mime) throw new RuntimeException('That file is not a readable image.');
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w < 50 || $h < 50) throw new RuntimeException('The screenshot is too small to check.');
        if ($w > self::MAX_SIDE || $h > self::MAX_SIDE || $w * $h > self::MAX_PIXELS) throw new RuntimeException('The image is too large. Upload a normal phone screenshot.');
        $uploadHash = hash('sha256', $raw);

        $bytes = $raw;
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($raw);
            if ($img === false) throw new RuntimeException('That file is not a readable image.');
            ob_start();
            $ok = match ($mime) {
                'image/png' => (function () use ($img) { imagesavealpha($img, true); return imagepng($img, null, 6); })(),
                'image/webp' => function_exists('imagewebp') ? imagewebp($img, null, 85) : imagejpeg($img, null, 85),
                default => imagejpeg($img, null, 85),
            };
            $bytes = (string) ob_get_clean();
            imagedestroy($img);
            if (!$ok || $bytes === '') throw new RuntimeException('That image could not be processed. Try another screenshot.');
            if ($mime === 'image/webp' && !function_exists('imagewebp')) $mime = 'image/jpeg';
        }
        return ['bytes' => $bytes, 'mime' => $mime, 'ext' => self::MIME_EXT[$mime], 'width' => $w, 'height' => $h, 'upload_sha256' => $uploadHash];
    }

    private static function tooBig(int $maxBytes): string
    {
        return 'The screenshot is larger than ' . round($maxBytes / 1048576, 1) . ' MB. Crop it or save it smaller.';
    }
}
