import { useMemo, useRef, useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { AlertCircle, CheckCircle2, Download, Loader2, Upload } from 'lucide-react';

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

export default function Tesoreria({ branches, bankAccounts }: Props) {
    const [selectedBranch, setSelectedBranch] = useState<string>('');
    const [selectedBankAccount, setSelectedBankAccount] = useState<string>('');
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [isUploading, setIsUploading] = useState(false);
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
        }
    };

    const handleUpload = async () => {
        if (!selectedBranch || !selectedBankAccount || !selectedFile) return;

        setIsUploading(true);
        setErrors([]);
        setSuccessResult(null);

        const formData = new FormData();
        formData.append('branch_id', selectedBranch);
        formData.append('bank_account_id', selectedBankAccount);
        formData.append('file', selectedFile);

        try {
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

            const data = await response.json();

            if (response.ok && data.success) {
                setSuccessResult(data.batch);
                setSelectedFile(null);
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            } else {
                setErrors(data.errors || []);
            }
        } catch {
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
                        <div className="grid gap-4 md:grid-cols-[1fr_auto]">
                            <div className="grid gap-2">
                                <Label htmlFor="file">Archivo Excel</Label>
                                <Input
                                    ref={fileInputRef}
                                    id="file"
                                    type="file"
                                    accept=".xlsx,.xls"
                                    onChange={handleFileChange}
                                    disabled={!selectedBankAccount}
                                />
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
