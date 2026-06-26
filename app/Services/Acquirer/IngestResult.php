<?php

namespace App\Services\Acquirer;

/**
 * Summary returned after ingesting (dedup-inserting) a settlement upload.
 */
final class IngestResult
{
    public function __construct(
        public int $total,
        public int $inserted,
        public int $duplicates,
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
    ) {}
}
