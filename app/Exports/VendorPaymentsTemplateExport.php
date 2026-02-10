<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class VendorPaymentsTemplateExport implements FromCollection, WithStyles
{
    public function collection(): Collection
    {
        return collect([
            // Row 1: Title
            ['Pagos a Proveedores (VendorPayments) - Service Layer SAP B1'],

            // Row 2: Endpoint info
            ['POST → https://[SAP-SERVER]:50000/b1s/v1/VendorPayments'],

            // Row 3: Section headers
            ['← ENCABEZADO DEL PAGO →', '', '', '', '', '← DETALLE FACTURAS (PaymentInvoices) →'],

            // Row 4: Column headers
            [
                'CardCode',
                'CardName',
                "DocDate\n(Fecha Pago)",
                'TransferDate',
                'TransferAccount',
                'DocEntry',
                'InvoiceType',
                'SumApplied',
            ],

            // Example data rows
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

    public function styles(Worksheet $sheet)
    {
        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(35);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(18);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->getColumnDimension('G')->setWidth(22);
        $sheet->getColumnDimension('H')->setWidth(15);

        return [
            // Row 1: Title - bold and centered
            1 => [
                'font' => ['bold' => true, 'size' => 14],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],

            // Row 2: Endpoint - italic
            2 => [
                'font' => ['italic' => true, 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            ],

            // Row 3: Section headers - bold
            3 => [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],

            // Row 4: Column headers - bold and centered
            4 => [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ],
        ];
    }
}
