<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AfirmeController extends Controller
{
    /**
     * Display the Afirme integration page.
     */
    public function index(): InertiaResponse
    {
        $branchIds = auth()->user()->branches()->pluck('branches.id');

        $branches = Branch::whereIn('id', $branchIds)
            ->whereNotNull('afirme_account')
            ->where('afirme_account', '!=', '')
            ->get(['id', 'name', 'sap_database', 'afirme_account']);

        return Inertia::render('afirme/index', [
            'branches' => $branches,
        ]);
    }

    /**
     * Get pending payments from SAP SQL Server.
     */
    public function getPayments(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $branch = Branch::findOrFail($request->input('branch_id'));

        if (! $branch->sap_database) {
            return response()->json([
                'success' => false,
                'message' => 'La sucursal no tiene base de datos SAP configurada',
            ], 422);
        }

        try {
            config(['database.connections.sap_sqlsrv.database' => $branch->sap_database]);
            DB::purge('sap_sqlsrv');

            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');

            $payments = DB::connection('sap_sqlsrv')
                ->table('OVPM as T0')
                ->leftJoin('OCRD as T1', 'T0.CardCode', '=', 'T1.CardCode')
                ->select([
                    'T0.DocEntry',
                    'T0.DocNum',
                    'T0.CardCode',
                    'T0.CardName',
                    'T1.LicTradNum as rfc',
                    'T1.DflAccount as clabe',
                    'T0.TrsfrSum as amount',
                    'T0.TrsfrDate as transfer_date',
                    'T0.Comments as reference',
                ])
                ->where('T0.Canceled', '<>', 'Y')
                ->where('T0.DocType', 'S')
                ->where('T1.CardType', 'S')
                ->where('T0.U_ProcesadoBanco', 'NO Pagado')
                ->whereBetween('T0.TrsfrDate', [$dateFrom, $dateTo])
                ->orderBy('T0.DocEntry')
                ->get();

            $totalAmount = $payments->sum('amount');

            return response()->json([
                'success' => true,
                'payments' => $payments,
                'summary' => [
                    'count' => $payments->count(),
                    'total_amount' => $totalAmount,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar SAP: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate TXT file for Afirme bank and mark payments as processed.
     */
    public function downloadTxt(Request $request): StreamedResponse|JsonResponse
    {
        $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $branch = Branch::findOrFail($request->input('branch_id'));

        if (! $branch->sap_database) {
            return response()->json([
                'success' => false,
                'message' => 'La sucursal no tiene base de datos SAP configurada',
            ], 422);
        }

        if (! $branch->afirme_account || strlen($branch->afirme_account) !== 18) {
            return response()->json([
                'success' => false,
                'message' => 'La sucursal no tiene cuenta CLABE Afirme válida (18 dígitos)',
            ], 422);
        }

        try {
            config(['database.connections.sap_sqlsrv.database' => $branch->sap_database]);
            DB::purge('sap_sqlsrv');

            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');

            // Get company info
            $company = DB::connection('sap_sqlsrv')
                ->table('OADM')
                ->where('Country', 'MX')
                ->first(['CompnyName', 'TaxIdNum']);

            if (! $company) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró información de la empresa en SAP',
                ], 422);
            }

            // Get payments
            $payments = DB::connection('sap_sqlsrv')
                ->table('OVPM as T0')
                ->leftJoin('OCRD as T1', 'T0.CardCode', '=', 'T1.CardCode')
                ->select([
                    'T0.DocEntry',
                    'T0.CardName',
                    'T1.LicTradNum as rfc',
                    'T1.DflAccount as clabe',
                    'T0.TrsfrSum as amount',
                    'T0.Comments as reference',
                ])
                ->where('T0.Canceled', '<>', 'Y')
                ->where('T0.DocType', 'S')
                ->where('T1.CardType', 'S')
                ->where('T0.U_ProcesadoBanco', 'NO Pagado')
                ->whereBetween('T0.TrsfrDate', [$dateFrom, $dateTo])
                ->orderBy('T0.DocEntry')
                ->get();

            if ($payments->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay pagos pendientes para el rango de fechas seleccionado',
                ], 422);
            }

            $totalAmount = $payments->sum('amount');
            $paymentCount = $payments->count();
            $today = now()->format('Ymd');
            $todayShort = now()->format('dmy');

            // Build TXT content
            $lines = [];

            // Record 01: Header
            $line01 = '01'; // Record type
            $line01 .= '0000001'; // Sequence number
            $line01 .= $today; // Presentation date YYYYMMDD
            $line01 .= '01'; // Currency code (01 = MXN)
            $line01 .= '1'; // Same day = 1
            $line01 .= '062'; // Presenting bank = 062 Afirme
            $line01 .= '01'; // Operation type 01 = Credit transfer
            $line01 .= $today; // Application date YYYYMMDD
            $line01 .= '01'; // Ordering account type 01 = Checking
            $line01 .= $branch->afirme_account; // CLABE 18 digits
            $line01 .= $this->padRight(strtoupper($company->CompnyName), 40); // Company name
            $line01 .= $this->padRight($company->TaxIdNum, 18); // RFC
            $line01 .= str_pad($paymentCount, 7, '0', STR_PAD_LEFT); // Number of operations
            $line01 .= '0'.str_pad(str_replace('.', '', number_format($totalAmount, 2, '.', '')), 18, '0', STR_PAD_LEFT); // Total amount
            $line01 .= $this->padRight('EGRESO BANCARIO', 40); // Batch description
            $line01 .= ':';

            $lines[] = $line01;

            // Record 02: Detail lines
            $sequence = 2;
            foreach ($payments as $payment) {
                $bankCode = substr($payment->clabe ?? '000', 0, 3);
                $amount = str_replace('.', '', number_format($payment->amount, 2, '.', ''));

                $line02 = '02'; // Record type
                $line02 .= str_pad($sequence, 7, '0', STR_PAD_LEFT); // Line number
                $line02 .= '60'; // Credit instruction
                $line02 .= $bankCode; // Receiving bank
                $line02 .= '0'.str_pad($amount, 15, '0', STR_PAD_LEFT); // Amount
                $line02 .= '40'; // Destination account type CLABE = 40
                $line02 .= '00'; // Complete to 20 digits
                $line02 .= $payment->clabe ?? str_repeat('*', 18); // Receiver CLABE
                $line02 .= $this->padRight(strtoupper($payment->CardName ?? ''), 40); // Supplier name
                $line02 .= $this->padRight(strtoupper($payment->rfc ?? ''), 18); // RFC
                $line02 .= $this->padRight($payment->reference ?? '', 40); // Payment reference
                $line02 .= $this->padRight(strtoupper($company->CompnyName), 40); // Service holder
                $line02 .= '0'.str_pad('0', 15, '0', STR_PAD_LEFT); // IVA (always 0)
                $line02 .= $todayShort; // Movement reference DDMMYY
                $line02 .= ' '.$this->padRight($payment->reference ?? '', 40); // Payment reference again
                $line02 .= ':';

                $lines[] = $line02;
                $sequence++;
            }

            // Record 09: Footer
            $line09 = '09'; // Record type
            $line09 .= str_pad($sequence, 7, '0', STR_PAD_LEFT); // Line number
            $line09 .= str_pad($paymentCount, 7, '0', STR_PAD_LEFT); // Number of operations
            $line09 .= str_pad(str_replace('.', '', number_format($totalAmount, 2, '.', '')), 18, '0', STR_PAD_LEFT); // Total amount

            $lines[] = $line09;

            // Update payments as processed
            DB::connection('sap_sqlsrv')
                ->table('OVPM')
                ->where('Canceled', '<>', 'Y')
                ->where('DocType', 'S')
                ->where('U_ProcesadoBanco', 'NO Pagado')
                ->whereBetween('TrsfrDate', [$dateFrom, $dateTo])
                ->update(['U_ProcesadoBanco' => 'Pagado']);

            $content = implode("\r\n", $lines);
            $filename = 'AFIRME_'.$branch->name.'_'.now()->format('Ymd_His').'.txt';

            return Response::streamDownload(function () use ($content) {
                echo $content;
            }, $filename, [
                'Content-Type' => 'text/plain',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar archivo: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pad string to the right with spaces.
     */
    private function padRight(string $value, int $length): string
    {
        $value = substr($value, 0, $length);

        return str_pad($value, $length, ' ', STR_PAD_RIGHT);
    }
}
