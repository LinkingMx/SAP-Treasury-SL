import { useCallback, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
import AccountCombobox, { SKIP_SAP_CODE } from '@/components/tesoreria/AccountCombobox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    type AnalyzeStructureResponse,
    type Bank,
    type BankAccount,
    type Branch,
    type ClassifiedTransaction,
    type ClassifyPreviewResponse,
    type ParseConfig,
    type SapAccount,
} from '@/types';
import {
    AlertCircle,
    AlertTriangle,
    ArrowDownCircle,
    ArrowUpCircle,
    Bot,
    Building2,
    CheckCircle2,
    Circle,
    FileSpreadsheet,
    Filter,
    Hash,
    Landmark,
    Loader2,
    Plus,
    RefreshCw,
    Save,
    Search,
    Sparkles,
    Upload,
    X,
    XCircle,
} from 'lucide-react';

type AiIngestStatus = 'idle' | 'analyzing' | 'classifying' | 'review' | 'saving' | 'complete' | 'error';

type ProcessStepStatus = 'pending' | 'active' | 'complete' | 'error';

interface ProcessStep {
    id: string;
    label: string;
    status: ProcessStepStatus;
}

const INITIAL_STEPS: ProcessStep[] = [
    { id: 'load', label: 'Cargando archivo', status: 'pending' },
    { id: 'structure', label: 'Analizando estructura con IA', status: 'pending' },
    { id: 'sap', label: 'Conectando a SAP', status: 'pending' },
    { id: 'classify', label: 'Clasificando transacciones', status: 'pending' },
    { id: 'preview', label: 'Preparando vista previa', status: 'pending' },
];

const STEP_DELAY_MS = 600;

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
    banks: Bank[];
    onBatchSaved?: () => void;
}

