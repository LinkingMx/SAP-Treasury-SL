import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
    type ReconciliationMatch,
    type ReconciliationResult,
    type ReconciliationRow,
} from '@/types';
import {
    AlertTriangle,
    ArrowDownCircle,
    ArrowUpCircle,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Landmark,
    RefreshCw,
    User,
    Calendar,
    Clock,
} from 'lucide-react';

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
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
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

function MatchedTable({ matches }: { matches: ReconciliationMatch[] }) {
    if (matches.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-8 text-muted-foreground">
                <CheckCircle2 className="mb-2 h-8 w-8" />
                <p>No se encontraron movimientos conciliados.</p>
            </div>
        );
    }

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
                    {matches.map((match, index) => (
                        <TableRow key={index}>
                            <TableCell className="text-center text-muted-foreground">
                                {index + 1}
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
                    {rows.map((row, index) => (
                        <TableRow key={index}>
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
        </div>
    );
}
