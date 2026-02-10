<?php

namespace App\Filament\Resources\BankAccounts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class BankAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información de la Cuenta Bancaria')
                    ->description('Datos de la cuenta bancaria y su sucursal asociada')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('branch_id')
                            ->label('Sucursal')
                            ->prefixIcon('heroicon-o-building-office-2')
                            ->placeholder('Selecciona una sucursal')
                            ->relationship('branch', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Sucursal a la que pertenece esta cuenta'),

                        TextInput::make('name')
                            ->label('Nombre')
                            ->prefixIcon('heroicon-o-banknotes')
                            ->placeholder('Cuenta Operativa Principal')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Nombre descriptivo para identificar la cuenta'),

                        TextInput::make('account')
                            ->label('Número de Cuenta')
                            ->prefixIcon('heroicon-o-credit-card')
                            ->placeholder('0123-4567-8901234567')
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('branch_id', $get('branch_id'))
                            )
                            ->helperText('Número de cuenta bancaria (debe ser único por sucursal)'),

                        TextInput::make('sap_bank_key')
                            ->label('Clave Bancaria SAP')
                            ->prefixIcon('heroicon-o-key')
                            ->placeholder('1020-001-000')
                            ->maxLength(50)
                            ->helperText('BankAccountKey de SAP para envío de extractos bancarios'),
                    ])
                    ->columns(2),
            ]);
    }
}
