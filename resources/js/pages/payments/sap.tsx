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
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { InfoCell, StatCard } from '@/components/page/detail-bits';
import { DollarSign } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ActivityHeatmap } from '@/components/page/activity-heatmap';
import { BatchStatusBadge } from '@/components/page/batch-status-badge';
import { ColumnVisibilityMenu } from '@/components/page/column-visibility-menu';
import { FilterField, FiltersCard } from '@/components/page/filters-card';
import { InfoWidget } from '@/components/page/info-widget';
import { PageHeader } from '@/components/page/page-header';
import { PageSection } from '@/components/page/page-section';
import { RowNumberBadge } from '@/components/page/row-number-badge';
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
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { sap as pagosSap } from '@/routes/payments';
import type {
    BankAccount,
    Branch,
    BreadcrumbItem,
} from '@/types';
import type {
    BatchResult,
    ImportError,
    PaginatedResponse,
    VendorPaymentBatch,
    VendorPaymentBatchDetail,
    VendorPaymentGroup,
} from '@/types/vendorPayments';
import { Head } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Download,
    Eye,
    FileSpreadsheet,
    Filter,
    Loader2,
    Play,
    RefreshCw,
    Trash2,
    Upload,
    Wallet,
    X,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Pagos a SAP',
        href: pagosSap().url,
    },
];

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
    activityData?: Record<string, number>;
}

type UploadStatus = 'idle' | 'validating' | 'processing' | 'success' | 'error';

