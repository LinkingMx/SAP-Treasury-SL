<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentOrder extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentOrderFactory> */
    use HasFactory;

    public const METHOD_AUTO_EXACT = 'auto_exact';

    public const METHOD_AUTO_FUZZY = 'auto_fuzzy';

    public const METHOD_MANUAL = 'manual';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'upload_id',
        'external_settlement_id',
        'acquirer_id',
        'branch_id',
        'parrot_payment_id',
        'order_reference',
        'payment_total',
        'external_reference',
        'match_method',
        'match_diff',
        'matched_at',
        'matched_by_user_id',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parrot_payment_id' => 'integer',
            'payment_total' => 'decimal:2',
            'match_diff' => 'decimal:2',
            'matched_at' => 'datetime',
        ];
    }

    /**
     * Get the upload that owns the reconciliation link.
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(SettlementUpload::class, 'upload_id');
    }

    /**
     * Get the external settlement row that was reconciled.
     */
    public function externalSettlement(): BelongsTo
    {
        return $this->belongsTo(ExternalSettlement::class);
    }

    /**
     * Get the acquirer for this reconciliation link.
     */
    public function acquirer(): BelongsTo
    {
        return $this->belongsTo(Acquirer::class);
    }

    /**
     * Get the branch for this reconciliation link.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the user who matched manually, if any.
     */
    public function matchedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by_user_id');
    }
}
