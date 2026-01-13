<?php

namespace App\Enums;

enum BatchStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Get the display label for the status.
     */
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
