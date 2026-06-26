<?php

namespace App\Filament\Resources\Acquirers\Pages;

use App\Filament\Resources\Acquirers\AcquirerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAcquirers extends ListRecords
{
    protected static string $resource = AcquirerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
