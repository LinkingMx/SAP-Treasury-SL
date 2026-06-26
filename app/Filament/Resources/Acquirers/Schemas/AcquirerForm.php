<?php

namespace App\Filament\Resources\Acquirers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class AcquirerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Datos del Adquirente')
                    ->description('Catálogo de adquirentes y agregadores que liquidan los pagos del POS.')
                    ->icon(Heroicon::OutlinedBuildingLibrary)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('code')
                            ->label('Código')
                            ->prefixIcon(Heroicon::OutlinedHashtag)
                            ->placeholder('MIFEL')
                            ->required()
                            ->maxLength(30)
                            ->unique(ignoreRecord: true)
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state)))
                            ->helperText('Identificador único en mayúsculas. Ej. MIFEL, AFIRME, RAPPI, UBER_EATS.'),

                        TextInput::make('name')
                            ->label('Nombre')
                            ->prefixIcon(Heroicon::OutlinedBuildingLibrary)
                            ->placeholder('CC MIFEL')
                            ->required()
                            ->maxLength(80),

                        Select::make('kind')
                            ->label('Tipo')
                            ->prefixIcon(Heroicon::OutlinedTag)
                            ->placeholder('Selecciona el tipo')
                            ->required()
                            ->options([
                                'BANK' => 'Banco / Adquirente de tarjeta',
                                'DELIVERY' => 'Agregador de delivery',
                                'WALLET' => 'Monedero / Wallet',
                            ])
                            ->helperText('Banco para tarjeta (MIFEL/AFIRME); delivery para plataformas (Rappi/Uber Eats).'),

                        TagsInput::make('parrot_payment_type_names')
                            ->label('Tipos de pago Parrot')
                            ->placeholder('CREDITO')
                            ->required()
                            ->helperText('Valores de payment_type_name de Parrot que cubre este adquirente. Ej. CREDITO, DEBITO, AMEX — o Rappi / Uber Eats. Enter para agregar cada uno.')
                            ->columnSpanFull(),

                        TextInput::make('amount_tolerance')
                            ->label('Tolerancia de monto')
                            ->prefix('±$')
                            ->placeholder('0.10')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->default(0.10)
                            ->helperText('Diferencia máxima permitida entre el monto del adquirente y el pago Parrot. Bancos ~0.10, delivery ~0.50.'),

                        TextInput::make('time_window_seconds')
                            ->label('Ventana de tiempo (segundos)')
                            ->prefixIcon(Heroicon::OutlinedClock)
                            ->placeholder('1800')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Opcional. Segundos máximos entre la hora de la transacción y la del pago Parrot. Vacío = no usar hora.'),

                        Toggle::make('active')
                            ->label('Activo')
                            ->default(true)
                            ->helperText('Solo los adquirentes activos se ofrecen al cargar conciliaciones.'),
                    ])
                    ->columns(2),
            ]);
    }
}
