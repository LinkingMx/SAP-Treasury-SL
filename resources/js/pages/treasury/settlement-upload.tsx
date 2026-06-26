import { FilterField, FiltersCard } from '@/components/page/filters-card';
import { PageHeader } from '@/components/page/page-header';
import { PageSection } from '@/components/page/page-section';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { CheckCircle2, Filter, Loader2, ReceiptText, Upload, X } from 'lucide-react';
import { useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Panel', href: '/dashboard' },
    { title: 'Estados de Adquirente', href: '/treasury/settlements' },
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

interface UploadResponse {
    success: boolean;
    message?: string;
    error?: string;
    upload?: UploadRow;
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

function formatPeriod(from: string | null, to: string | null): string {
    if (!from || !to) return '—';
    return `${from} → ${to}`;
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export default function SettlementUpload({ acquirers, branches, uploads: initialUploads }: Props) {
    const fileInputRef = useRef<HTMLInputElement>(null);

    const [acquirerId, setAcquirerId] = useState<string>('');
    const [branchId, setBranchId] = useState<string>('');
    const [file, setFile] = useState<File | null>(null);
    const [uploading, setUploading] = useState(false);
    const [result, setResult] = useState<UploadResponse | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [uploads, setUploads] = useState<UploadRow[]>(initialUploads);

    const canUpload = acquirerId !== '' && branchId !== '' && file !== null && !uploading;

    const handleClearFile = () => {
        setFile(null);
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    const handleUpload = async () => {
        if (!file || !acquirerId || !branchId) return;
        setUploading(true);
        setError(null);
        setResult(null);
        try {
            const formData = new FormData();
            formData.append('acquirer_id', acquirerId);
            formData.append('branch_id', branchId);
            formData.append('file', file);

            const res = await fetch('/treasury/settlements', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                },
                body: formData,
            });
            const json: UploadResponse = await res.json();

            if (res.ok && json.success && json.upload) {
                setResult(json);
                setUploads((prev) => [json.upload!, ...prev]);
                handleClearFile();
            } else {
                setError(json.error ?? 'No se pudo procesar el archivo.');
            }
        } catch {
            setError('Error de red al subir el archivo.');
        } finally {
            setUploading(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Estados de Adquirente" />
            <div className="space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={ReceiptText}
                    title="Cargar Estados de Adquirente"
                    description="Sube los Excel de cada pagador (Rappi, MIFEL, AFIRME, Uber Eats). Las filas se acumulan y las que ya existen no se vuelven a cargar."
                />

                <FiltersCard icon={Filter} columns={2}>
                    <FilterField label="Adquirente" htmlFor="acquirer">
                        <Select value={acquirerId} onValueChange={setAcquirerId}>
                            <SelectTrigger id="acquirer">
                                <SelectValue placeholder="Selecciona el adquirente" />
                            </SelectTrigger>
                            <SelectContent>
                                {acquirers.map((a) => (
                                    <SelectItem key={a.id} value={String(a.id)}>
                                        {a.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FilterField>

                    <FilterField label="Sucursal" htmlFor="branch">
                        <Select value={branchId} onValueChange={setBranchId}>
                            <SelectTrigger id="branch">
                                <SelectValue placeholder="Selecciona una sucursal" />
                            </SelectTrigger>
                            <SelectContent>
                                {branches.map((b) => (
                                    <SelectItem key={b.id} value={String(b.id)}>
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
                            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                            disabled={!acquirerId || !branchId || uploading}
                            className="sr-only"
                        />
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            disabled={!acquirerId || !branchId || uploading}
                            className="flex h-10 w-full items-center justify-center gap-2 rounded-md border border-dashed border-input bg-background px-3 text-sm text-muted-foreground transition-colors hover:bg-muted/50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <Upload className="h-4 w-4" />
                            <span className="truncate">
                                {file ? `${file.name} · ${formatFileSize(file.size)}` : 'Seleccionar archivo Excel o CSV'}
                            </span>
                            {file && !uploading ? (
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
                            La IA detecta las columnas automáticamente. Elige adquirente y sucursal antes de seleccionar el archivo.
                        </p>
                        <div className="flex justify-end pt-1">
                            <Button onClick={handleUpload} disabled={!canUpload}>
                                {uploading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4" />}
                                {uploading ? 'Procesando…' : 'Cargar'}
                            </Button>
                        </div>
                    </div>
                </FiltersCard>

                {error ? (
                    <p className="rounded-md bg-rose-500/10 p-4 text-sm text-rose-600 dark:text-rose-400">{error}</p>
                ) : null}

                {result?.upload ? (
                    <div className="flex items-start gap-3 rounded-md border border-emerald-500/30 bg-emerald-500/10 p-4">
                        <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        <div className="text-sm">
                            <p className="font-medium text-foreground">{result.message}</p>
                            <p className="text-muted-foreground">
                                {result.upload.original_name} · {result.upload.total_rows} filas ·{' '}
                                <span className="font-medium text-emerald-600 dark:text-emerald-400">{result.upload.inserted_rows} nuevas</span> ·{' '}
                                {result.upload.duplicate_rows} ya existían · periodo {formatPeriod(result.upload.period_start, result.upload.period_end)}
                            </p>
                        </div>
                    </div>
                ) : null}

                <PageSection icon={ReceiptText} title="Cargas recientes" description="Acumulado de archivos cargados por adquirente y sucursal.">
                    {uploads.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">Aún no hay cargas. Sube el primer Excel.</p>
                    ) : (
                        <div className="overflow-hidden rounded-md border">
                            <Table className="[&_td]:px-4 [&_th]:px-4">
                                <TableHeader className="bg-muted/50">
                                    <TableRow className="hover:bg-muted/50">
                                        <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Adquirente</TableHead>
                                        <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Sucursal</TableHead>
                                        <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Archivo</TableHead>
                                        <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Periodo</TableHead>
                                        <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total</TableHead>
                                        <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Nuevas</TableHead>
                                        <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Duplicadas</TableHead>
                                        <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Fecha</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {uploads.map((u) => (
                                        <TableRow key={u.uuid} className="hover:bg-muted/50">
                                            <TableCell className="py-3 font-medium">{u.acquirer ?? '—'}</TableCell>
                                            <TableCell className="py-3">{u.branch ?? '—'}</TableCell>
                                            <TableCell className="max-w-[220px] truncate py-3 text-muted-foreground">{u.original_name}</TableCell>
                                            <TableCell className="py-3 tabular-nums text-muted-foreground">{formatPeriod(u.period_start, u.period_end)}</TableCell>
                                            <TableCell className="py-3 text-right tabular-nums">{u.total_rows}</TableCell>
                                            <TableCell className="py-3 text-right tabular-nums font-medium text-emerald-600 dark:text-emerald-400">{u.inserted_rows}</TableCell>
                                            <TableCell className="py-3 text-right tabular-nums text-muted-foreground">{u.duplicate_rows}</TableCell>
                                            <TableCell className="py-3 text-muted-foreground">{formatDateTime(u.created_at)}</TableCell>
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
