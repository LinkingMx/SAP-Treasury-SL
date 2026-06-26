<?php

namespace App\Services\Acquirer;

/**
 * Outcome of matching a single settlement row against the Parrot payments.
 */
final class MatchResult
{
    public function __construct(
        public int $rowIndex,
        public ?int $parrotPaymentId,
        public ?string $orderReference,
        public ?float $paymentTotal,
        public ?float $diff,
        public ?string $method,
    ) {}

    public function matched(): bool
    {
        return $this->parrotPaymentId !== null;
    }
}
