# BankAccount Resource (Cuentas Bancarias)

## Overview

El recurso BankAccount permite gestionar las cuentas bancarias de la empresa asociadas a cada sucursal. Cada cuenta bancaria pertenece a una sucursal (Branch) y se identifica por un nombre descriptivo y número de cuenta.

Este recurso es fundamental para la gestión de tesorería, permitiendo vincular operaciones financieras a cuentas bancarias específicas de cada sucursal.

## Arquitectura

### Componentes

```
app/
├── Models/
│   ├── BankAccount.php                    # Modelo Eloquent
│   └── Branch.php                         # Relación hasMany agregada
├── Filament/
│   └── Resources/
│       └── BankAccounts/
│           ├── BankAccountResource.php    # Resource principal
│           ├── Schemas/
│           │   └── BankAccountForm.php    # Definición del formulario
│           ├── Tables/
│           │   └── BankAccountsTable.php  # Definición de la tabla
│           └── Pages/
│               ├── ListBankAccounts.php   # Listado
│               ├── CreateBankAccount.php  # Crear
│               └── EditBankAccount.php    # Editar
database/
├── migrations/
│   └── 2026_01_13_003808_create_bank_accounts_table.php
└── factories/
    └── BankAccountFactory.php
tests/
└── Feature/
    └── Filament/
        └── BankAccountResourceTest.php    # 15 tests
```

### Flujo de Datos

```
[Usuario] → [Filament UI] → [BankAccountResource] → [BankAccount Model] → [Database]
                                     ↓
                              [Branch Model]
```

## Base de Datos

### Tabla: `bank_accounts`

| Columna | Tipo | Nullable | Descripción |
|---------|------|----------|-------------|
| `id` | bigint | No | PK autoincremental |
| `branch_id` | bigint | No | FK a branches (cascade on delete) |
| `name` | varchar(255) | No | Nombre descriptivo de la cuenta |
| `account` | varchar(255) | No | Número de cuenta bancaria |
| `created_at` | timestamp | Yes | Fecha de creación |
| `updated_at` | timestamp | Yes | Fecha de actualización |

**Índice único compuesto**: `(branch_id, account)` - El número de cuenta debe ser único por sucursal.

### Migración

```php
Schema::create('bank_accounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('account');
    $table->timestamps();

    $table->unique(['branch_id', 'account']);
});
```

### Relaciones

```
Branch (1) ──────< (N) BankAccount
         hasMany      belongsTo
```

## API / Interfaces

### Modelo BankAccount

```php
use App\Models\BankAccount;

// Crear
$account = BankAccount::create([
    'branch_id' => 1,
    'name' => 'Cuenta Operativa',
    'account' => '0123-4567-8901234567',
]);

// Consultar
$accounts = BankAccount::all();
$account = BankAccount::find(1);

// Con relación
$account = BankAccount::with('branch')->find(1);
echo $account->branch->name;

// Cuentas de una sucursal
$branch = Branch::find(1);
$accounts = $branch->bankAccounts;

// Actualizar
$account->update(['name' => 'Cuenta Principal']);

// Eliminar
$account->delete();
```

### Atributos Fillable

- `branch_id` - ID de la sucursal (FK)
- `name` - Nombre descriptivo de la cuenta
- `account` - Número de cuenta bancaria

## Uso

### Acceso al Panel Admin

URL: `/admin/bank-accounts`

### Funcionalidades Disponibles

1. **Listar**: Ver todas las cuentas bancarias con búsqueda y ordenamiento
2. **Crear**: Formulario con selección de sucursal y validación única
3. **Editar**: Modificar datos de una cuenta existente
4. **Eliminar**: Eliminar una cuenta (acción en header de edición)

### Características del Formulario

- Sección con título "Información de la Cuenta Bancaria"
- Select de sucursal con búsqueda y precarga
- Campos con iconos prefix para mejor UX
- Placeholders con ejemplos realistas
- Helper texts explicativos
- Validación de unicidad por sucursal

### Características de la Tabla

- Columnas: Nombre, Número de Cuenta, Sucursal
- Búsqueda por nombre y número de cuenta
- Ordenamiento en todas las columnas
- Timestamps toggleables (ocultos por defecto)

## Testing

### Ejecutar Tests

```bash
# Solo tests de BankAccount
php artisan test tests/Feature/Filament/BankAccountResourceTest.php

# Todos los tests
php artisan test
```

### Cobertura de Tests

| Test | Descripción |
|------|-------------|
| `can render the list page` | Verifica carga de página de listado |
| `can list bank accounts` | Verifica visualización de registros |
| `can render the create page` | Verifica carga de página de creación |
| `can create a bank account` | Verifica creación y notificación |
| `validates required fields on create` | Valida campos requeridos (dataset) |
| `validates unique account per branch` | Valida unicidad por sucursal |
| `allows same account number in different branches` | Permite duplicados entre sucursales |
| `can render the edit page` | Verifica carga y datos del formulario |
| `can update a bank account` | Verifica actualización y notificación |
| `can delete a bank account` | Verifica eliminación y redirección |
| `can search bank accounts by name` | Verifica búsqueda por nombre |
| `can search bank accounts by account number` | Verifica búsqueda por cuenta |
| `can sort bank accounts by name` | Verifica ordenamiento ASC/DESC |

**Total**: 15 tests, 59 assertions

## Notas Técnicas

### Decisiones de Diseño

#### Validación Única Compuesta
Se implementó validación de unicidad del número de cuenta por sucursal utilizando la regla `unique` de Laravel con modificador:

```php
->unique(
    ignoreRecord: true,
    modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('branch_id', $get('branch_id'))
)
```

Esto permite que el mismo número de cuenta exista en diferentes sucursales, pero no duplicados dentro de la misma sucursal.

#### Cascade on Delete
La relación con Branch usa `cascadeOnDelete()`, lo que significa que si se elimina una sucursal, todas sus cuentas bancarias asociadas también se eliminarán automáticamente.

#### Estructura de Filament v4
Se utilizó la misma estructura de Filament v4 que el recurso Branch, con archivos separados para Form y Table schemas.

### Consideraciones Futuras

1. **Transacciones**: El modelo BankAccount podría relacionarse con transacciones bancarias cuando se implemente el módulo de movimientos.

2. **Validación de Cuenta**: Podría agregarse validación del formato de cuenta bancaria según el banco o país.

3. **Soft Deletes**: Considerar implementar soft deletes si las cuentas tienen relaciones con transacciones históricas.

4. **Campo Banco**: Si se requiere, se puede agregar un campo `bank` para identificar la institución bancaria.
