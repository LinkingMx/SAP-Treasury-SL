<?php

namespace App\Filament\Resources\Acquirers\Pages;

use App\Filament\Resources\Acquirers\AcquirerResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateAcquirer extends CreateRecord
{
    protected static string $resource = AcquirerResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->icon('heroicon-o-building-library')
            ->title('Adquirente creado')
            ->body('El adquirente ha sido creado exitosamente.');
    }
}
