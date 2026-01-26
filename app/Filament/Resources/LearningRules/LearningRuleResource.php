<?php

namespace App\Filament\Resources\LearningRules;

use App\Filament\Resources\LearningRules\Pages\CreateLearningRule;
use App\Filament\Resources\LearningRules\Pages\EditLearningRule;
use App\Filament\Resources\LearningRules\Pages\ListLearningRules;
use App\Filament\Resources\LearningRules\Schemas\LearningRuleForm;
use App\Filament\Resources\LearningRules\Tables\LearningRulesTable;
use App\Models\LearningRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class LearningRuleResource extends Resource
{
    protected static ?string $model = LearningRule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $modelLabel = 'Regla de Aprendizaje';

    protected static ?string $pluralModelLabel = 'Reglas de Aprendizaje';

    protected static ?string $navigationLabel = 'Reglas de Clasificación';

    protected static string|UnitEnum|null $navigationGroup = 'IA';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return LearningRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LearningRulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLearningRules::route('/'),
            'create' => CreateLearningRule::route('/create'),
            'edit' => EditLearningRule::route('/{record}/edit'),
        ];
    }
}
