<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use RuntimeException;

/** AfrStar API failure. definitive = AfrStar clearly refused (nothing was created). */
final class AfrstarException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $definitive)
    {
        parent::__construct($message);
    }
}
