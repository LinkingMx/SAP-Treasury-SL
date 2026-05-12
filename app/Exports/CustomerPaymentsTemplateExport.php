<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CustomerPaymentsTemplateExport implements FromCollection, WithStyles
{
    public function collection(): Collection
    {
        return collect([
            ['Pagos de Clientes (IncomingPayments) - Service Layer SAP B1'],

            ['POST → https://[SAP-SERVER]:50000/b1s/v1/IncomingPayments'],

            ['← ENCABEZADO DEL COBRO →', '', '', '', '', '← DETALLE FACTURAS (PaymentInvoices) →'],

            [
                'CardCode',
                'CardName',
                "DocDate\n(Fecha Cobro)",
                'TransferDate',
                'TransferAccount',
                'DocNum',
                'InvoiceType',
                'SumApplied',
            ],

            [
                'C0001',
                'CLIENTE DE EJEMPLO SA DE CV',
                '2026-04-14',
                '2026-04-14',
                '1020-001-000',
                15001,
                'it_Invoice',
                25000.00,
            ],
            [
                'C0001',
                'CLIENTE DE EJEMPLO SA DE CV',
                '2026-04-14',
                '2026-04-14',
                '1020-001-000',
                15002,
                'it_Invoice',
                12500.50,
            ],
            [
                'C0002',
                'OTRO CLIENTE SA',
                '2026-04-14',
                '2026-04-14',
                '1020-001-000',
                15010,
                'it_Invoice',
                5000.00,
            ],
        ]);
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getColumnDimension('A')->setWidth(15);
        $sheet->getColumnDimension('B')->setWidth(35);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(18);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->getColumnDimension('G')->setWidth(22);
        $sheet->getColumnDimension('H')->setWidth(15);

        return [
            1 => [
                'font' => ['bold' => true, 'size' => 14],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            2 => [
                'font' => ['italic' => true, 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            ],
            3 => [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            4 => [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ],
        ];
    }
}
