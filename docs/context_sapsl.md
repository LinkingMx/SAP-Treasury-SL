# AC Tesoreria - Sistema de Asientos Contables a SAP

Este documento describe el flujo completo del sistema de Automatizacion de Asientos Contables (AC Tesoreria) para envio a SAP mediante Service Layer.

## Resumen

El sistema permite cargar lotes de asientos contables desde archivos Excel para procesarlos y enviarlos automaticamente a SAP mediante su API Service Layer. El flujo es completamente asincrono usando una cola de trabajos.

---

## Arquitectura General

```
┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐
│  Frontend React  │───▶│  BatchController │───▶│  Queue (Job)     │
│  (index.tsx)     │    │  (Laravel)       │    │  ProcessBatch    │
└──────────────────┘    └──────────────────┘    └────────┬─────────┘
                                                         │
                                                         ▼
                                                ┌──────────────────┐
                                                │  SapServiceLayer │
                                                │  (API SAP B1)    │
                                                └──────────────────┘
```

---

## Componentes Principales

### Frontend

| Archivo | Descripcion |
|---------|-------------|
| `resources/js/pages/tesoreria/index.tsx` | Pagina principal con carga de archivos, listado de lotes y acciones |

**Funcionalidades:**
- Seleccion de sucursal y cuenta bancaria
- Carga de archivos Excel con validacion en tiempo real
- Vista de lotes procesados con paginacion
- Dialogo modal para ver detalles de lotes
- Acciones para procesar a SAP y reprocesar transacciones

### Backend

| Archivo | Descripcion |
|---------|-------------|
| `app/Http/Controllers/BatchController.php` | Controlador principal con endpoints REST |
| `app/Services/SapServiceLayer.php` | Servicio de comunicacion con SAP API |
| `app/Jobs/ProcessBatchToSapJob.php` | Job asincrono de procesamiento |
| `app/Imports/TransactionsImport.php` | Validacion e importacion de Excel |

### Modelos

| Archivo | Descripcion |
|---------|-------------|
| `app/Models/Batch.php` | Modelo de lote de transacciones |
| `app/Models/Transaction.php` | Modelo de transaccion individual |
| `app/Models/Branch.php` | Modelo de sucursal (contiene config SAP) |
| `app/Models/BankAccount.php` | Modelo de cuenta bancaria |
| `app/Enums/BatchStatus.php` | Estados posibles del lote |

---

## Endpoints API

```
GET    /tesoreria/batches                                    - Listar lotes
GET    /tesoreria/batches/{batch}                            - Ver detalle de lote
POST   /tesoreria/batches                                    - Cargar archivo Excel
DELETE /tesoreria/batches/{batch}                            - Eliminar lote
POST   /tesoreria/batches/{batch}/process-sap                - Enviar lote a SAP
POST   /tesoreria/batches/{batch}/transactions/{tx}/reprocess - Reprocesar transaccion
GET    /tesoreria/template/download                          - Descargar plantilla Excel
POST   /tesoreria/batches/error-log                          - Descargar log de errores
```

---

## Flujo Detallado del Proceso

### Paso 1: Carga del Archivo Excel

**Usuario:**
1. Selecciona sucursal
2. Selecciona cuenta bancaria
3. Sube archivo Excel

**Sistema:**
1. `BatchController::store()` valida la entrada
2. `TransactionsImport::collection()` procesa el Excel:
   - Valida cada fila
   - Crea `Batch` con status `pending`
   - Crea todas las `Transaction` asociadas
3. Retorna UUID del batch creado

**Estructura del Excel esperada:**

| sequence | duedate | memo | cuenta_contrapartida | debit_amount | credit_amount |
|----------|---------|------|----------------------|--------------|---------------|
| 1 | 2026-01-15 | Pago proveedor | 2010 | 1000.00 | |
| 2 | 2026-01-15 | Cobro cliente | 1010 | | 500.00 |

### Paso 2: Usuario Inicia Procesamiento

**Usuario:**
- Hace clic en boton "Procesar a SAP"

