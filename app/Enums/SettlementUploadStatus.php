<?php

namespace App\Enums;

enum SettlementUploadStatus: string
{
    case Pending = 'pending';
    case Parsing = 'parsing';
    case Matching = 'matching';
    case Done = 'done';
    case Failed = 'failed';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Parsing => 'Leyendo archivo',
            self::Matching => 'Conciliando',
            self::Done => 'Conciliado',
            self::Failed => 'Fallido',
        };
    }
}
