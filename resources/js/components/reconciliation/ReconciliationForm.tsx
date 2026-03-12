import { useCallback, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
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
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    type BankAccount,
    type Branch,
    type ReconciliationResult,
} from '@/types';
import {
    AlertCircle,
    Building2,
    Calendar,
    CheckCircle2,
    Circle,
    DollarSign,
    FileSpreadsheet,
    Landmark,
    Loader2,
    Search,
    Sparkles,
    Upload,
    X,
} from 'lucide-react';
import ReconciliationReport from '@/components/reconciliation/ReconciliationReport';

type ValidationStatus = 'idle' | 'processing' | 'complete' | 'error';

type ProcessStepStatus = 'pending' | 'active' | 'complete' | 'error';

interface ProcessStep {
    id: string;
    label: string;
    status: ProcessStepStatus;
    detail?: string;
}

const INITIAL_STEPS: ProcessStep[] = [
    { id: 'analyze', label: 'Analizando estructura del archivo...', status: 'pending' },
    { id: 'parse', label: 'Parseando movimientos del extracto...', status: 'pending' },
    { id: 'sap', label: 'Consultando movimientos en SAP...', status: 'pending' },
    { id: 'reconcile', label: 'Ejecutando conciliacion...', status: 'pending' },
    { id: 'done', label: 'Conciliacion completada', status: 'pending' },
];

const STEP_DELAY_MS = 400;

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
}

