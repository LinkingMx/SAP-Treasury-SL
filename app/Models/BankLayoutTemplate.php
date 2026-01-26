<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankLayoutTemplate extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'fingerprint',
        'bank_id',
        'bank_name_guess',
        'parse_config',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parse_config' => 'array',
        ];
    }

    /**
     * Get the bank that owns the template.
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * Find a template by its fingerprint.
     */
    public static function findByFingerprint(string $fingerprint): ?self
    {
        return static::where('fingerprint', $fingerprint)->first();
    }
}
