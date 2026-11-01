<?php
declare(strict_types=1);
namespace YoloTask\Payments;

use RuntimeException;

/**
 * The fee shown to the earner in the preview is no longer the fee that
 * applies (an admin changed the rate or the earner's level changed). Nothing
 * was created; the route shows the new preview so the earner can confirm again.
 */
final class WithdrawalFeeChanged extends RuntimeException
{
    public function __construct(public readonly array $quote)
    {
        parent::__construct('The withdrawal fee changed to ' . \YoloTask\Money\Fees::percent((int) $quote['bps']) . ' (' . \YoloTask\Money\Money::format((int) $quote['fee'])
            . '). You would receive ' . \YoloTask\Money\Money::format((int) $quote['net']) . '. Check the new amount and confirm again.');
    }
}
