<?php

namespace App\Filament\Resources\LearningRules\Pages;

use App\Filament\Resources\LearningRules\LearningRuleResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLearningRule extends CreateRecord
{
    protected static string $resource = LearningRuleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->icon('heroicon-o-check-circle')
            ->iconColor('success')
            ->title('Regla creada')
            ->body('La regla de aprendizaje se ha creado correctamente.');
    }
}
