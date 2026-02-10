<?php

namespace App\Filament\Resources\Banks\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BankForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información del Banco')
                    ->description('Datos del banco para identificación en el sistema')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->prefixIcon('heroicon-o-building-library')
                            ->placeholder('Santander')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Nombre del banco tal como aparece en los estados de cuenta')
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ]);
    }
}
