<?php

namespace App\Http\Controllers;

use App\Enums\SettlementUploadStatus;
use App\Http\Requests\SettlementIngestRequest;
use App\Http\Requests\SettlementUploadRequest;
use App\Models\Acquirer;
use App\Models\Branch;
use App\Models\ExternalSettlement;
use App\Models\PaymentOrder;
use App\Models\SettlementUpload;
use App\Services\Acquirer\AcquirerMatcher;
use App\Services\Acquirer\SettlementIngestService;
use App\Services\Ai\SettlementLayoutAnalyzer;
use App\Services\GcorePaymentsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettlementIngestController extends Controller
{
    public function __construct(
        protected SettlementLayoutAnalyzer $analyzer,
        protected GcorePaymentsService $gcore,
        protected AcquirerMatcher $matcher,
        protected SettlementIngestService $ingest,
    ) {}

    /**
     * Render the acquirer settlement upload page.
     */
    public function index(): Response
    {
        $branchIds = auth()->user()->branches()->pluck('branches.id');

        return Inertia::render('treasury/settlement-upload', [
            'acquirers' => Acquirer::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'kind']),
            'branches' => auth()->user()->branches()->get(['branches.id', 'branches.name']),
            'uploads' => SettlementUpload::query()
                ->whereIn('branch_id', $branchIds)
                ->with(['acquirer:id,code,name', 'branch:id,name'])
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (SettlementUpload $upload): array => $this->uploadPayload($upload)),
        ]);
    }

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
     * Upload an acquirer settlement file and dedup-ingest its rows. Synchronous:
     * accumulates into external_settlements, skipping rows that already exist.
     */
    public function store(SettlementIngestRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('settlements/'.date('Y/m'), 'local');

        $upload = SettlementUpload::create([
            'acquirer_id' => $request->integer('acquirer_id'),
            'branch_id' => $request->integer('branch_id'),
            'user_id' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'status' => SettlementUploadStatus::Parsing,
        ]);

        try {
            $result = $this->ingest->ingestFromFile($upload, $file);
        } catch (\Throwable $e) {
            $upload->update(['status' => SettlementUploadStatus::Failed, 'error_log' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'No se pudo procesar el archivo: '.$e->getMessage(),
            ], 422);
        }

        $sample = $upload->externalSettlements()
            ->latest('id')
            ->limit(10)
            ->get(['transaction_date', 'amount', 'authorization', 'reference', 'card_type', 'status'])
            ->map(fn (ExternalSettlement $row): array => [
                'transaction_date' => $row->transaction_date?->format('Y-m-d'),
                'amount' => (float) $row->amount,
                'authorization' => $row->authorization,
                'reference' => $row->reference,
                'card_type' => $row->card_type,
                'status' => $row->status,
            ]);

        return response()->json([
            'success' => true,
            'message' => "Cargadas {$result->inserted} filas nuevas ({$result->duplicates} ya existían).",
            'upload' => $this->uploadPayload($upload->fresh(['acquirer', 'branch'])),
            'sample' => $sample,
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
            'acquirer' => $upload->acquirer?->code,
            'branch' => $upload->branch?->name,
            'original_name' => $upload->original_name,
            'status' => $upload->status->value,
            'status_label' => $upload->status->label(),
            'total_rows' => $upload->total_rows,
            'inserted_rows' => $upload->inserted_rows,
            'duplicate_rows' => $upload->duplicate_rows,
            'period_start' => $upload->period_start?->format('Y-m-d'),
            'period_end' => $upload->period_end?->format('Y-m-d'),
            'created_at' => $upload->created_at?->toIso8601String(),
            'error_log' => $upload->error_log,
        ];
    }
}
