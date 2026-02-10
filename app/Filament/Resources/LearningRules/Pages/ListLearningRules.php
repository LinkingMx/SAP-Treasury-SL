<?php

namespace App\Filament\Resources\LearningRules\Pages;

use App\Filament\Resources\LearningRules\LearningRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLearningRules extends ListRecords
{
    protected static string $resource = LearningRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva Regla')
                ->icon('heroicon-o-plus'),
        ];
    }
}
