<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransactionsTemplateExport implements FromCollection, WithHeadings, WithStyles
{
    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function collection()
    {
        return collect([
            [
                'sequence' => 1,
                'duedate' => '2025-01-15',
                'memo' => 'Pago proveedor ABC',
                'cuenta_contrapartida' => '1101001',
                'debit_amount' => 1500.00,
                'credit_amount' => null,
            ],
            [
                'sequence' => 2,
                'duedate' => '2025-01-20',
                'memo' => 'Cobro cliente XYZ',
                'cuenta_contrapartida' => '1102001',
                'debit_amount' => null,
                'credit_amount' => 2500.50,
            ],
            [
                'sequence' => 3,
                'duedate' => '2025-01-25',
                'memo' => 'Transferencia interna',
                'cuenta_contrapartida' => '1103001',
                'debit_amount' => 750.00,
                'credit_amount' => null,
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'sequence',
            'duedate',
            'memo',
            'cuenta_contrapartida',
            'debit_amount',
            'credit_amount',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
