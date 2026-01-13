# User Resource (Usuarios)

## Overview

El recurso User permite gestionar los usuarios del sistema con asignación de roles mediante Spatie Permission. Incluye funcionalidad de contraseña opcional en edición y soporte para Two-Factor Authentication.

## Arquitectura

### Componentes

```
app/
└── Filament/
    └── Resources/
        └── Users/
            ├── UserResource.php           # Resource principal
            ├── Schemas/
            │   └── UserForm.php           # Formulario
            ├── Tables/
            │   └── UsersTable.php         # Tabla
            └── Pages/
                ├── ListUsers.php          # Listado
                ├── CreateUser.php         # Crear
                └── EditUser.php           # Editar
tests/
└── Feature/
    └── Filament/
        └── UserResourceTest.php           # 16 tests
```

### Flujo de Datos

```
[Usuario Admin] → [Filament UI] → [UserResource] → [User Model] → [Database]
                                        ↓
                                 [Spatie Roles]
```

## Base de Datos

### Tabla: `users`

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | bigint | PK autoincremental |
| `name` | varchar | Nombre del usuario |
| `email` | varchar | Email único |
| `email_verified_at` | datetime | Fecha verificación |
| `password` | varchar | Contraseña hasheada |
| `two_factor_secret` | text | Secreto 2FA |
| `two_factor_recovery_codes` | text | Códigos recuperación |
| `two_factor_confirmed_at` | datetime | Fecha confirmación 2FA |
| `remember_token` | varchar | Token remember me |
| `created_at` | timestamp | Fecha creación |
| `updated_at` | timestamp | Fecha actualización |

## API / Interfaces

### Modelo User

```php
use App\Models\User;

// Crear
$user = User::create([
    'name' => 'Juan Pérez',
    'email' => 'juan@ejemplo.com',
    'password' => Hash::make('password'),
]);

// Asignar roles
$user->assignRole('admin');
$user->assignRole(['editor', 'writer']);

// Verificar roles
$user->hasRole('admin');
$user->getRoleNames();

// Consultar con roles
$users = User::with('roles')->get();
```

## Uso

### Acceso al Panel Admin

URL: `/admin/users`

### Funcionalidades Disponibles

1. **Listar**: Ver todos los usuarios con búsqueda y ordenamiento
2. **Crear**: Formulario con validación de email único y contraseña
3. **Editar**: Modificar datos, contraseña opcional
4. **Eliminar**: Eliminar usuario (acción en header de edición)
5. **Asignar Roles**: CheckboxList para selección múltiple de roles

### Características del Formulario

#### Sección: Información del Usuario
- **Nombre**: TextInput con icono, required
- **Email**: TextInput con validación unique, helper text
- **Contraseña**: Password con reveal, confirmed
  - Create: required
  - Edit: opcional (dejar en blanco mantiene la actual)
- **Confirmar Contraseña**: Password confirmation

#### Sección: Roles y Permisos
- **Roles**: CheckboxList con relación many-to-many

### Características de la Tabla

- Columnas: Nombre, Email, Roles (badges)
- Búsqueda por nombre y email
- Ordenamiento en nombre, email, created_at
- Timestamps toggleables (ocultos por defecto)

## Testing

### Ejecutar Tests

```bash
# Solo tests de User
php artisan test tests/Feature/Filament/UserResourceTest.php

# Todos los tests
php artisan test
```

### Cobertura de Tests

| Test | Descripción |
|------|-------------|
| `can render the list page` | Verifica carga de página de listado |
| `can list users` | Verifica visualización de registros |
| `can render the create page` | Verifica carga de página de creación |
| `can create a user` | Verifica creación y notificación |
| `validates required fields on create` | Valida campos requeridos (dataset) |
| `validates email is unique` | Valida unicidad de email |
| `validates password confirmation` | Valida que passwords coincidan |
| `can render the edit page` | Verifica carga y datos del formulario |
| `can update a user without changing password` | Verifica update sin cambiar password |
| `can update a user with new password` | Verifica update con nuevo password |
| `can delete a user` | Verifica eliminación y redirección |
| `can search users by name` | Verifica búsqueda por nombre |
| `can search users by email` | Verifica búsqueda por email |
| `can assign roles to a user` | Verifica asignación de roles |

**Total**: 16 tests, 69 assertions

## Notas Técnicas

### Decisiones de Diseño

#### Password Opcional en Edición
Se implementó usando `dehydrated()` condicional:

```php
TextInput::make('password')
    ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
    ->dehydrated(fn ($state) => filled($state))
    ->required(fn (string $operation): bool => $operation === 'create')
```

Esto permite:
- En **create**: password es requerido
- En **edit**: password es opcional, solo se actualiza si se llena

#### Roles con CheckboxList
Se usa `CheckboxList` con relationship para selección múltiple:

```php
CheckboxList::make('roles')
    ->relationship('roles', 'name')
    ->columns(2)
```

### Consideraciones de Seguridad

1. **Hash de Contraseñas**: Las contraseñas se hashean automáticamente con `Hash::make()`
2. **Validación de Password**: Usa `Password::defaults()` para reglas de seguridad
3. **Email Único**: Validación `unique` con `ignoreRecord: true` para edición

### Consideraciones Futuras

1. **Filtro por Rol**: Agregar filtro para mostrar usuarios por rol específico
2. **Impersonación**: Funcionalidad para "entrar como" otro usuario
3. **Activar/Desactivar**: Soft delete o campo `is_active` para usuarios
4. **Historial de Acceso**: Registrar últimos accesos del usuario
