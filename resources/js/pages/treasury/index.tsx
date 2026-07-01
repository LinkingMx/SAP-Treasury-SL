import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { InfoCell, StatCard } from '@/components/page/detail-bits';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { BatchStatusBadge } from '@/components/page/batch-status-badge';
import { ColumnVisibilityMenu } from '@/components/page/column-visibility-menu';
import { FilterField, FiltersCard } from '@/components/page/filters-card';
import { InfoWidget } from '@/components/page/info-widget';
import { PageHeader } from '@/components/page/page-header';
import { PageSection } from '@/components/page/page-section';
import { RowNumberBadge } from '@/components/page/row-number-badge';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { treasury } from '@/routes';
import { downloadTemplate } from '@/actions/App/Http/Controllers/BatchController';
import AiIngest from '@/components/treasury/AiIngest';
import {
    type Bank,
    type BankAccount,
    type Batch,
    type BatchDetail,
    type BatchResult,
    type Branch,
    type BreadcrumbItem,
    type ImportError,
    type PaginatedResponse,
} from '@/types';
import { Head } from '@inertiajs/react';
import {
    AlertCircle,
    Bot,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Copy,
    Download,
    Eye,
    ClipboardList,
    FileSpreadsheet,
    Filter,
    Landmark,
    ListChecks,
    Loader2,
    Play,
    RefreshCw,
    Trash2,
    Upload,
    X,
} from 'lucide-react';

const QUICK_STEP_DETAILS: { id: string; title: string; description: string }[] = [
    {
        id: 'upload',
        title: 'Carga del archivo',
        description:
            'Subes el Excel/CSV del extracto bancario. Se valida tamaño y extensión antes de procesar.',
    },
    {
        id: 'parse',
        title: 'Lectura de filas',
        description:
            'Cada fila se convierte a una transacción normalizada (fecha + descripción + cargo/abono).',
    },
    {
        id: 'persist',
        title: 'Creación del lote',
        description:
            'Las transacciones se guardan como lote en estado Pendiente, listo para procesar.',
    },
    {
        id: 'process',
        title: 'Procesamiento a SAP',
        description:
            'Desde la tabla, el botón Play (▶) envía las transacciones al Service Layer de SAP B1.',
    },
];

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'AC Tesorería',
        href: treasury().url,
    },
];

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
    banks: Bank[];
}

type UploadStatus = 'idle' | 'validating' | 'processing' | 'success' | 'error';

