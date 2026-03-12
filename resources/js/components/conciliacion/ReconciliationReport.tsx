import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import {
    type AccountBalances,
    type ReconciliationMatch,
    type ReconciliationResult,
    type ReconciliationRow,
} from '@/types';
import {
    AlertTriangle,
    ArrowDownCircle,
    ArrowUpCircle,
    Building2,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    DollarSign,
    Download,
    FileSpreadsheet,
    Landmark,
    RefreshCw,
    TrendingDown,
    TrendingUp,
    User,
    Wallet,
    Calendar,
    Clock,
} from 'lucide-react';

const PAGE_SIZE_OPTIONS = [20, 50, 100];
const DEFAULT_PAGE_SIZE = 20;

interface Props {
    result: ReconciliationResult;
    onNewValidation: () => void;
    csrfToken: string;
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(value);
}

function formatDate(dateString: string): string {
    if (!dateString) return '-';
    const date = new Date(dateString + 'T00:00:00');
    return date.toLocaleDateString('es-MX', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
}

function formatDateTime(dateTimeString: string): string {
    if (!dateTimeString) return '-';
    const date = new Date(dateTimeString);
    return date.toLocaleDateString('es-MX', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function ReconciliationReport({ result, onNewValidation, csrfToken }: Props) {
    const [exporting, setExporting] = useState(false);
    const { summary } = result;

    const matchedPercentage = summary.total_extracto > 0
        ? Math.round((summary.total_matched / summary.total_extracto) * 100)
        : 0;

    const netDifference = summary.difference_debit - summary.difference_credit;
    const hasDifference = Math.abs(netDifference) > 0.01;

    const handleExport = async () => {
        setExporting(true);
        try {
            const response = await fetch('/conciliacion/validacion/export', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/octet-stream',
                },
                body: JSON.stringify(result),
            });

            if (!response.ok) {
                throw new Error('Error al exportar.');
            }

            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `conciliacion_${result.date_from}_${result.date_to}.csv`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);
        } catch (error) {
            console.error('Export error:', error);
        } finally {
            setExporting(false);
        }
    };

    return (
        <div className="space-y-4">
            {/* Header Card */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <FileSpreadsheet className="h-5 w-5 text-primary" />
                        Caratula de Conciliacion Bancaria
                    </CardTitle>
                    <CardDescription>
                        Resultado de la validacion de movimientos entre el extracto bancario y SAP.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                        <div className="flex items-center gap-2">
                            <Building2 className="h-4 w-4 text-muted-foreground" />
                            <div>
                                <p className="text-xs text-muted-foreground">Sucursal</p>
                                <p className="text-sm font-medium">{result.branch_name}</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Landmark className="h-4 w-4 text-muted-foreground" />
                            <div>
                                <p className="text-xs text-muted-foreground">Cuenta Bancaria</p>
                                <p className="text-sm font-medium">{result.bank_account_name}</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Calendar className="h-4 w-4 text-muted-foreground" />
                            <div>
                                <p className="text-xs text-muted-foreground">Periodo</p>
                                <p className="text-sm font-medium">
                                    {formatDate(result.date_from)} - {formatDate(result.date_to)}
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Clock className="h-4 w-4 text-muted-foreground" />
                            <div>
                                <p className="text-xs text-muted-foreground">Fecha de Generacion</p>
                                <p className="text-sm font-medium">{formatDateTime(result.generated_at)}</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <User className="h-4 w-4 text-muted-foreground" />
                            <div>
                                <p className="text-xs text-muted-foreground">Generado por</p>
                                <p className="text-sm font-medium">{result.generated_by}</p>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Stats Cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {/* Movimientos Extracto */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium text-muted-foreground">Movimientos Extracto</p>
                            <ArrowDownCircle className="h-5 w-5 text-blue-500" />
                        </div>
                        <p className="mt-2 text-2xl font-bold">{summary.total_extracto}</p>
                        <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                            <p>Debitos: {formatCurrency(summary.sum_debit_extracto)}</p>
                            <p>Creditos: {formatCurrency(summary.sum_credit_extracto)}</p>
                        </div>
                    </CardContent>
                </Card>

                {/* Movimientos SAP */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium text-muted-foreground">Movimientos SAP</p>
                            <ArrowUpCircle className="h-5 w-5 text-indigo-500" />
                        </div>
                        <p className="mt-2 text-2xl font-bold">{summary.total_sap}</p>
                        <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                            <p>Debitos: {formatCurrency(summary.sum_debit_sap)}</p>
                            <p>Creditos: {formatCurrency(summary.sum_credit_sap)}</p>
                        </div>
                    </CardContent>
                </Card>

                {/* Conciliados */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium text-muted-foreground">Conciliados</p>
                            <CheckCircle2 className={`h-5 w-5 ${matchedPercentage === 100 ? 'text-green-500' : 'text-amber-500'}`} />
                        </div>
                        <p className="mt-2 text-2xl font-bold">{summary.total_matched}</p>
                        <div className="mt-2">
                            <Badge variant={matchedPercentage === 100 ? 'default' : 'secondary'}
                                   className={matchedPercentage === 100 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : ''}>
                                {matchedPercentage}%
                            </Badge>
                        </div>
                    </CardContent>
                </Card>

                {/* Diferencia */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium text-muted-foreground">Diferencia</p>
                            <AlertTriangle className={`h-5 w-5 ${hasDifference ? 'text-red-500' : 'text-green-500'}`} />
                        </div>
                        <p className={`mt-2 text-2xl font-bold ${hasDifference ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'}`}>
                            {formatCurrency(Math.abs(netDifference))}
                        </p>
                        <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                            <p>Debitos: {formatCurrency(summary.difference_debit)}</p>
                            <p>Creditos: {formatCurrency(summary.difference_credit)}</p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Account Balances */}
            {result.balances && (
                <BalancesCard
                    balances={result.balances}
                    manualOpening={result.manual_opening_balance}
                    manualClosing={result.manual_closing_balance}
                    dateFrom={result.date_from}
                    dateTo={result.date_to}
                />
            )}

            {/* Tabs with tables */}
            <Card>
                <CardContent className="pt-6">
                    <Tabs defaultValue="matched">
                        <TabsList className="mb-4">
                            <TabsTrigger value="matched">
                                Conciliados ({summary.total_matched})
                            </TabsTrigger>
                            <TabsTrigger value="unmatched-extracto">
                                Faltantes en SAP ({summary.total_unmatched_extracto})
                            </TabsTrigger>
                            <TabsTrigger value="unmatched-sap">
                                Faltantes en Extracto ({summary.total_unmatched_sap})
                            </TabsTrigger>
                        </TabsList>

                        {/* Matched tab */}
                        <TabsContent value="matched">
                            <MatchedTable matches={result.matched} />
                        </TabsContent>

                        {/* Unmatched in SAP tab */}
                        <TabsContent value="unmatched-extracto">
                            <UnmatchedTable
                                rows={result.unmatched_extracto}
                                emptyMessage="Todos los movimientos del extracto tienen correspondencia en SAP."
                                variant="warning"
                            />
                        </TabsContent>

                        {/* Unmatched in Extracto tab */}
                        <TabsContent value="unmatched-sap">
                            <UnmatchedTable
                                rows={result.unmatched_sap}
                                emptyMessage="Todos los movimientos de SAP tienen correspondencia en el extracto."
                                variant="info"
                            />
                        </TabsContent>
                    </Tabs>
                </CardContent>
            </Card>

            {/* Footer actions */}
            <div className="flex justify-end gap-3">
                <Button variant="outline" onClick={onNewValidation} className="gap-2">
                    <RefreshCw className="h-4 w-4" />
                    Nueva Validacion
                </Button>
                <Button onClick={handleExport} disabled={exporting} className="gap-2">
                    {exporting ? (
                        <span className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                    ) : (
                        <Download className="h-4 w-4" />
                    )}
                    Exportar a Excel
                </Button>
            </div>
        </div>
    );
}

function TablePagination({
    page,
    pageSize,
    total,
    onPageChange,
    onPageSizeChange,
}: {
    page: number;
    pageSize: number;
    total: number;
    onPageChange: (page: number) => void;
    onPageSizeChange: (size: number) => void;
}) {
    const totalPages = Math.ceil(total / pageSize);
    const from = (page - 1) * pageSize + 1;
    const to = Math.min(page * pageSize, total);

    if (total <= PAGE_SIZE_OPTIONS[0]) return null;

    return (
        <div className="flex items-center justify-between border-t px-4 py-3">
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <span>Mostrando {from}-{to} de {total}</span>
                <Select value={String(pageSize)} onValueChange={(v) => { onPageSizeChange(Number(v)); onPageChange(1); }}>
                    <SelectTrigger className="h-8 w-[70px]">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {PAGE_SIZE_OPTIONS.map((size) => (
                            <SelectItem key={size} value={String(size)}>{size}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <span>por pagina</span>
            </div>
            <div className="flex items-center gap-1">
                <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>
                    <ChevronLeft className="h-4 w-4" />
                </Button>
                <span className="px-2 text-sm text-muted-foreground">
                    {page} / {totalPages}
                </span>
                <Button variant="outline" size="sm" disabled={page >= totalPages} onClick={() => onPageChange(page + 1)}>
                    <ChevronRight className="h-4 w-4" />
                </Button>
            </div>
        </div>
    );
}

function MatchedTable({ matches }: { matches: ReconciliationMatch[] }) {
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE);

    if (matches.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-8 text-muted-foreground">
                <CheckCircle2 className="mb-2 h-8 w-8" />
                <p>No se encontraron movimientos conciliados.</p>
            </div>
        );
    }

    const paged = matches.slice((page - 1) * pageSize, page * pageSize);

    return (
        <div className="overflow-auto rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-12 text-center">#</TableHead>
                        <TableHead>Fecha</TableHead>
                        <TableHead>Concepto Extracto</TableHead>
                        <TableHead>Concepto SAP</TableHead>
                        <TableHead className="text-right">Debito</TableHead>
                        <TableHead className="text-right">Credito</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {paged.map((match, index) => (
                        <TableRow key={(page - 1) * pageSize + index}>
                            <TableCell className="text-center text-muted-foreground">
                                {(page - 1) * pageSize + index + 1}
                            </TableCell>
                            <TableCell className="whitespace-nowrap">
                                {formatDate(match.extracto.due_date)}
                            </TableCell>
                            <TableCell className="max-w-[200px] truncate" title={match.extracto.memo}>
                                {match.extracto.memo}
                            </TableCell>
                            <TableCell className="max-w-[200px] truncate" title={match.sap.memo}>
                                {match.sap.memo}
                            </TableCell>
                            <TableCell className="whitespace-nowrap text-right">
                                {match.extracto.debit_amount > 0 ? formatCurrency(match.extracto.debit_amount) : '-'}
                            </TableCell>
                            <TableCell className="whitespace-nowrap text-right">
                                {match.extracto.credit_amount > 0 ? formatCurrency(match.extracto.credit_amount) : '-'}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            <TablePagination page={page} pageSize={pageSize} total={matches.length} onPageChange={setPage} onPageSizeChange={setPageSize} />
        </div>
    );
}

function UnmatchedTable({
    rows,
    emptyMessage,
    variant,
}: {
    rows: ReconciliationRow[];
    emptyMessage: string;
    variant: 'warning' | 'info';
}) {
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE);

    if (rows.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-8 text-muted-foreground">
                <CheckCircle2 className="mb-2 h-8 w-8 text-green-500" />
                <p>{emptyMessage}</p>
            </div>
        );
    }

    const borderClass = variant === 'warning'
        ? 'border-red-200 dark:border-red-900'
        : 'border-amber-200 dark:border-amber-900';

    const headerClass = variant === 'warning'
        ? 'bg-red-50 dark:bg-red-950/30'
        : 'bg-amber-50 dark:bg-amber-950/30';

    const paged = rows.slice((page - 1) * pageSize, page * pageSize);

    return (
        <div className={`overflow-auto rounded-md border ${borderClass}`}>
            <Table>
                <TableHeader>
                    <TableRow className={headerClass}>
                        <TableHead className="w-12 text-center">#</TableHead>
                        <TableHead>Fecha</TableHead>
                        <TableHead>Concepto</TableHead>
                        <TableHead>Referencia</TableHead>
                        <TableHead className="text-right">Debito</TableHead>
                        <TableHead className="text-right">Credito</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {paged.map((row, index) => (
                        <TableRow key={(page - 1) * pageSize + index}>
                            <TableCell className="text-center text-muted-foreground">
                                {row.sequence}
                            </TableCell>
                            <TableCell className="whitespace-nowrap">
                                {formatDate(row.due_date)}
                            </TableCell>
                            <TableCell className="max-w-[300px] truncate" title={row.memo}>
                                {row.memo}
                            </TableCell>
                            <TableCell className="max-w-[150px] truncate" title={row.reference}>
                                {row.reference || '-'}
                            </TableCell>
                            <TableCell className="whitespace-nowrap text-right">
                                {row.debit_amount > 0 ? formatCurrency(row.debit_amount) : '-'}
                            </TableCell>
                            <TableCell className="whitespace-nowrap text-right">
                                {row.credit_amount > 0 ? formatCurrency(row.credit_amount) : '-'}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            <TablePagination page={page} pageSize={pageSize} total={rows.length} onPageChange={setPage} onPageSizeChange={setPageSize} />
        </div>
    );
}

function BalanceDiffBadge({ diff }: { diff: number }) {
    const absDiff = Math.abs(diff);
    if (absDiff < 0.01) {
        return <Badge className="bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200 text-xs px-2 py-0.5">Cuadra</Badge>;
    }
    return <Badge variant="destructive" className="text-xs px-2 py-0.5">Dif: {formatCurrency(diff)}</Badge>;
}

function BalancesCard({
    balances,
    manualOpening,
    manualClosing,
    dateFrom,
    dateTo,
}: {
    balances: AccountBalances;
    manualOpening: number | null;
    manualClosing: number | null;
    dateFrom: string;
    dateTo: string;
}) {
    const hasManual = manualOpening !== null || manualClosing !== null;
    const openingDiff = manualOpening !== null ? manualOpening - balances.opening_balance : null;
    const closingDiff = manualClosing !== null ? manualClosing - balances.closing_balance : null;

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-2 text-sm">
                    <Wallet className="h-4 w-4 text-primary" />
                    Saldos de Cuenta SAP
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="overflow-auto rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="text-xs">Concepto</TableHead>
                                <TableHead className="text-right text-xs">SAP (JDT1)</TableHead>
                                {hasManual && <TableHead className="text-right text-xs">Extracto Bancario</TableHead>}
                                {hasManual && <TableHead className="text-center text-xs">Validacion</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow>
                                <TableCell className="py-2">
                                    <div className="flex items-center gap-1.5 text-xs font-medium">
                                        <DollarSign className="h-3.5 w-3.5 text-muted-foreground" />
                                        Saldo Inicial
                                        <span className="text-[10px] text-muted-foreground">({formatDate(dateFrom)})</span>
                                    </div>
                                </TableCell>
                                <TableCell className="py-2 text-right font-mono text-sm font-semibold">
                                    {formatCurrency(balances.opening_balance)}
                                </TableCell>
                                {hasManual && (
                                    <TableCell className="py-2 text-right font-mono text-sm font-semibold">
                                        {manualOpening !== null ? formatCurrency(manualOpening) : <span className="text-muted-foreground">-</span>}
                                    </TableCell>
                                )}
                                {hasManual && (
                                    <TableCell className="py-2 text-center">
                                        {openingDiff !== null ? <BalanceDiffBadge diff={openingDiff} /> : <span className="text-muted-foreground">-</span>}
                                    </TableCell>
                                )}
                            </TableRow>
                            <TableRow>
                                <TableCell className="py-2">
                                    <div className="flex items-center gap-1.5 text-xs">
                                        {balances.period_net >= 0 ? (
                                            <TrendingUp className="h-3.5 w-3.5 text-green-500" />
                                        ) : (
                                            <TrendingDown className="h-3.5 w-3.5 text-red-500" />
                                        )}
                                        <span className="font-medium">Neto del Periodo</span>
                                    </div>
                                    <div className="ml-5 mt-0.5 flex gap-3 text-[10px] text-muted-foreground">
                                        <span>Deb: {formatCurrency(balances.period_debit)}</span>
                                        <span>Cred: {formatCurrency(balances.period_credit)}</span>
                                    </div>
                                </TableCell>
                                <TableCell className={`py-2 text-right font-mono text-sm font-semibold ${balances.period_net >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                    {formatCurrency(balances.period_net)}
                                </TableCell>
                                {hasManual && <TableCell className="py-2" />}
                                {hasManual && <TableCell className="py-2" />}
                            </TableRow>
                            <TableRow className="bg-primary/5">
                                <TableCell className="py-2.5">
                                    <div className="flex items-center gap-1.5 text-xs font-semibold">
                                        <Wallet className="h-3.5 w-3.5 text-primary" />
                                        Saldo Final
                                        <span className="text-[10px] font-normal text-muted-foreground">({formatDate(dateTo)})</span>
                                    </div>
                                </TableCell>
                                <TableCell className="py-2.5 text-right font-mono text-base font-bold text-primary">
                                    {formatCurrency(balances.closing_balance)}
                                </TableCell>
                                {hasManual && (
                                    <TableCell className="py-2.5 text-right font-mono text-base font-bold">
                                        {manualClosing !== null ? formatCurrency(manualClosing) : <span className="text-muted-foreground">-</span>}
                                    </TableCell>
                                )}
                                {hasManual && (
                                    <TableCell className="py-2.5 text-center">
                                        {closingDiff !== null ? <BalanceDiffBadge diff={closingDiff} /> : <span className="text-muted-foreground">-</span>}
                                    </TableCell>
                                )}
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    );
}
