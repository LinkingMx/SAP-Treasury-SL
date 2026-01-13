# AC Tesoreria - Backend Documentation

## Overview

The AC Tesoreria (Treasury Automation) module handles the import of accounting entries with counterparts for bank movements from bank statements. It processes Excel files containing transaction data, validates them, and stores them as batches with associated transactions.

## Architecture

```
┌─────────────────┐     ┌──────────────────────┐     ┌─────────────────┐
│   Excel File    │────▶│  TransactionsImport  │────▶│     Batch       │
│   (.xlsx/.xls)  │     │   (Validation +      │     │  + Transactions │
└─────────────────┘     │    Processing)       │     └─────────────────┘
                        └──────────────────────┘
                                  │
                                  ▼
                        ┌──────────────────────┐
                        │   BatchController    │
                        │  (API Endpoints)     │
                        └──────────────────────┘
```

## Models

### Branch

Represents a company branch/location that can have multiple bank accounts.

**File:** `app/Models/Branch.php`

| Attribute | Type | Description |
|-----------|------|-------------|
| id | integer | Primary key |
| name | string | Branch name |
| sap_database | string | SAP database identifier |
| sap_branch_id | integer | SAP branch ID |
| ceco | string | Cost center code |

**Relationships:**
- `bankAccounts()` - HasMany BankAccount
- `users()` - BelongsToMany User

---

### BankAccount

Represents a bank account belonging to a branch.

**File:** `app/Models/BankAccount.php`

| Attribute | Type | Description |
|-----------|------|-------------|
| id | integer | Primary key |
| branch_id | integer | Foreign key to Branch |
| name | string | Bank/account name |
| account | string | Account number |

**Relationships:**
- `branch()` - BelongsTo Branch

---

### Batch

Represents a group of transactions imported from a single Excel file.

**File:** `app/Models/Batch.php`

| Attribute | Type | Description |
|-----------|------|-------------|
| id | integer | Primary key |
| uuid | string | Unique identifier (auto-generated) |
| branch_id | integer | Foreign key to Branch |
| bank_account_id | integer | Foreign key to BankAccount |
| user_id | integer | Foreign key to User (who uploaded) |
| filename | string | Original filename |
| total_records | integer | Number of transactions |
| total_debit | decimal(15,2) | Sum of all debit amounts |
| total_credit | decimal(15,2) | Sum of all credit amounts |
| processed_at | datetime | When the batch was processed |

**Relationships:**
- `branch()` - BelongsTo Branch
- `bankAccount()` - BelongsTo BankAccount
- `user()` - BelongsTo User
- `transactions()` - HasMany Transaction

**Boot Events:**
- On creating: Auto-generates UUID if not provided

---

### Transaction

Represents a single accounting entry within a batch.

**File:** `app/Models/Transaction.php`

| Attribute | Type | Description |
|-----------|------|-------------|
| id | integer | Primary key |
| batch_id | integer | Foreign key to Batch |
| sequence | integer | Row sequence from Excel |
| due_date | date | Transaction due date |
| memo | string | Description/memo |
| debit_amount | decimal(15,2) | Debit amount (nullable) |
| credit_amount | decimal(15,2) | Credit amount (nullable) |
| counterpart_account | string | Counterpart account code |

**Relationships:**
- `batch()` - BelongsTo Batch

---

## Controllers

### BatchController

**File:** `app/Http/Controllers/BatchController.php`

Handles all batch-related operations via JSON API.

#### Methods

##### `index(Request $request): JsonResponse`

Lists batches filtered by branch and bank account with pagination.

**Request Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| branch_id | integer | Yes | Filter by branch ID |
| bank_account_id | integer | Yes | Filter by bank account ID |
| page | integer | No | Page number (default: 1) |

**Response:** Paginated JSON with batch data
```json
{
  "data": [...],
  "current_page": 1,
  "last_page": 5,
  "per_page": 10,
  "total": 50,
  "from": 1,
  "to": 10
}
```

---

##### `store(Request $request): JsonResponse`

Processes an Excel file and creates a batch with transactions.

**Request Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| branch_id | integer | Yes | Branch ID |
| bank_account_id | integer | Yes | Bank account ID |
| file | file | Yes | Excel file (.xlsx, .xls, max 10MB) |

**Success Response (200):**
```json
{
  "success": true,
  "message": "Archivo procesado exitosamente",
  "batch": {
    "uuid": "a1b2c3d4-...",
    "total_records": 15,
    "total_debit": "10000.00",
    "total_credit": "10000.00",
    "processed_at": "2026-01-12 10:30:00"
  }
}
```

