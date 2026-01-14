<?php

namespace App\Filament\Resources\Branches\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información de la Sucursal')
                    ->description('Datos de la sucursal y su configuración en SAP')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->prefixIcon('heroicon-o-building-office-2')
                            ->placeholder('Sucursal Centro')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('sap_database')
                            ->label('Base de Datos SAP')
                            ->prefixIcon('heroicon-o-circle-stack')
                            ->placeholder('SBO_PRODUCCION')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Nombre de la base de datos de SAP Business One'),

                        TextInput::make('sap_branch_id')
                            ->label('ID de Sucursal SAP')
                            ->prefixIcon('heroicon-o-hashtag')
                            ->placeholder('1')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Identificador numérico de la sucursal en SAP'),

                        TextInput::make('ceco')
                            ->label('Centro de Costos (CECO)')
                            ->prefixIcon('heroicon-o-calculator')
                            ->placeholder('CC-001')
                            ->maxLength(255)
                            ->helperText('Código del centro de costos en SAP'),

                        TextInput::make('afirme_account')
                            ->label('Cuenta CLABE Afirme')
                            ->prefixIcon('heroicon-o-credit-card')
                            ->placeholder('012345678901234567')
                            ->maxLength(18)
                            ->regex('/^\d{18}$/')
                            ->helperText('Cuenta CLABE de 18 dígitos para transferencias Afirme'),
                    ])
                    ->columns(1),
            ]);
    }
}
