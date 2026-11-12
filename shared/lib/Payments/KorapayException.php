<?php
declare(strict_types=1);
namespace YoloTask\Payments;

/**
 * Korapay API failure. $definitive = true when Korapay clearly refused (so
 * nothing was created); false when the outcome is unknown (timeout, network,
 * 5xx) and the order must stay pending until a webhook or verify settles it.
 */
final class KorapayException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $definitive)
    {
        parent::__construct($message);
    }
}