**Sistema:**
1. `POST /tesoreria/batches/{batch}/process-sap`
2. `BatchController::processToSap()`:
   - Valida estado del batch (solo `pending`)
   - Cambia estado a `processing`
   - Dispara `ProcessBatchToSapJob::dispatch($batch)`
3. Retorna respuesta inmediata

### Paso 3: Job Asincrono

**`ProcessBatchToSapJob::handle()`:**

```php
// 1. Cargar relaciones
$this->batch->load(['branch', 'bankAccount', 'transactions']);

// 2. Login a SAP
$sap = new SapServiceLayer();
$sap->login($this->batch->branch->sap_database);

// 3. Iterar transacciones
foreach ($this->batch->transactions as $transaction) {
    if ($transaction->sap_number !== null) continue; // Saltar procesadas

    $result = $sap->createJournalEntry(
        $transaction,
        $this->batch->bankAccount->account,
        $this->batch->branch->ceco,
        $this->batch->branch->sap_branch_id
    );

    if ($result['success']) {
        $transaction->update([
            'sap_number' => $result['jdt_num'],
            'error' => null
        ]);
    } else {
        $transaction->update(['error' => $result['error']]);
        $hasErrors = true;
    }
}

// 4. Logout
$sap->logout();

// 5. Actualizar estado del batch
$this->batch->update([
    'status' => $hasErrors ? BatchStatus::Failed : BatchStatus::Completed,
    'processed_at' => now()
]);
```

### Paso 4: Creacion del Asiento en SAP

**`SapServiceLayer::createJournalEntry()`:**

```php
$payload = [
    'ReferenceDate' => $dueDate,
    'Memo' => $transaction->memo,
    'TaxDate' => $dueDate,
    'DueDate' => $dueDate,
    'JournalEntryLines' => [
        // Linea 1: Cuenta Bancaria (montos invertidos como contrapartida)
        [
            'AccountCode' => $bankAccountCode,
            'Debit' => $creditAmount,
            'Credit' => $debitAmount,
            'CostingCode' => $ceco,
            'BPLID' => $bplId
        ],
        // Linea 2: Cuenta Contrapartida
        [
            'AccountCode' => $transaction->counterpart_account,
            'Debit' => $debitAmount,
            'Credit' => $creditAmount,
            'CostingCode' => $ceco,
            'BPLID' => $bplId
        ]
    ]
];

// POST a SAP Service Layer
$response = Http::post("{$this->baseUrl}/JournalEntries", $payload);
```

**Respuesta exitosa:**
```json
{
    "success": true,
    "jdt_num": 12345,
    "error": null
}
```

---

## Estructura de Datos

### Tabla: batches

| Campo | Tipo | Descripcion |
|-------|------|-------------|
| id | bigint | Primary key |
| uuid | string | Identificador unico publico |
| branch_id | bigint | FK a branches |
| bank_account_id | bigint | FK a bank_accounts |
| user_id | bigint | FK a users |
| filename | string | Nombre del archivo subido |
| total_records | integer | Cantidad de transacciones |
| total_debit | decimal(15,2) | Suma de debitos |
| total_credit | decimal(15,2) | Suma de creditos |
| status | enum | pending, processing, completed, failed |
| error_message | text | Mensaje de error general |
| processed_at | timestamp | Fecha de procesamiento |

### Tabla: transactions

| Campo | Tipo | Descripcion |
|-------|------|-------------|
| id | bigint | Primary key |
| batch_id | bigint | FK a batches |
| sequence | integer | Numero de secuencia |
| due_date | date | Fecha del asiento |
| memo | string | Descripcion/concepto |
| debit_amount | decimal(15,2) | Monto debito (nullable) |
| credit_amount | decimal(15,2) | Monto credito (nullable) |
| counterpart_account | string | Codigo cuenta contrapartida SAP |
| sap_number | bigint | Numero de asiento en SAP (JdtNum) |
| error | text | Mensaje de error si fallo |

### Tabla: branches (campos relevantes)

| Campo | Tipo | Descripcion |
|-------|------|-------------|
| sap_database | string | Nombre de base de datos SAP para login |
| sap_branch_id | integer | ID de sucursal en SAP (BPLID), 0 = no usar |
| ceco | string | Centro de costo SAP |