export default function PagosSap({ branches, bankAccounts, activityData = {} }: Props) {
    const [selectedBranch, setSelectedBranch] = useState<string>('');
    const [selectedBankAccount, setSelectedBankAccount] = useState<string>('');
    const [processDate, setProcessDate] = useState<string>('');
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [isUploading, setIsUploading] = useState(false);
    const [uploadStatus, setUploadStatus] = useState<UploadStatus>('idle');
    const [uploadProgress, setUploadProgress] = useState(0);
    const [errors, setErrors] = useState<ImportError[]>([]);
    const [successResult, setSuccessResult] = useState<BatchResult | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    // Batches state
    const [batches, setBatches] = useState<VendorPaymentBatch[]>([]);
    const [batchesLoading, setBatchesLoading] = useState(false);
    const [batchesPagination, setBatchesPagination] = useState<{
        currentPage: number;
        lastPage: number;
        total: number;
    }>({ currentPage: 1, lastPage: 1, total: 0 });
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [batchToDelete, setBatchToDelete] = useState<VendorPaymentBatch | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    // Batch detail modal state
    const [batchDetailOpen, setBatchDetailOpen] = useState(false);
    const [batchDetail, setBatchDetail] = useState<VendorPaymentBatchDetail | null>(null);
    const [batchDetailLoading, setBatchDetailLoading] = useState(false);

    // SAP processing state
    const [processingBatchId, setProcessingBatchId] = useState<number | null>(null);
    const [reprocessingCardCode, setReprocessingCardCode] = useState<string | null>(null);

    const filteredBankAccounts = useMemo(() => {
        if (!selectedBranch) return [];
        return bankAccounts.filter((account) => account.branch_id === Number(selectedBranch));
    }, [selectedBranch, bankAccounts]);

    const fetchBatches = useCallback(
        async (page = 1, silent = false) => {
            if (!selectedBranch || !selectedBankAccount) {
                setBatches([]);
                setBatchesPagination({ currentPage: 1, lastPage: 1, total: 0 });
                return;
            }

            if (!silent) {
                setBatchesLoading(true);
            }
            try {
                const params = new URLSearchParams({
                    branch_id: selectedBranch,
                    bank_account_id: selectedBankAccount,
                    page: String(page),
                });

                const response = await fetch(`/payments/sap/batches?${params}`, {
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

                const data: PaginatedResponse<VendorPaymentBatch> = await response.json();
                setBatches(data.data);
                setBatchesPagination({
                    currentPage: data.current_page,
                    lastPage: data.last_page,
                    total: data.total,
                });
            } catch (error) {
                console.error('Error fetching batches:', error);
                if (!silent) {
                    setBatches([]);
                }
            } finally {
                if (!silent) {
                    setBatchesLoading(false);
                }
            }
        },
        [selectedBranch, selectedBankAccount]
    );

    const hasProcessingBatches = useMemo(
        () => batches.some((batch) => batch.status === 'processing'),
        [batches],
    );

    useEffect(() => {
        fetchBatches(1);
    }, [fetchBatches]);

    useEffect(() => {
        if (!hasProcessingBatches) return;

        const intervalId = setInterval(() => {
            fetchBatches(batchesPagination.currentPage, true);
        }, 5000);

        return () => clearInterval(intervalId);
    }, [hasProcessingBatches, fetchBatches, batchesPagination.currentPage]);

    const handleDeleteBatch = async () => {
        if (!batchToDelete) return;

        setIsDeleting(true);
        try {
            const response = await fetch(`/payments/sap/batches/${batchToDelete.id}`, {
                method: 'DELETE',
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

    const fetchBatchDetail = async (batch: VendorPaymentBatch) => {
        setBatchDetailLoading(true);
        setBatchDetailOpen(true);
        setBatchDetail(null);
        try {
            const response = await fetch(`/payments/sap/batches/${batch.id}`, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Error al cargar el detalle del lote');
            }

            const data: VendorPaymentBatchDetail = await response.json();
            setBatchDetail(data);
        } catch (error) {
            console.error('Error fetching batch detail:', error);
        } finally {
            setBatchDetailLoading(false);
        }
    };

    const handleProcessToSap = async (batch: VendorPaymentBatch) => {
        if (batch.status === 'processing' || batch.status === 'completed') {
            return;
        }

        setProcessingBatchId(batch.id);
        try {
            const response = await fetch(`/payments/sap/batches/${batch.id}/process`, {
                method: 'POST',
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

    const handleReprocessPayment = async (cardCode: string) => {
        if (!batchDetail) return;

        setReprocessingCardCode(cardCode);
        try {
            const response = await fetch(
                `/payments/sap/batches/${batchDetail.id}/payments/${cardCode}/reprocess`,
                {
                    method: 'POST',
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
                // Refresh batch detail
                await fetchBatchDetail(batchDetail);
                // Refresh batches list
                await fetchBatches(batchesPagination.currentPage);
            } else {
                console.error('Error reprocessing payment:', data.message);
            }
        } catch (error) {
            console.error('Error reprocessing payment:', error);
        } finally {
            setReprocessingCardCode(null);
        }
    };

    const formatCurrency = (value: string | number): string => {
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
        facturas: true,
        pagos: true,
        monto: true,
    });

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
        if (!selectedBranch || !selectedBankAccount || !processDate || !selectedFile) return;

        setIsUploading(true);
        setErrors([]);
        setSuccessResult(null);
        setUploadStatus('validating');
        setUploadProgress(20);

        const formData = new FormData();
        formData.append('branch_id', selectedBranch);
        formData.append('bank_account_id', selectedBankAccount);
        formData.append('process_date', processDate);
        formData.append('file', selectedFile);

        try {
            await new Promise((resolve) => setTimeout(resolve, 300));
            setUploadProgress(40);
            setUploadStatus('processing');

            const response = await fetch('/payments/sap/batches', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                            ?.content || '',
                    Accept: 'application/json',
                },
            });

            setUploadProgress(80);

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
                fetchBatches(1);
            } else {
                setUploadStatus('error');
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
        form.action = '/payments/sap/batches/error-log';

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

    const canUpload = selectedBranch && selectedBankAccount && processDate && selectedFile && !isUploading;

    const getStatusMessage = (): string => {
        switch (uploadStatus) {
            case 'validating':
                return 'Validando archivo...';
            case 'processing':
                return 'Procesando pagos...';
            case 'success':
                return 'Completado';
            case 'error':
                return 'Error en el proceso';
            default:
                return '';
        }
    };

    // Group invoices by vendor
    const groupedInvoices = useMemo<VendorPaymentGroup[]>(() => {
        if (!batchDetail) return [];

        const groups = new Map<string, VendorPaymentGroup>();

        batchDetail.invoices.forEach((invoice) => {
            if (!groups.has(invoice.card_code)) {
                groups.set(invoice.card_code, {
                    card_code: invoice.card_code,
                    card_name: invoice.card_name,
                    total_amount: 0,
                    invoice_count: 0,
                    invoices: [],
                    sap_doc_num: invoice.sap_doc_num,
                    has_error: !!invoice.error,
                    error: invoice.error,
                });
            }

            const group = groups.get(invoice.card_code)!;
            group.total_amount += Number(invoice.sum_applied);
            group.invoice_count++;
            group.invoices.push(invoice);
        });

        return Array.from(groups.values());
    }, [batchDetail]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pagos a SAP" />
            <div className="space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={Wallet}
                    title="Pagos a SAP"
                    description="Carga masiva de pagos a proveedores con liquidación de facturas."
                />

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <FiltersCard icon={Filter} columns={3} className="lg:col-span-2">
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
                        <FilterField label="Fecha de Proceso" htmlFor="processDate">
                            <Input
                                id="processDate"
                                type="date"
                                value={processDate}
                                onChange={(e) => setProcessDate(e.target.value)}
                                disabled={!selectedBankAccount}
                            />
                        </FilterField>
                        <div className="space-y-2 lg:col-span-3">
                            <div className="flex items-center justify-between">
                                <Label
                                    htmlFor="file"
                                    className="text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                                >
                                    Archivo del Banco
                                </Label>
                                <a
                                    href="/payments/sap/template/download"
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
                        title="Actividad"
                        icon={Activity}
                        footer="Lotes por día · últimas 13 semanas"
                    >
                        <ActivityHeatmap data={activityData} />
                    </InfoWidget>
                </div>

                {isUploading && (
                    <div className="space-y-2">
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-muted-foreground">{getStatusMessage()}</span>
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
                                    <span className="opacity-70">Total Facturas:</span>{' '}
                                    <span className="font-medium">{successResult.total_invoices}</span>
                                </p>
                                <p>
                                    <span className="opacity-70">Total Pagos:</span>{' '}
                                    <span className="font-medium">{successResult.total_payments}</span>
                                </p>
                                <p>
                                    <span className="opacity-70">Monto Total:</span>{' '}
                                    <span className="font-medium">
                                        ${formatCurrency(successResult.total_amount)}
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
                                        {error.row > 0 && <strong>Fila {error.row}:</strong>}{' '}
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
                    title="Lotes de Pagos"
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
                                    { key: 'facturas', label: 'Facturas' },
                                    { key: 'pagos', label: 'Pagos' },
                                    { key: 'monto', label: 'Monto Total' },
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
                                            {columnVisibility.facturas && (
                                                <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Facturas</TableHead>
                                            )}
                                            {columnVisibility.pagos && (
                                                <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Pagos</TableHead>
                                            )}
                                            {columnVisibility.monto && (
                                                <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Monto Total</TableHead>
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
                                                {columnVisibility.facturas && (
                                                    <TableCell className="py-3 text-right tabular-nums">
                                                        {batch.total_invoices}
                                                    </TableCell>
                                                )}
                                                {columnVisibility.pagos && (
                                                    <TableCell className="py-3 text-right tabular-nums">
                                                        {batch.total_payments}
                                                    </TableCell>
                                                )}
                                                {columnVisibility.monto && (
                                                    <TableCell className="py-3 text-right tabular-nums">
                                                        ${formatCurrency(batch.total_amount)}
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
                            y todas sus facturas asociadas. Esta acción no se puede deshacer.
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
                <DialogContent className="flex max-h-[85vh] max-w-3xl flex-col overflow-hidden">
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
                                    <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">
                                        {batchDetail.uuid.substring(0, 8)}
                                    </code>
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

                            <div className="grid grid-cols-3 gap-3">
                                <StatCard icon={FileSpreadsheet} label="Facturas" value={batchDetail.total_invoices} />
                                <StatCard icon={Play} label="Pagos" value={batchDetail.total_payments} tone="primary" />
                                <StatCard icon={DollarSign} label="Monto Total" value={`$${formatCurrency(batchDetail.total_amount)}`} tone="success" />
                            </div>

                            <div>
                                <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Pagos por Proveedor ({groupedInvoices.length})
                                </h4>
                                <div className="space-y-2">
                                    {groupedInvoices.map((group) => (
                                        <div key={group.card_code} className="space-y-2 rounded-md border bg-card p-3">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="text-sm font-medium">{group.card_code}</p>
                                                    <p className="truncate text-xs text-muted-foreground">{group.card_name}</p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="text-sm font-semibold tabular-nums">
                                                        ${formatCurrency(group.total_amount)}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {group.invoice_count} factura{group.invoice_count > 1 ? 's' : ''}
                                                    </p>
                                                </div>
                                            </div>
                                            {group.sap_doc_num ? (
                                                <Badge variant="outline" className="rounded-full font-mono text-xs">
                                                    SAP #{group.sap_doc_num}
                                                </Badge>
                                            ) : group.has_error ? (
                                                <div className="flex items-center gap-2">
                                                    <Badge className="rounded-full border-transparent bg-destructive/15 text-destructive">
                                                        Error
                                                    </Badge>
                                                    {batchDetail.status !== 'completed' && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => handleReprocessPayment(group.card_code)}
                                                            disabled={reprocessingCardCode === group.card_code}
                                                        >
                                                            {reprocessingCardCode === group.card_code ? (
                                                                <>
                                                                    <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                                                                    Reprocesando...
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <RefreshCw className="mr-1 h-3 w-3" />
                                                                    Reprocesar
                                                                </>
                                                            )}
                                                        </Button>
                                                    )}
                                                </div>
                                            ) : (
                                                <Badge className="rounded-full border-transparent bg-muted text-muted-foreground">
                                                    Pendiente
                                                </Badge>
                                            )}
                                            {group.error && (
                                                <p className="text-xs text-destructive">{group.error}</p>
                                            )}
                                        </div>
                                    ))}
                                </div>
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
