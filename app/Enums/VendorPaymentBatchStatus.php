<?php

namespace App\Enums;

enum VendorPaymentBatchStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Processing => 'Procesando',
            self::Completed => 'Completado',
            self::Failed => 'Fallido',
        };
    }
}
