<?php

namespace App\Services\Acquirer;

/**
 * Per-acquirer matching configuration, built from an Acquirer model.
 */
final class MatchRule
{
    /**
     * @param  array<int, string>  $parrotTypes  Parrot payment_type_name values this acquirer covers
     * @param  float  $tolerance  max absolute difference between settlement amount and payment.total
     * @param  int|null  $timeWindowSeconds  optional max seconds between settlement time and payment time
     */
    public function __construct(
        public array $parrotTypes,
        public float $tolerance,
        public ?int $timeWindowSeconds = null,
    ) {}
}
