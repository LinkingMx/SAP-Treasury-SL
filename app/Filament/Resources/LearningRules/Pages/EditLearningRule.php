<?php

namespace App\Filament\Resources\LearningRules\Pages;

use App\Filament\Resources\LearningRules\LearningRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLearningRule extends EditRecord
{
    protected static string $resource = LearningRuleResource::class;

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
            ->icon('heroicon-o-check-circle')
            ->iconColor('success')
            ->title('Regla actualizada')
            ->body('La regla de aprendizaje se ha actualizado correctamente.');
    }
}
