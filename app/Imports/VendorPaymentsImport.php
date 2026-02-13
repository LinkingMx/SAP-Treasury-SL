<?php

namespace App\Imports;

use App\Enums\VendorPaymentBatchStatus;
use App\Models\VendorPaymentBatch;
use App\Models\VendorPaymentInvoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class VendorPaymentsImport implements ToCollection, WithHeadingRow
{
    protected array $errors = [];

    protected ?VendorPaymentBatch $batch = null;

    public function __construct(
        protected int $branchId,
        protected int $bankAccountId,
        protected int $userId,
        protected string $filename
    ) {}

    /**
     * Specify which row contains the headings.
     */
    public function headingRow(): int
    {
        return 4;
    }

    public function collection(Collection $rows): void
    {
        // Validate all rows before creating anything
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +2 because index starts at 0 and Excel has header row
            $this->validateRow($row->toArray(), $rowNumber);
        }

        // If there are errors, don't create batch or invoices
        if ($this->hasErrors()) {
            return;
        }

        // Group by CardCode to validate consistency and calculate totals
        $groupedByVendor = $rows->groupBy('cardcode');

        // Validate grouped data
        foreach ($groupedByVendor as $cardCode => $vendorRows) {
            $this->validateVendorGroup($cardCode, $vendorRows);
        }

        if ($this->hasErrors()) {
            return;
        }

        // Create batch and invoices in a transaction
        DB::transaction(function () use ($rows, $groupedByVendor) {
            // Calculate totals
            $totalInvoices = $rows->count();
            $totalPayments = $groupedByVendor->count();
            $totalAmount = $rows->sum(function ($row) {
                return $this->parseAmount($row['sumapplied'] ?? null);
            });

            // Create batch
            $this->batch = VendorPaymentBatch::create([
                'branch_id' => $this->branchId,
                'bank_account_id' => $this->bankAccountId,
                'user_id' => $this->userId,
                'filename' => $this->filename,
                'total_invoices' => $totalInvoices,
                'total_payments' => $totalPayments,
                'total_amount' => $totalAmount,
                'status' => VendorPaymentBatchStatus::Pending,
                'processed_at' => now(),
            ]);

            // Create invoices grouped by vendor
            foreach ($groupedByVendor as $cardCode => $vendorRows) {
                $lineNum = 0;

                foreach ($vendorRows as $row) {
                    $rowArray = $row->toArray();

                    VendorPaymentInvoice::create([
                        'batch_id' => $this->batch->id,
                        'card_code' => $rowArray['cardcode'],
                        'card_name' => $rowArray['cardname'] ?? null,
                        'doc_date' => $this->parseDate($rowArray['docdate_fecha_pago']),
                        'transfer_date' => $this->parseDate($rowArray['transferdate']),
                        'transfer_account' => $rowArray['transferaccount'],
                        'line_num' => $lineNum++, // Auto-assign LineNum
                        'doc_entry' => (int) $rowArray['docnum'],
                        'invoice_type' => $rowArray['invoicetype'] ?? 'it_PurchaseInvoice',
                        'sum_applied' => $this->parseAmount($rowArray['sumapplied']),
                    ]);
                }
            }
        });
    }

    protected function validateRow(array $row, int $rowNumber): void
    {
        $validator = Validator::make($row, [
            'cardcode' => ['required', 'string', 'max:50'],
            'cardname' => ['nullable', 'string'],
            'docdate_fecha_pago' => ['required'],
            'transferdate' => ['required'],
            'transferaccount' => ['required', 'string', 'max:50'],
            'docnum' => ['required', 'integer'],
            'invoicetype' => ['nullable', 'string', 'max:50'],
            'sumapplied' => ['required', 'numeric', 'gt:0'],
        ], [
            'cardcode.required' => 'El código del proveedor es requerido',
            'cardcode.max' => 'El código del proveedor no debe exceder 50 caracteres',
            'docdate_fecha_pago.required' => 'La fecha del documento es requerida',
            'transferdate.required' => 'La fecha de transferencia es requerida',
            'transferaccount.required' => 'La cuenta de transferencia es requerida',
            'docnum.required' => 'El número de documento (DocNum) es requerido',
            'docnum.integer' => 'El número de documento debe ser un número entero',
            'sumapplied.required' => 'El monto a pagar es requerido',
            'sumapplied.numeric' => 'El monto a pagar debe ser un número',
            'sumapplied.gt' => 'El monto a pagar debe ser mayor a 0',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->errors[] = [
                    'row' => $rowNumber,
                    'error' => $error,
                ];
            }
        }

        // Validate date formats
        if (isset($row['docdate_fecha_pago']) && ! $this->isValidDate($row['docdate_fecha_pago'])) {
            $this->errors[] = [
                'row' => $rowNumber,
                'error' => 'El formato de la fecha del documento es inválido',
            ];
        }

        if (isset($row['transferdate']) && ! $this->isValidDate($row['transferdate'])) {
            $this->errors[] = [
                'row' => $rowNumber,
                'error' => 'El formato de la fecha de transferencia es inválido',
            ];
        }
    }

    protected function validateVendorGroup(string $cardCode, Collection $vendorRows): void
    {
        // Validate that all rows for the same vendor have consistent data
        $firstRow = $vendorRows->first();
        $docDate = $this->parseDate($firstRow['docdate_fecha_pago']);
        $transferDate = $this->parseDate($firstRow['transferdate']);
        $transferAccount = $firstRow['transferaccount'];

        foreach ($vendorRows as $row) {
            if ($this->parseDate($row['docdate_fecha_pago'])->format('Y-m-d') !== $docDate->format('Y-m-d')) {
                $this->errors[] = [
                    'row' => 0,
                    'error' => "Todas las facturas del proveedor {$cardCode} deben tener la misma fecha de documento",
                ];
                break;
            }

            if ($this->parseDate($row['transferdate'])->format('Y-m-d') !== $transferDate->format('Y-m-d')) {
                $this->errors[] = [
                    'row' => 0,
                    'error' => "Todas las facturas del proveedor {$cardCode} deben tener la misma fecha de transferencia",
                ];
                break;
            }

            if ($row['transferaccount'] !== $transferAccount) {
                $this->errors[] = [
                    'row' => 0,
                    'error' => "Todas las facturas del proveedor {$cardCode} deben tener la misma cuenta de transferencia",
                ];
                break;
            }
        }
    }

    protected function parseAmount(?string $value): ?float
    {
        if ($value === null || trim($value) === '' || trim($value) === '0') {
            return null;
        }

        return (float) str_replace(',', '', $value);
    }

    protected function parseDate(mixed $value): Carbon
    {
        // Handle Excel serial date format
        if (is_numeric($value)) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value));
        }

        // Handle string dates
        return Carbon::parse($value);
    }

    protected function isValidDate(mixed $value): bool
    {
        try {
            $this->parseDate($value);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getBatch(): ?VendorPaymentBatch
    {
        return $this->batch;
    }

    public function getErrorsAsText(): string
    {
        $lines = ['=== ERRORES DE IMPORTACIÓN ===', ''];

        foreach ($this->errors as $error) {
            if ($error['row'] > 0) {
                $lines[] = "Fila {$error['row']}: {$error['error']}";
            } else {
                $lines[] = $error['error'];
            }
        }

        $lines[] = '';
        $lines[] = 'Total de errores: '.count($this->errors);

        return implode("\n", $lines);
    }
}
