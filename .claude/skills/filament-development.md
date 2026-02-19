# Filament Development Skill

Activate this skill when working with Filament resources, forms, tables, or admin panel features.

## Core Principles

- **Always use native Filament components**. No custom CSS or JS unless absolutely necessary and with prior authorization.
- **All UI text in Spanish**: labels, plurals, model names, navigation groups, notifications, etc.

## Soft Deletes (Mandatory)

All models managed by Filament MUST use soft deletes. No exceptions.

### Migration

```php
$table->softDeletes();
```

### Model

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Example extends Model
{
    use SoftDeletes;
}
```

### Resource Table

Always include trash filters and restore/force delete actions:

```php
use Filament\Tables\Filters\TrashedFilter;

public static function table(Table $table): Table
{
    return $table
        ->columns([
            // columns...
        ])
        ->filters([
            TrashedFilter::make(),
        ])
        ->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
            Tables\Actions\RestoreAction::make(),
            Tables\Actions\ForceDeleteAction::make(),
        ])
        ->bulkActions([
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(),
                Tables\Actions\RestoreBulkAction::make(),
                Tables\Actions\ForceDeleteBulkAction::make(),
            ]),
        ]);
}
```

### Resource Query

```php
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->withTrashed();
}
```

## Redirects After Create/Edit

All create and edit actions MUST redirect back to the resource index. No exceptions.

### In CreateRecord Page

```php
protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}
```

### In EditRecord Page

```php
protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}
```

## Custom Notifications

All notifications must include: icon (primary color), title, and body (subtitle).

### After Create

```php
protected function getCreatedNotification(): ?Notification
{
    return Notification::make()
        ->success()
        ->icon('heroicon-o-check-circle')
        ->iconColor('primary')
        ->title('Registro creado')
        ->body('El registro ha sido creado exitosamente.');
}
```

### After Update

```php
protected function getSavedNotification(): ?Notification
{
    return Notification::make()
        ->success()
        ->icon('heroicon-o-check-circle')
        ->iconColor('primary')
        ->title('Registro actualizado')
        ->body('Los cambios han sido guardados exitosamente.');
}
```

### After Delete

```php
protected function getDeletedNotification(): ?Notification
{
    return Notification::make()
        ->success()
        ->icon('heroicon-o-trash')
        ->iconColor('primary')
        ->title('Registro eliminado')
        ->body('El registro ha sido enviado a la papelera.');
}
```

## Form Structure

### Sections (IMPORTANT: Full Width)

**All sections MUST be full width.** Always use `->columnSpanFull()` on every Section.

```php
Forms\Components\Section::make('Titulo de la Seccion')
    ->description('Descripcion de la seccion')
    ->schema([
        // fields...
    ])
    ->columns(1)
    ->columnSpanFull(),  // <-- REQUIRED on ALL sections
```

**Never** place sections side by side. Forms must flow vertically, one section below another.

### Fields

Do NOT use prefix icons (`->prefixIcon()`) on form inputs. Keep forms clean and simple.

### Placeholders

All fields must have descriptive placeholders:

```php
->placeholder('ejemplo@correo.com')
```

### Helper Texts

Use sparingly, only when adding real value:

```php
->helperText('Este campo es opcional')
```

## Complete Resource Example

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected static ?string $navigationGroup = 'Administracion';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informacion del Usuario')
                    ->description('Datos principales del usuario')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre')
                            ->placeholder('Juan Perez')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label('Correo Electronico')
                            ->placeholder('usuario@ejemplo.com')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->helperText('Este correo sera usado para acceso al sistema'),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Correo')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
                Tables\Actions\ForceDeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withTrashed();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
```

## Checklist

Before completing any Filament task, verify:

- [ ] Model uses `SoftDeletes` trait
- [ ] Migration includes `$table->softDeletes()`
- [ ] Resource includes `TrashedFilter`
- [ ] Resource includes restore/force delete actions
- [ ] `getEloquentQuery()` includes `->withTrashed()`
- [ ] Create page redirects to index
- [ ] Edit page redirects to index
- [ ] Notifications have icon, title, and body
- [ ] All labels are in Spanish
- [ ] Forms use Sections with descriptions
- [ ] All sections use `->columnSpanFull()`
- [ ] No prefix icons on form fields
- [ ] All fields have placeholders
