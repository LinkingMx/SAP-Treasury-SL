# AC Tesoreria - Frontend Documentation

## Overview

The AC Tesoreria frontend is built with React, TypeScript, and Inertia.js. It provides a user interface for uploading Excel files with bank transactions and managing processed batches.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Tesoreria Page                           │
│  resources/js/pages/tesoreria/index.tsx                     │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Card: Automatizacion de asientos contables         │   │
│  │  ├── Branch Select                                   │   │
│  │  ├── Bank Account Select (filtered by branch)       │   │
│  │  ├── File Upload (.xlsx, .xls)                       │   │
│  │  ├── Progress Bar (during upload)                    │   │
│  │  ├── Success Alert (after successful upload)         │   │
│  │  └── Error Alert (validation errors)                 │   │
│  └─────────────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  Card: Lotes de Transaccion                          │   │
│  │  ├── DataTable (batches list)                        │   │
│  │  ├── Pagination                                       │   │
│  │  └── Delete Confirmation Dialog                       │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

## Page Component

### Tesoreria

**File:** `resources/js/pages/tesoreria/index.tsx`

Main page component for treasury automation.

#### Props

```typescript
interface Props {
    branches: Branch[];      // Available branches for current user
    bankAccounts: BankAccount[];  // All bank accounts for user's branches
}
```

#### State Management

| State | Type | Description |
|-------|------|-------------|
| selectedBranch | string | Selected branch ID |
| selectedBankAccount | string | Selected bank account ID |
| selectedFile | File \| null | Selected Excel file |
| isUploading | boolean | Upload in progress |
| uploadStatus | UploadStatus | Current upload state |
| uploadProgress | number | Upload progress (0-100) |
| errors | ImportError[] | Validation errors from server |
| successResult | BatchResult \| null | Result after successful upload |
| batches | Batch[] | List of batches for current selection |
| batchesLoading | boolean | Loading batches |
| batchesPagination | object | Pagination state |
| deleteDialogOpen | boolean | Delete dialog visibility |
| batchToDelete | Batch \| null | Batch to be deleted |
| isDeleting | boolean | Deletion in progress |

#### Upload Status Flow

```
idle -> validating -> processing -> success
                  |              -> error
```

---

## TypeScript Interfaces

**File:** `resources/js/types/index.d.ts`

### Branch

```typescript
export interface Branch {
    id: number;
    name: string;
}
```

### BankAccount

```typescript
export interface BankAccount {
    id: number;
    branch_id: number;
    name: string;
    account: string;
}
```

### ImportError

```typescript
export interface ImportError {
    row: number;    // 0 for general errors, >0 for row-specific
    error: string;  // Error message
}
```

### BatchResult

Returned after successful upload.

```typescript
export interface BatchResult {
    uuid: string;
    total_records: number;
    total_debit: string;
    total_credit: string;
    processed_at: string;
}
```

### Batch

Full batch data for listing.

```typescript
export interface Batch {
    id: number;
    uuid: string;
    branch_id: number;
    bank_account_id: number;
    user_id: number;
    filename: string;
    total_records: number;
    total_debit: string;
    total_credit: string;
    processed_at: string;
    created_at: string;
    updated_at: string;
}
```

### PaginatedResponse

Generic paginated response from Laravel.

```typescript
export interface PaginatedResponse<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}
```

---

## Key Functions

### handleBranchChange

Handles branch selection change. Resets bank account selection.

```typescript
const handleBranchChange = (value: string) => {
    setSelectedBranch(value);
    setSelectedBankAccount('');
};
```

### handleFileChange

Handles file input change. Resets error/success states.

```typescript
const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
        setSelectedFile(file);
        setErrors([]);
        setSuccessResult(null);
        setUploadStatus('idle');
        setUploadProgress(0);
    }
};
```

### handleUpload

Uploads the Excel file to the server.

**Flow:**
1. Create FormData with branch_id, bank_account_id, file
2. Set status to 'validating' with progress 20%
3. Send POST to `/tesoreria/batches`
4. Set status to 'processing' with progress 40-80%
5. Parse JSON response
6. On success: Set result, clear file, refresh batches
7. On error: Set errors array

```typescript
const handleUpload = async () => {
    if (!selectedBranch || !selectedBankAccount || !selectedFile) return;
    // ... upload logic
};
```

### fetchBatches

Fetches batches for selected branch and bank account.

```typescript
const fetchBatches = useCallback(async (page = 1) => {
    if (!selectedBranch || !selectedBankAccount) {
        setBatches([]);
        return;
    }
    // GET /tesoreria/batches?branch_id=X&bank_account_id=Y&page=Z
}, [selectedBranch, selectedBankAccount]);
```

### handleDeleteBatch

Deletes a batch after confirmation.

```typescript
const handleDeleteBatch = async () => {
    if (!batchToDelete) return;
    // DELETE /tesoreria/batches/{id}
    // Refresh batches on success
};
```

### handleDownloadErrors

Downloads error log as text file via form POST.

```typescript
const handleDownloadErrors = () => {
    // POST /tesoreria/batches/error-log with errors array
    // Server returns text file download
};
```

---

## UI Components Used

### From shadcn/ui

