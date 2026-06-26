<?php

namespace Database\Seeders;

use App\Models\Acquirer;
use Illuminate\Database\Seeder;

class AcquirerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Tentative defaults (payment types + tolerance) until real acquirer files
     * arrive; adjust per acquirer afterwards.
     */
    public function run(): void
    {
        $acquirers = [
            [
                'code' => 'MIFEL',
                'name' => 'CC MIFEL',
                'kind' => 'BANK',
                // gCore records cards as CREDITO/DEBITO (AMEX is a CREDITO brand).
                'parrot_payment_type_names' => ['CREDITO', 'DEBITO'],
                'amount_tolerance' => 0.10,
                'time_window_seconds' => null,
                // Default mapping for the MIFEL export (validated vs gCore April:
                // 99.1% matched). Dates/times are Excel serials → parsed natively;
                // use "Fecha transacción" (swipe), not "Fecha de aplicación".
                'column_map' => [
                    'columns' => [
                        'transaction_date' => ['header' => 'Fecha transacción', 'format' => 'DD/MM/YY'],
                        'transaction_time' => ['header' => 'Hora transacción'],
                        'amount' => ['header' => 'Monto'],
                        'authorization' => ['header' => 'Núm. de autorización'],
                        'reference' => ['header' => 'Núm. de referencia'],
                        'card_type' => ['header' => 'Tipo de tarjeta'],
                        'card_brand' => ['header' => 'Emisor'],
                        'terminal' => ['header' => 'Núm. de terminal'],
                        'operation_type' => ['header' => 'Tipo de operación'],
                        'status' => ['header' => 'Estatus'],
                    ],
                    // The totals row (formula-only) is dropped under readDataOnly,
                    // so headers land on matrix row 0.
                    'header_row' => 0,
                    'delimiter' => "\t",
                ],
            ],
            [
                'code' => 'AFIRME',
                'name' => 'Afirme',
                'kind' => 'BANK',
                'parrot_payment_type_names' => ['CREDITO', 'DEBITO', 'AMEX'],
                'amount_tolerance' => 0.10,
                'time_window_seconds' => null,
            ],
            [
                'code' => 'RAPPI',
                'name' => 'Rappi',
                'kind' => 'DELIVERY',
                'parrot_payment_type_names' => ['Rappi'],
                'amount_tolerance' => 0.50,
                'time_window_seconds' => null,
                // Default mapping for the Rappi export (validated vs gCore April).
                'column_map' => [
                    'columns' => [
                        'transaction_date' => ['header' => 'Fecha de creación orden', 'format' => 'es_datetime'],
                        'amount' => ['header' => 'Venta Bruta'],
                        'reference' => ['header' => 'ID de la órden'],
                        'status' => ['header' => 'Estado de la órden'],
                    ],
                    // The subtotal row (a formula-only row) is dropped under
                    // readDataOnly, so headers land on matrix row 0.
                    'header_row' => 0,
                    'delimiter' => "\t",
                ],
            ],
            [
                'code' => 'UBER_EATS',
                'name' => 'Uber Eats',
                'kind' => 'DELIVERY',
                'parrot_payment_type_names' => ['Uber Eats'],
                'amount_tolerance' => 0.50,
                'time_window_seconds' => null,
            ],
        ];

        foreach ($acquirers as $acquirer) {
            Acquirer::updateOrCreate(['code' => $acquirer['code']], $acquirer);
        }
    }
}
