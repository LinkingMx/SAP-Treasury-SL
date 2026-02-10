<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class VendorPaymentsTemplateExport implements FromCollection, WithHeadings, WithStyles
{
    public function collection(): Collection
    {
        return collect([
            [
                'P0172',
                'STANDARD FOODS',
                '2026-02-09',
                '2026-02-09',
                '1020-001-000',
                42936,
                'it_PurchaseInvoice',
                135508.89,
            ],
            [
                'P0172',
                'STANDARD FOODS',
                '2026-02-09',
                '2026-02-09',
                '1020-001-000',
                42925,
                'it_PurchaseInvoice',
                2939.68,
            ],
            [
                'P0285',
                'JAPAN BAR SA DE CV',
                '2026-02-09',
                '2026-02-09',
                '1020-001-000',
                43001,
                'it_PurchaseInvoice',
                5000.00,
            ],
        ]);
    }

    public function headings(): array
    {
        return [
            'CardCode',
            'CardName',
            'DocDate',
            'TransferDate',
            'TransferAccount',
            'DocEntry',
            'InvoiceType',
            'SumApplied',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
