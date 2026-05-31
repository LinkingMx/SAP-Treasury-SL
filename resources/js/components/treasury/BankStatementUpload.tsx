import { useCallback, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { FilterField, FiltersCard } from '@/components/page/filters-card';
import { InfoWidget } from '@/components/page/info-widget';
import { PageSection } from '@/components/page/page-section';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { InfoCell, StatCard } from '@/components/page/detail-bits';
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
import {
    type AnalyzeStructureResponse,
    type BankAccount,
    type BankStatementHistory,
    type Branch,
    type ClassifiedTransaction,
    type ClassifyPreviewResponse,
} from '@/types';
import {
    AlertCircle,
    AlertTriangle,
    ArrowDownCircle,
    ArrowDownRight,
    ArrowUpCircle,
    ArrowUpRight,
    Building2,
    Calendar,
    CheckCircle2,
    Circle,
    Eye,
    FileSpreadsheet,
    Filter,
    Hash,
    Landmark,
    ListChecks,
    Loader2,
    RefreshCw,
    Send,
    Sparkles,
    Trash2,
    Upload,
    X,
} from 'lucide-react';

const STEP_DETAILS: { id: string; title: string; description: string }[] = [
    {
        id: 'load',
        title: 'Carga del archivo',
        description:
            'El archivo Excel o CSV se sube al servidor en formato binario. Se valida tamaño y extensión antes de procesar.',
    },
    {
        id: 'structure',
        title: 'Análisis de estructura con IA',
        description:
            'La IA detecta qué columnas contienen fecha, descripción, cargo y abono — sin importar el formato del banco.',
    },
    {
        id: 'extract',
        title: 'Extracción de transacciones',
        description:
            'Las filas se convierten a movimientos normalizados con fecha + monto + tipo (cargo/abono). Filas inválidas se reportan.',
    },
    {
        id: 'review',
        title: 'Revisión manual',
        description:
            'Antes de enviar a SAP, ves la lista clasificada y puedes ajustar o descartar movimientos detectados como duplicados.',
    },
    {
        id: 'send',
        title: 'Envío a SAP',
        description:
            'Se publica al endpoint BankStatements de SAP Business One vía Service Layer. Recibes el DocEntry como confirmación.',
    },
];

type UploadStatus = 'idle' | 'analyzing' | 'classifying' | 'review' | 'sending' | 'complete' | 'error';

type ProcessStepStatus = 'pending' | 'active' | 'complete' | 'error';

interface ProcessStep {
    id: string;
    label: string;
    status: ProcessStepStatus;
    detail?: string;
}

const INITIAL_STEPS: ProcessStep[] = [
    { id: 'load', label: 'Cargando archivo', status: 'pending' },
    { id: 'structure', label: 'Analizando estructura con IA', status: 'pending' },
    { id: 'extract', label: 'Extrayendo transacciones', status: 'pending' },
];

const STEP_DELAY_MS = 600;

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
    onStatementSent?: () => void;
}

