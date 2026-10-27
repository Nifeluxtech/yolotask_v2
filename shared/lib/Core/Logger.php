<?php
declare(strict_types=1);
namespace YoloTask\Core;

final class Logger
{
    public static function error(string $event, array $context = []): void
    {
        // Avoid logging secrets and full financial/provider payloads.
        $line = json_encode(['time'=>gmdate('c'),'level'=>'error','event'=>$event,'context'=>$context], JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR);
        error_log($line ?: 'YOLOTASK logging failure');
    }
}