export default function AiIngest({ branches, bankAccounts, banks, onBatchSaved }: Props) {
    // Selection state
    const [selectedBranch, setSelectedBranch] = useState<string>('');
    const [selectedBankAccount, setSelectedBankAccount] = useState<string>('');
    const [selectedBank, setSelectedBank] = useState<string>('');
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    // Processing state
    const [status, setStatus] = useState<AiIngestStatus>('idle');
    const [progress, setProgress] = useState(0);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    // Data state
    const [parseConfig, setParseConfig] = useState<ParseConfig | null>(null);
    const [bankNameGuess, setBankNameGuess] = useState<string | null>(null);
    const [transactions, setTransactions] = useState<ClassifiedTransaction[]>([]);
    const [chartOfAccounts, setChartOfAccounts] = useState<SapAccount[]>([]);
    const [summary, setSummary] = useState<{
        total_records: number;
        total_debit: string;
        total_credit: string;
        unclassified_count: number;
    } | null>(null);
    const [savedRules, setSavedRules] = useState<Set<number>>(new Set());
    const [savingRule, setSavingRule] = useState<number | null>(null);
    const [isReclassifying, setIsReclassifying] = useState(false);

    // SAP connection state
    const [sapConnected, setSapConnected] = useState<boolean>(true);

    // Process steps state
    const [steps, setSteps] = useState<ProcessStep[]>(INITIAL_STEPS);

    // Filter state
    const [searchQuery, setSearchQuery] = useState('');
    const [confidenceFilter, setConfidenceFilter] = useState<string>('all');

    const filteredBankAccounts = useMemo(() => {
        if (!selectedBranch) return [];
        return bankAccounts.filter((account) => account.branch_id === Number(selectedBranch));
    }, [selectedBranch, bankAccounts]);

    const csrfToken = useMemo(() => {
        return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
    }, []);

    const canStartProcess = useMemo(() => {
        return selectedBranch && selectedBankAccount && selectedFile && status === 'idle';
    }, [selectedBranch, selectedBankAccount, selectedFile, status]);

    const hasUnclassified = useMemo(() => {
        // SKIP_SAP_CODE is a valid classification (intentionally not sent to SAP)
        return transactions.some((t) => !t.sap_account_code || t.sap_account_code === '');
    }, [transactions]);

    const filteredTransactions = useMemo(() => {
        return transactions.filter((t) => {
            // Search filter
            if (searchQuery) {
                const query = searchQuery.toLowerCase();
                const matchesMemo = t.memo.toLowerCase().includes(query);
                const matchesAccount = t.sap_account_code?.toLowerCase().includes(query) ||
                    t.sap_account_name?.toLowerCase().includes(query);
                if (!matchesMemo && !matchesAccount) return false;
            }

            // Confidence filter
            if (confidenceFilter !== 'all') {
                const isSkipSap = t.sap_account_code === SKIP_SAP_CODE;
                if (confidenceFilter === 'unclassified' && t.sap_account_code) return false;
                if (confidenceFilter === 'skip' && !isSkipSap) return false;
                if (confidenceFilter === 'rule' && (t.source !== 'rule' || isSkipSap)) return false;
                if (confidenceFilter === 'high' && (t.confidence < 90 || t.source === 'rule' || isSkipSap)) return false;
                if (confidenceFilter === 'medium' && (t.confidence < 70 || t.confidence >= 90 || t.source === 'rule' || isSkipSap)) return false;
                if (confidenceFilter === 'low' && (t.confidence >= 70 || !t.sap_account_code || isSkipSap)) return false;
            }

            return true;
        });
    }, [transactions, searchQuery, confidenceFilter]);

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

    const updateStep = useCallback((index: number, status: ProcessStepStatus) => {
        setSteps((prev) =>
            prev.map((step, i) => (i === index ? { ...step, status } : step))
        );
    }, []);

    const resetSteps = useCallback(() => {
        setSteps(INITIAL_STEPS.map((step) => ({ ...step, status: 'pending' })));
    }, []);

    const delay = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

    const handleReset = () => {
        setStatus('idle');
        setProgress(0);
        setErrorMessage(null);
        setParseConfig(null);
        setBankNameGuess(null);
        setTransactions([]);
        setChartOfAccounts([]);
        setSummary(null);
        setSapConnected(true);
        resetSteps();
        handleClearFile();
    };

    const startProcess = async () => {
        if (!selectedFile || !selectedBranch || !selectedBankAccount) return;

        setStatus('analyzing');
        setProgress(5);
        setErrorMessage(null);
        resetSteps();

        try {
            // Step 1: Load file
            updateStep(0, 'active');
            setProgress(10);
            await delay(STEP_DELAY_MS);

            const analyzeFormData = new FormData();
            analyzeFormData.append('file', selectedFile);

            // Step 2: Analyze structure
            updateStep(0, 'complete');
            updateStep(1, 'active');
            setProgress(25);

            const analyzeResponse = await fetch('/tesoreria/ai/analyze-structure', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: analyzeFormData,
            });

            const analyzeData: AnalyzeStructureResponse & { message?: string } = await analyzeResponse.json();

            if (!analyzeResponse.ok || !analyzeData.success) {
                throw new Error(analyzeData.message || 'Error al analizar la estructura del archivo.');
            }

            setParseConfig(analyzeData.parse_config);
            setBankNameGuess(analyzeData.bank_name_guess);
            await delay(STEP_DELAY_MS);

            // Step 3: Connect to SAP
            updateStep(1, 'complete');
            updateStep(2, 'active');
            setStatus('classifying');
            setProgress(45);

            const classifyFormData = new FormData();
            classifyFormData.append('file', selectedFile);
            classifyFormData.append('parse_config', JSON.stringify(analyzeData.parse_config));
            classifyFormData.append('branch_id', selectedBranch);

            const classifyResponse = await fetch('/tesoreria/ai/classify-preview', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: classifyFormData,
            });

            const classifyData: ClassifyPreviewResponse & { message?: string } = await classifyResponse.json();

            if (!classifyResponse.ok || !classifyData.success) {
                throw new Error(classifyData.message || 'Error al clasificar las transacciones.');
            }

            // Update SAP connection status and step indicator
            setSapConnected(classifyData.sap_connected);
            updateStep(2, classifyData.sap_connected ? 'complete' : 'error');
            await delay(STEP_DELAY_MS);

            // Step 4: Classify transactions
            updateStep(3, 'active');
            setProgress(65);
            await delay(STEP_DELAY_MS);

            // Step 5: Prepare preview
            updateStep(3, 'complete');
            updateStep(4, 'active');
            setProgress(90);

            setTransactions(classifyData.transactions.map(t => ({
                ...t,
                ai_suggested_account: t.sap_account_code,
                user_modified: false,
            })));
            setChartOfAccounts(classifyData.chart_of_accounts);
            setSummary(classifyData.summary);
            await delay(STEP_DELAY_MS);

            // Complete
            updateStep(4, 'complete');
            setProgress(100);
            await delay(STEP_DELAY_MS / 2);
            setStatus('review');

        } catch (error) {
            console.error('Process error:', error);
            setErrorMessage(error instanceof Error ? error.message : 'Error desconocido.');
            setStatus('error');
        }
    };

    const handleAccountChange = (sequence: number, accountCode: string, accountName: string) => {
        setTransactions((prev) =>
            prev.map((t) => {
                if (t.sequence === sequence) {
                    return {
                        ...t,
                        sap_account_code: accountCode,
                        sap_account_name: accountName,
                        confidence: 100,
                        source: 'manual' as const,
                        user_modified: true,
                    };
                }
                return t;
            })
        );
    };

    const handleSaveBatch = async () => {
        if (hasUnclassified) {
            setErrorMessage('Todas las transacciones deben tener una cuenta SAP asignada.');
            return;
        }

        setStatus('saving');
        setErrorMessage(null);

        try {
            const response = await fetch('/tesoreria/ai/save-batch', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    branch_id: selectedBranch,
                    bank_account_id: selectedBankAccount,
                    bank_id: selectedBank || null,
                    filename: selectedFile?.name || 'unknown.xlsx',
                    transactions: transactions.map((t) => ({
                        sequence: t.sequence,
                        due_date: t.due_date,
                        memo: t.memo,
                        debit_amount: t.debit_amount,
                        credit_amount: t.credit_amount,
                        sap_account_code: t.sap_account_code,
                        sap_account_name: t.sap_account_name,
                        ai_suggested_account: t.ai_suggested_account,
                    })),
                }),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Error al guardar el lote.');
            }

            setStatus('complete');
            onBatchSaved?.();

        } catch (error) {
            console.error('Save error:', error);
            setErrorMessage(error instanceof Error ? error.message : 'Error al guardar.');
            setStatus('review');
        }
    };

    const handleSaveRule = async (transaction: ClassifiedTransaction) => {
        if (!transaction.sap_account_code) return;

        setSavingRule(transaction.sequence);

        try {
            const response = await fetch('/tesoreria/ai/save-rule', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    memo: transaction.memo,
                    sap_account_code: transaction.sap_account_code,
                    sap_account_name: transaction.sap_account_name,
                }),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Error al guardar la regla.');
            }

            setSavedRules((prev) => new Set(prev).add(transaction.sequence));
        } catch (error) {
            console.error('Save rule error:', error);
            setErrorMessage(error instanceof Error ? error.message : 'Error al guardar regla.');
        } finally {
            setSavingRule(null);
        }
    };

    const handleReclassify = async () => {
        if (!selectedFile || !parseConfig) return;

        setIsReclassifying(true);
        setErrorMessage(null);

        try {
            const classifyFormData = new FormData();
            classifyFormData.append('file', selectedFile);
            classifyFormData.append('parse_config', JSON.stringify(parseConfig));
            classifyFormData.append('branch_id', selectedBranch);
            // Use full classification: rules + AI

            const classifyResponse = await fetch('/tesoreria/ai/classify-preview', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: classifyFormData,
            });

            const classifyData: ClassifyPreviewResponse & { message?: string } = await classifyResponse.json();

            if (!classifyResponse.ok || !classifyData.success) {
                throw new Error(classifyData.message || 'Error al reclasificar las transacciones.');
            }

            setTransactions(classifyData.transactions.map(t => ({
                ...t,
                ai_suggested_account: t.sap_account_code,
                user_modified: false,
            })));
            setChartOfAccounts(classifyData.chart_of_accounts);
            setSummary(classifyData.summary);
            setSavedRules(new Set());

        } catch (error) {
            console.error('Reclassify error:', error);
            setErrorMessage(error instanceof Error ? error.message : 'Error al reclasificar.');
        } finally {
            setIsReclassifying(false);
        }
    };

    const formatCurrency = (value: number | string | null): string => {
        if (value === null) return '-';
        const num = typeof value === 'string' ? parseFloat(value) : value;
        return num.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    const getConfidenceBadge = (confidence: number, source: string, accountCode: string | null) => {
        // Check if account exists in the loaded catalog
        const accountInCatalog = accountCode && chartOfAccounts.some((a) => a.code === accountCode);

        if (accountCode === SKIP_SAP_CODE) {
            return <Badge variant="outline" className="text-amber-500 border-amber-500">Omitir</Badge>;
        }
        if (source === 'rule') {
            // Rule found but account not verified in SAP catalog
            if (!accountInCatalog && accountCode) {
                return (
                    <Badge variant="secondary" className="bg-amber-600 text-white">
                        <AlertTriangle className="mr-1 h-3 w-3" />
                        Regla
                    </Badge>
                );
            }
            return <Badge variant="default" className="bg-green-600">Regla ({confidence}%)</Badge>;
        }
        if (confidence >= 90) {
            return <Badge variant="default" className="bg-green-600">IA ({confidence}%)</Badge>;
        }
        if (confidence >= 70) {
            return <Badge variant="secondary" className="bg-yellow-600 text-white">IA ({confidence}%)</Badge>;
        }
        if (confidence > 0) {
            return <Badge variant="destructive">IA ({confidence}%)</Badge>;
        }
        return <Badge variant="outline" className="text-red-500 border-red-500">Sin clasificar</Badge>;
    };

    // Render based on status
    if (status === 'complete') {
        return (
            <Card>
                <CardContent className="pt-6">
                    <Alert>
                        <CheckCircle2 className="h-4 w-4 text-green-500" />
                        <AlertTitle>Lote guardado exitosamente</AlertTitle>
                        <AlertDescription>
                            Las transacciones han sido guardadas. Puedes procesarlas a SAP desde la pestana de Historial.
                        </AlertDescription>
                    </Alert>
                    <Button className="mt-4" onClick={handleReset}>
                        <Upload className="mr-2 h-4 w-4" />
                        Cargar otro archivo
                    </Button>
                </CardContent>
            </Card>
        );
    }

    if (status === 'review' || status === 'saving') {
        const branchName = branches.find(b => b.id === Number(selectedBranch))?.name || '-';
        const bankName = banks.find(b => b.id === Number(selectedBank))?.name || bankNameGuess || 'No especificado';

        return (
            <div className="space-y-4">
                {/* Summary Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                            <FileSpreadsheet className="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 className="text-lg font-semibold">Revision de Transacciones</h2>
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Building2 className="h-3.5 w-3.5" />
                                <span>{branchName}</span>
                                <span className="text-muted-foreground/50">|</span>
                                <Landmark className="h-3.5 w-3.5" />
                                <span>{bankName}</span>
                            </div>
                        </div>
                    </div>
                    <Button variant="ghost" size="sm" onClick={handleReset}>
                        <X className="mr-2 h-4 w-4" />
                        Cancelar
                    </Button>
                </div>

                {/* Stats Cards */}
                <div className="grid grid-cols-4 gap-3">
                    <div className="flex items-center gap-3 rounded-lg border bg-card p-3">
                        <div className="flex h-9 w-9 items-center justify-center rounded-md bg-blue-500/10">
                            <Hash className="h-4 w-4 text-blue-500" />
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Registros</p>
                            <p className="text-lg font-semibold">{summary?.total_records}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 rounded-lg border bg-card p-3">
                        <div className="flex h-9 w-9 items-center justify-center rounded-md bg-red-500/10">
                            <ArrowUpCircle className="h-4 w-4 text-red-500" />
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Total Debito</p>
                            <p className="text-lg font-semibold text-red-500">${formatCurrency(summary?.total_debit || '0')}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 rounded-lg border bg-card p-3">
                        <div className="flex h-9 w-9 items-center justify-center rounded-md bg-green-500/10">
                            <ArrowDownCircle className="h-4 w-4 text-green-500" />
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Total Credito</p>
                            <p className="text-lg font-semibold text-green-500">${formatCurrency(summary?.total_credit || '0')}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3 rounded-lg border bg-card p-3">
                        <div className={`flex h-9 w-9 items-center justify-center rounded-md ${summary?.unclassified_count ? 'bg-amber-500/10' : 'bg-green-500/10'}`}>
                            {summary?.unclassified_count ? (
                                <XCircle className="h-4 w-4 text-amber-500" />
                            ) : (
                                <CheckCircle2 className="h-4 w-4 text-green-500" />
                            )}
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Sin clasificar</p>
                            <p className={`text-lg font-semibold ${summary?.unclassified_count ? 'text-amber-500' : 'text-green-500'}`}>
                                {summary?.unclassified_count || 0}
                            </p>
                        </div>
                    </div>
                </div>

                {/* SAP connection warning */}
                {!sapConnected && (
                    <Alert className="border-amber-500 bg-amber-500/10">
                        <AlertTriangle className="h-4 w-4 text-amber-500" />
                        <AlertTitle className="text-amber-500">Conexion a SAP no disponible</AlertTitle>
                        <AlertDescription>
                            No se pudo conectar a la base de datos SAP para obtener el catalogo de cuentas.
                            Las cuentas de las reglas aprendidas se muestran pero deben verificarse manualmente.
                        </AlertDescription>
                    </Alert>
                )}

                {/* Error message */}
                {errorMessage && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Error</AlertTitle>
                        <AlertDescription>{errorMessage}</AlertDescription>
                    </Alert>
                )}

                {/* Filters */}
                <div className="flex items-center gap-3">
                    <div className="relative flex-1 max-w-sm">
                        <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Buscar por descripcion o cuenta..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="pl-9 h-9"
                        />
                    </div>
                    <Select value={confidenceFilter} onValueChange={setConfidenceFilter}>
                        <SelectTrigger className="w-[180px] h-9">
                            <Filter className="mr-2 h-4 w-4 text-muted-foreground" />
                            <SelectValue placeholder="Filtrar por confianza" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos</SelectItem>
                            <SelectItem value="unclassified">Sin clasificar</SelectItem>
                            <SelectItem value="skip">Omitidos (No SAP)</SelectItem>
                            <SelectItem value="rule">Regla (100%)</SelectItem>
                            <SelectItem value="high">IA Alta (90%+)</SelectItem>
                            <SelectItem value="medium">IA Media (70-89%)</SelectItem>
                            <SelectItem value="low">IA Baja (&lt;70%)</SelectItem>
                        </SelectContent>
                    </Select>
                    {(searchQuery || confidenceFilter !== 'all') && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setSearchQuery('');
                                setConfidenceFilter('all');
                            }}
                            className="h-9"
                        >
                            <X className="mr-1 h-4 w-4" />
                            Limpiar
                        </Button>
                    )}
                    <span className="text-sm text-muted-foreground">
                        {filteredTransactions.length} de {transactions.length}
                    </span>
                </div>

                {/* Transactions Table */}
                <Card>
                    <CardContent className="pt-4">
                        <div className="max-h-[400px] overflow-y-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-12">#</TableHead>
                                        <TableHead className="w-24">Fecha</TableHead>
                                        <TableHead className="min-w-[200px]">Descripcion</TableHead>
                                        <TableHead className="w-28 text-right">Debito</TableHead>
                                        <TableHead className="w-28 text-right">Credito</TableHead>
                                        <TableHead className="min-w-[250px]">Cuenta SAP</TableHead>
                                        <TableHead className="w-32">Confianza</TableHead>
                                        <TableHead className="w-24">Acciones</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {filteredTransactions.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={8} className="h-24 text-center text-muted-foreground">
                                                No se encontraron transacciones con los filtros aplicados.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        filteredTransactions.map((t) => {
                                            const isSkipSap = t.sap_account_code === SKIP_SAP_CODE;
                                            return (
                                                <TableRow
                                                    key={t.sequence}
                                                    className={cn(
                                                        !t.sap_account_code && 'bg-red-50 dark:bg-red-950/20',
                                                        isSkipSap && 'bg-amber-50/50 dark:bg-amber-950/20'
                                                    )}
                                                >
                                                    <TableCell>{t.sequence}</TableCell>
                                                    <TableCell>{new Date(t.due_date).toLocaleDateString('es-MX')}</TableCell>
                                                    <TableCell className="max-w-[300px] truncate" title={t.memo}>
                                                        {t.memo}
                                                    </TableCell>
                                                    <TableCell className={cn('text-right', !isSkipSap && 'text-red-500')}>
                                                        {t.debit_amount ? `$${formatCurrency(t.debit_amount)}` : '-'}
                                                    </TableCell>
                                                    <TableCell className={cn('text-right', !isSkipSap && 'text-green-500')}>
                                                        {t.credit_amount ? `$${formatCurrency(t.credit_amount)}` : '-'}
                                                    </TableCell>
                                                    <TableCell>
                                                        <AccountCombobox
                                                            accounts={chartOfAccounts}
                                                            value={t.sap_account_code}
                                                            onChange={(code, name) => handleAccountChange(t.sequence, code, name)}
                                                            hasError={!t.sap_account_code}
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        {getConfidenceBadge(t.confidence, t.source, t.sap_account_code)}
                                                    </TableCell>
                                                    <TableCell>
                                                        {/* Allow saving rules for both SAP accounts and SKIP_SAP */}
                                                        {t.sap_account_code && !savedRules.has(t.sequence) ? (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => handleSaveRule(t)}
                                                                disabled={savingRule === t.sequence}
                                                                title={isSkipSap ? "Guardar regla para omitir" : "Guardar como regla"}
                                                            >
                                                                {savingRule === t.sequence ? (
                                                                    <Loader2 className="h-4 w-4 animate-spin" />
                                                                ) : (
                                                                    <Plus className={cn("h-4 w-4", isSkipSap && "text-amber-500")} />
                                                                )}
                                                            </Button>
                                                        ) : savedRules.has(t.sequence) ? (
                                                            <CheckCircle2 className={cn("h-4 w-4", isSkipSap ? "text-amber-500" : "text-green-500")} />
                                                        ) : null}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {/* Action Buttons */}
                <div className="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        onClick={handleReclassify}
                        disabled={isReclassifying || status === 'saving'}
                        size="lg"
                    >
                        {isReclassifying ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Reclasificando...
                            </>
                        ) : (
                            <>
                                <RefreshCw className="mr-2 h-4 w-4" />
                                Reclasificar con IA
                            </>
                        )}
                    </Button>
                    <Button
                        onClick={handleSaveBatch}
                        disabled={hasUnclassified || status === 'saving' || isReclassifying}
                        size="lg"
                    >
                        {status === 'saving' ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Guardando...
                            </>
                        ) : (
                            <>
                                <Save className="mr-2 h-4 w-4" />
                                Guardar Lote
                            </>
                        )}
                    </Button>
                </div>
            </div>
        );
    }

    // Processing states (analyzing, classifying)
    if (status === 'analyzing' || status === 'classifying') {
        return (
            <Card>
                <CardContent className="pt-6">
                    <div className="flex flex-col items-center justify-center py-8 space-y-6">
                        <div className="relative">
                            <Sparkles className="h-12 w-12 text-primary animate-pulse" />
                        </div>
                        <h3 className="text-lg font-semibold">Procesando archivo...</h3>

                        {/* Steps indicator */}
                        <div className="w-full max-w-sm space-y-3">
                            {steps.map((step) => (
                                <div key={step.id} className="flex items-center gap-3">
                                    {step.status === 'complete' && (
                                        <CheckCircle2 className="h-5 w-5 text-green-500 shrink-0" />
                                    )}
                                    {step.status === 'active' && (
                                        <Loader2 className="h-5 w-5 text-primary animate-spin shrink-0" />
                                    )}
                                    {step.status === 'error' && (
                                        <AlertTriangle className="h-5 w-5 text-amber-500 shrink-0" />
                                    )}
                                    {step.status === 'pending' && (
                                        <Circle className="h-5 w-5 text-muted-foreground/50 shrink-0" />
                                    )}
                                    <span
                                        className={cn(
                                            'text-sm',
                                            step.status === 'active' && 'font-medium text-foreground',
                                            step.status === 'complete' && 'text-muted-foreground',
                                            step.status === 'error' && 'text-amber-500',
                                            step.status === 'pending' && 'text-muted-foreground/50'
                                        )}
                                    >
                                        {step.label}
                                        {step.status === 'error' && ' (no disponible)'}
                                    </span>
                                </div>
                            ))}
                        </div>

                        <Progress value={progress} className="w-64" />
                        <p className="text-sm text-muted-foreground">{progress}%</p>
                    </div>
                </CardContent>
            </Card>
        );
    }

    // Error state
    if (status === 'error') {
        return (
            <Card>
                <CardContent className="pt-6">
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Error en el proceso</AlertTitle>
                        <AlertDescription>{errorMessage}</AlertDescription>
                    </Alert>
                    <Button className="mt-4" onClick={handleReset}>
                        <RefreshCw className="mr-2 h-4 w-4" />
                        Intentar de nuevo
                    </Button>
                </CardContent>
            </Card>
        );
    }

    // Idle state - Form
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Bot className="h-5 w-5" />
                    Ingesta Inteligente con IA
                </CardTitle>
                <CardDescription>
                    Sube el archivo de estado de cuenta del banco. La IA detectara automaticamente el formato y clasificara las transacciones.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {/* Branch and Account Selection */}
                <div className="grid md:grid-cols-3 gap-4">
                    <div className="space-y-2">
                        <Label>Sucursal</Label>
                        <Select value={selectedBranch} onValueChange={handleBranchChange}>
                            <SelectTrigger>
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
                        <Label>Cuenta Bancaria</Label>
                        <Select
                            value={selectedBankAccount}
                            onValueChange={setSelectedBankAccount}
                            disabled={!selectedBranch}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Selecciona una cuenta" />
                            </SelectTrigger>
                            <SelectContent>
                                {filteredBankAccounts.map((account) => (
                                    <SelectItem key={account.id} value={String(account.id)}>
                                        {account.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-2">
                        <Label>Banco (opcional)</Label>
                        <Select value={selectedBank} onValueChange={setSelectedBank}>
                            <SelectTrigger>
                                <SelectValue placeholder="Selecciona el banco" />
                            </SelectTrigger>
                            <SelectContent>
                                {banks.map((bank) => (
                                    <SelectItem key={bank.id} value={String(bank.id)}>
                                        {bank.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {/* File Upload */}
                <div className="space-y-2">
                    <Label>Archivo del Banco</Label>
                    <div className="flex items-center gap-2">
                        <Input
                            ref={fileInputRef}
                            type="file"
                            accept=".xlsx,.xls,.csv"
                            onChange={handleFileSelect}
                            className="hidden"
                            id="ai-file-input"
                        />
                        <Button
                            variant="outline"
                            onClick={() => fileInputRef.current?.click()}
                            className="flex-1"
                        >
                            <Upload className="mr-2 h-4 w-4" />
                            {selectedFile ? selectedFile.name : 'Seleccionar archivo Excel o CSV'}
                        </Button>
                        {selectedFile && (
                            <Button variant="ghost" size="icon" onClick={handleClearFile}>
                                <X className="h-4 w-4" />
                            </Button>
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Formatos soportados: Excel (.xlsx, .xls) y CSV. Archivos de Santander, Afirme y otros bancos.
                    </p>
                </div>

                {/* Error message */}
                {errorMessage && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>{errorMessage}</AlertDescription>
                    </Alert>
                )}

                {/* Process Button */}
                <Button
                    onClick={startProcess}
                    disabled={!canStartProcess}
                    className="w-full"
                    size="lg"
                >
                    <Sparkles className="mr-2 h-4 w-4" />
                    Analizar y Clasificar con IA
                </Button>
            </CardContent>
        </Card>
    );
}
