<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPaymentInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'card_code',
        'card_name',
        'doc_date',
        'transfer_date',
        'transfer_account',
        'line_num',
        'doc_entry',
        'invoice_type',
        'sum_applied',
        'sap_doc_num',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
            'transfer_date' => 'date',
            'line_num' => 'integer',
            'doc_entry' => 'integer',
            'sum_applied' => 'decimal:2',
            'sap_doc_num' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CustomerPaymentBatch::class, 'batch_id');
    }
}
