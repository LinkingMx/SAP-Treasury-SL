import { useCallback, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { FilterField, FiltersCard } from '@/components/page/filters-card';
import { InfoWidget } from '@/components/page/info-widget';
import {
    type BankAccount,
    type Branch,
    type ReconciliationResult,
} from '@/types';
import {
    AlertCircle,
    CheckCircle2,
    Circle,
    Filter,
    ListChecks,
    Loader2,
    Search,
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
    { id: 'reconcile', label: 'Ejecutando conciliación...', status: 'pending' },
    { id: 'done', label: 'Conciliación completada', status: 'pending' },
];

const STEP_DELAY_MS = 400;

const STEP_DETAILS: { id: string; title: string; description: string }[] = [
    {
        id: 'analyze',
        title: 'Análisis del archivo',
        description:
            'Se inspecciona la estructura del Excel/CSV para detectar columnas (fecha, descripción, cargo, abono) y rango de movimientos.',
    },
    {
        id: 'parse',
        title: 'Parseo de movimientos',
        description:
            'Cada fila del extracto se convierte a un movimiento normalizado, descartando filas vacías y registrando errores de formato.',
    },
    {
        id: 'sap',
        title: 'Consulta a SAP',
        description:
            'Se piden a SAP Business One los movimientos contables de la cuenta seleccionada para el rango de fechas indicado.',
    },
    {
        id: 'reconcile',
        title: 'Conciliación',
        description:
            'Se cruzan extracto y SAP por fecha + monto + referencia. El resultado incluye matches exactos, parciales y partidas no conciliadas.',
    },
];

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
}

