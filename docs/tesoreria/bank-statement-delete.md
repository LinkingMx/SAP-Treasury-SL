# Eliminar Extracto Bancario de SAP

## Overview

Permite a los usuarios eliminar los movimientos bancarios (BankPages) de un extracto previamente enviado a SAP, directamente desde la interfaz de historial de extractos. Al eliminar, el registro local se marca como "Cancelado en SAP" para mantener trazabilidad.

## Arquitectura

```
Frontend (React)                Backend (Laravel)              SAP Service Layer
┌──────────────────┐    DELETE  ┌─────────────────────┐        ┌──────────────┐
│ BankStatementUpload│ ──────→ │ BankStatementController│ ────→ │ SapServiceLayer│
│ - AlertDialog     │    JSON  │ ::destroy()           │       │ ::deleteBankPages()│
│ - Trash2 button   │ ←────── │                       │ ←──── │ DELETE /BankPages({seq})│
└──────────────────┘          └─────────────────────┘        └──────────────┘
```

## API

### DELETE `/tesoreria/bank-statements/{bankStatement}`

Elimina los BankPages de SAP y marca el extracto como cancelado.

**Autorizacion**: Usuario autenticado con acceso a la sucursal del extracto.

**Respuesta exitosa (200)**:
```json
{
  "success": true,
  "message": "Se eliminaron 5 movimientos de SAP.",
  "deleted_count": 5
}
```

**Respuesta parcial (200)**:
```json
{
  "success": true,
  "message": "Se eliminaron 3 de 5 movimientos. 2 fallaron.",
  "deleted_count": 3,
  "failed_count": 2,
  "errors": ["Error en secuencia 123: ..."]
}
```

**Errores**: 403 (sin acceso), 422 (sin secuencias SAP), 500 (fallo SAP login).

## Base de Datos

- `BankStatementStatus` enum: se agrego `Cancelled = 'cancelled'`
- Migration `add_cancelled_status_to_bank_statements_table`: agrega 'cancelled' al enum de status en MySQL
- Migration original actualizada para incluir 'cancelled' en SQLite (tests)

## SAP Service Layer

- `deleteBankPage(int $sequence)`: `DELETE /BankPages({Sequence})`
- `deleteBankPages(array $sequences)`: itera sobre multiples secuencias, retorna contadores de exito/fallo

## Frontend

- Boton rojo Trash2 visible en extractos con status `sent` o `failed`
- AlertDialog de confirmacion antes de ejecutar la eliminacion
- Badge naranja "Cancelado en SAP" para status `cancelled`
- Alerta naranja en modal de detalle (ojito) para extractos cancelados

## Testing

```bash
php artisan test --compact tests/Feature/BankStatementDeleteTest.php
```

7 tests: auth, acceso denegado, not found, sin secuencias, happy path, fallo parcial, fallo login SAP.

## Notas Tecnicas

- SAP no soporta eliminacion en batch de BankPages; se itera secuencia por secuencia
- Incluso con fallos parciales, el extracto se marca como cancelado (los movimientos restantes pueden limpiarse desde SAP directo)
- Las secuencias SAP (`sap_sequence`) se almacenan en `payload.BankPages[].sap_sequence` al momento del envio
