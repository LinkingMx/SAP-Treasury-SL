<?php

namespace App\Filament\Resources\LearningRules\Schemas;

use App\Models\LearningRule;
use App\Models\SapAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Set;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LearningRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tipo de Regla')
                    ->description('Define cómo se identificará esta regla')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('rule_type')
                            ->label('Tipo de Regla')
                            ->prefixIcon('heroicon-o-tag')
                            ->options([
                                LearningRule::TYPE_RFC => 'RFC - Identifica por RFC del tercero',
                                LearningRule::TYPE_ACTOR => 'Actor - Identifica por nombre del tercero',
                                LearningRule::TYPE_CONCEPTO => 'Concepto - Identifica por tipo de operación',
                            ])
                            ->default(LearningRule::TYPE_CONCEPTO)
                            ->required()
                            ->live()
                            ->helperText('El tipo determina la prioridad de matching'),

                        Select::make('priority')
                            ->label('Prioridad')
                            ->prefixIcon('heroicon-o-arrow-trending-up')
                            ->options([
                                LearningRule::PRIORITY_HIGH => 'Alta (1) - Se evalúa primero',
                                LearningRule::PRIORITY_MEDIUM => 'Media (2) - Evaluación estándar',
                                LearningRule::PRIORITY_LOW => 'Baja (3) - Fallback',
                            ])
                            ->default(LearningRule::PRIORITY_MEDIUM)
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Identificadores')
                    ->description('Datos específicos para el matching')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('rfc')
                            ->label('RFC')
                            ->prefixIcon('heroicon-o-identification')
                            ->placeholder('ABC123456XYZ')
                            ->maxLength(15)
                            ->helperText('RFC del tercero (12-13 caracteres)')
                            ->visible(fn ($get) => $get('rule_type') === LearningRule::TYPE_RFC),

                        TextInput::make('actor')
                            ->label('Actor/Tercero')
                            ->prefixIcon('heroicon-o-building-office')
                            ->placeholder('NOMBRE DE LA EMPRESA')
                            ->maxLength(100)
                            ->helperText('Nombre limpio del tercero (sin SA DE CV, etc.)')
                            ->visible(fn ($get) => $get('rule_type') === LearningRule::TYPE_ACTOR),

                        Textarea::make('pattern')
                            ->label('Patrón/Keywords')
                            ->placeholder('PALABRAS CLAVE, SEPARADAS POR COMA')
                            ->required()
                            ->rows(2)
                            ->helperText('Keywords que identifican este tipo de transacción')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Cuenta SAP')
                    ->description('Cuenta contable que se asignará')
                    ->columnSpanFull()
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
                            ->columnSpanFull(),

                        TextInput::make('sap_account_name')
                            ->label('Nombre de la Cuenta')
                            ->prefixIcon('heroicon-o-tag')
                            ->placeholder('Se llenará automáticamente')
                            ->disabled()
                            ->dehydrated()
                            ->columnSpanFull(),
                    ])
                    ->columns(1),

                Section::make('Configuración')
                    ->description('Parámetros adicionales')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('confidence_score')
                            ->label('Confianza')
                            ->prefixIcon('heroicon-o-chart-bar')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(100)
                            ->suffix('%')
                            ->required(),

                        Select::make('source')
                            ->label('Origen')
                            ->prefixIcon('heroicon-o-information-circle')
                            ->options([
                                'user_correction' => 'Corrección de Usuario',
                                'ai_high_confidence' => 'IA Alta Confianza',
                            ])
                            ->default('user_correction')
                            ->required(),

                        Select::make('match_type')
                            ->label('Tipo Match')
                            ->options([
                                'contains' => 'Contiene',
                                'exact' => 'Exacto',
                            ])
                            ->default('contains')
                            ->required(),
                    ])
                    ->columns(3),
            ]);
    }
}