export default function ReconciliationForm({ branches, bankAccounts }: Props) {
    // Selection state
    const [selectedBranch, setSelectedBranch] = useState<string>('');
    const [selectedBankAccount, setSelectedBankAccount] = useState<string>('');
    const [dateFrom, setDateFrom] = useState<string>('');
    const [dateTo, setDateTo] = useState<string>('');
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [manualOpeningBalance, setManualOpeningBalance] = useState<string>('');
    const [manualClosingBalance, setManualClosingBalance] = useState<string>('');

    // Processing state
    const [status, setStatus] = useState<ValidationStatus>('idle');
    const [progress, setProgress] = useState(0);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    // Process steps state
    const [steps, setSteps] = useState<ProcessStep[]>(INITIAL_STEPS);

    // Result state
    const [result, setResult] = useState<ReconciliationResult | null>(null);

    // Filter accounts by branch
    const filteredBankAccounts = useMemo(() => {
        if (!selectedBranch) return [];
        return bankAccounts.filter(
            (account) => account.branch_id === Number(selectedBranch),
        );
    }, [selectedBranch, bankAccounts]);

    const csrfToken = useMemo(() => {
        return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
    }, []);

    const canStartProcess = useMemo(() => {
        return selectedBranch && selectedBankAccount && selectedFile && dateFrom && dateTo && status === 'idle';
    }, [selectedBranch, selectedBankAccount, selectedFile, dateFrom, dateTo, status]);

    const updateStep = useCallback((index: number, stepStatus: ProcessStepStatus, detail?: string) => {
        setSteps((prev) =>
            prev.map((step, i) => (i === index ? { ...step, status: stepStatus, detail: detail ?? step.detail } : step)),
        );
    }, []);

    const resetSteps = useCallback(() => {
        setSteps(INITIAL_STEPS.map((step) => ({ ...step, status: 'pending' as ProcessStepStatus })));
    }, []);

    const delay = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

    const handleBranchChange = (value: string) => {
        setSelectedBranch(value);
        setSelectedBankAccount('');
    };

    const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            const validTypes = [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
                'text/csv',
            ];
            if (!validTypes.includes(file.type) && !file.name.match(/\.(xlsx|xls|csv)$/i)) {
                setErrorMessage('Por favor selecciona un archivo Excel (.xlsx, .xls) o CSV.');
                return;
            }
            setSelectedFile(file);
            setErrorMessage(null);
        }
    };

    const handleClearFile = () => {
        setSelectedFile(null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleReset = () => {
        setStatus('idle');
        setProgress(0);
        setErrorMessage(null);
        setResult(null);
        resetSteps();
        handleClearFile();
    };

    const handleSubmit = async () => {
        if (!canStartProcess || !selectedFile) return;

        setStatus('processing');
        setProgress(0);
        setErrorMessage(null);
        setResult(null);
        resetSteps();

        try {
            // Step 1: Analyzing file structure
            updateStep(0, 'active');
            setProgress(5);
            await delay(STEP_DELAY_MS);

            const formData = new FormData();
            formData.append('file', selectedFile);
            formData.append('branch_id', selectedBranch);
            formData.append('bank_account_id', selectedBankAccount);
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);

            const response = await fetch('/reconciliation/validation/validate', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: formData,
            });

            if (!response.ok && !response.body) {
                throw new Error('Error al conectar con el servidor.');
            }

            // Read NDJSON stream
            const reader = response.body!.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let finalData: ReconciliationResult | null = null;

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';

                for (const line of lines) {
                    if (!line.trim()) continue;
                    try {
                        const event = JSON.parse(line);

                        switch (event.event) {
                            case 'step':
                                handleStepEvent(event);
                                break;

                            case 'complete':
                                finalData = event.data as ReconciliationResult;
                                break;

                            case 'error':
                                throw new Error(event.message || 'Error en el procesamiento.');
                        }
                    } catch (parseError) {
                        if (parseError instanceof SyntaxError) continue;
                        throw parseError;
                    }
                }
            }

            if (!finalData) {
                throw new Error('No se recibio respuesta del servidor.');
            }

            // Attach manual balances
            finalData.manual_opening_balance = manualOpeningBalance ? parseFloat(manualOpeningBalance) : null;
            finalData.manual_closing_balance = manualClosingBalance ? parseFloat(manualClosingBalance) : null;

            // Complete
            updateStep(4, 'complete');
            setProgress(100);
            setResult(finalData);
            await delay(STEP_DELAY_MS);
            setStatus('complete');
        } catch (error) {
            console.error('Validation error:', error);
            setErrorMessage(error instanceof Error ? error.message : 'Error desconocido.');
            setStatus('error');
        }
    };

    const handleStepEvent = (event: { step: number; message?: string; detail?: string }) => {
        const stepIndex = event.step - 1;
        if (stepIndex < 0 || stepIndex >= INITIAL_STEPS.length) return;

        // Mark previous steps as complete
        for (let i = 0; i < stepIndex; i++) {
            updateStep(i, 'complete');
        }

        // Mark current step as active
        updateStep(stepIndex, 'active', event.detail);
        setProgress(Math.round((stepIndex / INITIAL_STEPS.length) * 90) + 10);
    };

    // Render complete state - show report
    if (status === 'complete' && result) {
        return (
            <ReconciliationReport
                result={result}
                onNewValidation={handleReset}
                csrfToken={csrfToken}
            />
        );
    }

    // Render processing state
    if (status === 'processing') {
        return (
            <Card>
                <CardContent className="pt-6">
                    <div className="flex flex-col items-center justify-center space-y-6 py-8">
                        <div className="relative">
                            <Sparkles className="h-12 w-12 animate-pulse text-primary" />
                        </div>
                        <h3 className="text-lg font-semibold">Ejecutando conciliacion...</h3>

                        {/* Steps indicator */}
                        <div className="w-full max-w-md space-y-3">
                            {steps.map((step) => (
                                <div key={step.id} className="flex items-start gap-3">
                                    <div className="mt-0.5">
                                        {step.status === 'complete' && (
                                            <CheckCircle2 className="h-5 w-5 shrink-0 text-green-500" />
                                        )}
                                        {step.status === 'active' && (
                                            <Loader2 className="h-5 w-5 shrink-0 animate-spin text-primary" />
                                        )}
                                        {step.status === 'error' && (
                                            <AlertCircle className="h-5 w-5 shrink-0 text-red-500" />
                                        )}
                                        {step.status === 'pending' && (
                                            <Circle className="h-5 w-5 shrink-0 text-muted-foreground/50" />
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <span
                                            className={cn(
                                                'text-sm',
                                                step.status === 'active' && 'font-medium text-foreground',
                                                step.status === 'complete' && 'text-muted-foreground',
                                                step.status === 'error' && 'text-red-500',
                                                step.status === 'pending' && 'text-muted-foreground/50',
                                            )}
                                        >
                                            {step.label}
                                        </span>
                                        {step.detail && (
                                            <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                                {step.detail}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <Progress value={progress} className="w-full max-w-md" />
                        <p className="text-sm text-muted-foreground">{progress}% completado</p>
                    </div>
                </CardContent>
            </Card>
        );
    }

    return (
        <div className="space-y-4">
            {/* Error alert */}
            {errorMessage && (
                <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>Error</AlertTitle>
                    <AlertDescription>{errorMessage}</AlertDescription>
                </Alert>
            )}

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Search className="h-5 w-5" />
                        Parametros de Validacion
                    </CardTitle>
                    <CardDescription>
                        Selecciona la sucursal, cuenta bancaria, rango de fechas y sube el extracto bancario para ejecutar la conciliacion.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                    {/* Row 1: Branch and Bank Account */}
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="branch">
                                <Building2 className="mr-1 inline h-4 w-4" />
                                Sucursal
                            </Label>
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

                        <div className="space-y-2">
                            <Label htmlFor="bank-account">
                                <Landmark className="mr-1 inline h-4 w-4" />
                                Cuenta Bancaria
                            </Label>
                            <Select
                                value={selectedBankAccount}
                                onValueChange={setSelectedBankAccount}
                                disabled={!selectedBranch}
                            >
                                <SelectTrigger id="bank-account">
                                    <SelectValue placeholder={
                                        !selectedBranch
                                            ? 'Primero selecciona una sucursal'
                                            : filteredBankAccounts.length === 0
                                              ? 'No hay cuentas disponibles'
                                              : 'Selecciona una cuenta bancaria'
                                    } />
                                </SelectTrigger>
                                <SelectContent>
                                    {filteredBankAccounts.map((account) => (
                                        <SelectItem key={account.id} value={String(account.id)}>
                                            {account.name} ({account.account})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {/* Row 2: Date range */}
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="date-from">
                                <Calendar className="mr-1 inline h-4 w-4" />
                                Fecha Desde
                            </Label>
                            <Input
                                id="date-from"
                                type="date"
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                                placeholder="2025-01-01"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="date-to">
                                <Calendar className="mr-1 inline h-4 w-4" />
                                Fecha Hasta
                            </Label>
                            <Input
                                id="date-to"
                                type="date"
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                                placeholder="2025-01-31"
                            />
                        </div>
                    </div>

                    {/* Row 3: Manual balances (optional) */}
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="opening-balance">
                                <DollarSign className="mr-1 inline h-4 w-4" />
                                Saldo Inicial (Extracto Bancario)
                            </Label>
                            <Input
                                id="opening-balance"
                                type="number"
                                step="0.01"
                                value={manualOpeningBalance}
                                onChange={(e) => setManualOpeningBalance(e.target.value)}
                                placeholder="Opcional - ej: 5,454,702.84"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="closing-balance">
                                <DollarSign className="mr-1 inline h-4 w-4" />
                                Saldo Final (Extracto Bancario)
                            </Label>
                            <Input
                                id="closing-balance"
                                type="number"
                                step="0.01"
                                value={manualClosingBalance}
                                onChange={(e) => setManualClosingBalance(e.target.value)}
                                placeholder="Opcional - ej: 4,834,119.47"
                            />
                        </div>
                    </div>

                    {/* Row 4: File upload */}
                    <div className="space-y-2">
                        <Label htmlFor="file">
                            <FileSpreadsheet className="mr-1 inline h-4 w-4" />
                            Archivo de Extracto Bancario
                        </Label>
                        {selectedFile ? (
                            <div className="flex items-center gap-2 rounded-lg border bg-muted/50 p-3">
                                <FileSpreadsheet className="h-5 w-5 text-primary" />
                                <span className="flex-1 truncate text-sm">{selectedFile.name}</span>
                                <span className="text-xs text-muted-foreground">
                                    {(selectedFile.size / 1024).toFixed(1)} KB
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-6 w-6"
                                    onClick={handleClearFile}
                                >
                                    <X className="h-4 w-4" />
                                </Button>
                            </div>
                        ) : (
                            <div
                                className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-6 transition-colors hover:border-primary/50 hover:bg-muted/50"
                                onClick={() => fileInputRef.current?.click()}
                            >
                                <Upload className="h-8 w-8 text-muted-foreground" />
                                <p className="text-sm text-muted-foreground">
                                    Haz clic para seleccionar un archivo .xlsx, .xls o .csv
                                </p>
                            </div>
                        )}
                        <input
                            ref={fileInputRef}
                            id="file"
                            type="file"
                            className="hidden"
                            accept=".xlsx,.xls,.csv"
                            onChange={handleFileSelect}
                        />
                    </div>

                    {/* Submit button */}
                    <div className="flex justify-end">
                        <Button
                            onClick={handleSubmit}
                            disabled={!canStartProcess}
                            className="gap-2"
                        >
                            <Search className="h-4 w-4" />
                            Validar Conciliacion
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