### Tabla: bank_accounts (campos relevantes)

| Campo | Tipo | Descripcion |
|-------|------|-------------|
| account | string | Codigo de cuenta contable en SAP |

---

## Estados del Batch (BatchStatus)

```php
enum BatchStatus: string {
    case Pending = 'pending';       // Creado, listo para procesar
    case Processing = 'processing'; // Job ejecutandose
    case Completed = 'completed';   // Todas las transacciones exitosas
    case Failed = 'failed';         // Una o mas transacciones fallaron
}
```

---

## Reprocesamiento de Transacciones

Si una transaccion falla, el usuario puede reprocesarla individualmente.

**Endpoint:** `POST /tesoreria/batches/{batch}/transactions/{transaction}/reprocess`

**Proceso (sincrono):**
1. Login a SAP
2. `createJournalEntry()` para la transaccion especifica
3. Actualiza `sap_number` o `error`
4. Logout de SAP
5. Recalcula estado del batch

```php
public function reprocessTransaction(string $batch, int $transaction)
{
    $sap = new SapServiceLayer();
    $sap->login($batch->branch->sap_database);

    $result = $sap->createJournalEntry(
        $transaction,
        $batch->bankAccount->account,
        $batch->branch->ceco,
        $batch->branch->sap_branch_id
    );

    if ($result['success']) {
        $transaction->update([
            'sap_number' => $result['jdt_num'],
            'error' => null
        ]);
    }

    $sap->logout();

    // Actualizar estado del batch
    $pendingCount = $batch->transactions()
        ->whereNull('sap_number')
        ->count();

    if ($pendingCount === 0) {
        $batch->update(['status' => BatchStatus::Completed]);
    }
}
```

---

## Configuracion

### Variables de Entorno

```env
# SAP Service Layer
SAP_SL_BASE_URL=https://sapserver:50000/b1s/v1
SAP_SL_USERNAME=manager
SAP_SL_PASSWORD=password123

# Cola de trabajos
QUEUE_CONNECTION=database
```

### Archivo de Configuracion

`config/services.php`:

```php
'sap_service_layer' => [
    'base_url' => env('SAP_SL_BASE_URL'),
    'username' => env('SAP_SL_USERNAME'),
    'password' => env('SAP_SL_PASSWORD'),
],
```

---

## SAP Service Layer API

### Autenticacion

**Login:**
```
POST {baseUrl}/Login
Content-Type: application/json

{
    "CompanyDB": "DEMO",
    "UserName": "manager",
    "Password": "password123"
}
```

**Respuesta:**
```json
{
    "SessionId": "abc123...",
    "Version": "10.0"
}
```

La sesion se mantiene via cookie `B1SESSION` o header con `SessionId`.

### Crear Asiento Contable

**Request:**
```
POST {baseUrl}/JournalEntries
Content-Type: application/json
Cookie: B1SESSION=abc123...

{
    "ReferenceDate": "2026-01-15T00:00:00Z",
    "Memo": "Pago proveedor",
    "TaxDate": "2026-01-15T00:00:00Z",
    "DueDate": "2026-01-15T00:00:00Z",
    "JournalEntryLines": [
        {
            "AccountCode": "2010",
            "Debit": 0,
            "Credit": 1000.00,
            "CostingCode": "1001",
            "BPLID": 1
        },
        {
            "AccountCode": "2000",
            "Debit": 1000.00,
            "Credit": 0,
            "CostingCode": "1001",
            "BPLID": 1
        }
    ]
}
```

**Respuesta exitosa:**
```json
{
    "JdtNum": 12345,
    "Series": 1,
    "Number": 100,
    ...
}
```

### Logout

```
POST {baseUrl}/Logout
Cookie: B1SESSION=abc123...
```

---

## Manejo de Errores

### Niveles de Error

1. **Error de importacion Excel:** Validacion falla, no se crea batch
2. **Error de login SAP:** Batch pasa a `failed`, mensaje en `error_message`
3. **Error de transaccion individual:** Transaction guarda error, batch puede ser `failed`
4. **Error de Job:** Batch pasa a `failed` via `failed()` method

