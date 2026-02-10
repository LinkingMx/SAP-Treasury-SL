<?php

namespace App\Filament\Resources\SapAccounts;

use App\Filament\Resources\SapAccounts\Pages\ListSapAccounts;
use App\Filament\Resources\SapAccounts\Tables\SapAccountsTable;
use App\Models\SapAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

class SapAccountResource extends Resource
{
    protected static ?string $model = SapAccount::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $modelLabel = 'Cuenta SAP';

    protected static ?string $pluralModelLabel = 'Cuentas SAP';

    protected static ?string $navigationLabel = 'Catálogo de Cuentas';

    protected static string|UnitEnum|null $navigationGroup = 'SAP';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return SapAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSapAccounts::route('/'),
        ];
    }

    /**
     * Disable create - accounts are synced from SAP.
     */
    public static function canCreate(): bool
    {
        return false;
    }
}
