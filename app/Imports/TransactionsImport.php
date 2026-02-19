<?php

namespace App\Imports;

use App\Enums\BatchStatus;
use App\Models\Batch;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class TransactionsImport implements ToCollection, WithHeadingRow
{
    protected int $branchId;

    protected int $bankAccountId;

    protected int $userId;

    protected string $filename;

    /** @var array<int, array<string, string>> */
    protected array $errors = [];

    protected ?Batch $batch = null;

    public function __construct(int $branchId, int $bankAccountId, int $userId, string $filename)
    {
        $this->branchId = $branchId;
        $this->bankAccountId = $bankAccountId;
        $this->userId = $userId;
        $this->filename = $filename;
    }

    public function collection(Collection $rows): void
    {
        // Log the first row to see what keys we're getting
        if ($rows->isNotEmpty()) {
            Log::info('Excel import - First row keys', [
                'keys' => array_keys($rows->first()->toArray()),
                'first_row' => $rows->first()->toArray(),
            ]);
        }

        // Filter out completely empty rows
        $rows = $rows->filter(function ($row) {
            // Check if at least one non-null value exists in the row
            return collect($row)->filter(function ($value) {
                return $value !== null && $value !== '';
            })->isNotEmpty();
        });

        // If no valid rows after filtering, add error
        if ($rows->isEmpty()) {
            $this->errors[] = [
                'row' => 0,
                'error' => 'El archivo no contiene filas con datos válidos',
            ];

            return;
        }

        Log::info('Excel import - Valid rows count', ['count' => $rows->count()]);

        // Validate all rows first
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +2 because index starts at 0 and we skip header row
            $this->validateRow($row->toArray(), $rowNumber);
        }

        // If there are errors, don't proceed with import
        if ($this->hasErrors()) {
            return;
        }

        // All rows are valid, proceed with import in a transaction
        DB::transaction(function () use ($rows) {
            $totalDebit = 0;
            $totalCredit = 0;

            // Create batch
            $this->batch = Batch::create([
                'branch_id' => $this->branchId,
                'bank_account_id' => $this->bankAccountId,
                'user_id' => $this->userId,
                'filename' => $this->filename,
                'total_records' => $rows->count(),
                'status' => BatchStatus::Pending,
                'processed_at' => now(),
            ]);

            // Create transactions
            foreach ($rows as $row) {
                $debitAmount = $this->parseAmount($row['debit_amount'] ?? null);
                $creditAmount = $this->parseAmount($row['credit_amount'] ?? null);

                Transaction::create([
                    'batch_id' => $this->batch->id,
                    'sequence' => (int) $row['sequence'],
                    'due_date' => $this->parseDate($row['duedate']),
                    'memo' => trim($row['memo']),
                    'debit_amount' => $debitAmount,
                    'credit_amount' => $creditAmount,
                    'counterpart_account' => trim($row['cuenta_contrapartida']),
                ]);

                $totalDebit += $debitAmount ?? 0;
                $totalCredit += $creditAmount ?? 0;
            }

            // Update batch totals
            $this->batch->update([
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function validateRow(array $row, int $rowNumber): void
    {
        $validator = Validator::make($row, [
            'sequence' => ['required', 'integer', 'min:1'],
            'duedate' => ['required'],
            'memo' => ['required', 'string', 'max:255'],
            'cuenta_contrapartida' => ['required', 'string', 'max:50'],
        ], [
            'sequence.required' => 'La secuencia es requerida',
            'sequence.integer' => 'La secuencia debe ser un número entero',
            'duedate.required' => 'La fecha es requerida',
            'memo.required' => 'El memo/descripción es requerido',
            'cuenta_contrapartida.required' => 'La cuenta contrapartida es requerida',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->errors[] = [
                    'row' => $rowNumber,
                    'error' => $error,
                ];
            }
        }

        // Additional validation: at least one amount must be present
        $debit = $this->parseAmount($row['debit_amount'] ?? null);
        $credit = $this->parseAmount($row['credit_amount'] ?? null);

        if ($debit === null && $credit === null) {
            $this->errors[] = [
                'row' => $rowNumber,
                'error' => 'Debe existir al menos un monto (débito o crédito)',
            ];
        }

        // Validate date format
        if (isset($row['duedate']) && ! $this->isValidDate($row['duedate'])) {
            $this->errors[] = [
                'row' => $rowNumber,
                'error' => 'El formato de fecha es inválido',
            ];
        }
    }

    protected function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }

        return (float) $value;
    }

    protected function parseDate(mixed $value): Carbon
    {
        // Excel stores dates as serial numbers
        if (is_numeric($value)) {
            return Carbon::createFromFormat('Y-m-d', gmdate('Y-m-d', ($value - 25569) * 86400));
        }

        return Carbon::parse($value);
    }

    protected function isValidDate(mixed $value): bool
    {
        if (is_numeric($value)) {
            // Excel serial date - valid if it's a reasonable number
            return $value > 0 && $value < 100000;
        }

        try {
            Carbon::parse($value);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getBatch(): ?Batch
    {
        return $this->batch;
    }

    public function getErrorsAsText(): string
    {
        $lines = ['=== ERRORES DE IMPORTACIÓN ===', ''];

        foreach ($this->errors as $error) {
            $lines[] = "Fila {$error['row']}: {$error['error']}";
        }

        $lines[] = '';
        $lines[] = 'Total de errores: '.count($this->errors);

        return implode("\n", $lines);
    }
}
