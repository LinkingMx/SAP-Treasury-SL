<?php

namespace App\Models;

use App\Services\Acquirer\MatchRule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Acquirer extends Model
{
    /** @use HasFactory<\Database\Factories\AcquirerFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'kind',
        'parrot_payment_type_names',
        'amount_tolerance',
        'time_window_seconds',
        'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parrot_payment_type_names' => 'array',
            'amount_tolerance' => 'decimal:2',
            'time_window_seconds' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * Build the matching rule for this acquirer.
     */
    public function matchRule(): MatchRule
    {
        return new MatchRule(
            parrotTypes: $this->parrot_payment_type_names ?? [],
            tolerance: (float) $this->amount_tolerance,
            timeWindowSeconds: $this->time_window_seconds,
        );
    }

    /**
     * Get the uploads for this acquirer.
     */
    public function uploads(): HasMany
    {
        return $this->hasMany(SettlementUpload::class);
    }
}
