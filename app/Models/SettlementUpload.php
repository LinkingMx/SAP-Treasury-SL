<?php

namespace App\Models;

use App\Enums\SettlementUploadStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SettlementUpload extends Model
{
    /** @use HasFactory<\Database\Factories\SettlementUploadFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'acquirer_id',
        'branch_id',
        'user_id',
        'original_name',
        'stored_path',
        'period_start',
        'period_end',
        'status',
        'total_rows',
        'inserted_rows',
        'duplicate_rows',
        'matched_rows',
        'unmatched_rows',
        'error_log',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => SettlementUploadStatus::class,
            'total_rows' => 'integer',
            'inserted_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'matched_rows' => 'integer',
            'unmatched_rows' => 'integer',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SettlementUpload $upload) {
            if (empty($upload->uuid)) {
                $upload->uuid = Str::uuid()->toString();
            }
        });
    }

    /**
     * Get the acquirer that owns the upload.
     */
    public function acquirer(): BelongsTo
    {
        return $this->belongsTo(Acquirer::class);
    }

    /**
     * Get the branch that owns the upload.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the user that created the upload.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the external settlements for the upload.
     */
    public function externalSettlements(): HasMany
    {
        return $this->hasMany(ExternalSettlement::class, 'upload_id');
    }

    /**
     * Get the payment orders (reconciliation links) for the upload.
     */
    public function paymentOrders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class, 'upload_id');
    }
}
