import { FilterField, FiltersCard } from '@/components/page/filters-card';
import { PageHeader } from '@/components/page/page-header';
import { PageSection } from '@/components/page/page-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    Filter,
    Loader2,
    ReceiptText,
    Table2,
    Upload,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Panel', href: '/dashboard' },
    { title: 'Estados de Adquirente', href: '/treasury/settlements' },
];

const NONE = '__none__';

const FIELDS: { key: string; label: string; required?: boolean }[] = [
    { key: 'transaction_date', label: 'Fecha', required: true },
    { key: 'amount', label: 'Monto', required: true },
    { key: 'authorization', label: 'Autorización' },
    { key: 'reference', label: 'Referencia' },
    { key: 'transaction_time', label: 'Hora' },
    { key: 'card_type', label: 'Tipo de tarjeta' },
    { key: 'card_brand', label: 'Marca' },
    { key: 'terminal', label: 'Terminal' },
    { key: 'operation_type', label: 'Operación' },
    { key: 'status', label: 'Estatus' },
];

const COMBINED_DATETIME = 'es_datetime';

const DELIMITERS: { value: string; label: string }[] = [
    { value: ',', label: 'Coma  ,' },
    { value: ';', label: 'Punto y coma  ;' },
    { value: '\t', label: 'Tabulador' },
    { value: '|', label: 'Barra vertical  |' },
];

const DATE_FORMATS: { value: string; label: string }[] = [
    { value: 'DD/MM/YYYY', label: 'DD/MM/YYYY' },
    { value: 'DD/MM/YY', label: 'DD/MM/YY' },
    { value: 'YYYY-MM-DD', label: 'YYYY-MM-DD' },
    { value: COMBINED_DATETIME, label: 'Fecha y hora juntas (español)' },
];

interface AcquirerOption {
    id: number;
    code: string;
    name: string;
    kind: string;
}

interface BranchOption {
    id: number;
    name: string;
}

interface UploadRow {
    uuid: string;
    acquirer: string | null;
    branch: string | null;
    original_name: string;
    status: string;
    status_label: string;
    total_rows: number;
    inserted_rows: number;
    duplicate_rows: number;
    period_start: string | null;
    period_end: string | null;
    created_at: string | null;
    error_log: string | null;
}

interface HeadersResponse {
    success: boolean;
    rows: string[][];
    header_row: number;
    delimiter: string;
    headers: string[];
    suggested_mapping: Record<string, number | null>;
    suggested_format: string;
}

interface StoreResponse {
    success: boolean;
    message?: string;
    error?: string;
    upload?: UploadRow;
}

interface SkippedRow {
    line: number;
    reason: string;
    preview: string;
}

interface PreviewResponse {
    success: boolean;
    error?: string;
    total_rows: number;
    parsed_rows: number;
    skipped_rows: number;
    skipped: SkippedRow[];
    sample: Record<string, string | number | null>[];
}

