import { useState } from 'react';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { afirme } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Download, FileText, Loader2, Search } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Integración Afirme',
        href: afirme().url,
    },
];

interface Branch {
    id: number;
    name: string;
    sap_database: string;
    afirme_account: string;
}

interface Payment {
    DocEntry: number;
    DocNum: number;
    CardCode: string;
    CardName: string;
    rfc: string | null;
    clabe: string | null;
    amount: number;
    transfer_date: string;
    reference: string | null;
}

interface PaymentSummary {
    count: number;
    total_amount: number;
}

interface Props {
    branches: Branch[];
}

export default function AfirmeIndex({ branches }: Props) {
    const [selectedBranch, setSelectedBranch] = useState<string>('');
    const [dateFrom, setDateFrom] = useState<string>('');
    const [dateTo, setDateTo] = useState<string>('');
    const [isLoading, setIsLoading] = useState(false);
    const [isDownloading, setIsDownloading] = useState(false);
    const [payments, setPayments] = useState<Payment[]>([]);
    const [summary, setSummary] = useState<PaymentSummary | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [confirmDialogOpen, setConfirmDialogOpen] = useState(false);
    const [downloadSuccess, setDownloadSuccess] = useState(false);

    const selectedBranchData = branches.find((b) => String(b.id) === selectedBranch);

    const handleSearch = async () => {
        if (!selectedBranch || !dateFrom || !dateTo) return;

        setIsLoading(true);
        setError(null);
        setPayments([]);
        setSummary(null);
        setDownloadSuccess(false);

        try {
            const params = new URLSearchParams({
                branch_id: selectedBranch,
                date_from: dateFrom,
                date_to: dateTo,
            });

            const response = await fetch(`/afirme/payments?${params}`, {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ||
                        '',
                },
            });

            const data = await response.json();

            if (response.ok && data.success) {
                setPayments(data.payments);
                setSummary(data.summary);
            } else {
                setError(data.message || 'Error al consultar pagos');
            }
        } catch (err) {
            setError('Error de conexión al servidor');
            console.error('Error fetching payments:', err);
        } finally {
            setIsLoading(false);
        }
    };

    const handleDownload = async () => {
        setConfirmDialogOpen(false);
        setIsDownloading(true);
        setError(null);

        try {
            const response = await fetch('/afirme/download', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json, text/plain',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ||
                        '',
                },
                body: JSON.stringify({
                    branch_id: selectedBranch,
                    date_from: dateFrom,
                    date_to: dateTo,
                }),
            });

            const contentType = response.headers.get('content-type');

            if (contentType?.includes('application/json')) {
                const data = await response.json();
                setError(data.message || 'Error al generar archivo');
            } else {
                // It's a file download
                const blob = await response.blob();
                const disposition = response.headers.get('content-disposition');
                let filename = 'AFIRME_pagos.txt';
                if (disposition) {
                    const match = disposition.match(/filename="?([^"]+)"?/);
                    if (match) filename = match[1];
                }

                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);

                setDownloadSuccess(true);
                setPayments([]);
                setSummary(null);
            }
        } catch (err) {
            setError('Error al descargar archivo');
            console.error('Error downloading file:', err);
        } finally {
            setIsDownloading(false);
        }
    };

    const formatCurrency = (value: number): string => {
        return value.toLocaleString('es-MX', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    };

    const formatDate = (dateString: string): string => {
        return new Date(dateString).toLocaleDateString('es-MX');
    };

    const canSearch = selectedBranch && dateFrom && dateTo && !isLoading;
    const canDownload = payments.length > 0 && !isDownloading;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Integración Afirme" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <FileText className="h-5 w-5" />
                            Generador de archivo TXT para Afirme
                        </CardTitle>
                        <CardDescription>
                            Consulta pagos pendientes de SAP y genera el archivo TXT para
                            procesamiento bancario con Afirme
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {/* Filters Row */}
                        <div className="grid gap-4 md:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="branch">Sucursal</Label>
                                <Select value={selectedBranch} onValueChange={setSelectedBranch}>
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
                                <Label htmlFor="dateFrom">Fecha Desde</Label>
                                <Input
                                    id="dateFrom"
                                    type="date"
                                    value={dateFrom}
                                    onChange={(e) => setDateFrom(e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="dateTo">Fecha Hasta</Label>
                                <Input
                                    id="dateTo"
                                    type="date"
                                    value={dateTo}
                                    onChange={(e) => setDateTo(e.target.value)}
                                />
                            </div>
                            <div className="flex items-end">
                                <Button onClick={handleSearch} disabled={!canSearch} className="w-full">
                                    {isLoading ? (
                                        <>
                                            <Loader2 className="h-4 w-4 animate-spin" />
                                            Consultando...
                                        </>
                                    ) : (
                                        <>
                                            <Search className="h-4 w-4" />
                                            Consultar
                                        </>
                                    )}
                                </Button>
                            </div>
                        </div>

                        {/* Selected Branch Info */}
                        {selectedBranchData && (
                            <div className="rounded-lg bg-muted/50 p-3 text-sm">
                                <span className="text-muted-foreground">Cuenta CLABE Afirme:</span>{' '}
                                <span className="font-mono font-medium">
                                    {selectedBranchData.afirme_account}
                                </span>
                            </div>
                        )}

                        {/* Error Alert */}
                        {error && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertTitle>Error</AlertTitle>
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        )}

                        {/* Success Alert */}
                        {downloadSuccess && (
                            <Alert variant="success">
                                <CheckCircle2 className="h-4 w-4" />
                                <AlertTitle>Archivo generado exitosamente</AlertTitle>
                                <AlertDescription>
                                    El archivo TXT ha sido descargado y los pagos han sido marcados
                                    como procesados en SAP.
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* Summary Cards */}
                        {summary && (
                            <div className="grid grid-cols-2 gap-4">
                                <div className="rounded-lg bg-blue-500/10 p-4 text-center">
                                    <p className="text-2xl font-bold text-blue-600">{summary.count}</p>
                                    <p className="text-xs text-muted-foreground uppercase tracking-wide">
                                        Pagos Pendientes
                                    </p>
                                </div>
                                <div className="rounded-lg bg-green-500/10 p-4 text-center">
                                    <p className="text-2xl font-bold text-green-600 tabular-nums">
                                        ${formatCurrency(summary.total_amount)}
                                    </p>
                                    <p className="text-xs text-muted-foreground uppercase tracking-wide">
                                        Monto Total
                                    </p>
                                </div>
                            </div>
                        )}

                        {/* Payments Table */}
                        {payments.length > 0 && (
                            <div className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <h3 className="font-medium">Pagos a Procesar</h3>
                                    <Button
                                        onClick={() => setConfirmDialogOpen(true)}
                                        disabled={!canDownload}
                                    >
                                        {isDownloading ? (
                                            <>
                                                <Loader2 className="h-4 w-4 animate-spin" />
                                                Generando...
                                            </>
                                        ) : (
                                            <>
                                                <Download className="h-4 w-4" />
                                                Generar TXT
                                            </>
                                        )}
                                    </Button>
                                </div>

                                <div className="rounded-lg border">
                                    <div className="max-h-[400px] overflow-auto">
                                        <Table>
                                            <TableHeader className="sticky top-0 bg-background">
                                                <TableRow>
                                                    <TableHead className="w-20"># Doc</TableHead>
                                                    <TableHead>Proveedor</TableHead>
                                                    <TableHead className="w-32">RFC</TableHead>
                                                    <TableHead className="w-44">CLABE</TableHead>
                                                    <TableHead className="w-28 text-right">Monto</TableHead>
                                                    <TableHead className="w-28">Fecha</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {payments.map((payment) => (
                                                    <TableRow key={payment.DocEntry}>
                                                        <TableCell className="font-mono text-xs">
                                                            {payment.DocNum}
                                                        </TableCell>
                                                        <TableCell className="max-w-[200px] truncate">
                                                            {payment.CardName}
                                                        </TableCell>
                                                        <TableCell className="font-mono text-xs">
                                                            {payment.rfc || '-'}
                                                        </TableCell>
                                                        <TableCell className="font-mono text-xs">
                                                            {payment.clabe || (
                                                                <span className="text-destructive">
                                                                    SIN CLABE
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right tabular-nums">
                                                            ${formatCurrency(payment.amount)}
                                                        </TableCell>
                                                        <TableCell className="text-xs">
                                                            {formatDate(payment.transfer_date)}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Empty State */}
                        {!isLoading && payments.length === 0 && summary === null && !error && (
                            <div className="flex flex-col items-center justify-center py-12 text-center text-muted-foreground">
                                <FileText className="mb-4 h-12 w-12 opacity-50" />
                                <p>Selecciona una sucursal y rango de fechas para consultar pagos</p>
                            </div>
                        )}

                        {/* No Results */}
                        {!isLoading && summary?.count === 0 && (
                            <div className="flex flex-col items-center justify-center py-12 text-center text-muted-foreground">
                                <CheckCircle2 className="mb-4 h-12 w-12 opacity-50" />
                                <p>No hay pagos pendientes para el rango de fechas seleccionado</p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Confirmation Dialog */}
            <AlertDialog open={confirmDialogOpen} onOpenChange={setConfirmDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Confirmar generación de archivo</AlertDialogTitle>
                        <AlertDialogDescription className="space-y-2">
                            <p>
                                Se generará el archivo TXT con{' '}
                                <strong>{summary?.count} pagos</strong> por un total de{' '}
                                <strong>${formatCurrency(summary?.total_amount || 0)}</strong>.
                            </p>
                            <p className="text-destructive font-medium">
                                Al descargar el archivo, los pagos serán marcados como "Pagado" en
                                SAP. Esta acción no se puede deshacer.
                            </p>
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction onClick={handleDownload}>
                            Generar y Descargar
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