export default function BankStatementUpload({ branches, bankAccounts, onStatementSent }: Props) {
    // Selection state
    const [selectedBranch, setSelectedBranch] = useState<string>('');
    const [selectedBankAccount, setSelectedBankAccount] = useState<string>('');
    const [statementDate, setStatementDate] = useState<string>(() => {
        const today = new Date();
        return today.toISOString().split('T')[0];
    });
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    // Processing state
    const [status, setStatus] = useState<UploadStatus>('idle');
    const [progress, setProgress] = useState(0);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    // Data state
    const [transactions, setTransactions] = useState<ClassifiedTransaction[]>([]);
    const [summary, setSummary] = useState<{
        total_records: number;
        total_debit: string;
        total_credit: string;
        unclassified_count: number;
    } | null>(null);

    // Process steps state
    const [steps, setSteps] = useState<ProcessStep[]>(INITIAL_STEPS);

    // Result state
    const [sendResult, setSendResult] = useState<{
        statement_number: string;
        sap_doc_entry: number | null;
    } | null>(null);

    // History state
    const [history, setHistory] = useState<BankStatementHistory[]>([]);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [reprocessingId, setReprocessingId] = useState<number | null>(null);

    // Delete from SAP state
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [statementToDelete, setStatementToDelete] = useState<BankStatementHistory | null>(null);

    // Duplicate check state
    const [duplicateCheckLoading, setDuplicateCheckLoading] = useState(false);
    const [duplicateDialogOpen, setDuplicateDialogOpen] = useState(false);
    const [duplicateData, setDuplicateData] = useState<{
        duplicates: Array<{
            index: number;
            due_date: string;
            memo: string;
            debit_amount: number;
            credit_amount: number;
            existing_statement_number: string;
            existing_filename: string;
        }>;
        matched_statements: Array<{
            statement_id: number;
            statement_number: string;
            statement_date: string;
            original_filename: string;
        }>;
    } | null>(null);

    // Detail modal state
    const [detailOpen, setDetailOpen] = useState(false);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailData, setDetailData] = useState<{
        statement_number: string;
        statement_date: string;
        original_filename: string;
        rows_count: number;
        status: string;
        status_label: string;
        sap_doc_entry: number | null;
        sap_error: string | null;
        branch: { name: string };
        bank_account: { name: string; sap_bank_key: string };
        user: { name: string };
        rows: Array<{
            DueDate: string;
            Details?: string;
            PaymentReference?: string;
            Memo?: string;
            Debit?: number;
            Credit?: number;
            DebitAmount?: string | number;
            CreditAmount?: string | number;
            AccountCode?: string;
            DocNumberType?: string;
            sap_sequence?: number | null;
            sap_error?: string | null;
        }>;
    } | null>(null);

    // Filter accounts with sap_bank_key
    const filteredBankAccounts = useMemo(() => {
        if (!selectedBranch) return [];
        return bankAccounts.filter(
            (account) => account.branch_id === Number(selectedBranch) && account.sap_bank_key
        );
    }, [selectedBranch, bankAccounts]);

    const csrfToken = useMemo(() => {
        return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
    }, []);

    const canStartProcess = useMemo(() => {
        return selectedBranch && selectedBankAccount && selectedFile && statementDate && status === 'idle';
    }, [selectedBranch, selectedBankAccount, selectedFile, statementDate, status]);

    const selectedBankAccountData = useMemo(() => {
        return bankAccounts.find((a) => a.id === Number(selectedBankAccount));
    }, [bankAccounts, selectedBankAccount]);

    const handleBranchChange = (value: string) => {
        setSelectedBranch(value);
        setSelectedBankAccount('');
        // Fetch history when branch changes
        if (value) {
            fetchHistory(value);
        } else {
            setHistory([]);
        }
    };

    const handleBankAccountChange = (value: string) => {
        setSelectedBankAccount(value);
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

    // Progress info state
    const [progressInfo, setProgressInfo] = useState<{
        bankName?: string;
        columnDescription?: string;
        totalChunks?: number;
        currentChunk?: number;
        extractedCount?: number;
        totalLines?: number;
        dataLines?: number;
    }>({});

    const updateStep = useCallback((index: number, status: ProcessStepStatus, detail?: string) => {
        setSteps((prev) =>
            prev.map((step, i) => (i === index ? { ...step, status, detail: detail ?? step.detail } : step))
        );
    }, []);

    const resetSteps = useCallback(() => {
        setSteps(INITIAL_STEPS.map((step) => ({ ...step, status: 'pending' })));
    }, []);

    const delay = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

    const fetchHistory = async (branchId: string) => {
        setHistoryLoading(true);
        try {
            const response = await fetch(`/treasury/bank-statements/history?branch_id=${branchId}`, {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });

            if (response.ok) {
                const data = await response.json();
                setHistory(data.history || []);
            }
        } catch (error) {
            console.error('Error fetching history:', error);
        } finally {
            setHistoryLoading(false);
        }
    };

    const handleReset = () => {
        setStatus('idle');
        setProgress(0);
        setErrorMessage(null);
        setTransactions([]);
        setSummary(null);
        setSendResult(null);
        setProgressInfo({});
        resetSteps();
        handleClearFile();
    };

    const startProcess = async () => {
        if (!selectedFile || !selectedBranch || !selectedBankAccount || !statementDate) return;

        setStatus('analyzing');
        setProgress(5);
        setErrorMessage(null);
        setProgressInfo({});
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
            setProgress(15);

            const analyzeResponse = await fetch('/treasury/bank-statements/analyze', {
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

            // Show detected bank info
            setProgressInfo((prev) => ({
                ...prev,
                bankName: analyzeData.bank_name_guess,
                columnDescription: analyzeData.parse_config?.column_description,
            }));
            updateStep(1, 'complete', `Banco: ${analyzeData.bank_name_guess}`);
            await delay(STEP_DELAY_MS);

            // Step 3: Extract & classify via streaming
            updateStep(2, 'active');
            setStatus('classifying');
            setProgress(25);

            const previewFormData = new FormData();
            previewFormData.append('file', selectedFile);
            previewFormData.append('parse_config', JSON.stringify(analyzeData.parse_config));
            previewFormData.append('branch_id', selectedBranch);

            const previewResponse = await fetch('/treasury/bank-statements/preview', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: previewFormData,
            });

            if (!previewResponse.ok && !previewResponse.body) {
                throw new Error('Error al conectar con el servidor.');
            }

            // Read NDJSON stream
            const reader = previewResponse.body!.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let finalData: (ClassifyPreviewResponse & { message?: string }) | null = null;

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
                            case 'extraction_start':
                                setProgressInfo((prev) => ({
                                    ...prev,
                                    totalChunks: event.total_chunks,
                                    totalLines: event.total_lines,
                                    dataLines: event.data_lines,
                                    columnDescription: event.column_description || prev.columnDescription,
                                }));
                                updateStep(2, 'active', `${event.total_chunks} fragmento(s) por procesar`);
                                break;

                            case 'chunk_progress':
                                setProgressInfo((prev) => ({ ...prev, currentChunk: event.current }));
                                updateStep(2, 'active', `Procesando fragmento ${event.current} de ${event.total}...`);
                                setProgress(25 + Math.round(((event.current - 1) / event.total) * 50));
                                break;

                            case 'chunk_done':
                                setProgressInfo((prev) => ({ ...prev, extractedCount: event.extracted }));
                                updateStep(2, 'active', `Fragmento ${event.current}/${event.total} listo (${event.extracted} transacciones)`);
                                setProgress(25 + Math.round((event.current / event.total) * 50));
                                break;

                            case 'complete':
                                finalData = event as ClassifyPreviewResponse & { message?: string };
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

            if (!finalData || !finalData.success) {
                throw new Error(finalData?.message || 'No se recibio respuesta del servidor.');
            }

            // Extraction complete — show preview
            updateStep(2, 'complete');
            setProgress(100);

            setTransactions(finalData.transactions.map((t) => ({
                ...t,
                ai_suggested_account: t.sap_account_code,
                user_modified: false,
            })));
            setSummary(finalData.summary);
            await delay(STEP_DELAY_MS / 2);
            setStatus('review');

        } catch (error) {
            console.error('Process error:', error);
            setErrorMessage(error instanceof Error ? error.message : 'Error desconocido.');
            setStatus('error');
        }
    };

    const handleSendToSap = async () => {
        if (!selectedBranch || !selectedBankAccount || !statementDate || transactions.length === 0) return;

        // First check for duplicates
        setDuplicateCheckLoading(true);
        setErrorMessage(null);

        try {
            const checkResponse = await fetch('/treasury/bank-statements/check-duplicates', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    bank_account_id: selectedBankAccount,
                    transactions: transactions.map((t) => ({
                        due_date: t.due_date,
                        memo: t.memo,
                        debit_amount: t.debit_amount,
                        credit_amount: t.credit_amount,
                    })),
                }),
            });

            const checkData = await checkResponse.json();

            if (checkResponse.ok && checkData.has_duplicates) {
                setDuplicateData({
                    duplicates: checkData.duplicates,
                    matched_statements: checkData.matched_statements,
                });
                setDuplicateDialogOpen(true);
                setDuplicateCheckLoading(false);
                return;
            }
        } catch (error) {
            console.error('Duplicate check error:', error);
            // If check fails, proceed with send anyway (non-blocking)
        } finally {
            setDuplicateCheckLoading(false);
        }

        // No duplicates found, proceed with send
        await executeSendToSap();
    };

    const executeSendToSap = async (transactionsToSend?: typeof transactions) => {
        if (!selectedBranch || !selectedBankAccount || !statementDate) return;

        const txToSend = transactionsToSend || transactions;
        if (txToSend.length === 0) return;

        setStatus('sending');
        setErrorMessage(null);
        setDuplicateDialogOpen(false);

        try {
            const response = await fetch('/treasury/bank-statements/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    branch_id: selectedBranch,
                    bank_account_id: selectedBankAccount,
                    statement_date: statementDate,
                    filename: selectedFile?.name || 'unknown.xlsx',
                    transactions: txToSend.map((t) => ({
                        due_date: t.due_date,
                        memo: t.memo,
                        debit_amount: t.debit_amount,
                        credit_amount: t.credit_amount,
                    })),
                }),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Error al enviar a SAP.');
            }

            setSendResult({
                statement_number: data.bank_statement.statement_number,
                sap_doc_entry: data.bank_statement.sap_doc_entry,
            });
            setStatus('complete');

            // Refresh history
            fetchHistory(selectedBranch);
            onStatementSent?.();

        } catch (error) {
            console.error('Send error:', error);
            setErrorMessage(error instanceof Error ? error.message : 'Error al enviar.');
            setStatus('review');
        }
    };

    const handleSendWithoutDuplicates = () => {
        if (!duplicateData) return;
        const duplicateIndices = new Set(duplicateData.duplicates.map((d) => d.index));
        const filtered = transactions.filter((_, i) => !duplicateIndices.has(i));
        executeSendToSap(filtered);
    };

    const formatCurrency = (value: number | string | null | undefined): string => {
        if (value === null || value === undefined) return '-';
        const num = typeof value === 'string' ? parseFloat(value) : value;
        if (isNaN(num)) return '-';
        return num.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    const getStatusBadge = (status: string) => {
        switch (status) {
            case 'sent':
                return <Badge variant="default" className="bg-green-600">Enviado</Badge>;
            case 'failed':
                return <Badge variant="destructive">Fallido</Badge>;
            case 'cancelled':
                return <Badge variant="outline" className="border-orange-500 text-orange-600">Cancelado en SAP</Badge>;
            default:
                return <Badge variant="secondary">Pendiente</Badge>;
        }
    };

    const handleViewDetail = async (statementId: number) => {
        setDetailOpen(true);
        setDetailLoading(true);
        setDetailData(null);

        try {
            const response = await fetch(`/treasury/bank-statements/${statementId}`, {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
            });

            if (response.ok) {
                const data = await response.json();
                setDetailData(data.bank_statement);
            } else {
                setDetailOpen(false);
                setErrorMessage('Error al cargar el detalle del extracto.');
            }
        } catch (error) {
            console.error('Error fetching detail:', error);
            setDetailOpen(false);
            setErrorMessage('Error de conexion al cargar el detalle.');
        } finally {
            setDetailLoading(false);
        }
    };

    const handleReprocess = async (statementId: number) => {
        setReprocessingId(statementId);
        try {
            const response = await fetch(`/treasury/bank-statements/${statementId}/reprocess`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
            });

            const data = await response.json();

            if (response.ok && data.success) {
                // Refresh history
                fetchHistory(selectedBranch);
            } else {
                setErrorMessage(data.message || 'Error al reprocesar el extracto.');
            }
        } catch (error) {
            console.error('Reprocess error:', error);
            setErrorMessage('Error de conexion al reprocesar.');
        } finally {
            setReprocessingId(null);
        }
    };

    const handleDeleteFromSap = async () => {
        if (!statementToDelete) return;

        setDeleteDialogOpen(false);
        setDeletingId(statementToDelete.id);

        try {
            const response = await fetch(`/treasury/bank-statements/${statementToDelete.id}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
            });

            const data = await response.json();

            if (response.ok && data.success) {
                fetchHistory(selectedBranch);
            } else {
                setErrorMessage(data.message || 'Error al eliminar de SAP.');
            }
        } catch (error) {
            console.error('Delete from SAP error:', error);
            setErrorMessage('Error de conexion al eliminar de SAP.');
        } finally {
            setDeletingId(null);
            setStatementToDelete(null);
        }
    };

    // Render based on status
    if (status === 'complete') {
        return (
            <Card>
                <CardContent className="pt-6">
                    <Alert>
                        <CheckCircle2 className="h-4 w-4 text-green-500" />
                        <AlertTitle>Extracto enviado a SAP exitosamente</AlertTitle>
                        <AlertDescription>
                            <div className="mt-2 space-y-1">
                                <p><strong>Numero de extracto:</strong> {sendResult?.statement_number}</p>
                                {sendResult?.sap_doc_entry && (
                                    <p><strong>DocEntry SAP:</strong> {sendResult.sap_doc_entry}</p>
                                )}
                            </div>
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

    if (status === 'review' || status === 'sending') {
        const branchName = branches.find((b) => b.id === Number(selectedBranch))?.name || '-';
        const bankAccountName = selectedBankAccountData?.name || '-';

        return (
            <div className="space-y-4">
                {/* Summary Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                            <FileSpreadsheet className="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <h2 className="text-lg font-semibold">Vista Previa - Extracto Bancario SAP</h2>
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Building2 className="h-3.5 w-3.5" />
                                <span>{branchName}</span>
                                <span className="text-muted-foreground/50">|</span>
                                <Landmark className="h-3.5 w-3.5" />
                                <span>{bankAccountName}</span>
                                <span className="text-muted-foreground/50">|</span>
                                <Calendar className="h-3.5 w-3.5" />
                                <span>{statementDate}</span>
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
                        <div className="flex h-9 w-9 items-center justify-center rounded-md bg-primary/10">
                            <Landmark className="h-4 w-4 text-primary" />
                        </div>
                        <div>
                            <p className="text-xs text-muted-foreground">Clave SAP</p>
                            <p className="text-lg font-semibold">{selectedBankAccountData?.sap_bank_key || '-'}</p>
                        </div>
                    </div>
                </div>

                {/* Error message */}
                {errorMessage && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Error</AlertTitle>
                        <AlertDescription>{errorMessage}</AlertDescription>
                    </Alert>
                )}

                {/* Transactions Table */}
                <Card>
                    <CardContent className="pt-4">
                        <div className="max-h-[400px] overflow-y-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-12">#</TableHead>
                                        <TableHead className="w-24">Fecha</TableHead>
                                        <TableHead className="min-w-[300px]">Descripcion</TableHead>
                                        <TableHead className="w-32 text-right">Debito</TableHead>
                                        <TableHead className="w-32 text-right">Credito</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transactions.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={5} className="h-24 text-center text-muted-foreground">
                                                No hay transacciones para mostrar.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        transactions.map((t) => (
                                            <TableRow key={t.sequence}>
                                                <TableCell>{t.sequence}</TableCell>
                                                <TableCell>{new Date(t.due_date + 'T12:00:00').toLocaleDateString('es-MX')}</TableCell>
                                                <TableCell className="max-w-[400px] truncate" title={t.memo}>
                                                    {t.memo}
                                                </TableCell>
                                                <TableCell className="text-right text-red-500">
                                                    {t.debit_amount ? `$${formatCurrency(t.debit_amount)}` : '-'}
                                                </TableCell>
                                                <TableCell className="text-right text-green-500">
                                                    {t.credit_amount ? `$${formatCurrency(t.credit_amount)}` : '-'}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {/* Action Buttons */}
                <div className="flex justify-end gap-2">
                    <Button
                        onClick={handleSendToSap}
                        disabled={status === 'sending' || duplicateCheckLoading || transactions.length === 0}
                        size="lg"
                    >
                        {status === 'sending' ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Enviando a SAP...
                            </>
                        ) : duplicateCheckLoading ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Verificando duplicados...
                            </>
                        ) : (
                            <>
                                <Send className="mr-2 h-4 w-4" />
                                Enviar a SAP
                            </>
                        )}
                    </Button>
                </div>

                {/* Duplicate Warning Dialog */}
                <Dialog open={duplicateDialogOpen} onOpenChange={setDuplicateDialogOpen}>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2 text-orange-500">
                                <AlertTriangle className="h-5 w-5" />
                                Duplicados detectados
                            </DialogTitle>
                            <DialogDescription>
                                <strong className="text-foreground">{duplicateData?.duplicates.length} de {transactions.length}</strong> transacciones
                                ya fueron enviadas a SAP.
                                {transactions.length - (duplicateData?.duplicates.length ?? 0) > 0 && (
                                    <> Las otras <strong className="text-foreground">{transactions.length - (duplicateData?.duplicates.length ?? 0)}</strong> son nuevas.</>
                                )}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-3">
                            {duplicateData?.matched_statements && duplicateData.matched_statements.length > 0 && (
                                <div className="rounded-md border border-orange-200 bg-orange-50 p-3 dark:border-orange-800/50 dark:bg-orange-950/20">
                                    <p className="mb-1 text-xs font-medium text-orange-800 dark:text-orange-300">Coinciden con:</p>
                                    {duplicateData.matched_statements.map((s) => (
                                        <p key={s.statement_id} className="text-xs text-orange-700 dark:text-orange-400">
                                            Folio <strong>{s.statement_number}</strong> - {s.original_filename}
                                        </p>
                                    ))}
                                </div>
                            )}

                            <div className="max-h-[180px] overflow-auto rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-20 text-xs">Fecha</TableHead>
                                            <TableHead className="text-xs">Descripcion</TableHead>
                                            <TableHead className="w-24 text-right text-xs">Monto</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {duplicateData?.duplicates.map((d, i) => (
                                            <TableRow key={i}>
                                                <TableCell className="text-xs">
                                                    {new Date(d.due_date + 'T12:00:00').toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit' })}
                                                </TableCell>
                                                <TableCell className="max-w-[180px] truncate text-xs" title={d.memo}>
                                                    {d.memo}
                                                </TableCell>
                                                <TableCell className={cn('text-right text-xs font-medium', d.debit_amount ? 'text-red-500' : 'text-green-500')}>
                                                    ${formatCurrency(d.debit_amount || d.credit_amount)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>

                        <div className="flex flex-col gap-2 border-t pt-4">
                            {transactions.length - (duplicateData?.duplicates.length ?? 0) > 0 && (
                                <Button onClick={handleSendWithoutDuplicates} className="w-full">
                                    <Send className="mr-2 h-4 w-4" />
                                    Enviar solo las {transactions.length - (duplicateData?.duplicates.length ?? 0)} no duplicadas
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                className="w-full border-destructive text-destructive hover:bg-destructive/10"
                                onClick={() => executeSendToSap()}
                            >
                                Enviar todas ({transactions.length}) incluyendo duplicadas
                            </Button>
                            <Button
                                variant="ghost"
                                className="w-full"
                                onClick={() => setDuplicateDialogOpen(false)}
                            >
                                Cancelar
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>
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
                        <div className="w-full max-w-md space-y-3">
                            {steps.map((step) => (
                                <div key={step.id} className="flex items-start gap-3">
                                    <div className="mt-0.5">
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
                                    </div>
                                    <div className="flex-1 min-w-0">
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
                                        </span>
                                        {step.detail && (
                                            <p className="text-xs text-muted-foreground mt-0.5 truncate">
                                                {step.detail}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <Progress value={progress} className="w-72" />

                        {/* Detailed progress info */}
                        <div className="text-center space-y-1">
                            <p className="text-sm font-medium text-muted-foreground">{progress}%</p>
                            {progressInfo.bankName && (
                                <p className="text-xs text-muted-foreground">
                                    <span className="font-medium">Banco detectado:</span> {progressInfo.bankName}
                                </p>
                            )}
                            {progressInfo.extractedCount !== undefined && progressInfo.extractedCount > 0 && (
                                <p className="text-xs text-muted-foreground">
                                    <span className="font-medium">{progressInfo.extractedCount}</span> transacciones extraidas
                                </p>
                            )}
                            {progressInfo.totalChunks && progressInfo.totalChunks > 1 && (
                                <p className="text-xs text-muted-foreground/70">
                                    Archivo grande — procesando en {progressInfo.totalChunks} fragmentos
                                </p>
                            )}
                        </div>
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
        <div className="space-y-4">
            {errorMessage && (
                <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>Error</AlertTitle>
                    <AlertDescription>{errorMessage}</AlertDescription>
                </Alert>
            )}

            {selectedBranch && filteredBankAccounts.length === 0 && (
                <Alert>
                    <AlertTriangle className="h-4 w-4 text-amber-500" />
                    <AlertDescription>
                        No hay cuentas bancarias con <strong>Clave Bancaria SAP</strong> configurada para esta sucursal.
                        Por favor, configura la Clave SAP en el panel de administración (Cuentas Bancarias).
                    </AlertDescription>
                </Alert>
            )}

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

                    <FilterField label="Cuenta Bancaria (con Clave SAP)" htmlFor="bank-account">
                        <Select
                            value={selectedBankAccount}
                            onValueChange={handleBankAccountChange}
                            disabled={!selectedBranch}
                        >
                            <SelectTrigger id="bank-account">
                                <SelectValue placeholder="Selecciona una cuenta" />
                            </SelectTrigger>
                            <SelectContent>
                                {filteredBankAccounts.length === 0 ? (
                                    <SelectItem value="_none" disabled>
                                        No hay cuentas con Clave SAP
                                    </SelectItem>
                                ) : (
                                    filteredBankAccounts.map((account) => (
                                        <SelectItem key={account.id} value={String(account.id)}>
                                            {account.name} (SAP: {account.sap_bank_key})
                                        </SelectItem>
                                    ))
                                )}
                            </SelectContent>
                        </Select>
                    </FilterField>

                    <FilterField label="Fecha del Extracto" htmlFor="statement-date">
                        <Input
                            id="statement-date"
                            type="date"
                            value={statementDate}
                            onChange={(e) => setStatementDate(e.target.value)}
                        />
                    </FilterField>

                    <div className="space-y-2 lg:col-span-3">
                        <Label
                            htmlFor="bank-statement-file-input"
                            className="text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                        >
                            Archivo del Banco
                        </Label>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept=".xlsx,.xls,.csv"
                            onChange={handleFileSelect}
                            className="sr-only"
                            id="bank-statement-file-input"
                            disabled={!selectedBankAccount}
                        />
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            disabled={!selectedBankAccount}
                            className="flex h-10 w-full items-center justify-center gap-2 rounded-md border border-dashed border-input bg-background px-3 text-sm text-muted-foreground transition-colors hover:bg-muted/50 disabled:cursor-not-allowed disabled:opacity-50"
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
                        <div className="flex justify-end pt-1">
                            <Button onClick={startProcess} disabled={!canStartProcess}>
                                <Sparkles className="h-4 w-4" />
                                Analizar y Preparar Envío
                            </Button>
                        </div>
                    </div>
                </FiltersCard>

                <InfoWidget
                    title="Qué pasará al enviar"
                    icon={ListChecks}
                    footer="El proceso toma entre 10 y 60 segundos."
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

            {selectedBranch && (
                <PageSection
                    icon={FileSpreadsheet}
                    title="Historial de Extractos Enviados"
                    description="Últimos extractos enviados al endpoint BankStatements de SAP."
                >
                    {historyLoading ? (
                        <div className="flex items-center justify-center py-8">
                            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                        </div>
                    ) : history.length === 0 ? (
                        <p className="text-center text-muted-foreground py-8">
                            No hay extractos enviados para esta sucursal
                        </p>
                    ) : (
                        <div className="overflow-hidden rounded-md border">
                            <div className="max-h-[320px] overflow-y-auto">
                                <Table className="[&_td]:px-4 [&_th]:px-4">
                                    <TableHeader className="bg-muted/50">
                                        <TableRow className="hover:bg-muted/50">
                                            <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Número</TableHead>
                                            <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Fecha</TableHead>
                                            <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Archivo</TableHead>
                                            <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Filas</TableHead>
                                            <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Estado</TableHead>
                                            <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">DocEntry</TableHead>
                                            <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Usuario</TableHead>
                                            <TableHead className="h-11 w-24 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Acciones</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {history.map((item) => (
                                            <TableRow key={item.id}>
                                                <TableCell className="py-3 font-mono text-xs tabular-nums">
                                                    {item.statement_number}
                                                </TableCell>
                                                <TableCell className="py-3 whitespace-nowrap text-xs tabular-nums">
                                                    {item.statement_date}
                                                </TableCell>
                                                <TableCell className="max-w-[180px] truncate py-3 text-xs" title={item.original_filename}>
                                                    {item.original_filename}
                                                </TableCell>
                                                <TableCell className="py-3 text-right tabular-nums">{item.rows_count}</TableCell>
                                                <TableCell className="py-3">{getStatusBadge(item.status)}</TableCell>
                                                <TableCell className="py-3 font-mono text-xs tabular-nums">
                                                    {item.sap_doc_entry || (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="py-3 text-xs">{item.user.name}</TableCell>
                                                <TableCell className="py-3 text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-8 w-8"
                                                            onClick={() => handleViewDetail(item.id)}
                                                            title="Ver detalle"
                                                        >
                                                            <Eye className="h-4 w-4" />
                                                        </Button>
                                                        {item.status === 'failed' && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-8 w-8"
                                                                onClick={() => handleReprocess(item.id)}
                                                                disabled={reprocessingId === item.id}
                                                                title="Reprocesar"
                                                            >
                                                                {reprocessingId === item.id ? (
                                                                    <Loader2 className="h-4 w-4 animate-spin" />
                                                                ) : (
                                                                    <RefreshCw className="h-4 w-4" />
                                                                )}
                                                            </Button>
                                                        )}
                                                        {(item.status === 'sent' || item.status === 'failed') && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-8 w-8 text-destructive hover:text-destructive"
                                                                onClick={() => {
                                                                    setStatementToDelete(item);
                                                                    setDeleteDialogOpen(true);
                                                                }}
                                                                disabled={deletingId === item.id}
                                                                title="Eliminar de SAP"
                                                            >
                                                                {deletingId === item.id ? (
                                                                    <Loader2 className="h-4 w-4 animate-spin" />
                                                                ) : (
                                                                    <Trash2 className="h-4 w-4" />
                                                                )}
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    )}
                </PageSection>
            )}

            {/* Detail Modal */}
            <Dialog open={detailOpen} onOpenChange={setDetailOpen}>
                <DialogContent className="flex max-h-[85vh] max-w-4xl flex-col overflow-hidden">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <FileSpreadsheet className="h-4 w-4 text-muted-foreground" />
                            Detalle del Extracto Bancario
                        </DialogTitle>
                        <DialogDescription className="font-mono text-xs">
                            {detailData?.original_filename || 'Cargando...'}
                        </DialogDescription>
                    </DialogHeader>

                    {detailLoading ? (
                        <div className="flex items-center justify-center py-12">
                            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                        </div>
                    ) : detailData ? (
                        <div className="flex-1 space-y-4 overflow-y-auto pr-1">
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <InfoCell label="Número">
                                    <span className="font-mono tabular-nums">{detailData.statement_number}</span>
                                </InfoCell>
                                <InfoCell label="Fecha">
                                    <span className="tabular-nums">{detailData.statement_date}</span>
                                </InfoCell>
                                <InfoCell label="Estado">
                                    <span>{getStatusBadge(detailData.status)}</span>
                                </InfoCell>
                                <InfoCell label="DocEntry SAP">
                                    <span className="font-mono tabular-nums">{detailData.sap_doc_entry || '—'}</span>
                                </InfoCell>
                            </div>

                            {detailData.status === 'failed' && detailData.sap_error && (
                                <Alert variant="destructive">
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertTitle>Error de SAP</AlertTitle>
                                    <AlertDescription>{detailData.sap_error}</AlertDescription>
                                </Alert>
                            )}

                            {detailData.status === 'cancelled' && (
                                <Alert className="border-orange-500 text-orange-700 dark:text-orange-400">
                                    <AlertTriangle className="h-4 w-4" />
                                    <AlertTitle>Cancelado en SAP</AlertTitle>
                                    <AlertDescription>
                                        Los movimientos de este extracto fueron eliminados de SAP.
                                    </AlertDescription>
                                </Alert>
                            )}

                            {(() => {
                                const totalDebit = (detailData.rows ?? []).reduce(
                                    (sum, r) => sum + parseFloat(String(r.DebitAmount ?? r.Debit ?? 0)),
                                    0,
                                );
                                const totalCredit = (detailData.rows ?? []).reduce(
                                    (sum, r) => sum + parseFloat(String(r.CreditAmount ?? r.Credit ?? 0)),
                                    0,
                                );
                                return (
                                    <div className="grid grid-cols-3 gap-3">
                                        <StatCard icon={FileSpreadsheet} label="Filas" value={detailData.rows?.length ?? 0} />
                                        <StatCard icon={ArrowDownRight} label="Total Débito" value={`$${formatCurrency(totalDebit)}`} tone="danger" />
                                        <StatCard icon={ArrowUpRight} label="Total Crédito" value={`$${formatCurrency(totalCredit)}`} tone="success" />
                                    </div>
                                );
                            })()}

                            <div>
                                <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Transacciones ({detailData.rows?.length || 0})
                                </h4>
                                <div className="overflow-hidden rounded-md border">
                                    <div className="max-h-[320px] overflow-auto">
                                        <Table className="[&_td]:px-3 [&_th]:px-3">
                                            <TableHeader className="sticky top-0 z-10 bg-muted/50 backdrop-blur-sm">
                                                <TableRow className="hover:bg-muted/50">
                                                    <TableHead className="h-10 w-12 text-xs font-semibold uppercase tracking-wide text-muted-foreground">#</TableHead>
                                                    <TableHead className="h-10 w-24 text-xs font-semibold uppercase tracking-wide text-muted-foreground">SAP Seq</TableHead>
                                                    <TableHead className="h-10 w-28 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Fecha</TableHead>
                                                    <TableHead className="h-10 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Descripción</TableHead>
                                                    <TableHead className="h-10 w-28 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Cuenta SAP</TableHead>
                                                    <TableHead className="h-10 w-28 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Débito</TableHead>
                                                    <TableHead className="h-10 w-28 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Crédito</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {detailData.rows?.length === 0 ? (
                                                    <TableRow>
                                                        <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                                                            No hay transacciones
                                                        </TableCell>
                                                    </TableRow>
                                                ) : (
                                                    detailData.rows?.map((row, index) => {
                                                        const debit = parseFloat(String(row.DebitAmount ?? row.Debit ?? 0));
                                                        const credit = parseFloat(String(row.CreditAmount ?? row.Credit ?? 0));
                                                        const description = row.Memo ?? row.PaymentReference ?? row.Details ?? '';
                                                        const hasSapSequence = !!row.sap_sequence;
                                                        return (
                                                            <TableRow
                                                                key={index}
                                                                className={!hasSapSequence && detailData.status === 'failed' ? 'bg-destructive/5' : ''}
                                                            >
                                                                <TableCell className="py-2 font-mono text-xs tabular-nums text-muted-foreground">
                                                                    {index + 1}
                                                                </TableCell>
                                                                <TableCell className="py-2 font-mono text-xs">
                                                                    {row.sap_sequence ? (
                                                                        <span className="text-emerald-600 dark:text-emerald-400">
                                                                            {row.sap_sequence}
                                                                        </span>
                                                                    ) : (
                                                                        <span
                                                                            className="text-destructive"
                                                                            title={row.sap_error || 'Pendiente'}
                                                                        >
                                                                            {row.sap_error ? '✗ Error' : 'Pendiente'}
                                                                        </span>
                                                                    )}
                                                                </TableCell>
                                                                <TableCell className="py-2 whitespace-nowrap text-xs tabular-nums">
                                                                    {row.DueDate ? new Date(row.DueDate.replace(/T.*$/, 'T12:00:00')).toLocaleDateString('es-MX') : '—'}
                                                                </TableCell>
                                                                <TableCell className="max-w-[260px] truncate py-2 text-sm" title={description}>
                                                                    {description}
                                                                </TableCell>
                                                                <TableCell className="py-2 font-mono text-xs">
                                                                    {row.AccountCode || <span className="text-muted-foreground">—</span>}
                                                                </TableCell>
                                                                <TableCell className="py-2 text-right text-xs font-medium tabular-nums">
                                                                    {debit > 0 ? (
                                                                        <span className="text-rose-600 dark:text-rose-400">
                                                                            ${formatCurrency(debit)}
                                                                        </span>
                                                                    ) : (
                                                                        <span className="text-muted-foreground/40">—</span>
                                                                    )}
                                                                </TableCell>
                                                                <TableCell className="py-2 text-right text-xs font-medium tabular-nums">
                                                                    {credit > 0 ? (
                                                                        <span className="text-emerald-600 dark:text-emerald-400">
                                                                            ${formatCurrency(credit)}
                                                                        </span>
                                                                    ) : (
                                                                        <span className="text-muted-foreground/40">—</span>
                                                                    )}
                                                                </TableCell>
                                                            </TableRow>
                                                        );
                                                    })
                                                )}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-between border-t pt-2 text-xs text-muted-foreground">
                                <span>Creado por: {detailData.user?.name}</span>
                                <span>
                                    Cuenta: {detailData.bank_account?.name} (SAP: {detailData.bank_account?.sap_bank_key})
                                </span>
                            </div>
                        </div>
                    ) : null}

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDetailOpen(false)}>
                            Cerrar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Delete Confirmation Dialog */}
            <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Eliminar extracto de SAP</AlertDialogTitle>
                        <AlertDialogDescription>
                            Esta accion eliminara los movimientos bancarios de SAP para el extracto{' '}
                            <strong>{statementToDelete?.statement_number}</strong> ({statementToDelete?.original_filename}).
                            El registro local se marcara como &quot;Cancelado en SAP&quot;.
                            Esta accion no se puede deshacer.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleDeleteFromSap}
                            className="bg-red-600 hover:bg-red-700"
                        >
                            Eliminar de SAP
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}