**Validation Error Response (422):**
```json
{
  "success": false,
  "message": "El archivo contiene errores y no fue procesado",
  "errors": [
    { "row": 2, "error": "La secuencia es requerida" },
    { "row": 5, "error": "El formato de fecha es invalido" }
  ],
  "error_count": 2
}
```

---

##### `destroy(Batch $batch): JsonResponse`

Deletes a batch and all associated transactions (cascade).

**Response:**
```json
{
  "success": true,
  "message": "Lote eliminado exitosamente"
}
```

---

##### `downloadErrorLog(Request $request): StreamedResponse`

Generates a downloadable text file with import errors.

**Request Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| errors | array | Yes | Array of error objects with row and error keys |

**Response:** Text file download (`errores_importacion_YYYY-MM-DD_HHmmss.txt`)

---

## Imports

### TransactionsImport

**File:** `app/Imports/TransactionsImport.php`

Handles Excel file processing using Maatwebsite/Excel package.

**Implements:**
- `ToCollection` - Processes rows as collection
- `WithHeadingRow` - Uses first row as headers

#### Constructor

```php
public function __construct(
    int $branchId,
    int $bankAccountId,
    int $userId,
    string $filename
)
```

#### Expected Excel Columns

| Column Header | Type | Required | Description |
|---------------|------|----------|-------------|
| sequence | integer | Yes | Row sequence number |
| duedate | date/number | Yes | Transaction date (Excel date or string) |
| memo | string | Yes | Description (max 255 chars) |
| debit_amount | decimal | No* | Debit amount |
| credit_amount | decimal | No* | Credit amount |
| cuenta_contrapartida | string | Yes | Counterpart account code (max 50 chars) |

*At least one of debit_amount or credit_amount must be provided.

#### Validation Rules

1. **sequence**: Required, integer, minimum 1
2. **duedate**: Required, valid date format (Excel serial or parseable string)
3. **memo**: Required, string, max 255 characters
4. **cuenta_contrapartida**: Required, string, max 50 characters
5. **amounts**: At least one (debit or credit) must be present

#### Processing Flow

1. **Validation Phase**: All rows are validated before any data is saved
2. **Error Collection**: If errors exist, import is aborted with error list
3. **Transaction Phase**: If valid, creates batch and transactions in DB transaction
4. **Totals Calculation**: Sums are calculated and saved to batch

#### Public Methods

| Method | Return | Description |
|--------|--------|-------------|
| `hasErrors()` | bool | Check if validation errors exist |
| `getErrors()` | array | Get array of error objects |
| `getBatch()` | ?Batch | Get created batch (null if errors) |
| `getErrorsAsText()` | string | Get formatted error log text |

---

## Routes

**File:** `routes/web.php`

All routes require authentication and email verification.

| Method | URI | Controller Action | Name |
|--------|-----|-------------------|------|
| GET | /tesoreria | Closure (Inertia) | tesoreria |
| GET | /tesoreria/batches | BatchController@index | batches.index |
| POST | /tesoreria/batches | BatchController@store | batches.store |
| DELETE | /tesoreria/batches/{batch} | BatchController@destroy | batches.destroy |
| POST | /tesoreria/batches/error-log | BatchController@downloadErrorLog | batches.error-log |

---

## Database Schema

### batches

```sql
CREATE TABLE batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    branch_id BIGINT UNSIGNED NOT NULL,
    bank_account_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    total_records INT UNSIGNED NOT NULL DEFAULT 0,
    total_debit DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_credit DECIMAL(15,2) NOT NULL DEFAULT 0,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### transactions

```sql
CREATE TABLE transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT UNSIGNED NOT NULL,
    sequence INT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    memo VARCHAR(255) NOT NULL,
    debit_amount DECIMAL(15,2) NULL,
    credit_amount DECIMAL(15,2) NULL,
    counterpart_account VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
);
```

---

## Error Handling

### Validation Errors

All validation errors are collected and returned as an array:

```php
[
    ['row' => 2, 'error' => 'La secuencia es requerida'],
    ['row' => 3, 'error' => 'El formato de fecha es invalido'],
]
```

### Exception Handling

The BatchController catches:
1. `Maatwebsite\Excel\Validators\ValidationException` - Excel validation errors
2. `\Exception` - General errors (logged to Laravel log)

All errors return JSON responses with appropriate HTTP status codes.

---

## Security

- All endpoints require authentication (`auth` middleware)
- All endpoints require email verification (`verified` middleware)
- CSRF token required for all POST/DELETE requests
- File uploads validated for type (xlsx, xls) and size (max 10MB)
- User can only see batches for branches they have access to
