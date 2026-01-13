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
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
import { tesoreria } from '@/routes';
import { downloadTemplate } from '@/actions/App/Http/Controllers/BatchController';
import {
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
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Copy,
    Download,
    Eye,
    FileSpreadsheet,
    Loader2,
    Trash2,
    Upload,
    X,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'AC Tesorería',
        href: tesoreria().url,
    },
];

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
}

type UploadStatus = 'idle' | 'validating' | 'processing' | 'success' | 'error';

export default function Tesoreria({ branches, bankAccounts }: Props) {
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

                const response = await fetch(`/tesoreria/batches?${params}`, {
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
            const response = await fetch(`/tesoreria/batches/${batchToDelete.id}`, {
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

    const fetchBatchDetail = async (batch: Batch) => {
        setBatchDetailLoading(true);
        setBatchDetailOpen(true);
        setBatchDetail(null);
        try {
            const response = await fetch(`/tesoreria/batches/${batch.id}`, {
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

            const response = await fetch('/tesoreria/batches', {
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
        form.action = '/tesoreria/batches/error-log';

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
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Automatización de asientos contables</CardTitle>
                        <CardDescription>
                            Carga de asientos contables con contrapartidas para movimientos
                            bancarios desde Extractos bancarios
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
                                            href={downloadTemplate.url()}
                                            className="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors"
                                        >
                                            <Download className="h-3 w-3" />
                                            Descargar plantilla de ejemplo
                                        </a>
                                    </div>
                                    {/* Hidden native input */}
                                    <input
                                        ref={fileInputRef}
                                        id="file"
                                        type="file"
                                        accept=".xlsx,.xls"
                                        onChange={handleFileChange}
                                        disabled={!selectedBankAccount || isUploading}
                                        className="sr-only"
                                    />
                                    {/* Custom file drop zone */}
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
                            <Alert className="border-green-500 bg-green-50 dark:bg-green-950">
                                <CheckCircle2 className="h-4 w-4 text-green-600" />
                                <AlertTitle className="text-green-800 dark:text-green-200">
                                    Archivo procesado exitosamente
                                </AlertTitle>
                                <AlertDescription className="text-green-700 dark:text-green-300">
                                    <div className="mt-2 space-y-1">
                                        <p>
                                            <strong>Lote:</strong> {successResult.uuid}
                                        </p>
                                        <p>
                                            <strong>Registros:</strong> {successResult.total_records}
                                        </p>
                                        <p>
                                            <strong>Total Débito:</strong> $
                                            {Number(successResult.total_debit).toLocaleString(
                                                'es-MX',
                                                { minimumFractionDigits: 2 }
                                            )}
                                        </p>
                                        <p>
                                            <strong>Total Crédito:</strong> $
                                            {Number(successResult.total_credit).toLocaleString(
                                                'es-MX',
                                                { minimumFractionDigits: 2 }
                                            )}
                                        </p>
                                        <p>
                                            <strong>Procesado:</strong> {successResult.processed_at}
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
                        <CardTitle>Lotes de Transacción</CardTitle>
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
                                            <TableHead>Fecha procesado</TableHead>
                                            <TableHead className="text-right">Registros</TableHead>
                                            <TableHead className="text-right">Total Débito</TableHead>
                                            <TableHead className="text-right">Total Crédito</TableHead>
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
                                                <TableCell>{formatDate(batch.processed_at)}</TableCell>
                                                <TableCell className="text-right">
                                                    {batch.total_records}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    ${formatCurrency(batch.total_debit)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    ${formatCurrency(batch.total_credit)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-8 w-8"
                                                            title="Ver detalle"
                                                            onClick={() => fetchBatchDetail(batch)}
                                                        >
                                                            <Eye className="h-4 w-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-8 w-8 text-destructive hover:text-destructive"
                                                            title="Eliminar lote"
                                                            onClick={() => {
                                                                setBatchToDelete(batch);
                                                                setDeleteDialogOpen(true);
                                                            }}
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
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
                            {/* Batch Info - Reorganized */}
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                {/* UUID with copy */}
                                <div className="space-y-1">
                                    <p className="text-xs text-muted-foreground uppercase tracking-wide">UUID</p>
                                    <div className="flex items-center gap-2">
                                        <code className="text-sm bg-muted px-2 py-1 rounded">
                                            {batchDetail.uuid.substring(0, 8)}
                                        </code>
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-7 w-7"
                                                    onClick={() => {
                                                        navigator.clipboard.writeText(batchDetail.uuid);
                                                    }}
                                                >
                                                    <Copy className="h-3.5 w-3.5" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>Copiar UUID completo</TooltipContent>
                                        </Tooltip>
                                    </div>
                                </div>

                                {/* Fecha */}
                                <div className="space-y-1">
                                    <p className="text-xs text-muted-foreground uppercase tracking-wide">Procesado</p>
                                    <p className="font-medium">
                                        {batchDetail.processed_at ? formatDate(batchDetail.processed_at) : '-'}
                                    </p>
                                </div>

                                {/* Sucursal */}
                                <div className="space-y-1">
                                    <p className="text-xs text-muted-foreground uppercase tracking-wide">Sucursal</p>
                                    <p className="font-medium">{batchDetail.branch?.name || '-'}</p>
                                </div>

                                {/* Cuenta */}
                                <div className="space-y-1">
                                    <p className="text-xs text-muted-foreground uppercase tracking-wide">Cuenta</p>
                                    <p className="font-medium">{batchDetail.bank_account?.name || '-'}</p>
                                </div>
                            </div>

                            {/* Summary Cards */}
                            <div className="grid grid-cols-3 gap-3">
                                <div className="bg-muted/50 rounded-lg p-3 text-center">
                                    <p className="text-xl md:text-2xl font-bold">{batchDetail.total_records}</p>
                                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Registros</p>
                                </div>
                                <div className="bg-red-500/10 rounded-lg p-3 text-center">
                                    <p className="text-lg md:text-xl font-bold text-red-500 tabular-nums">
                                        ${formatCurrency(batchDetail.total_debit)}
                                    </p>
                                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Total Débito</p>
                                </div>
                                <div className="bg-green-500/10 rounded-lg p-3 text-center">
                                    <p className="text-lg md:text-xl font-bold text-green-500 tabular-nums">
                                        ${formatCurrency(batchDetail.total_credit)}
                                    </p>
                                    <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Total Crédito</p>
                                </div>
                            </div>

                            {/* Transactions Table */}
                            <div>
                                <h4 className="font-medium mb-3 text-sm uppercase tracking-wide text-muted-foreground">
                                    Transacciones ({batchDetail.transactions.length})
                                </h4>
                                <div className="border rounded-lg overflow-hidden">
                                    <div className="max-h-[280px] overflow-y-auto overflow-x-auto">
                                        <table className="w-full min-w-[700px] text-sm">
                                            <thead className="sticky top-0 bg-muted/95 backdrop-blur-sm border-b">
                                                <tr>
                                                    <th className="px-3 py-2 text-left font-medium text-muted-foreground w-12">#</th>
                                                    <th className="px-3 py-2 text-left font-medium text-muted-foreground w-24">Fecha</th>
                                                    <th className="px-3 py-2 text-left font-medium text-muted-foreground">Memo</th>
                                                    <th className="px-3 py-2 text-left font-medium text-muted-foreground w-32">Cuenta</th>
                                                    <th className="px-3 py-2 text-right font-medium text-muted-foreground w-28">Débito</th>
                                                    <th className="px-3 py-2 text-right font-medium text-muted-foreground w-28">Crédito</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-border">
                                                {batchDetail.transactions.length === 0 ? (
                                                    <tr>
                                                        <td
                                                            colSpan={6}
                                                            className="text-center text-muted-foreground py-8"
                                                        >
                                                            No hay transacciones en este lote
                                                        </td>
                                                    </tr>
                                                ) : (
                                                    batchDetail.transactions.map((transaction) => (
                                                        <tr key={transaction.id} className="hover:bg-muted/50">
                                                            <td className="px-3 py-2 font-mono text-xs text-muted-foreground">
                                                                {transaction.sequence}
                                                            </td>
                                                            <td className="px-3 py-2 text-xs whitespace-nowrap">
                                                                {new Date(transaction.due_date).toLocaleDateString(
                                                                    'es-MX'
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <span className="block truncate max-w-[220px] cursor-default">
                                                                            {transaction.memo}
                                                                        </span>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent side="top" className="max-w-xs">
                                                                        {transaction.memo}
                                                                    </TooltipContent>
                                                                </Tooltip>
                                                            </td>
                                                            <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">
                                                                {transaction.counterpart_account}
                                                            </td>
                                                            <td className="px-3 py-2 text-right tabular-nums whitespace-nowrap">
                                                                {Number(transaction.debit_amount) > 0 ? (
                                                                    <span className="text-red-500 font-medium">
                                                                        ${formatCurrency(transaction.debit_amount)}
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-muted-foreground/40">-</span>
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-2 text-right tabular-nums whitespace-nowrap">
                                                                {Number(transaction.credit_amount) > 0 ? (
                                                                    <span className="text-green-500 font-medium">
                                                                        ${formatCurrency(transaction.credit_amount)}
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-muted-foreground/40">-</span>
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

                            {/* Footer info */}
                            <div className="flex items-center justify-between text-xs text-muted-foreground pt-2 border-t">
                                <span>Creado por: {batchDetail.user || 'Sistema'}</span>
                            </div>
                        </div>
                    ) : null}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