### Logging

Los errores se registran en:
- `storage/logs/laravel.log` - Logs de Laravel
- `transactions.error` - Error especifico por transaccion
- `batches.error_message` - Error general del lote

---

## Consideraciones Tecnicas

### SSL

La clase `SapServiceLayer` desactiva verificacion SSL con `withoutVerifying()` para soportar certificados autofirmados en servidores SAP.

### Inversion de Montos

En la linea de cuenta bancaria, los montos se invierten porque representa la contrapartida:
- Debito de transaccion -> Credito en cuenta bancaria
- Credito de transaccion -> Debito en cuenta bancaria

### Centro de Costo

Todas las lineas del asiento incluyen `CostingCode` (centro de costo) que viene de la sucursal.

### Cola de Trabajos

- Driver por defecto: `database`
- Intentos: 1 (sin reintentos automaticos)
- Timeout: 300 segundos (5 minutos)

### Transacciones de Base de Datos

- **Importacion Excel:** Usa `DB::transaction()` para consistencia
- **Job de procesamiento:** Sin transaccion DB (cada update es independiente)

---

## Diagrama de Flujo Completo

```
┌─────────────────────────────────────────────────────────────────┐
│ PASO 1: USUARIO CARGA ARCHIVO                                   │
│ ─────────────────────────────────────────────────────────────── │
│ • Selecciona sucursal y cuenta                                  │
│ • Sube archivo Excel                                            │
│ • POST /tesoreria/batches                                       │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ PASO 2: VALIDACION E IMPORTACION                                │
│ ─────────────────────────────────────────────────────────────── │
│ • BatchController::store() valida entrada                       │
│ • TransactionsImport valida Excel                               │
│ • Crea Batch (status: pending)                                  │
│ • Crea Transactions                                             │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ PASO 3: USUARIO INICIA PROCESAMIENTO                            │
│ ─────────────────────────────────────────────────────────────── │
│ • Clic en "Procesar a SAP"                                      │
│ • POST /tesoreria/batches/{batch}/process-sap                   │
│ • Batch cambia a status: processing                             │
│ • Respuesta inmediata al usuario                                │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ PASO 4: JOB ASINCRONO                                           │
│ ─────────────────────────────────────────────────────────────── │
│ ProcessBatchToSapJob::dispatch($batch)                          │
│   │                                                             │
│   ├─▶ Login a SAP (POST /Login)                                 │
│   │                                                             │
│   ├─▶ Para cada transaccion:                                    │
│   │     • createJournalEntry (POST /JournalEntries)             │
│   │     • Si OK: guarda sap_number                              │
│   │     • Si error: guarda error                                │
│   │                                                             │
│   ├─▶ Logout de SAP (POST /Logout)                              │
│   │                                                             │
│   └─▶ Actualiza batch.status:                                   │
│         • completed (todos OK)                                  │
│         • failed (hay errores)                                  │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ PASO 5: USUARIO VE RESULTADOS                                   │
│ ─────────────────────────────────────────────────────────────── │
│ • Tabla actualizada con estados                                 │
│ • Detalle de cada transaccion:                                  │
│   - sap_number (si OK)                                          │
│   - error (si fallo)                                            │
│ • Opcion de reprocesar transacciones fallidas                   │
└─────────────────────────────────────────────────────────────────┘
```

---

## Archivos Clave

| Archivo | Lineas | Proposito |
|---------|--------|-----------|
| `app/Services/SapServiceLayer.php` | ~208 | Comunicacion con SAP API |
| `app/Jobs/ProcessBatchToSapJob.php` | ~150 | Job asincrono |
| `app/Http/Controllers/BatchController.php` | ~365 | Endpoints REST |
| `app/Imports/TransactionsImport.php` | ~208 | Importacion Excel |
| `resources/js/pages/tesoreria/index.tsx` | ~1179 | Interfaz React |
| `routes/web.php` | - | Definicion de rutas |
| `app/Models/Batch.php` | ~96 | Modelo Batch |
| `app/Models/Transaction.php` | ~52 | Modelo Transaction |
| `app/Enums/BatchStatus.php` | ~25 | Estados del batch |
