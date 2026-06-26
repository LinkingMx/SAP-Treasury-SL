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
                'parrot_payment_type_names' => ['CREDITO', 'DEBITO', 'AMEX'],
                'amount_tolerance' => 0.10,
                'time_window_seconds' => null,
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
                    'header_row' => 1,
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
