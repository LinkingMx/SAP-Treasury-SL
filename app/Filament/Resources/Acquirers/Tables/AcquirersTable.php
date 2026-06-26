<?php

namespace App\Filament\Resources\Acquirers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AcquirersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'BANK' => 'Banco',
                        'DELIVERY' => 'Delivery',
                        'WALLET' => 'Wallet',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'BANK' => 'success',
                        'DELIVERY' => 'warning',
                        'WALLET' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('parrot_payment_type_names')
                    ->label('Tipos de pago Parrot')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('amount_tolerance')
                    ->label('Tolerancia')
                    ->prefix('±$')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('time_window_seconds')
                    ->label('Ventana (s)')
                    ->numeric()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('active')
                    ->label('Activo')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
