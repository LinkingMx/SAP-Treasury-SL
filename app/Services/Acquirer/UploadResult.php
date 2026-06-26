<?php

namespace App\Services\Acquirer;

/**
 * Summary returned after reconciling a settlement upload.
 */
final class UploadResult
{
    public function __construct(
        public int $total,
        public int $matched,
    ) {}

    public function unmatched(): int
    {
        return $this->total - $this->matched;
    }
}