interface Props {
    acquirers: AcquirerOption[];
    branches: BranchOption[];
    uploads: UploadRow[];
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function formatAmount(value: string | number | null | undefined): string {
    const n = typeof value === 'number' ? value : Number(value);
    if (!Number.isFinite(n)) return String(value ?? '');
    return n.toLocaleString('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function formatPeriod(from: string | null, to: string | null): string {
    if (!from || !to) return '—';
    return `${from} → ${to}`;
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('es-MX', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content || ''
    );
}

export default function SettlementUpload({
    acquirers,
    branches,
    uploads: initialUploads,
}: Props) {
    const fileInputRef = useRef<HTMLInputElement>(null);

    const [step, setStep] = useState<'upload' | 'mapping'>('upload');
    const [acquirerId, setAcquirerId] = useState<string>('');
    const [branchId, setBranchId] = useState<string>('');
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<StoreResponse | null>(null);
    const [uploads, setUploads] = useState<UploadRow[]>(initialUploads);

    // Mapping step state.
    const [matrix, setMatrix] = useState<string[][]>([]);
    const [headerRow, setHeaderRow] = useState(0);
    const [delimiter, setDelimiter] = useState('\t');
    const [mapping, setMapping] = useState<Record<string, number | null>>({});
    const [dateFormat, setDateFormat] = useState('DD/MM/YYYY');
    const [remember, setRemember] = useState(true);
    const [preview, setPreview] = useState<PreviewResponse | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const [showSkipped, setShowSkipped] = useState(false);

    const headers = useMemo(() => matrix[headerRow] ?? [], [matrix, headerRow]);
    const canRead =
        acquirerId !== '' && branchId !== '' && file !== null && !busy;
    const canUpload =
        mapping.transaction_date != null && mapping.amount != null && !busy;

    const buildParseConfig = useCallback(() => {
        const columns: Record<
            string,
            { index: number; header: string; format?: string }
        > = {};
        for (const { key } of FIELDS) {
            const idx = mapping[key];
            if (idx == null) continue;
            columns[key] = { index: idx, header: headers[idx] ?? '' };
            if (key === 'transaction_date') columns[key].format = dateFormat;
        }
        return { columns, header_lines_count: headerRow + 1, delimiter };
    }, [mapping, headers, dateFormat, headerRow, delimiter]);

    // Live preview: the server parses the file with the current mapping using the
    // very same code the real ingest runs, so what you see is what will be loaded.
    useEffect(() => {
        if (step !== 'mapping' || !file) return;

        const controller = new AbortController();
        const timer = setTimeout(async () => {
            if (mapping.transaction_date == null || mapping.amount == null) {
                setPreview(null);
                return;
            }
            setPreviewing(true);
            try {
                const fd = new FormData();
                fd.append('file', file);
                fd.append('parse_config', JSON.stringify(buildParseConfig()));
                const res = await fetch('/treasury/settlements/preview', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                    },
                    body: fd,
                    signal: controller.signal,
                });
                setPreview(await res.json());
            } catch {
                // Aborted by a newer keystroke, or network hiccup: keep the last preview.
            } finally {
                setPreviewing(false);
            }
        }, 400);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [
        step,
        file,
        mapping,
        dateFormat,
        headerRow,
        delimiter,
        buildParseConfig,
    ]);

    const handleClearFile = () => {
        setFile(null);
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    const handleReadHeaders = async (forceDelimiter?: string) => {
        if (!file || !acquirerId || !branchId) return;
        setBusy(true);
        setError(null);
        setResult(null);
        try {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('acquirer_id', acquirerId);
            if (forceDelimiter !== undefined)
                fd.append('delimiter', forceDelimiter);
            const res = await fetch('/treasury/settlements/headers', {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: fd,
            });
            const json: HeadersResponse = await res.json();
            if (res.ok && json.success) {
                setMatrix(json.rows);
                setHeaderRow(json.header_row);
                setDelimiter(json.delimiter);
                setMapping(json.suggested_mapping);
                setDateFormat(json.suggested_format || 'DD/MM/YYYY');
                setStep('mapping');
            } else {
                setError('No se pudieron leer las columnas del archivo.');
            }
        } catch {
            setError('Error de red al leer el archivo.');
        } finally {
            setBusy(false);
        }
    };

    const handleUpload = async () => {
        if (!file || !canUpload) return;
        setBusy(true);
        setError(null);
        try {
            const parseConfig = buildParseConfig();

            const fd = new FormData();
            fd.append('acquirer_id', acquirerId);
            fd.append('branch_id', branchId);
            fd.append('file', file);
            fd.append('parse_config', JSON.stringify(parseConfig));
            fd.append('remember', remember ? '1' : '0');

            const res = await fetch('/treasury/settlements', {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: fd,
            });
            const json: StoreResponse = await res.json();
            if (res.ok && json.success && json.upload) {
                setResult(json);
                setUploads((prev) => [json.upload!, ...prev]);
                setStep('upload');
                handleClearFile();
            } else {
                setError(json.error ?? 'No se pudo procesar el archivo.');
            }
        } catch {
            setError('Error de red al subir el archivo.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Estados de Adquirente" />
            <div className="space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={ReceiptText}
                    title="Cargar Estados de Adquirente"
                    description="Sube el Excel de cada pagador, mapea sus columnas y carga. Las filas se acumulan y las que ya existen no se vuelven a cargar."
                />

                {step === 'upload' ? (
                    <FiltersCard icon={Filter} columns={2}>
                        <FilterField label="Adquirente" htmlFor="acquirer">
                            <Select
                                value={acquirerId}
                                onValueChange={setAcquirerId}
                            >
                                <SelectTrigger id="acquirer">
                                    <SelectValue placeholder="Selecciona el adquirente" />
                                </SelectTrigger>
                                <SelectContent>
                                    {acquirers.map((a) => (
                                        <SelectItem
                                            key={a.id}
                                            value={String(a.id)}
                                        >
                                            {a.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FilterField>

                        <FilterField label="Sucursal" htmlFor="branch">
                            <Select
                                value={branchId}
                                onValueChange={setBranchId}
                            >
                                <SelectTrigger id="branch">
                                    <SelectValue placeholder="Selecciona una sucursal" />
                                </SelectTrigger>
                                <SelectContent>
                                    {branches.map((b) => (
                                        <SelectItem
                                            key={b.id}
                                            value={String(b.id)}
                                        >
                                            {b.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FilterField>

                        <div className="space-y-2 lg:col-span-2">
                            <input
                                ref={fileInputRef}
                                id="file"
                                type="file"
                                accept=".xlsx,.xls,.csv"
                                onChange={(e) =>
                                    setFile(e.target.files?.[0] ?? null)
                                }
                                disabled={!acquirerId || !branchId || busy}
                                className="sr-only"
                            />
                            <button
                                type="button"
                                onClick={() => fileInputRef.current?.click()}
                                disabled={!acquirerId || !branchId || busy}
                                className="flex h-10 w-full items-center justify-center gap-2 rounded-md border border-dashed border-input bg-background px-3 text-sm text-muted-foreground transition-colors hover:bg-muted/50 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <Upload className="h-4 w-4" />
                                <span className="truncate">
                                    {file
                                        ? `${file.name} · ${formatFileSize(file.size)}`
                                        : 'Seleccionar archivo Excel o CSV'}
                                </span>
                                {file && !busy ? (
                                    <span
                                        role="button"
                                        aria-label="Remover archivo"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            handleClearFile();
                                        }}
                                        className="ml-2 inline-flex items-center text-muted-foreground hover:text-foreground"
                                    >
                                        <X className="h-3.5 w-3.5" />
                                    </span>
                                ) : null}
                            </button>
                            <p className="text-xs text-muted-foreground">
                                Elige adquirente y sucursal, luego el archivo.
                                En el siguiente paso mapeas las columnas.
                            </p>
                            <div className="flex justify-end pt-1">
                                <Button
                                    onClick={handleReadHeaders}
                                    disabled={!canRead}
                                >
                                    {busy ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : (
                                        <Table2 className="h-4 w-4" />
                                    )}
                                    {busy ? 'Leyendo…' : 'Leer columnas'}
                                </Button>
                            </div>
                        </div>
                    </FiltersCard>
                ) : (
                    <PageSection
                        icon={Table2}
                        title="Mapear columnas"
                        description={`${file?.name ?? ''} · indica qué columna corresponde a cada campo.`}
                        action={
                            <Button
                                variant="outline"
                                onClick={() => setStep('upload')}
                                disabled={busy}
                            >
                                <ArrowLeft className="h-4 w-4" />
                                Atrás
                            </Button>
                        }
                    >
                        <div className="space-y-5">
                            {/* Preview */}
                            <div className="overflow-x-auto rounded-md border">
                                <Table className="[&_td]:px-3 [&_th]:px-3">
                                    <TableBody>
                                        {matrix
                                            .slice(0, headerRow + 6)
                                            .map((row, ri) => (
                                                <TableRow
                                                    key={ri}
                                                    onClick={() =>
                                                        setHeaderRow(ri)
                                                    }
                                                    title="Usar esta fila como encabezados"
                                                    className={cn(
                                                        'cursor-pointer',
                                                        ri === headerRow
                                                            ? 'bg-primary/10 font-medium'
                                                            : 'hover:bg-muted/50',
                                                    )}
                                                >
                                                    <TableCell className="w-10 py-2 text-xs text-muted-foreground">
                                                        {ri}
                                                    </TableCell>
                                                    {row.map((cell, ci) => (
                                                        <TableCell
                                                            key={ci}
                                                            className="max-w-[160px] truncate py-2 text-xs tabular-nums"
                                                        >
                                                            {cell}
                                                        </TableCell>
                                                    ))}
                                                </TableRow>
                                            ))}
                                    </TableBody>
                                </Table>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <FilterField label="Fila de encabezados">
                                    <Input
                                        type="number"
                                        min={0}
                                        max={Math.max(0, matrix.length - 1)}
                                        value={headerRow}
                                        onChange={(e) =>
                                            setHeaderRow(
                                                Math.max(
                                                    0,
                                                    Math.min(
                                                        matrix.length - 1,
                                                        Number(
                                                            e.target.value,
                                                        ) || 0,
                                                    ),
                                                ),
                                            )
                                        }
                                    />
                                </FilterField>
                                <FilterField label="Formato de fecha">
                                    <Select
                                        value={dateFormat}
                                        onValueChange={setDateFormat}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {DATE_FORMATS.map((f) => (
                                                <SelectItem
                                                    key={f.value}
                                                    value={f.value}
                                                >
                                                    {f.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </FilterField>
                                <FilterField label="Separador de columnas">
                                    <Select
                                        value={delimiter}
                                        onValueChange={(v) => {
                                            setDelimiter(v);
                                            void handleReadHeaders(v);
                                        }}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {DELIMITERS.map((d) => (
                                                <SelectItem
                                                    key={d.value}
                                                    value={d.value}
                                                >
                                                    {d.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </FilterField>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {FIELDS.map(({ key, label, required }) => {
                                    const timeFromDate =
                                        key === 'transaction_time' &&
                                        dateFormat === COMBINED_DATETIME;
                                    const isMapped =
                                        timeFromDate || mapping[key] != null;
                                    const parsed = preview?.success
                                        ? preview.sample[0]?.[key]
                                        : undefined;
                                    const parsedFailed =
                                        isMapped &&
                                        preview?.success &&
                                        (parsed === null ||
                                            parsed === undefined);

                                    return (
                                        <FilterField
                                            key={key}
                                            label={
                                                required ? `${label} *` : label
                                            }
                                        >
                                            <Select
                                                disabled={timeFromDate}
                                                value={
                                                    timeFromDate ||
                                                    mapping[key] == null
                                                        ? NONE
                                                        : String(mapping[key])
                                                }
                                                onValueChange={(v) =>
                                                    setMapping((prev) => ({
                                                        ...prev,
                                                        [key]:
                                                            v === NONE
                                                                ? null
                                                                : Number(v),
                                                    }))
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue
                                                        placeholder={
                                                            timeFromDate
                                                                ? 'Incluida en la fecha'
                                                                : '— ninguna —'
                                                        }
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value={NONE}>
                                                        — ninguna —
                                                    </SelectItem>
                                                    {headers.map((h, i) => (
                                                        <SelectItem
                                                            key={i}
                                                            value={String(i)}
                                                        >
                                                            {h ||
                                                                `Columna ${i + 1}`}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {isMapped && preview?.success ? (
                                                parsedFailed ? (
                                                    <p className="mt-1 text-xs text-rose-600 dark:text-rose-400">
                                                        No se pudo interpretar
                                                    </p>
                                                ) : (
                                                    <p className="mt-1 truncate text-xs text-emerald-600 tabular-nums dark:text-emerald-400">
                                                        {key === 'amount'
                                                            ? formatAmount(
                                                                  parsed,
                                                              )
                                                            : String(parsed)}
                                                    </p>
                                                )
                                            ) : null}
                                        </FilterField>
                                    );
                                })}
                            </div>

                            {previewing || preview ? (
                                <div className="rounded-md border bg-card p-3">
                                    {previewing && !preview ? (
                                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                            <Loader2 className="h-4 w-4 animate-spin" />{' '}
                                            Analizando el archivo…
                                        </p>
                                    ) : preview?.success ? (
                                        <>
                                            <p className="text-sm">
                                                Se leyeron{' '}
                                                <span className="font-medium tabular-nums">
                                                    {preview.total_rows}
                                                </span>{' '}
                                                filas ·{' '}
                                                <span className="font-medium text-emerald-600 tabular-nums dark:text-emerald-400">
                                                    {preview.parsed_rows} se
                                                    importarán
                                                </span>
                                                {preview.skipped_rows > 0 ? (
                                                    <>
                                                        {' '}
                                                        ·{' '}
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setShowSkipped(
                                                                    (s) => !s,
                                                                )
                                                            }
                                                            className="font-medium text-amber-600 tabular-nums underline underline-offset-2 dark:text-amber-400"
                                                        >
                                                            {
                                                                preview.skipped_rows
                                                            }{' '}
                                                            se omitirán
                                                        </button>
                                                    </>
                                                ) : null}
                                                {previewing ? (
                                                    <Loader2 className="ml-2 inline h-3 w-3 animate-spin" />
                                                ) : null}
                                            </p>
                                            {showSkipped &&
                                            preview.skipped.length > 0 ? (
                                                <div className="mt-2 max-h-40 space-y-1 overflow-y-auto">
                                                    {preview.skipped.map(
                                                        (s) => (
                                                            <p
                                                                key={s.line}
                                                                className="text-xs text-muted-foreground"
                                                            >
                                                                <span className="font-medium">
                                                                    Línea{' '}
                                                                    {s.line}:
                                                                </span>{' '}
                                                                {s.reason} —{' '}
                                                                <span className="opacity-70">
                                                                    {s.preview}
                                                                </span>
                                                            </p>
                                                        ),
                                                    )}
                                                </div>
                                            ) : null}
                                        </>
                                    ) : (
                                        <p className="text-sm text-rose-600 dark:text-rose-400">
                                            {preview?.error ??
                                                'No se pudo generar la vista previa.'}
                                        </p>
                                    )}
                                </div>
                            ) : null}

                            <div className="flex flex-col items-start gap-3 pt-1 sm:flex-row sm:items-center sm:justify-between">
                                <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <input
                                        type="checkbox"
                                        checked={remember}
                                        onChange={(e) =>
                                            setRemember(e.target.checked)
                                        }
                                        className="h-4 w-4 rounded border-input"
                                    />
                                    Recordar este mapeo para el adquirente
                                </label>
                                <Button
                                    onClick={handleUpload}
                                    disabled={
                                        !canUpload || preview?.parsed_rows === 0
                                    }
                                >
                                    {busy ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : (
                                        <Upload className="h-4 w-4" />
                                    )}
                                    {busy ? 'Cargando…' : 'Cargar'}
                                </Button>
                            </div>
                            {!canUpload &&
                            mapping.transaction_date != null &&
                            mapping.amount != null ? null : (
                                <p className="text-xs text-muted-foreground">
                                    Fecha y Monto son obligatorios.
                                </p>
                            )}
                        </div>
                    </PageSection>
                )}

                {error ? (
                    <p className="rounded-md bg-rose-500/10 p-4 text-sm text-rose-600 dark:text-rose-400">
                        {error}
                    </p>
                ) : null}

                {result?.upload ? (
                    <div className="flex items-start gap-3 rounded-md border border-emerald-500/30 bg-emerald-500/10 p-4">
                        <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <div className="text-sm">
                            <p className="font-medium text-foreground">
                                {result.message}
                            </p>
                            <p className="text-muted-foreground">
                                {result.upload.original_name} ·{' '}
                                {result.upload.total_rows} filas ·{' '}
                                <span className="font-medium text-emerald-600 dark:text-emerald-400">
                                    {result.upload.inserted_rows} nuevas
                                </span>{' '}
                                · {result.upload.duplicate_rows} ya existían ·
                                periodo{' '}
                                {formatPeriod(
                                    result.upload.period_start,
                                    result.upload.period_end,
                                )}
                            </p>
                        </div>
                    </div>
                ) : null}

                <PageSection
                    icon={ReceiptText}
                    title="Cargas recientes"
                    description="Acumulado de archivos cargados por adquirente y sucursal."
                >
                    {uploads.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            Aún no hay cargas. Sube el primer Excel.
                        </p>
                    ) : (
                        <div className="overflow-hidden rounded-md border">
                            <Table className="[&_td]:px-4 [&_th]:px-4">
                                <TableHeader className="bg-muted/50">
                                    <TableRow className="hover:bg-muted/50">
                                        <TableHead className="h-11 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Adquirente
                                        </TableHead>
                                        <TableHead className="h-11 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Sucursal
                                        </TableHead>
                                        <TableHead className="h-11 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Archivo
                                        </TableHead>
                                        <TableHead className="h-11 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Periodo
                                        </TableHead>
                                        <TableHead className="h-11 text-right text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Total
                                        </TableHead>
                                        <TableHead className="h-11 text-right text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Nuevas
                                        </TableHead>
                                        <TableHead className="h-11 text-right text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Duplicadas
                                        </TableHead>
                                        <TableHead className="h-11 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Fecha
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {uploads.map((u) => (
                                        <TableRow
                                            key={u.uuid}
                                            className="hover:bg-muted/50"
                                        >
                                            <TableCell className="py-3 font-medium">
                                                {u.acquirer ?? '—'}
                                            </TableCell>
                                            <TableCell className="py-3">
                                                {u.branch ?? '—'}
                                            </TableCell>
                                            <TableCell className="max-w-[220px] truncate py-3 text-muted-foreground">
                                                {u.original_name}
                                            </TableCell>
                                            <TableCell className="py-3 text-muted-foreground tabular-nums">
                                                {formatPeriod(
                                                    u.period_start,
                                                    u.period_end,
                                                )}
                                            </TableCell>
                                            <TableCell className="py-3 text-right tabular-nums">
                                                {u.total_rows}
                                            </TableCell>
                                            <TableCell className="py-3 text-right font-medium text-emerald-600 tabular-nums dark:text-emerald-400">
                                                {u.inserted_rows}
                                            </TableCell>
                                            <TableCell className="py-3 text-right text-muted-foreground tabular-nums">
                                                {u.duplicate_rows}
                                            </TableCell>
                                            <TableCell className="py-3 text-muted-foreground">
                                                {formatDateTime(u.created_at)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </PageSection>
            </div>
        </AppLayout>
    );
}
