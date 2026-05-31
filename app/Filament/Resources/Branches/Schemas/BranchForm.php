<?php

namespace App\Filament\Resources\Branches\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información de la Sucursal')
                    ->description('Datos generales y configuración SAP de la sucursal.')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->prefixIcon(Heroicon::OutlinedBuildingOffice2)
                            ->placeholder('Sucursal Centro')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('sap_database')
                            ->label('Base de Datos SAP')
                            ->prefixIcon(Heroicon::OutlinedCircleStack)
                            ->placeholder('SBO_PRODUCCION')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Nombre de la base de datos de SAP Business One.'),

                        TextInput::make('sap_branch_id')
                            ->label('ID de Sucursal SAP')
                            ->prefixIcon(Heroicon::OutlinedHashtag)
                            ->placeholder('1')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Identificador numérico de la sucursal en SAP.'),

                        TextInput::make('ceco')
                            ->label('Centro de Costos (CECO)')
                            ->prefixIcon(Heroicon::OutlinedCalculator)
                            ->placeholder('CC-001')
                            ->maxLength(255)
                            ->helperText('Código del centro de costos en SAP.'),

                        TextInput::make('afirme_account')
                            ->label('Cuenta CLABE Afirme')
                            ->prefixIcon(Heroicon::OutlinedCreditCard)
                            ->placeholder('012345678901234567')
                            ->maxLength(18)
                            ->regex('/^\d{18}$/')
                            ->helperText('Cuenta CLABE de 18 dígitos para transferencias Afirme.'),
                    ])
                    ->columns(2),
            ]);
    }
}
