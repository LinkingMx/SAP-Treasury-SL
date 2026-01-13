import { useMemo, useRef, useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import AppLayout from '@/layouts/app-layout';
import { tesoreria } from '@/routes';
import {
    type BankAccount,
    type BatchResult,
    type Branch,
    type BreadcrumbItem,
    type ImportError,
} from '@/types';
import { Head } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Download, FileSpreadsheet, Loader2, Upload, X } from 'lucide-react';

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

    const filteredBankAccounts = useMemo(() => {
        if (!selectedBranch) return [];
        return bankAccounts.filter((account) => account.branch_id === Number(selectedBranch));
    }, [selectedBranch, bankAccounts]);

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
            const data = await response.json();
            setUploadProgress(100);

            if (response.ok && data.success) {
                setUploadStatus('success');
                setSuccessResult(data.batch);
                setSelectedFile(null);
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            } else {
                setUploadStatus('error');
                setErrors(data.errors || []);
            }
        } catch {
            setUploadStatus('error');
            setErrors([{ row: 0, error: 'Error de conexión. Intente nuevamente.' }]);
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
                                    <Label htmlFor="file">Archivo Excel</Label>
                                    <Input
                                        ref={fileInputRef}
                                        id="file"
                                        type="file"
                                        accept=".xlsx,.xls"
                                        onChange={handleFileChange}
                                        disabled={!selectedBankAccount || isUploading}
                                        className={selectedFile ? 'hidden' : ''}
                                    />
                                    {/* Selected File Badge */}
                                    {selectedFile && (
                                        <div className="flex items-center gap-2 rounded-md border bg-muted/50 p-3">
                                            <FileSpreadsheet className="h-5 w-5 text-green-600" />
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
                                                    size="icon"
                                                    className="h-8 w-8 shrink-0"
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
                                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                Procesando...
                                            </>
                                        ) : (
                                            <>
                                                <Upload className="mr-2 h-4 w-4" />
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
            </div>
        </AppLayout>
    );
}
