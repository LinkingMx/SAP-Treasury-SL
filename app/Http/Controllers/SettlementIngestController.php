<?php

namespace App\Http\Controllers;

use App\Enums\SettlementUploadStatus;
use App\Http\Requests\SettlementHeadersRequest;
use App\Http\Requests\SettlementIngestRequest;
use App\Models\Acquirer;
use App\Models\ExternalSettlement;
use App\Models\SettlementUpload;
use App\Services\Acquirer\SettlementIngestService;
use App\Services\Acquirer\SettlementParser;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettlementIngestController extends Controller
{
    public function __construct(
        protected SettlementParser $parser,
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
     * Read the first rows of an uploaded file so the user can map the columns.
     * Suggests a mapping from the acquirer's saved column_map, then from aliases.
     */
    public function headers(SettlementHeadersRequest $request): JsonResponse
    {
        // An explicit delimiter means the user is correcting our guess.
        $read = $this->parser->readHeaders(
            $request->file('file'),
            delimiter: $request->filled('delimiter') ? (string) $request->input('delimiter') : null,
        );

        $savedMap = $request->filled('acquirer_id')
            ? Acquirer::find($request->integer('acquirer_id'))?->column_map
            : null;

        // Header row and delimiter are properties of THIS file (the same acquirer
        // ships xlsx and csv with different layouts), so detection wins. The saved
        // map still drives the column mapping, which is matched by header name.
        $headerRow = $read['rows'][$read['header_row']] ?? [];

        return response()->json([
            'success' => true,
            'rows' => $read['rows'],
            'header_row' => $read['header_row'],
            'delimiter' => $read['delimiter'],
            'headers' => $headerRow,
            'suggested_mapping' => $this->parser->suggestMapping($headerRow, $savedMap),
            'suggested_format' => $savedMap['columns']['transaction_date']['format'] ?? 'DD/MM/YYYY',
        ]);
    }

    /**
     * Parse the file with the user's current mapping WITHOUT saving anything, so
     * the UI can show the values it would actually import. Runs the same
     * parseWithDiagnostics() the real ingest uses, so the preview cannot lie.
     */
    public function preview(SettlementHeadersRequest $request): JsonResponse
    {
        $parseConfig = $request->input('parse_config');
        if (is_string($parseConfig)) {
            $parseConfig = json_decode($parseConfig, true);
        }

        if (! is_array($parseConfig)) {
            return response()->json(['success' => false, 'error' => 'Falta la configuración de columnas.'], 422);
        }

        try {
            $result = $this->parser->parseWithDiagnostics($request->file('file'), $parseConfig);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'total_rows' => $result['total_rows'],
            'parsed_rows' => count($result['rows']),
            'skipped_rows' => $result['skipped_rows'],
            'skipped' => $result['skipped'],
            'sample' => array_map(
                static fn (array $row): array => collect($row)->except('raw')->all(),
                array_slice($result['rows'], 0, 5),
            ),
        ]);
    }

    /**
     * Upload an acquirer settlement file and dedup-ingest its rows using the
     * user-supplied column mapping. Synchronous: accumulates into
     * external_settlements, skipping rows that already exist. No matching.
     */
    public function store(SettlementIngestRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $parseConfig = $request->input('parse_config');
        $path = $file->store('settlements/'.date('Y/m'), 'local');

        $upload = SettlementUpload::create([
            'acquirer_id' => $request->integer('acquirer_id'),
            'branch_id' => $request->integer('branch_id'),
            'user_id' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'parse_config' => $parseConfig,
            'status' => SettlementUploadStatus::Parsing,
        ]);

        try {
            $result = $this->ingest->ingestFromFile($upload, $file, $parseConfig);
        } catch (\Throwable $e) {
            $upload->update(['status' => SettlementUploadStatus::Failed, 'error_log' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'No se pudo procesar el archivo: '.$e->getMessage(),
            ], 422);
        }

        if ($request->boolean('remember')) {
            $this->rememberMapping($request->integer('acquirer_id'), $parseConfig);
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
     * Persist the column mapping (by header name) on the acquirer so the next
     * upload of the same acquirer pre-fills it.
     *
     * @param  array<string, mixed>  $parseConfig
     */
    protected function rememberMapping(int $acquirerId, array $parseConfig): void
    {
        $columns = [];
        foreach (($parseConfig['columns'] ?? []) as $field => $spec) {
            if (! is_array($spec) || ! isset($spec['header'])) {
                continue;
            }
            $columns[$field] = array_filter(
                ['header' => $spec['header'], 'format' => $spec['format'] ?? null],
                static fn ($value): bool => $value !== null,
            );
        }

        Acquirer::whereKey($acquirerId)->update([
            'column_map' => [
                'columns' => $columns,
                'delimiter' => $parseConfig['delimiter'] ?? null,
                'header_row' => isset($parseConfig['header_lines_count'])
                    ? max(0, (int) $parseConfig['header_lines_count'] - 1)
                    : null,
            ],
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
