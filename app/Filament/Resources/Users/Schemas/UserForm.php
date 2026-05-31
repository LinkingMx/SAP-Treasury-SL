<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)
                    ->columnSpanFull()
                    ->schema([
                        Section::make('Información del Usuario')
                            ->description('Datos personales y credenciales de acceso.')
                            ->icon(Heroicon::OutlinedUser)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nombre')
                                    ->prefixIcon(Heroicon::OutlinedUser)
                                    ->placeholder('Juan Pérez')
                                    ->required()
                                    ->maxLength(255),

                                TextInput::make('email')
                                    ->label('Email')
                                    ->prefixIcon(Heroicon::OutlinedEnvelope)
                                    ->placeholder('usuario@ejemplo.com')
                                    ->email()
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(255)
                                    ->helperText('Este correo será utilizado para notificaciones y acceso al sistema.'),

                                TextInput::make('password')
                                    ->label('Contraseña')
                                    ->prefixIcon(Heroicon::OutlinedLockClosed)
                                    ->placeholder('••••••••')
                                    ->password()
                                    ->revealable()
                                    ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                                    ->dehydrated(fn ($state) => filled($state))
                                    ->required(fn (string $operation): bool => $operation === 'create')
                                    ->rule(Password::defaults())
                                    ->confirmed()
                                    ->helperText(fn (string $operation): string => $operation === 'edit'
                                        ? 'Dejar en blanco para mantener la contraseña actual.'
                                        : 'Mínimo 8 caracteres.'),

                                TextInput::make('password_confirmation')
                                    ->label('Confirmar Contraseña')
                                    ->prefixIcon(Heroicon::OutlinedLockClosed)
                                    ->placeholder('••••••••')
                                    ->password()
                                    ->revealable()
                                    ->required(fn (string $operation): bool => $operation === 'create')
                                    ->dehydrated(false),
                            ])
                            ->columns(1),

                        Grid::make(1)
                            ->schema([
                                Section::make('Roles y Permisos')
                                    ->description('Asigna los roles de acceso al usuario.')
                                    ->icon(Heroicon::OutlinedShieldCheck)
                                    ->schema([
                                        CheckboxList::make('roles')
                                            ->label('Roles')
                                            ->relationship('roles', 'name')
                                            ->columns(2)
                                            ->helperText('Selecciona los roles que tendrá este usuario.'),
                                    ])
                                    ->columns(1),

                                Section::make('Sucursales')
                                    ->description('Asigna las sucursales a las que tendrá acceso el usuario.')
                                    ->icon(Heroicon::OutlinedBuildingOffice2)
                                    ->schema([
                                        Select::make('branches')
                                            ->label('Sucursales')
                                            ->prefixIcon(Heroicon::OutlinedBuildingOffice2)
                                            ->relationship('branches', 'name')
                                            ->multiple()
                                            ->preload()
                                            ->searchable()
                                            ->helperText('Selecciona una o más sucursales.'),
                                    ])
                                    ->columns(1),
                            ]),
                    ]),
            ]);
    }
}
