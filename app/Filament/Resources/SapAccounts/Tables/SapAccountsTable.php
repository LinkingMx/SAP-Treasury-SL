<?php

namespace App\Filament\Resources\SapAccounts\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SapAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('code')
                    ->label('Código')
                    ->sortable()
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Código copiado')
                    ->weight('bold')
                    ->color('primary'),

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->wrap()
                    ->limit(60),

                TextColumn::make('account_type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Activo' => 'info',
                        'Pasivo' => 'warning',
                        'Capital' => 'success',
                        'Ingreso' => 'success',
                        'Costo' => 'danger',
                        'Gasto' => 'danger',
                        default => 'gray',
                    }),

                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                TextColumn::make('updated_at')
                    ->label('Sincronizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('Sucursal')
                    ->relationship('branch', 'name')
                    ->preload()
                    ->searchable(),

                SelectFilter::make('account_type')
                    ->label('Tipo')
                    ->options([
                        'Activo' => 'Activo',
                        'Pasivo' => 'Pasivo',
                        'Capital' => 'Capital',
                        'Ingreso' => 'Ingreso',
                        'Costo' => 'Costo',
                        'Gasto' => 'Gasto',
                    ]),

                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Solo activas')
                    ->falseLabel('Solo inactivas'),
            ])
            ->defaultSort('code')
            ->striped()
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50);
    }
}
