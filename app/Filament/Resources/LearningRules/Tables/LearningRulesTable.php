<?php

namespace App\Filament\Resources\LearningRules\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LearningRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('pattern')
                    ->label('Patrón')
                    ->searchable()
                    ->wrap()
                    ->limit(80)
                    ->tooltip(fn ($record) => $record->pattern),

                TextColumn::make('match_type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'exact' => 'success',
                        'contains' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'exact' => 'Exacto',
                        'contains' => 'Contiene',
                        default => $state,
                    }),

                TextColumn::make('sap_account_code')
                    ->label('Cuenta SAP')
                    ->sortable()
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Código copiado')
                    ->weight('bold')
                    ->color('primary'),

                TextColumn::make('sap_account_name')
                    ->label('Nombre Cuenta')
                    ->searchable()
                    ->wrap()
                    ->limit(40),

                TextColumn::make('confidence_score')
                    ->label('Confianza')
                    ->suffix('%')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 90 => 'success',
                        $state >= 70 => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('source')
                    ->label('Origen')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'user_correction' => 'success',
                        'ai_learned' => 'info',
                        'manual' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'user_correction' => 'Corrección Usuario',
                        'ai_learned' => 'IA',
                        'manual' => 'Manual',
                        default => $state,
                    }),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('match_type')
                    ->label('Tipo de Coincidencia')
                    ->options([
                        'exact' => 'Exacto',
                        'contains' => 'Contiene',
                    ]),

                SelectFilter::make('source')
                    ->label('Origen')
                    ->options([
                        'user_correction' => 'Corrección Usuario',
                        'ai_learned' => 'IA',
                        'manual' => 'Manual',
                    ]),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('confidence_score', 'desc')
            ->striped()
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50);
    }
}
