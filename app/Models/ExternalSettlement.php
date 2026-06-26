<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExternalSettlement extends Model
{
    /** @use HasFactory<\Database\Factories\ExternalSettlementFactory> */
    use HasFactory;

    public const MATCH_UNMATCHED = 'unmatched';

    public const MATCH_MATCHED = 'matched';

    public const MATCH_DISCARDED = 'discarded';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'upload_id',
        'acquirer_id',
        'branch_id',
        'transaction_date',
        'transaction_time',
        'amount',
        'card_type',
        'card_brand',
        'authorization',
        'reference',
        'terminal',
        'operation_type',
        'status',
        'match_status',
        'raw',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
            'raw' => 'array',
        ];
    }

    /**
     * Get the upload that owns the settlement row.
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(SettlementUpload::class, 'upload_id');
    }

    /**
     * Get the acquirer that owns the settlement row.
     */
    public function acquirer(): BelongsTo
    {
        return $this->belongsTo(Acquirer::class);
    }

    /**
     * Get the branch that owns the settlement row.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the reconciliation link, if matched.
     */
    public function paymentOrder(): HasOne
    {
        return $this->hasOne(PaymentOrder::class);
    }
}