| Component | Import Path | Usage |
|-----------|-------------|-------|
| Alert | @/components/ui/alert | Success/error messages |
| AlertDialog | @/components/ui/alert-dialog | Delete confirmation |
| Button | @/components/ui/button | Actions |
| Card | @/components/ui/card | Section containers |
| Input | @/components/ui/input | File input |
| Label | @/components/ui/label | Form labels |
| Progress | @/components/ui/progress | Upload progress bar |
| Select | @/components/ui/select | Branch/account dropdowns |
| Table | @/components/ui/table | Batches DataTable |

### From Lucide React (Icons)

| Icon | Usage |
|------|-------|
| AlertCircle | Error alert icon |
| CheckCircle2 | Success alert icon |
| ChevronLeft | Pagination previous |
| ChevronRight | Pagination next |
| Download | Download errors button |
| Eye | View batch details |
| FileSpreadsheet | File badge icon |
| Loader2 | Loading spinner |
| Trash2 | Delete batch |
| Upload | Upload button |
| X | Remove file |

---

## UI Sections

### 1. Upload Section

**Card: "Automatizacion de asientos contables"**

Contains:
- **Branch Select**: Dropdown with user's available branches
- **Bank Account Select**: Filtered by selected branch, disabled until branch selected
- **File Input**: Accepts .xlsx, .xls files
- **File Badge**: Shows selected file name and size with remove button
- **Upload Button**: Disabled until all fields filled
- **Progress Bar**: Shows during upload with status message

### 2. Success Alert

Shows after successful upload:
- Batch UUID
- Total records count
- Total debit amount (formatted)
- Total credit amount (formatted)
- Processed timestamp

### 3. Error Alert

Shows when validation fails:
- Error count
- First 10 errors with row numbers
- "Download error log" button

### 4. Batches Section

**Card: "Lotes de Transaccion"**

States:
- **No selection**: Message "Selecciona sucursal y cuenta para ver lotes"
- **Loading**: Spinner
- **Empty**: Message "No hay lotes procesados para esta seleccion"
- **With data**: DataTable with pagination

**DataTable Columns:**
| Column | Description |
|--------|-------------|
| UUID | First 8 chars + "..." |
| Archivo | Filename (truncated) |
| Fecha procesado | Formatted datetime |
| Registros | Record count |
| Total Debito | Currency formatted |
| Total Credito | Currency formatted |
| Acciones | View/Delete buttons |

**Pagination:**
- 10 items per page
- Previous/Next buttons
- Page indicator

### 5. Delete Confirmation Dialog

AlertDialog with:
- Warning title
- Batch UUID preview
- Cancel button
- Delete button (red, shows spinner when deleting)

---

## Data Flow

### Page Load

```
1. Inertia renders page with branches and bankAccounts props
2. User selects branch -> filteredBankAccounts updates
3. User selects bank account -> fetchBatches() called
4. Batches displayed in DataTable
```

### File Upload

```
1. User selects file -> handleFileChange()
2. User clicks "Cargar Excel" -> handleUpload()
3. Progress: idle -> validating (20%) -> processing (40-80%) -> success/error (100%)
4. On success: fetchBatches(1) to refresh list
5. On error: errors displayed, can download log
```

### Batch Deletion

```
1. User clicks delete icon -> setBatchToDelete(), setDeleteDialogOpen(true)
2. User confirms -> handleDeleteBatch()
3. DELETE request sent
4. On success: fetchBatches(currentPage), close dialog
```

---

## Formatting Utilities

### formatFileSize

Formats bytes to human-readable size.

```typescript
const formatFileSize = (bytes: number): string => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
};
```

### formatCurrency

Formats decimal string to Mexican peso format.

```typescript
const formatCurrency = (value: string): string => {
    return Number(value).toLocaleString('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
};
```

### formatDate

Formats ISO date to localized datetime.

```typescript
const formatDate = (dateString: string): string => {
    return new Date(dateString).toLocaleString('es-MX', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};
```

---

## CSRF Token Handling

All fetch requests include CSRF token from meta tag:

```typescript
headers: {
    'X-CSRF-TOKEN': document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content || '',
    'Accept': 'application/json',
}
```

Required meta tag in `resources/views/app.blade.php`:
```html
<meta name="csrf-token" content="{{ csrf_token() }}">
```

---

## Error Handling

### Non-JSON Response Detection

```typescript
const contentType = response.headers.get('content-type');
if (!contentType || !contentType.includes('application/json')) {
    const text = await response.text();
    console.error('Non-JSON response:', text.substring(0, 500));
    throw new Error(`El servidor respondio con un error (${response.status})`);
}
```

### Error Display Logic

```typescript
if (data.errors && Array.isArray(data.errors)) {
    setErrors(data.errors);
} else if (data.message) {
    setErrors([{ row: 0, error: data.message }]);
} else {
    setErrors([{ row: 0, error: 'Error desconocido al procesar el archivo' }]);
}
```

---

## Responsive Design

- Uses Tailwind CSS responsive classes
- Grid layout: `md:grid-cols-2` for selects
- File upload: `md:grid-cols-[1fr_auto]`
- Table scrolls horizontally on small screens
- Pagination stacks on mobile

---

## Accessibility

- Labels associated with inputs via `htmlFor`
- Screen reader text for icon buttons (`sr-only`)
- Disabled states properly managed
- Focus management in dialogs
