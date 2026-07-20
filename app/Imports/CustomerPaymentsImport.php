<?php

namespace App\Imports;

use App\Enums\CustomerPaymentBatchStatus;
use App\Models\CustomerPaymentBatch;
use App\Models\CustomerPaymentInvoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CustomerPaymentsImport implements ToCollection, WithHeadingRow
{
    protected array $errors = [];

    protected ?CustomerPaymentBatch $batch = null;

    public function __construct(
        protected int $branchId,
        protected int $bankAccountId,
        protected int $userId,
        protected string $filename,
        protected string $processDate
    ) {}

    public function headingRow(): int
    {
        return 4;
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $this->validateRow($row->toArray(), $rowNumber);
        }

        if ($this->hasErrors()) {
            return;
        }

        $groupedByCustomer = $rows->groupBy('cardcode');

        foreach ($groupedByCustomer as $cardCode => $customerRows) {
            $this->validateCustomerGroup($cardCode, $customerRows);
        }

        if ($this->hasErrors()) {
            return;
        }

        DB::transaction(function () use ($rows, $groupedByCustomer) {
            $totalInvoices = $rows->count();
            $totalPayments = $groupedByCustomer->count();
            $totalAmount = $rows->sum(function ($row) {
                return $this->parseAmount($row['sumapplied'] ?? null);
            });

            $this->batch = CustomerPaymentBatch::create([
                'branch_id' => $this->branchId,
                'bank_account_id' => $this->bankAccountId,
                'user_id' => $this->userId,
                'filename' => $this->filename,
                'process_date' => $this->processDate,
                'total_invoices' => $totalInvoices,
                'total_payments' => $totalPayments,
                'total_amount' => $totalAmount,
                'status' => CustomerPaymentBatchStatus::Pending,
                'processed_at' => now(),
            ]);

            // Collect invoices grouped by customer, then bulk-insert in chunks: one
            // round-trip per ~500 rows instead of one per row (a per-row loop over a
            // large file blows past the request timeout on a remote DB).
            $now = now();
            $pending = [];

            foreach ($groupedByCustomer as $cardCode => $customerRows) {
                $lineNum = 0;

                foreach ($customerRows as $row) {
                    $rowArray = $this->normalizeRow($row->toArray());

                    $processDateCarbon = Carbon::parse($this->processDate);

                    $pending[] = [
                        'batch_id' => $this->batch->id,
                        'card_code' => $rowArray['cardcode'],
                        'card_name' => $rowArray['cardname'] ?? null,
                        'doc_date' => $processDateCarbon,
                        'transfer_date' => $processDateCarbon,
                        'transfer_account' => $rowArray['transferaccount'],
                        'line_num' => $lineNum++,
                        'doc_entry' => (int) $rowArray['docnum'],
                        'invoice_type' => $rowArray['invoicetype'] ?? 'it_Invoice',
                        'sum_applied' => $this->parseAmount($rowArray['sumapplied']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($pending, 500) as $chunk) {
                CustomerPaymentInvoice::insert($chunk);
            }
        });
    }

    protected function normalizeRow(array $row): array
    {
        foreach (['cardcode', 'cardname', 'transferaccount', 'invoicetype'] as $field) {
            if (isset($row[$field]) && ! is_string($row[$field])) {
                $row[$field] = (string) $row[$field];
            }
        }

        return $row;
    }

    protected function validateRow(array $row, int $rowNumber): void
    {
        $row = $this->normalizeRow($row);

        $validator = Validator::make($row, [
            'cardcode' => ['required', 'string', 'max:50'],
            'cardname' => ['nullable', 'string'],
            'docdate_fecha_cobro' => ['nullable'],
            'transferdate' => ['nullable'],
            'transferaccount' => ['required', 'string', 'max:50'],
            'docnum' => ['required', 'integer'],
            'invoicetype' => ['nullable', 'string', 'max:50'],
            'sumapplied' => ['required', 'numeric', 'gt:0'],
        ], [
            'cardcode.required' => 'El código del cliente es requerido',
            'cardcode.string' => 'El código del cliente debe ser texto',
            'cardcode.max' => 'El código del cliente no debe exceder 50 caracteres',
            'transferaccount.required' => 'La cuenta de transferencia es requerida',
            'docnum.required' => 'El número de documento (DocNum) es requerido',
            'docnum.integer' => 'El número de documento debe ser un número entero',
            'sumapplied.required' => 'El monto a cobrar es requerido',
            'sumapplied.numeric' => 'El monto a cobrar debe ser un número',
            'sumapplied.gt' => 'El monto a cobrar debe ser mayor a 0',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->errors[] = [
                    'row' => $rowNumber,
                    'error' => $error,
                ];
            }
        }
    }

    protected function validateCustomerGroup(string $cardCode, Collection $customerRows): void
    {
        $firstRow = $customerRows->first();
        $transferAccount = $firstRow['transferaccount'];

        foreach ($customerRows as $row) {
            if ($row['transferaccount'] !== $transferAccount) {
                $this->errors[] = [
                    'row' => 0,
                    'error' => "Todas las facturas del cliente {$cardCode} deben tener la misma cuenta de transferencia",
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

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getBatch(): ?CustomerPaymentBatch
    {
        return $this->batch;
    }
}