export default function ReconciliationForm({ branches, bankAccounts }: Props) {
    const [selectedBranch, setSelectedBranch] = useState<string>('');
    const [selectedBankAccount, setSelectedBankAccount] = useState<string>('');
    const [dateFrom, setDateFrom] = useState<string>('');
    const [dateTo, setDateTo] = useState<string>('');
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [manualOpeningBalance, setManualOpeningBalance] = useState<string>('');
    const [manualClosingBalance, setManualClosingBalance] = useState<string>('');

    const [status, setStatus] = useState<ValidationStatus>('idle');
    const [progress, setProgress] = useState(0);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const [steps, setSteps] = useState<ProcessStep[]>(INITIAL_STEPS);

    const [result, setResult] = useState<ReconciliationResult | null>(null);

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
                throw new Error('No se recibió respuesta del servidor.');
            }

            finalData.manual_opening_balance = manualOpeningBalance ? parseFloat(manualOpeningBalance) : null;
            finalData.manual_closing_balance = manualClosingBalance ? parseFloat(manualClosingBalance) : null;

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

        for (let i = 0; i < stepIndex; i++) {
            updateStep(i, 'complete');
        }

        updateStep(stepIndex, 'active', event.detail);
        setProgress(Math.round((stepIndex / INITIAL_STEPS.length) * 90) + 10);
    };

    // Complete — delegate to report
    if (status === 'complete' && result) {
        return (
            <ReconciliationReport
                result={result}
                onNewValidation={handleReset}
                csrfToken={csrfToken}
            />
        );
    }

    // Processing state
    if (status === 'processing') {
        return (
            <Card>
                <CardContent className="py-10">
                    <div className="mx-auto flex max-w-md flex-col items-center gap-6">
                        <Loader2 className="h-10 w-10 animate-spin text-primary" />
                        <h3 className="text-lg font-semibold">Ejecutando conciliación...</h3>

                        <div className="w-full space-y-2">
                            {steps.map((step) => (
                                <div
                                    key={step.id}
                                    className="flex items-start gap-3 rounded-md bg-muted/40 px-3 py-2"
                                >
                                    <div className="mt-0.5">
                                        {step.status === 'complete' && (
                                            <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-500" />
                                        )}
                                        {step.status === 'active' && (
                                            <Loader2 className="h-4 w-4 shrink-0 animate-spin text-primary" />
                                        )}
                                        {step.status === 'error' && (
                                            <AlertCircle className="h-4 w-4 shrink-0 text-destructive" />
                                        )}
                                        {step.status === 'pending' && (
                                            <Circle className="h-4 w-4 shrink-0 text-muted-foreground/40" />
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <span
                                            className={cn(
                                                'text-sm',
                                                step.status === 'active' && 'font-medium text-foreground',
                                                step.status === 'complete' && 'text-muted-foreground',
                                                step.status === 'error' && 'text-destructive',
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

                        <div className="w-full space-y-1.5">
                            <Progress value={progress} className="h-1.5" />
                            <p className="text-right text-xs tabular-nums text-muted-foreground">
                                {progress}% completado
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        );
    }

    // Idle — form
    return (
        <div className="space-y-4">
            {errorMessage && (
                <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>Error</AlertTitle>
                    <AlertDescription>{errorMessage}</AlertDescription>
                </Alert>
            )}

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

                    <FilterField label="Cuenta Bancaria" htmlFor="bank-account">
                        <Select
                            value={selectedBankAccount}
                            onValueChange={setSelectedBankAccount}
                            disabled={!selectedBranch}
                        >
                            <SelectTrigger id="bank-account">
                                <SelectValue
                                    placeholder={
                                        !selectedBranch
                                            ? 'Primero selecciona una sucursal'
                                            : filteredBankAccounts.length === 0
                                              ? 'No hay cuentas disponibles'
                                              : 'Selecciona una cuenta bancaria'
                                    }
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {filteredBankAccounts.map((account) => (
                                    <SelectItem key={account.id} value={String(account.id)}>
                                        {account.name} ({account.account})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FilterField>

                    <FilterField label="Fecha Desde" htmlFor="date-from">
                        <Input
                            id="date-from"
                            type="date"
                            value={dateFrom}
                            onChange={(e) => setDateFrom(e.target.value)}
                        />
                    </FilterField>

                    <FilterField label="Fecha Hasta" htmlFor="date-to">
                        <Input
                            id="date-to"
                            type="date"
                            value={dateTo}
                            onChange={(e) => setDateTo(e.target.value)}
                        />
                    </FilterField>

                    <FilterField label="Saldo Inicial (opcional)" htmlFor="opening-balance">
                        <Input
                            id="opening-balance"
                            type="number"
                            step="0.01"
                            value={manualOpeningBalance}
                            onChange={(e) => setManualOpeningBalance(e.target.value)}
                            placeholder="ej: 5,454,702.84"
                        />
                    </FilterField>

                    <FilterField label="Saldo Final (opcional)" htmlFor="closing-balance">
                        <Input
                            id="closing-balance"
                            type="number"
                            step="0.01"
                            value={manualClosingBalance}
                            onChange={(e) => setManualClosingBalance(e.target.value)}
                            placeholder="ej: 4,834,119.47"
                        />
                    </FilterField>

                    <div className="space-y-2 md:col-span-2">
                        <Label
                            htmlFor="file"
                            className="text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                        >
                            Archivo de Extracto Bancario
                        </Label>
                        <input
                            ref={fileInputRef}
                            id="file"
                            type="file"
                            accept=".xlsx,.xls,.csv"
                            className="sr-only"
                            onChange={handleFileSelect}
                        />
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            className="flex h-10 w-full items-center justify-center gap-2 rounded-md border border-dashed border-input bg-background px-3 text-sm text-muted-foreground transition-colors hover:bg-muted/50"
                        >
                            <Upload className="h-4 w-4" />
                            <span className="truncate">
                                {selectedFile
                                    ? `${selectedFile.name} · ${(selectedFile.size / 1024).toFixed(1)} KB`
                                    : 'Seleccionar archivo Excel o CSV'}
                            </span>
                            {selectedFile ? (
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
                            Formatos soportados: Excel (.xlsx, .xls) y CSV.
                        </p>
                    </div>

                    <div className="flex justify-end pt-1 md:col-span-2">
                        <Button onClick={handleSubmit} disabled={!canStartProcess}>
                            <Search className="h-4 w-4" />
                            Validar Conciliación
                        </Button>
                    </div>
                </FiltersCard>

                <InfoWidget
                    title="Qué pasará al validar"
                    icon={ListChecks}
                    footer="El proceso toma entre 5 y 30 segundos."
                >
                    <Accordion type="single" collapsible className="w-full">
                        {STEP_DETAILS.map((step, i) => (
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
        </div>
    );
}
