<?php

namespace App\Filament\Resources\LearningRules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LearningRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rule_type')
                    ->label('Tipo Regla')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'RFC' => 'success',
                        'ACTOR' => 'info',
                        'CONCEPTO' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('priority')
                    ->label('Prioridad')
                    ->badge()
                    ->color(fn (int $state): string => match ($state) {
                        1 => 'success',
                        2 => 'warning',
                        3 => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        1 => 'Alta',
                        2 => 'Media',
                        3 => 'Baja',
                        default => (string) $state,
                    }),

                TextColumn::make('actor')
                    ->label('Actor')
                    ->searchable()
                    ->placeholder('—')
                    ->limit(30)
                    ->toggleable(),

                TextColumn::make('rfc')
                    ->label('RFC')
                    ->searchable()
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('pattern')
                    ->label('Patrón/Keywords')
                    ->searchable()
                    ->wrap()
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->pattern),

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
                    ->limit(30)
                    ->toggleable(),

                TextColumn::make('confidence_score')
                    ->label('Conf.')
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
                        'ai_high_confidence' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'user_correction' => 'Usuario',
                        'ai_high_confidence' => 'IA',
                        default => $state,
                    })
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('rule_type')
                    ->label('Tipo de Regla')
                    ->options([
                        'RFC' => 'RFC',
                        'ACTOR' => 'Actor',
                        'CONCEPTO' => 'Concepto',
                    ]),

                SelectFilter::make('priority')
                    ->label('Prioridad')
                    ->options([
                        1 => 'Alta',
                        2 => 'Media',
                        3 => 'Baja',
                    ]),

                SelectFilter::make('source')
                    ->label('Origen')
                    ->options([
                        'user_correction' => 'Corrección Usuario',
                        'ai_high_confidence' => 'IA',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('priority')
            ->striped()
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50);
    }
}
