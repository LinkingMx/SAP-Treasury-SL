<?php

namespace App\Models;

use App\Enums\VendorPaymentBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class VendorPaymentBatch extends Model
{
    protected $fillable = [
        'uuid',
        'branch_id',
        'bank_account_id',
        'user_id',
        'filename',
        'total_invoices',
        'total_payments',
        'total_amount',
        'status',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_invoices' => 'integer',
            'total_payments' => 'integer',
            'total_amount' => 'decimal:2',
            'status' => VendorPaymentBatchStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(VendorPaymentInvoice::class, 'batch_id');
    }
}
