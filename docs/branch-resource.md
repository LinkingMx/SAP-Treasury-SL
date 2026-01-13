# Branch Resource (Sucursales)

## Overview

El recurso Branch permite gestionar las sucursales de la empresa y su configuración de conexión con SAP Business One. Cada sucursal representa una unidad de negocio que se conecta a una base de datos específica de SAP.

Este recurso es fundamental para la integración con SAP Treasury, ya que define qué base de datos SAP consultar para cada operación de tesorería según la sucursal seleccionada.

## Arquitectura

### Componentes

```
app/
├── Models/
│   └── Branch.php                    # Modelo Eloquent
├── Filament/
│   └── Resources/
│       └── Branches/
│           ├── BranchResource.php    # Resource principal
│           ├── Schemas/
│           │   └── BranchForm.php    # Definición del formulario
│           ├── Tables/
│           │   └── BranchesTable.php # Definición de la tabla
│           └── Pages/
│               ├── ListBranches.php  # Listado
│               ├── CreateBranch.php  # Crear
│               └── EditBranch.php    # Editar
database/
├── migrations/
│   └── 2026_01_13_001658_create_branches_table.php
└── factories/
    └── BranchFactory.php
tests/
└── Feature/
    └── Filament/
        └── BranchResourceTest.php    # 12 tests
```

### Flujo de Datos

```
[Usuario] → [Filament UI] → [BranchResource] → [Branch Model] → [Database]
```

## Base de Datos

### Tabla: `branches`

| Columna | Tipo | Nullable | Descripción |
|---------|------|----------|-------------|
| `id` | bigint | No | PK autoincremental |
| `name` | varchar(255) | No | Nombre de la sucursal |
| `sap_database` | varchar(255) | No | Nombre de la BD de SAP |
| `sap_branch_id` | integer | No | ID de sucursal en SAP |
| `created_at` | timestamp | Yes | Fecha de creación |
| `updated_at` | timestamp | Yes | Fecha de actualización |

### Migración

```php
Schema::create('branches', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('sap_database');
    $table->integer('sap_branch_id');
    $table->timestamps();
});
```

## API / Interfaces

### Modelo Branch

```php
use App\Models\Branch;

// Crear
$branch = Branch::create([
    'name' => 'Sucursal Centro',
    'sap_database' => 'SBO_PRODUCCION',
    'sap_branch_id' => 1,
]);

// Consultar
$branches = Branch::all();
$branch = Branch::find(1);

// Actualizar
$branch->update(['name' => 'Sucursal Norte']);

// Eliminar
$branch->delete();
```

### Atributos Fillable

- `name` - Nombre de la sucursal
- `sap_database` - Nombre de la base de datos SAP
- `sap_branch_id` - ID numérico de la sucursal en SAP (cast a integer)

## Uso

### Acceso al Panel Admin

URL: `/admin/branches`

### Funcionalidades Disponibles

1. **Listar**: Ver todas las sucursales con búsqueda y ordenamiento
2. **Crear**: Formulario con validación de campos requeridos
3. **Editar**: Modificar datos de una sucursal existente
4. **Eliminar**: Eliminar una sucursal (acción en header de edición)

### Características del Formulario

- Sección con título "Información de la Sucursal"
- Campos con iconos prefix para mejor UX
- Placeholders con ejemplos realistas
- Helper texts explicativos
- Validación de campos requeridos

### Características de la Tabla

- Columnas: Nombre, Base de Datos SAP, ID Sucursal SAP
- Búsqueda por nombre y base de datos
- Ordenamiento en todas las columnas
- Timestamps toggleables (ocultos por defecto)

## Testing

### Ejecutar Tests

```bash
# Solo tests de Branch
php artisan test tests/Feature/Filament/BranchResourceTest.php

# Todos los tests
php artisan test
```

### Cobertura de Tests

| Test | Descripción |
|------|-------------|
| `can render the list page` | Verifica carga de página de listado |
| `can list branches` | Verifica visualización de registros |
| `can render the create page` | Verifica carga de página de creación |
| `can create a branch` | Verifica creación y notificación |
| `validates required fields on create` | Valida campos requeridos (dataset) |
| `can render the edit page` | Verifica carga y datos del formulario |
| `can update a branch` | Verifica actualización y notificación |
| `can delete a branch` | Verifica eliminación y redirección |
| `can search branches by name` | Verifica búsqueda en tabla |
| `can sort branches by name` | Verifica ordenamiento ASC/DESC |

**Total**: 12 tests, 47 assertions

## Notas Técnicas

### Decisiones de Diseño

#### Estructura de Filament v4
Se utilizó la nueva estructura de Filament v4 con archivos separados para Form y Table schemas, siguiendo las convenciones del framework:
- `Schemas/BranchForm.php` - Definición del formulario
- `Tables/BranchesTable.php` - Definición de la tabla

#### Namespace de Section
En Filament v4, el componente `Section` se encuentra en `Filament\Schemas\Components\Section` (no en `Filament\Forms\Components\Section`).

#### Notificaciones Personalizadas
Se implementaron notificaciones personalizadas en Create y Edit con:
- Icono: `heroicon-o-building-office-2`
- Título descriptivo en español
- Body con mensaje de confirmación

#### Redirección Post-Acción
Tanto Create como Edit redirigen al listado (`index`) mediante `getRedirectUrl()` para mejor UX.

### Consideraciones Futuras

1. **Relaciones**: El modelo Branch podría relacionarse con otros modelos (usuarios, transacciones) cuando se implemente la lógica de negocio.

2. **Validación SAP**: Podría agregarse validación en tiempo real para verificar conectividad con la BD de SAP especificada.

3. **Soft Deletes**: Considerar implementar soft deletes si las sucursales tienen relaciones críticas.
