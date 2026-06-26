<?php

namespace App\Filament\Resources\Acquirers;

use App\Filament\Resources\Acquirers\Pages\CreateAcquirer;
use App\Filament\Resources\Acquirers\Pages\EditAcquirer;
use App\Filament\Resources\Acquirers\Pages\ListAcquirers;
use App\Filament\Resources\Acquirers\Schemas\AcquirerForm;
use App\Filament\Resources\Acquirers\Tables\AcquirersTable;
use App\Models\Acquirer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AcquirerResource extends Resource
{
    protected static ?string $model = Acquirer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $modelLabel = 'Adquirente';

    protected static ?string $pluralModelLabel = 'Adquirentes';

    protected static ?string $navigationLabel = 'Adquirentes';

    protected static string|UnitEnum|null $navigationGroup = 'Tesorería';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return AcquirerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AcquirersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAcquirers::route('/'),
            'create' => CreateAcquirer::route('/create'),
            'edit' => EditAcquirer::route('/{record}/edit'),
        ];
    }
}