export default function Tesoreria({ branches, bankAccounts, banks }: Props) {
    const [activeTab, setActiveTab] = useState<string>('quick');
    const [selectedBranch, setSelectedBranch] = useState<string>('');
    const [selectedBankAccount, setSelectedBankAccount] = useState<string>('');
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [isUploading, setIsUploading] = useState(false);
    const [uploadStatus, setUploadStatus] = useState<UploadStatus>('idle');
    const [uploadProgress, setUploadProgress] = useState(0);
    const [errors, setErrors] = useState<ImportError[]>([]);
    const [successResult, setSuccessResult] = useState<BatchResult | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    // Batches state
    const [batches, setBatches] = useState<Batch[]>([]);
    const [batchesLoading, setBatchesLoading] = useState(false);
    const [batchesPagination, setBatchesPagination] = useState<{
        currentPage: number;
        lastPage: number;
        total: number;
    }>({ currentPage: 1, lastPage: 1, total: 0 });
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [batchToDelete, setBatchToDelete] = useState<Batch | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    // Batch detail modal state
    const [batchDetailOpen, setBatchDetailOpen] = useState(false);
    const [batchDetail, setBatchDetail] = useState<BatchDetail | null>(null);
    const [batchDetailLoading, setBatchDetailLoading] = useState(false);

    // SAP processing state
    const [processingBatchId, setProcessingBatchId] = useState<number | null>(null);
    const [reprocessingTransactionId, setReprocessingTransactionId] = useState<number | null>(null);

    const filteredBankAccounts = useMemo(() => {
        if (!selectedBranch) return [];
        return bankAccounts.filter((account) => account.branch_id === Number(selectedBranch));
    }, [selectedBranch, bankAccounts]);

    const fetchBatches = useCallback(
        async (page = 1) => {
            if (!selectedBranch || !selectedBankAccount) {
                setBatches([]);
                setBatchesPagination({ currentPage: 1, lastPage: 1, total: 0 });
                return;
            }

            setBatchesLoading(true);
            try {
                const params = new URLSearchParams({
                    branch_id: selectedBranch,
                    bank_account_id: selectedBankAccount,
                    page: String(page),
                });

                const response = await fetch(`/treasury/batches?${params}`, {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN':
                            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                                ?.content || '',
                    },
                });

                if (!response.ok) {
                    throw new Error('Error al cargar los lotes');
                }

                const data: PaginatedResponse<Batch> = await response.json();
                setBatches(data.data);
                setBatchesPagination({
                    currentPage: data.current_page,
                    lastPage: data.last_page,
                    total: data.total,
                });
            } catch (error) {
                console.error('Error fetching batches:', error);
                setBatches([]);
            } finally {
                setBatchesLoading(false);
            }
        },
        [selectedBranch, selectedBankAccount]
    );

    useEffect(() => {
        fetchBatches(1);
    }, [fetchBatches]);

    const handleDeleteBatch = async () => {
        if (!batchToDelete) return;

        setIsDeleting(true);
        try {
            const response = await fetch(`/treasury/batches/${batchToDelete.id}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ||
                        '',
                },
            });

            if (!response.ok) {
                throw new Error('Error al eliminar el lote');
            }

            await fetchBatches(batchesPagination.currentPage);
            setDeleteDialogOpen(false);
            setBatchToDelete(null);
        } catch (error) {
            console.error('Error deleting batch:', error);
        } finally {
            setIsDeleting(false);
        }
    };

    const fetchBatchDetail = async (batch: Batch) => {
        setBatchDetailLoading(true);
        setBatchDetailOpen(true);
        setBatchDetail(null);
        try {
            const response = await fetch(`/treasury/batches/${batch.id}`, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Error al cargar el detalle del lote');
            }

            const data: BatchDetail = await response.json();
            setBatchDetail(data);
        } catch (error) {
            console.error('Error fetching batch detail:', error);
        } finally {
            setBatchDetailLoading(false);
        }
    };

    const handleProcessToSap = async (batch: Batch) => {
        if (batch.status === 'processing' || batch.status === 'completed') {
            return;
        }

        setProcessingBatchId(batch.id);
        try {
            const response = await fetch(`/treasury/batches/${batch.id}/process-sap`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ||
                        '',
                },
            });

            if (!response.ok) {
                const data = await response.json();
                console.error('Error processing to SAP:', data.message);
                return;
            }

            // Refresh batches to show updated status
            await fetchBatches(batchesPagination.currentPage);
        } catch (error) {
            console.error('Error processing to SAP:', error);
        } finally {
            setProcessingBatchId(null);
        }
    };

    const handleReprocessTransaction = async (transactionId: number) => {
        if (!batchDetail) return;

        setReprocessingTransactionId(transactionId);
        try {
            const response = await fetch(
                `/treasury/batches/${batchDetail.id}/transactions/${transactionId}/reprocess`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN':
                            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ||
                            '',
                    },
                }
            );

            const data = await response.json();

            if (response.ok) {
                // Update transaction in batchDetail
                setBatchDetail((prev) => {
                    if (!prev) return prev;
                    return {
                        ...prev,
                        status: data.batch_status,
                        status_label: data.batch_status_label,
                        error_message: data.batch_status === 'completed' ? null : prev.error_message,
                        transactions: prev.transactions.map((t) =>
                            t.id === transactionId ? data.transaction : t
                        ),
                    };
                });

                // Refresh batches list to reflect status changes
                await fetchBatches(batchesPagination.currentPage);
            } else {
                console.error('Error reprocessing transaction:', data.message);
            }
        } catch (error) {
            console.error('Error reprocessing transaction:', error);
        } finally {
            setReprocessingTransactionId(null);
        }
    };

    const formatCurrency = (value: string): string => {
        return Number(value).toLocaleString('es-MX', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    };

    const formatDate = (dateString: string): string => {
        return new Date(dateString).toLocaleString('es-MX', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const formatDateShort = (dateString: string): string => {
        return new Date(dateString).toLocaleDateString('es-MX', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const [columnVisibility, setColumnVisibility] = useState<Record<string, boolean>>({
        archivo: false,
        fecha: true,
        estado: true,
        registros: true,
        debito: true,
        credito: true,
    });

    const hasProcessingBatches = useMemo(
        () => batches.some((batch) => batch.status === 'processing'),
        [batches],
    );

    const handleBranchChange = (value: string) => {
        setSelectedBranch(value);
        setSelectedBankAccount('');
    };

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setSelectedFile(file);
            setErrors([]);
            setSuccessResult(null);
            setUploadStatus('idle');
            setUploadProgress(0);
        }
    };

    const handleRemoveFile = () => {
        setSelectedFile(null);
        setErrors([]);
        setSuccessResult(null);
        setUploadStatus('idle');
        setUploadProgress(0);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const formatFileSize = (bytes: number): string => {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    };

    const handleUpload = async () => {
        if (!selectedBranch || !selectedBankAccount || !selectedFile) return;

        setIsUploading(true);
        setErrors([]);
        setSuccessResult(null);
        setUploadStatus('validating');
        setUploadProgress(20);

        const formData = new FormData();
        formData.append('branch_id', selectedBranch);
        formData.append('bank_account_id', selectedBankAccount);
        formData.append('file', selectedFile);

        try {
            // Simular progreso de validación
            await new Promise((resolve) => setTimeout(resolve, 300));
            setUploadProgress(40);
            setUploadStatus('processing');

            const response = await fetch('/treasury/batches', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                            ?.content || '',
                    Accept: 'application/json',
                },
            });

            setUploadProgress(80);

            // Handle non-JSON responses (like HTML error pages)
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text.substring(0, 500));
                throw new Error(`El servidor respondió con un error (${response.status})`);
            }

            const data = await response.json();
            setUploadProgress(100);

            if (response.ok && data.success) {
                setUploadStatus('success');
                setSuccessResult(data.batch);
                setSelectedFile(null);
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
                // Refresh batches list after successful upload
                fetchBatches(1);
            } else {
                setUploadStatus('error');
                // Handle both validation errors and general errors
                if (data.errors && Array.isArray(data.errors)) {
                    setErrors(data.errors);
                } else if (data.message) {
                    setErrors([{ row: 0, error: data.message }]);
                } else {
                    setErrors([{ row: 0, error: 'Error desconocido al procesar el archivo' }]);
                }
            }
        } catch (err) {
            setUploadStatus('error');
            const errorMessage = err instanceof Error ? err.message : 'Error de conexión';
            console.error('Upload error:', err);
            setErrors([{ row: 0, error: errorMessage }]);
        } finally {
            setIsUploading(false);
        }
    };

    const handleDownloadErrors = () => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/treasury/batches/error-log';

        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_token';
        csrfInput.value =
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
        form.appendChild(csrfInput);

        errors.forEach((error, index) => {
            const rowInput = document.createElement('input');
            rowInput.type = 'hidden';
            rowInput.name = `errors[${index}][row]`;
            rowInput.value = String(error.row);
            form.appendChild(rowInput);

            const errorInput = document.createElement('input');
            errorInput.type = 'hidden';
            errorInput.name = `errors[${index}][error]`;
            errorInput.value = error.error;
            form.appendChild(errorInput);
        });

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    };

    const canUpload = selectedBranch && selectedBankAccount && selectedFile && !isUploading;

    const getStatusMessage = (): string => {
        switch (uploadStatus) {
            case 'validating':
                return 'Validando archivo...';
            case 'processing':
                return 'Procesando transacciones...';
            case 'success':
                return 'Completado';
            case 'error':
                return 'Error en el proceso';
            default:
                return '';
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="AC Tesorería" />
            <div className="space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={Landmark}
                    title="AC Tesorería"
                    description="Carga de extractos bancarios y envío a SAP — manual o asistido por IA."
                />
                <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
                    <TabsList className="grid w-full grid-cols-2 max-w-md">
                        <TabsTrigger value="quick" className="flex items-center gap-2">
                            <FileSpreadsheet className="h-4 w-4" />
                            Carga Rapida
                        </TabsTrigger>
                        <TabsTrigger value="ai" className="flex items-center gap-2">
                            <Bot className="h-4 w-4" />
                            Carga con IA
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="ai" className="mt-4">
                        <AiIngest
                            branches={branches}
                            bankAccounts={bankAccounts}
                            banks={banks}
                            onBatchSaved={() => {
                                setActiveTab('quick');
                                fetchBatches(1);
                            }}
                        />
                    </TabsContent>

                    <TabsContent value="quick" className="mt-4 space-y-4">
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <FiltersCard icon={Filter} columns={2} className="lg:col-span-2">
                                <FilterField label="Sucursal" htmlFor="branch">
                                    <Select value={selectedBranch} onValueChange={handleBranchChange}>
                                        <SelectTrigger id="branch">
                                            <SelectValue placeholder="Selecciona una sucursal" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {branches.map((branch) => (
                                                <SelectItem key={branch.id} value={String(branch.id)}>
                                                    {branch.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </FilterField>
                                <FilterField label="Cuenta Bancaria" htmlFor="bankAccount">
                                    <Select
                                        value={selectedBankAccount}
                                        onValueChange={setSelectedBankAccount}
                                        disabled={!selectedBranch}
                                    >
                                        <SelectTrigger id="bankAccount">
                                            <SelectValue placeholder="Selecciona una cuenta" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {filteredBankAccounts.map((account) => (
                                                <SelectItem key={account.id} value={String(account.id)}>
                                                    {account.name} - {account.account}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </FilterField>

                                <div className="space-y-2 md:col-span-2">
                                    <div className="flex items-center justify-between">
                                        <Label
                                            htmlFor="file"
                                            className="text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                                        >
                                            Archivo del Banco
                                        </Label>
                                        <a
                                            href={downloadTemplate.url()}
                                            className="inline-flex items-center gap-1.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
                                        >
                                            <Download className="h-3 w-3" />
                                            Descargar plantilla
                                        </a>
                                    </div>
                                    <input
                                        ref={fileInputRef}
                                        id="file"
                                        type="file"
                                        accept=".xlsx,.xls"
                                        onChange={handleFileChange}
                                        disabled={!selectedBankAccount || isUploading}
                                        className="sr-only"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => fileInputRef.current?.click()}
                                        disabled={!selectedBankAccount || isUploading}
                                        className="flex h-10 w-full items-center justify-center gap-2 rounded-md border border-dashed border-input bg-background px-3 text-sm text-muted-foreground transition-colors hover:bg-muted/50 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <Upload className="h-4 w-4" />
                                        <span className="truncate">
                                            {selectedFile
                                                ? `${selectedFile.name} · ${formatFileSize(selectedFile.size)}`
                                                : 'Seleccionar archivo Excel'}
                                        </span>
                                        {selectedFile && !isUploading ? (
                                            <span
                                                role="button"
                                                aria-label="Remover archivo"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    handleRemoveFile();
                                                }}
                                                className="ml-2 inline-flex items-center text-muted-foreground hover:text-foreground"
                                            >
                                                <X className="h-3.5 w-3.5" />
                                            </span>
                                        ) : null}
                                    </button>
                                    <p className="text-xs text-muted-foreground">
                                        Formatos soportados: Excel (.xlsx, .xls).
                                    </p>
                                    <div className="flex justify-end pt-1">
                                        <Button onClick={handleUpload} disabled={!canUpload}>
                                            {isUploading ? (
                                                <>
                                                    <Loader2 className="h-4 w-4 animate-spin" />
                                                    Procesando...
                                                </>
                                            ) : (
                                                <>
                                                    <Upload className="h-4 w-4" />
                                                    Cargar Excel
                                                </>
                                            )}
                                        </Button>
                                    </div>
                                </div>
                            </FiltersCard>

                            <InfoWidget
                                title="Cómo funciona"
                                icon={ListChecks}
                                footer="El lote queda Pendiente hasta procesarlo manualmente."
                            >
                                <Accordion type="single" collapsible className="w-full">
                                    {QUICK_STEP_DETAILS.map((step, i) => (
                                        <AccordionItem key={step.id} value={step.id}>
                                            <AccordionTrigger className="py-2.5 hover:no-underline">
                                                <div className="flex items-center gap-2.5">
                                                    <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold tabular-nums text-muted-foreground">
                                                        {i + 1}
                                                    </span>
                                                    <span className="text-sm font-medium">{step.title}</span>
                                                </div>
                                            </AccordionTrigger>
                                            <AccordionContent className="pl-7 text-xs text-muted-foreground">
                                                {step.description}
                                            </AccordionContent>
                                        </AccordionItem>
                                    ))}
                                </Accordion>
                            </InfoWidget>
                        </div>

                        {isUploading && (
                            <div className="space-y-2">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">
                                        {getStatusMessage()}
                                    </span>
                                    <span className="font-medium">{uploadProgress}%</span>
                                </div>
                                <Progress value={uploadProgress} className="h-2" />
                            </div>
                        )}

                        {successResult && (
                            <Alert variant="success">
                                <CheckCircle2 className="h-4 w-4" />
                                <AlertTitle>Archivo procesado exitosamente</AlertTitle>
                                <AlertDescription>
                                    <div className="mt-2 space-y-1 text-sm">
                                        <p>
                                            <span className="opacity-70">Lote:</span>{' '}
                                            <span className="font-medium">{successResult.uuid}</span>
                                        </p>
                                        <p>
                                            <span className="opacity-70">Registros:</span>{' '}
                                            <span className="font-medium">{successResult.total_records}</span>
                                        </p>
                                        <p>
                                            <span className="opacity-70">Total Débito:</span>{' '}
                                            <span className="font-medium">
                                                ${Number(successResult.total_debit).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                                            </span>
                                        </p>
                                        <p>
                                            <span className="opacity-70">Total Crédito:</span>{' '}
                                            <span className="font-medium">
                                                ${Number(successResult.total_credit).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                                            </span>
                                        </p>
                                        <p>
                                            <span className="opacity-70">Procesado:</span>{' '}
                                            <span className="font-medium">{successResult.processed_at}</span>
                                        </p>
                                    </div>
                                </AlertDescription>
                            </Alert>
                        )}

                        {errors.length > 0 && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertTitle>
                                    El archivo contiene {errors.length} error
                                    {errors.length > 1 ? 'es' : ''} y no fue procesado
                                </AlertTitle>
                                <AlertDescription>
                                    <div className="mt-2 max-h-48 space-y-1 overflow-y-auto">
                                        {errors.slice(0, 10).map((error, index) => (
                                            <p key={index} className="text-sm">
                                                {error.row > 0 && (
                                                    <strong>Fila {error.row}:</strong>
                                                )}{' '}
                                                {error.error}
                                            </p>
                                        ))}
                                        {errors.length > 10 && (
                                            <p className="text-sm italic">
                                                ... y {errors.length - 10} errores más
                                            </p>
                                        )}
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="mt-3"
                                        onClick={handleDownloadErrors}
                                    >
                                        <Download className="mr-2 h-4 w-4" />
                                        Descargar log de errores
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        )}

                        <PageSection
                            icon={ClipboardList}
                            title="Lotes de Transacción"
                            description="Historial de lotes procesados para la sucursal y cuenta seleccionadas."
                            action={
                                <div className="flex items-center gap-2">
                                    {hasProcessingBatches && (
                                        <RefreshCw className="h-4 w-4 animate-spin text-muted-foreground" />
                                    )}
                                    <ColumnVisibilityMenu
                                        columns={[
                                            { key: 'archivo', label: 'Archivo' },
                                            { key: 'fecha', label: 'Fecha' },
                                            { key: 'estado', label: 'Estado' },
                                            { key: 'registros', label: 'Registros' },
                                            { key: 'debito', label: 'Total Débito' },
                                            { key: 'credito', label: 'Total Crédito' },
                                        ]}
                                        visibility={columnVisibility}
                                        onChange={(k, v) =>
                                            setColumnVisibility((s) => ({ ...s, [k]: v }))
                                        }
                                    />
                                </div>
                            }
                        >
                        {!selectedBranch || !selectedBankAccount ? (
                            <div className="flex items-center justify-center py-8 text-muted-foreground">
                                <p>Selecciona sucursal y cuenta para ver lotes</p>
                            </div>
                        ) : batchesLoading ? (
                            <div className="flex items-center justify-center py-8">
                                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                            </div>
                        ) : batches.length === 0 ? (
                            <div className="flex items-center justify-center py-8 text-muted-foreground">
                                <p>No hay lotes procesados para esta selección</p>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <div className="overflow-hidden rounded-md border">
                                <Table className="[&_td]:px-4 [&_th]:px-4">
                                    <TableHeader className="bg-muted/50">
                                        <TableRow className="hover:bg-muted/50">
                                            <TableHead className="h-11 w-20 text-xs font-semibold uppercase tracking-wide text-muted-foreground">#</TableHead>
                                            {columnVisibility.archivo && (
                                                <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Archivo</TableHead>
                                            )}
                                            {columnVisibility.fecha && (
                                                <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Fecha</TableHead>
                                            )}
                                            {columnVisibility.estado && (
                                                <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Estado</TableHead>
                                            )}
                                            {columnVisibility.registros && (
                                                <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Registros</TableHead>
                                            )}
                                            {columnVisibility.debito && (
                                                <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total Débito</TableHead>
                                            )}
                                            {columnVisibility.credito && (
                                                <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total Crédito</TableHead>
                                            )}
                                            <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Acciones</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {batches.map((batch) => (
                                            <TableRow key={batch.id}>
                                                <TableCell className="py-3">
                                                    <RowNumberBadge id={batch.id} />
                                                </TableCell>
                                                {columnVisibility.archivo && (
                                                    <TableCell className="max-w-[200px] truncate py-3">
                                                        {batch.filename}
                                                    </TableCell>
                                                )}
                                                {columnVisibility.fecha && (
                                                    <TableCell className="py-3">
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <span className="cursor-default">
                                                                    {formatDateShort(batch.created_at)}
                                                                </span>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                {formatDate(batch.created_at)}
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TableCell>
                                                )}
                                                {columnVisibility.estado && (
                                                    <TableCell className="py-3">
                                                        <BatchStatusBadge status={batch.status} />
                                                    </TableCell>
                                                )}
                                                {columnVisibility.registros && (
                                                    <TableCell className="py-3 text-right tabular-nums">
                                                        {batch.total_records}
                                                    </TableCell>
                                                )}
                                                {columnVisibility.debito && (
                                                    <TableCell className="py-3 text-right tabular-nums">
                                                        ${formatCurrency(batch.total_debit)}
                                                    </TableCell>
                                                )}
                                                {columnVisibility.credito && (
                                                    <TableCell className="py-3 text-right tabular-nums">
                                                        ${formatCurrency(batch.total_credit)}
                                                    </TableCell>
                                                )}
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-2">
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-8 w-8"
                                                                    onClick={() => fetchBatchDetail(batch)}
                                                                >
                                                                    <Eye className="h-4 w-4" />
                                                                </Button>
                                                            </TooltipTrigger>
                                                            <TooltipContent>Ver detalle</TooltipContent>
                                                        </Tooltip>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-8 w-8"
                                                                    onClick={() => handleProcessToSap(batch)}
                                                                    disabled={
                                                                        batch.status === 'processing' ||
                                                                        batch.status === 'completed' ||
                                                                        processingBatchId === batch.id
                                                                    }
                                                                >
                                                                    {processingBatchId === batch.id ? (
                                                                        <Loader2 className="h-4 w-4 animate-spin" />
                                                                    ) : (
                                                                        <Play className="h-4 w-4" />
                                                                    )}
                                                                </Button>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                {batch.status === 'completed'
                                                                    ? 'Ya procesado'
                                                                    : batch.status === 'processing'
                                                                      ? 'Procesando...'
                                                                      : 'Procesar a SAP'}
                                                            </TooltipContent>
                                                        </Tooltip>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-8 w-8 text-destructive hover:text-destructive"
                                                                    onClick={() => {
                                                                        setBatchToDelete(batch);
                                                                        setDeleteDialogOpen(true);
                                                                    }}
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                </Button>
                                                            </TooltipTrigger>
                                                            <TooltipContent>Eliminar lote</TooltipContent>
                                                        </Tooltip>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                                </div>

                                {/* Pagination */}
                                {batchesPagination.lastPage > 1 && (
                                    <div className="flex items-center justify-between">
                                        <p className="text-sm text-muted-foreground">
                                            Mostrando {batches.length} de {batchesPagination.total} lotes
                                        </p>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    fetchBatches(batchesPagination.currentPage - 1)
                                                }
                                                disabled={batchesPagination.currentPage === 1}
                                            >
                                                <ChevronLeft className="h-4 w-4" />
                                                Anterior
                                            </Button>
                                            <span className="text-sm">
                                                Página {batchesPagination.currentPage} de{' '}
                                                {batchesPagination.lastPage}
                                            </span>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    fetchBatches(batchesPagination.currentPage + 1)
                                                }
                                                disabled={
                                                    batchesPagination.currentPage ===
                                                    batchesPagination.lastPage
                                                }
                                            >
                                                Siguiente
                                                <ChevronRight className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                        </PageSection>
                    </TabsContent>
                </Tabs>
            </div>

            {/* Delete Confirmation Dialog */}
            <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>¿Eliminar este lote?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Esta acción eliminará permanentemente el lote{' '}
                            <span className="font-mono font-medium">
                                {batchToDelete?.uuid.substring(0, 8)}...
                            </span>{' '}
                            y todas sus transacciones asociadas. Esta acción no se puede deshacer.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeleting}>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleDeleteBatch}
                            disabled={isDeleting}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            {isDeleting ? (
                                <>
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    Eliminando...
                                </>
                            ) : (
                                'Eliminar'
                            )}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Batch Detail Modal */}
            <Dialog open={batchDetailOpen} onOpenChange={setBatchDetailOpen}>
                <DialogContent className="flex max-h-[85vh] max-w-4xl flex-col overflow-hidden">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <FileSpreadsheet className="h-4 w-4 text-muted-foreground" />
                            Detalle del Lote
                        </DialogTitle>
                        <DialogDescription className="font-mono text-xs">
                            {batchDetail?.filename || 'Cargando...'}
                        </DialogDescription>
                    </DialogHeader>

                    {batchDetailLoading ? (
                        <div className="flex items-center justify-center py-12">
                            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                        </div>
                    ) : batchDetail ? (
                        <div className="flex-1 space-y-6 overflow-y-auto pr-1">
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <InfoCell label="UUID">
                                    <div className="flex items-center gap-1.5">
                                        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">
                                            {batchDetail.uuid.substring(0, 8)}
                                        </code>
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-6 w-6"
                                                    onClick={() => {
                                                        navigator.clipboard.writeText(batchDetail.uuid);
                                                    }}
                                                >
                                                    <Copy className="h-3 w-3" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>Copiar UUID completo</TooltipContent>
                                        </Tooltip>
                                    </div>
                                </InfoCell>
                                <InfoCell label="Procesado">
                                    <span className="tabular-nums">
                                        {batchDetail.processed_at ? formatDate(batchDetail.processed_at) : '—'}
                                    </span>
                                </InfoCell>
                                <InfoCell label="Sucursal">
                                    <span className="block truncate">{batchDetail.branch?.name || '—'}</span>
                                </InfoCell>
                                <InfoCell label="Cuenta">
                                    <span className="block truncate">{batchDetail.bank_account?.name || '—'}</span>
                                </InfoCell>
                            </div>

                            {batchDetail.status === 'failed' && batchDetail.error_message && (
                                <Alert variant="destructive" className="py-3">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertTitle className="text-sm">Error en el procesamiento</AlertTitle>
                                    <AlertDescription className="text-sm">
                                        {batchDetail.error_message}
                                    </AlertDescription>
                                </Alert>
                            )}

                            <div className="grid grid-cols-3 gap-3">
                                <StatCard icon={FileSpreadsheet} label="Registros" value={batchDetail.total_records} />
                                <StatCard icon={ArrowDownRight} label="Total Débito" value={`$${formatCurrency(batchDetail.total_debit)}`} tone="danger" />
                                <StatCard icon={ArrowUpRight} label="Total Crédito" value={`$${formatCurrency(batchDetail.total_credit)}`} tone="success" />
                            </div>

                            <div>
                                <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Transacciones ({batchDetail.transactions.length})
                                </h4>
                                <div className="overflow-hidden rounded-md border">
                                    <div className="max-h-[320px] overflow-auto">
                                        <table className="w-full min-w-[700px] text-sm">
                                            <thead className="sticky top-0 z-10 bg-muted/50 backdrop-blur-sm">
                                                <tr>
                                                    <th className="w-12 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">#</th>
                                                    <th className="w-24 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">Fecha</th>
                                                    <th className="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">Memo</th>
                                                    <th className="w-32 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">Cuenta</th>
                                                    <th className="w-28 px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Débito</th>
                                                    <th className="w-28 px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Crédito</th>
                                                    <th className="w-28 px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">N° SAP</th>
                                                    <th className="w-40 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">Error</th>
                                                    <th className="w-16 px-3 py-2 text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground"></th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-border">
                                                {batchDetail.transactions.length === 0 ? (
                                                    <tr>
                                                        <td colSpan={9} className="py-8 text-center text-muted-foreground">
                                                            No hay transacciones en este lote
                                                        </td>
                                                    </tr>
                                                ) : (
                                                    batchDetail.transactions.map((transaction) => (
                                                        <tr key={transaction.id} className="hover:bg-muted/30">
                                                            <td className="px-3 py-2 font-mono text-xs tabular-nums text-muted-foreground">
                                                                {transaction.sequence}
                                                            </td>
                                                            <td className="whitespace-nowrap px-3 py-2 text-xs tabular-nums">
                                                                {new Date(transaction.due_date).toLocaleDateString('es-MX')}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <span className="block max-w-[220px] cursor-default truncate text-sm">
                                                                            {transaction.memo}
                                                                        </span>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent side="top" className="max-w-xs">
                                                                        {transaction.memo}
                                                                    </TooltipContent>
                                                                </Tooltip>
                                                            </td>
                                                            <td className="whitespace-nowrap px-3 py-2 font-mono text-xs">
                                                                {transaction.counterpart_account}
                                                            </td>
                                                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                                                {Number(transaction.debit_amount) > 0 ? (
                                                                    <span className="text-xs font-medium text-rose-600 dark:text-rose-400">
                                                                        ${formatCurrency(transaction.debit_amount)}
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-muted-foreground/40">—</span>
                                                                )}
                                                            </td>
                                                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                                                {Number(transaction.credit_amount) > 0 ? (
                                                                    <span className="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                                                        ${formatCurrency(transaction.credit_amount)}
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-muted-foreground/40">—</span>
                                                                )}
                                                            </td>
                                                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                                                {transaction.sap_number !== null ? (
                                                                    <span className="font-mono text-xs">
                                                                        {transaction.sap_number}
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-xs text-muted-foreground">—</span>
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                {transaction.error ? (
                                                                    <Tooltip>
                                                                        <TooltipTrigger asChild>
                                                                            <span className="block max-w-[150px] cursor-default truncate text-xs text-destructive">
                                                                                {transaction.error}
                                                                            </span>
                                                                        </TooltipTrigger>
                                                                        <TooltipContent side="left" className="max-w-sm">
                                                                            {transaction.error}
                                                                        </TooltipContent>
                                                                    </Tooltip>
                                                                ) : (
                                                                    <span className="text-xs text-muted-foreground/40">—</span>
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-2 text-center">
                                                                {transaction.sap_number === null && batchDetail.status !== 'completed' ? (
                                                                    <Tooltip>
                                                                        <TooltipTrigger asChild>
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="icon"
                                                                                className="h-7 w-7"
                                                                                onClick={() => handleReprocessTransaction(transaction.id)}
                                                                                disabled={reprocessingTransactionId !== null}
                                                                            >
                                                                                {reprocessingTransactionId === transaction.id ? (
                                                                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                                                ) : (
                                                                                    <RefreshCw className="h-3.5 w-3.5" />
                                                                                )}
                                                                            </Button>
                                                                        </TooltipTrigger>
                                                                        <TooltipContent>Reprocesar</TooltipContent>
                                                                    </Tooltip>
                                                                ) : (
                                                                    <span className="text-xs text-muted-foreground/40">—</span>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div className="border-t pt-2 text-xs text-muted-foreground">
                                Creado por: {batchDetail.user || 'Sistema'}
                            </div>
                        </div>
                    ) : null}

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setBatchDetailOpen(false)}>
                            Cerrar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
