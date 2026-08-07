<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file layout this acquirer has sent before, so we never have to work it out
 * (or pay an AI to work it out) twice.
 */
class AcquirerLayout extends Model
{
    /** @use HasFactory<\Database\Factories\AcquirerLayoutFactory> */
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AI = 'ai';

    protected $fillable = [
        'acquirer_id',
        'fingerprint',
        'label',
        'column_map',
        'date_format',
        'delimiter',
        'header_row',
        'source',
        'times_used',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'column_map' => 'array',
            'header_row' => 'integer',
            'times_used' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function acquirer(): BelongsTo
    {
        return $this->belongsTo(Acquirer::class);
    }

    /**
     * Fingerprint a file by its HEADER NAMES, normalized and sorted.
     *
     * Deliberately excludes the data rows: the bank-side attempt hashed the first
     * 30 lines of the file, so a new statement every month produced a new
     * fingerprint and the cache could never hit. Header names are stable, and
     * sorting means a reordered export still matches — the stored map resolves
     * columns by name, so the indexes are recomputed anyway.
     *
     * @param  array<int, string>  $headers
     */
    public static function fingerprintFor(array $headers): string
    {
        $normalized = array_values(array_filter(array_map(
            static function ($header): string {
                $value = mb_strtolower(trim((string) $header));
                $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);

                return (string) preg_replace('/\s+/', ' ', $value);
            },
            $headers,
        ), static fn (string $h): bool => $h !== ''));

        sort($normalized);

        return hash('sha256', implode('|', $normalized));
    }

    /**
     * Look up a learned layout for this acquirer + file shape.
     */
    public static function findFor(int $acquirerId, string $fingerprint): ?self
    {
        return static::query()
            ->where('acquirer_id', $acquirerId)
            ->where('fingerprint', $fingerprint)
            ->first();
    }

    /**
     * Record that this layout was applied — lets us measure how often we avoid
     * re-deriving (or re-paying for) a mapping.
     */
    public function markUsed(): void
    {
        // Atomic: a stale in-memory counter (the column default lives in the DB)
        // would otherwise reset the count on a freshly created row.
        $this->increment('times_used', 1, ['last_used_at' => now()]);
    }
}
