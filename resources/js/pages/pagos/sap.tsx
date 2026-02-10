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
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { sap as pagosSap } from '@/routes/pagos';
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
    AlertCircle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Download,
    Eye,
    FileSpreadsheet,
    Loader2,
    Play,
    RefreshCw,
    Trash2,
    Upload,
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
}

type UploadStatus = 'idle' | 'validating' | 'processing' | 'success' | 'error';

export default function PagosSap({ branches, bankAccounts }: Props) {
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

                const response = await fetch(`/pagos/sap/batches?${params}`, {
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
            const response = await fetch(`/pagos/sap/batches/${batchToDelete.id}`, {
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
            const response = await fetch(`/pagos/sap/batches/${batch.id}`, {
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
            const response = await fetch(`/pagos/sap/batches/${batch.id}/process`, {
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
                `/pagos/sap/batches/${batchDetail.id}/payments/${cardCode}/reprocess`,
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

    const getStatusLabel = (status: VendorPaymentBatch['status']): string => {
        const labels = {
            pending: 'Pendiente',
            processing: 'Procesando',
            completed: 'Completado',
            failed: 'Fallido',
        };
        return labels[status];
    };

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
            await new Promise((resolve) => setTimeout(resolve, 300));
            setUploadProgress(40);
            setUploadStatus('processing');

            const response = await fetch('/pagos/sap/batches', {
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
        form.action = '/pagos/sap/batches/error-log';

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
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Pagos a Proveedores</CardTitle>
                        <CardDescription>
                            Carga masiva de pagos a proveedores con liquidación de facturas
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {/* Selects Row */}
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="branch">Sucursal</Label>
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
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="bankAccount">Cuenta Bancaria</Label>
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
                            </div>
                        </div>

                        {/* File Upload Row */}
                        <div className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-[1fr_auto]">
                                <div className="grid gap-2">
                                    <div className="flex items-center justify-between">
                                        <Label htmlFor="file">Archivo Excel</Label>
                                        <a
                                            href="/pagos/sap/template/download"
                                            className="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors"
                                        >
                                            <Download className="h-3 w-3" />
                                            Descargar plantilla de ejemplo
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
                                    {!selectedFile ? (
                                        <button
                                            type="button"
                                            onClick={() => fileInputRef.current?.click()}
                                            disabled={!selectedBankAccount || isUploading}
                                            className="flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-muted-foreground/25 bg-muted/50 px-6 py-8 text-center transition-colors hover:border-muted-foreground/50 hover:bg-muted disabled:pointer-events-none disabled:opacity-50"
                                        >
                                            <div className="rounded-full bg-background p-3 shadow-sm">
                                                <Upload className="h-6 w-6 text-muted-foreground" />
                                            </div>
                                            <div className="space-y-1">
                                                <p className="text-sm font-medium">
                                                    Haz clic para seleccionar archivo
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    Formatos: .xlsx, .xls
                                                </p>
                                            </div>
                                        </button>
                                    ) : (
                                        <div className="flex items-center gap-3 rounded-lg border bg-muted/50 p-3">
                                            <div className="rounded-lg bg-green-500/10 p-2">
                                                <FileSpreadsheet className="h-5 w-5 text-green-600" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm font-medium truncate">
                                                    {selectedFile.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatFileSize(selectedFile.size)}
                                                </p>
                                            </div>
                                            {!isUploading && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    className="shrink-0 text-muted-foreground hover:text-foreground"
                                                    onClick={handleRemoveFile}
                                                >
                                                    <X className="h-4 w-4" />
                                                    <span className="sr-only">Remover archivo</span>
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </div>
                                <div className="flex items-end">
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

                            {/* Progress Bar */}
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
                        </div>

                        {/* Success Alert */}
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

                        {/* Error Alert */}
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
                    </CardContent>
                </Card>

                {/* Batches Section */}
                <Card>
                    <CardHeader>
                        <CardTitle>Lotes de Pagos</CardTitle>
                        <CardDescription>
                            Historial de lotes procesados para la sucursal y cuenta seleccionadas
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
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
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>UUID</TableHead>
                                            <TableHead>Archivo</TableHead>
                                            <TableHead>Fecha</TableHead>
                                            <TableHead>Estado</TableHead>
                                            <TableHead className="text-right">Facturas</TableHead>
                                            <TableHead className="text-right">Pagos</TableHead>
                                            <TableHead className="text-right">Monto Total</TableHead>
                                            <TableHead className="text-right">Acciones</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {batches.map((batch) => (
                                            <TableRow key={batch.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {batch.uuid.substring(0, 8)}...
                                                </TableCell>
                                                <TableCell className="max-w-[200px] truncate">
                                                    {batch.filename}
                                                </TableCell>
                                                <TableCell>{formatDate(batch.created_at)}</TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={batch.status === 'failed' ? 'destructive' : 'default'}
                                                    >
                                                        {getStatusLabel(batch.status)}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {batch.total_invoices}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {batch.total_payments}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    ${formatCurrency(batch.total_amount)}
                                                </TableCell>
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
                    </CardContent>
                </Card>
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
                <DialogContent className="!max-w-[90vw] !w-[1400px] max-h-[85vh] overflow-hidden flex flex-col">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-3">
                            <FileSpreadsheet className="h-5 w-5" />
                            Detalle del Lote
                        </DialogTitle>
                        <DialogDescription>
                            {batchDetail?.filename || 'Cargando...'}
                        </DialogDescription>
                    </DialogHeader>

                    {batchDetailLoading ? (
                        <div className="flex items-center justify-center py-12">
                            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                        </div>
                    ) : batchDetail ? (
                        <div className="flex-1 overflow-y-auto space-y-6">
                            {/* Batch Info */}
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                <div className="space-y-1">
                                    <p className="text-xs text-muted-foreground uppercase tracking-wide">UUID</p>
                                    <code className="text-sm bg-muted px-2 py-1 rounded block">
                                        {batchDetail.uuid.substring(0, 8)}
                                    </code>
                                </div>
                                <div className="space-y-1">
                                    <p className="text-xs text-muted-foreground uppercase tracking-wide">Procesado</p>
                                    <p className="font-medium">
                                        {batchDetail.processed_at ? formatDate(batchDetail.processed_at) : '-'}
                                    </p>
                                </div>
                                <div className="space-y-1">
                                    <p className="text-xs text-muted-foreground uppercase tracking-wide">Sucursal</p>
                                    <p className="font-medium">{batchDetail.branch?.name || '-'}</p>
                                </div>
                                <div className="space-y-1">
                                    <p className="text-xs text-muted-foreground uppercase tracking-wide">Cuenta</p>
                                    <p className="font-medium">{batchDetail.bank_account?.name || '-'}</p>
                                </div>
                            </div>

                            {/* Summary Cards */}
                            <div className="grid grid-cols-3 gap-3">
                                <div className="bg-muted/50 rounded-lg p-3 text-center">
                                    <p className="text-xl md:text-2xl font-bold">{batchDetail.total_invoices}</p>
                                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Facturas</p>
                                </div>
                                <div className="bg-blue-500/10 rounded-lg p-3 text-center">
                                    <p className="text-xl md:text-2xl font-bold text-blue-500">{batchDetail.total_payments}</p>
                                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Pagos</p>
                                </div>
                                <div className="bg-green-500/10 rounded-lg p-3 text-center">
                                    <p className="text-lg md:text-xl font-bold text-green-500 tabular-nums">
                                        ${formatCurrency(batchDetail.total_amount)}
                                    </p>
                                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Monto Total</p>
                                </div>
                            </div>

                            {/* Grouped by Vendor */}
                            <div>
                                <h4 className="font-medium mb-3 text-sm uppercase tracking-wide text-muted-foreground">
                                    Pagos por Proveedor ({groupedInvoices.length})
                                </h4>
                                <div className="space-y-2">
                                    {groupedInvoices.map((group) => (
                                        <div key={group.card_code} className="border rounded-lg p-4">
                                            <div className="flex items-center justify-between mb-2">
                                                <div>
                                                    <p className="font-medium">{group.card_code}</p>
                                                    <p className="text-sm text-muted-foreground">{group.card_name}</p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="font-bold text-lg">${formatCurrency(group.total_amount)}</p>
                                                    <p className="text-xs text-muted-foreground">
                                                        {group.invoice_count} factura{group.invoice_count > 1 ? 's' : ''}
                                                    </p>
                                                </div>
                                            </div>
                                            {group.sap_doc_num ? (
                                                <Badge variant="default" className="text-xs">
                                                    SAP Doc: {group.sap_doc_num}
                                                </Badge>
                                            ) : group.has_error ? (
                                                <div className="flex items-center gap-2">
                                                    <Badge variant="destructive" className="text-xs">Error</Badge>
                                                    {batchDetail.status !== 'completed' && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => handleReprocessPayment(group.card_code)}
                                                            disabled={reprocessingCardCode === group.card_code}
                                                        >
                                                            {reprocessingCardCode === group.card_code ? (
                                                                <>
                                                                    <Loader2 className="h-3 w-3 animate-spin mr-1" />
                                                                    Reprocesando...
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <RefreshCw className="h-3 w-3 mr-1" />
                                                                    Reprocesar
                                                                </>
                                                            )}
                                                        </Button>
                                                    )}
                                                </div>
                                            ) : (
                                                <Badge variant="secondary" className="text-xs">Pendiente</Badge>
                                            )}
                                            {group.error && (
                                                <p className="text-xs text-destructive mt-2">{group.error}</p>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
