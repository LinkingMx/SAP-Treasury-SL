<?php

namespace App\Filament\Resources\Acquirers\Pages;

use App\Filament\Resources\Acquirers\AcquirerResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAcquirer extends EditRecord
{
    protected static string $resource = AcquirerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->icon('heroicon-o-building-library')
            ->title('Adquirente actualizado')
            ->body('El adquirente ha sido actualizado exitosamente.');
    }
}
