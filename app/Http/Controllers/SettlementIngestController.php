<?php

namespace App\Http\Controllers;

use App\Enums\SettlementUploadStatus;
use App\Http\Requests\SettlementUploadRequest;
use App\Jobs\ProcessSettlementUpload;
use App\Models\Acquirer;
use App\Models\Branch;
use App\Models\PaymentOrder;
use App\Models\SettlementUpload;
use App\Services\Acquirer\AcquirerMatcher;
use App\Services\Ai\SettlementLayoutAnalyzer;
use App\Services\GcorePaymentsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettlementIngestController extends Controller
{
    public function __construct(
        protected SettlementLayoutAnalyzer $analyzer,
        protected GcorePaymentsService $gcore,
        protected AcquirerMatcher $matcher,
    ) {}

    /**
     * Detect the column layout of an uploaded settlement file (no persistence).
     */
    public function analyze(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:40960'],
        ]);

        $analysis = $this->analyzer->analyze($request->file('file'));

        return response()->json([
            'success' => true,
            'parse_config' => $analysis['parse_config'],
            'acquirer_guess' => $analysis['acquirer_guess'],
            'fingerprint' => $analysis['fingerprint'],
        ]);
    }

    /**
     * Dry-run reconciliation: parse the file, pull payments, and show the
     * proposed matches without writing anything.
     */
    public function preview(SettlementUploadRequest $request): JsonResponse
    {
        $acquirer = Acquirer::findOrFail($request->integer('acquirer_id'));
        $branch = Branch::findOrFail($request->integer('branch_id'));

        if (empty($branch->payment_branch)) {
            return response()->json([
                'success' => false,
                'error' => "La sucursal «{$branch->name}» no tiene configurado el campo «Sucursal en API de Pagos» (payment_branch).",
            ], 422);
        }

        $analysis = $this->analyzer->analyze($request->file('file'));
        $rows = $this->analyzer->parseRows($request->file('file'), $analysis['parse_config']);

        $payments = $this->gcore->allParrotOrderPayments(
            $branch->payment_branch,
            $request->date('period_start')->format('Y-m-d'),
            $request->date('period_end')->format('Y-m-d'),
            ['status' => 'CHARGED'],
        );

        if (! $payments['success']) {
            return response()->json([
                'success' => false,
                'error' => $payments['error'],
            ], 502);
        }

        $excluded = PaymentOrder::query()
            ->where('branch_id', $branch->id)
            ->pluck('parrot_payment_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $results = $this->matcher->match($rows, $payments['data'], $acquirer->matchRule(), $excluded);

        $matched = array_filter($results, fn ($r): bool => $r->matched());
        $proposed = array_map(function ($result) use ($rows) {
            $row = $rows[$result->rowIndex];

            return [
                'row_index' => $result->rowIndex,
                'transaction_date' => $row['transaction_date'],
                'amount' => $row['amount'],
                'authorization' => $row['authorization'] ?? null,
                'matched' => $result->matched(),
                'parrot_payment_id' => $result->parrotPaymentId,
                'payment_total' => $result->paymentTotal,
                'match_diff' => $result->diff,
                'match_method' => $result->method,
            ];
        }, $results);

        return response()->json([
            'success' => true,
            'acquirer_guess' => $analysis['acquirer_guess'],
            'summary' => [
                'total_rows' => count($rows),
                'matched_rows' => count($matched),
                'unmatched_rows' => count($rows) - count($matched),
                'payments_fetched' => count($payments['data']),
            ],
            'proposed' => $proposed,
        ]);
    }

    /**
     * Persist the upload and queue reconciliation.
     */
    public function store(SettlementUploadRequest $request): JsonResponse
    {
        $path = $request->file('file')->store('settlements/'.date('Y/m'), 'local');

        $upload = SettlementUpload::create([
            'acquirer_id' => $request->integer('acquirer_id'),
            'branch_id' => $request->integer('branch_id'),
            'user_id' => $request->user()->id,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'stored_path' => $path,
            'period_start' => $request->date('period_start'),
            'period_end' => $request->date('period_end'),
            'status' => SettlementUploadStatus::Pending,
        ]);

        ProcessSettlementUpload::dispatch($upload->id);

        return response()->json([
            'success' => true,
            'message' => 'Carga recibida. La conciliación se está procesando.',
            'upload' => $this->uploadPayload($upload),
        ]);
    }

    /**
     * Status + counters for an upload (for polling).
     */
    public function show(SettlementUpload $upload): JsonResponse
    {
        abort_unless(
            $upload->user()->whereKey(request()->user()->id)->exists()
                || request()->user()->branches()->whereKey($upload->branch_id)->exists(),
            403,
        );

        return response()->json([
            'success' => true,
            'upload' => $this->uploadPayload($upload),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function uploadPayload(SettlementUpload $upload): array
    {
        return [
            'uuid' => $upload->uuid,
            'status' => $upload->status->value,
            'status_label' => $upload->status->label(),
            'total_rows' => $upload->total_rows,
            'matched_rows' => $upload->matched_rows,
            'unmatched_rows' => $upload->unmatched_rows,
            'error_log' => $upload->error_log,
        ];
    }
}
