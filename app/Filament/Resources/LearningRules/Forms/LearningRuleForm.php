<?php

namespace App\Filament\Resources\LearningRules\Forms;

use App\Models\SapAccount;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Set;

class LearningRuleForm
{
    public static function configure(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Patrón de Coincidencia')
                    ->description('Define el texto que debe coincidir para aplicar esta regla')
                    ->schema([
                        Textarea::make('pattern')
                            ->label('Patrón')
                            ->placeholder('Texto que debe coincidir con el memo de la transacción')
                            ->required()
                            ->rows(3)
                            ->helperText('Este texto se comparará con el memo de las transacciones'),

                        Select::make('match_type')
                            ->label('Tipo de Coincidencia')
                            ->prefixIcon('heroicon-o-magnifying-glass')
                            ->options([
                                'contains' => 'Contiene - El memo contiene este patrón',
                                'exact' => 'Exacto - El memo es exactamente igual',
                            ])
                            ->default('contains')
                            ->required()
                            ->helperText('Selecciona cómo debe coincidir el patrón'),
                    ])
                    ->columns(1),

                Section::make('Cuenta SAP')
                    ->description('Cuenta contable que se asignará cuando coincida el patrón')
                    ->schema([
                        Select::make('sap_account_code')
                            ->label('Cuenta SAP')
                            ->prefixIcon('heroicon-o-clipboard-document-list')
                            ->placeholder('Selecciona una cuenta SAP')
                            ->options(function () {
                                return SapAccount::query()
                                    ->active()
                                    ->orderBy('code')
                                    ->limit(500)
                                    ->get()
                                    ->mapWithKeys(fn ($account) => [
                                        $account->code => "{$account->code} - {$account->name}",
                                    ]);
                            })
                            ->searchable()
                            ->required()
                            ->afterStateUpdated(function (Set $set, ?string $state) {
                                if ($state) {
                                    $account = SapAccount::where('code', $state)->first();
                                    if ($account) {
                                        $set('sap_account_name', $account->name);
                                    }
                                }
                            })
                            ->live()
                            ->helperText('La cuenta que se asignará automáticamente'),

                        TextInput::make('sap_account_name')
                            ->label('Nombre de la Cuenta')
                            ->prefixIcon('heroicon-o-tag')
                            ->placeholder('Se llenará automáticamente')
                            ->disabled()
                            ->dehydrated(),
                    ])
                    ->columns(1),

                Section::make('Configuración')
                    ->description('Parámetros adicionales de la regla')
                    ->schema([
                        TextInput::make('confidence_score')
                            ->label('Puntuación de Confianza')
                            ->prefixIcon('heroicon-o-chart-bar')
                            ->placeholder('100')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(100)
                            ->suffix('%')
                            ->required()
                            ->helperText('Qué tan confiable es esta regla (0-100)'),

                        Select::make('source')
                            ->label('Origen')
                            ->prefixIcon('heroicon-o-information-circle')
                            ->options([
                                'manual' => 'Manual',
                                'user_correction' => 'Corrección de Usuario',
                                'ai_learned' => 'Aprendida por IA',
                            ])
                            ->default('manual')
                            ->required()
                            ->helperText('Cómo se creó esta regla'),
                    ])
                    ->columns(2),
            ]);
    }
}
