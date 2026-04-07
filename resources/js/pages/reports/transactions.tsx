import AppLayout from '@/layouts/app-layout';
import { type BankAccount, type Branch, type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Download,
    FileSpreadsheet,
    Search,
    Wallet,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Panel', href: '/dashboard' },
    { title: 'Rastreo de Transacciones', href: '/reports/transactions' },
];

interface User {
    id: number;
    name: string;
}

interface TransactionRow {
    id: number;
    type: 'batch' | 'vendor_payment';
    type_label: string;
    date: string;
    description: string;
    debit: number;
    credit: number;
    amount: number;
    sap_number: number;
    counterpart_account: string | null;
    card_code: string | null;
    card_name: string | null;
    batch_id: number;
    batch_uuid: string;
    batch_filename: string;
    branch: string;
    bank_account: string;
    bank_account_code: string;
    user: string | null;
}

interface Summary {
    total_records: number;
    total_debit: number;
    total_credit: number;
    total_amount: number;
}

interface PagedResponse {
    data: TransactionRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    summary: Summary;
}

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
    users: User[];
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(value);
}

function formatDate(dateStr: string): string {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
}

function getMonthRange(): { from: string; to: string } {
    const now = new Date();
    const from = new Date(now.getFullYear(), now.getMonth(), 1);
    const to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    return {
        from: from.toISOString().slice(0, 10),
        to: to.toISOString().slice(0, 10),
    };
}

export default function TransactionsReport({ branches, bankAccounts, users }: Props) {
    const defaultRange = useMemo(() => getMonthRange(), []);

    const [branchId, setBranchId] = useState<string>('all');
    const [bankAccountId, setBankAccountId] = useState<string>('all');
    const [userId, setUserId] = useState<string>('all');
    const [dateFrom, setDateFrom] = useState(defaultRange.from);
    const [dateTo, setDateTo] = useState(defaultRange.to);
    const [sapNumber, setSapNumber] = useState('');
    const [type, setType] = useState<string>('all');
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<PagedResponse | null>(null);

    const filteredBankAccounts = useMemo(() => {
        if (branchId === 'all') return bankAccounts;
        return bankAccounts.filter((ba) => ba.branch_id === Number(branchId));
    }, [branchId, bankAccounts]);

    const fetchData = useCallback(async (pageNum: number) => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (branchId !== 'all') params.set('branch_id', branchId);
            if (bankAccountId !== 'all') params.set('bank_account_id', bankAccountId);
            if (userId !== 'all') params.set('user_id', userId);
            if (dateFrom) params.set('date_from', dateFrom);
            if (dateTo) params.set('date_to', dateTo);
            if (sapNumber.trim()) params.set('sap_number', sapNumber.trim());
            if (type !== 'all') params.set('type', type);
            params.set('page', String(pageNum));
            params.set('per_page', '25');

            const res = await fetch(`/reports/transactions/data?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                },
            });

            if (res.ok) {
                setResult(await res.json());
            }
        } catch {
            // Silent fail
        } finally {
            setLoading(false);
        }
    }, [branchId, bankAccountId, userId, dateFrom, dateTo, sapNumber, type]);

    const handleSearch = () => {
        setPage(1);
        fetchData(1);
    };

    const handlePageChange = (newPage: number) => {
        setPage(newPage);
        fetchData(newPage);
    };

    // Initial fetch
    useEffect(() => {
        fetchData(1);
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const handleExportCsv = () => {
        const params = new URLSearchParams();
        if (branchId !== 'all') params.set('branch_id', branchId);
        if (bankAccountId !== 'all') params.set('bank_account_id', bankAccountId);
        if (userId !== 'all') params.set('user_id', userId);
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);
        if (sapNumber.trim()) params.set('sap_number', sapNumber.trim());
        if (type !== 'all') params.set('type', type);
        params.set('per_page', '10000');

        fetch(`/reports/transactions/data?${params.toString()}`, {
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
            },
        })
            .then((res) => res.json())
            .then((data: PagedResponse) => {
                const rows = data.data;
                const headers = [
                    'Fecha',
                    'Tipo',
                    'Doc SAP',
                    'Descripcion',
                    'Cargo',
                    'Abono',
                    'Monto',
                    'Sucursal',
                    'Cuenta Bancaria',
                    'Usuario',
                    'Archivo de Lote',
                    'Proveedor',
                    'Cuenta Contrapartida',
                ];
                const csvRows = rows.map((r) => [
                    r.date,
                    r.type_label,
                    r.sap_number,
                    `"${(r.description || '').replace(/"/g, '""')}"`,
                    r.debit || '',
                    r.credit || '',
                    r.amount,
                    `"${r.branch}"`,
                    `"${r.bank_account}"`,
                    `"${r.user || ''}"`,
                    `"${r.batch_filename}"`,
                    r.card_code ? `"${r.card_code} - ${r.card_name}"` : '',
                    r.counterpart_account || '',
                ]);

                const csv = [headers.join(','), ...csvRows.map((r) => r.join(','))].join('\n');
                const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `reporte_transacciones_${dateFrom}_${dateTo}.csv`;
                a.click();
                URL.revokeObjectURL(url);
            });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Rastreo de Transacciones" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
                {/* Filters */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Search className="size-4" />
                            Filtros de busqueda
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 md:grid-cols-4">
                            <div className="space-y-1.5">
                                <Label className="text-xs">Sucursal</Label>
                                <Select value={branchId} onValueChange={(v) => { setBranchId(v); setBankAccountId('all'); }}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Todas" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Todas las sucursales</SelectItem>
                                        {branches.map((b) => (
                                            <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs">Cuenta bancaria</Label>
                                <Select value={bankAccountId} onValueChange={setBankAccountId}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Todas" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Todas las cuentas</SelectItem>
                                        {filteredBankAccounts.map((ba) => (
                                            <SelectItem key={ba.id} value={String(ba.id)}>
                                                {ba.name} ({ba.account})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs">Usuario</Label>
                                <Select value={userId} onValueChange={setUserId}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Todos" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Todos los usuarios</SelectItem>
                                        {users.map((u) => (
                                            <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs">Tipo de movimiento</Label>
                                <Select value={type} onValueChange={setType}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Todos</SelectItem>
                                        <SelectItem value="batch">Extracto Bancario</SelectItem>
                                        <SelectItem value="vendor_payment">Pago a Proveedor</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs">Fecha desde</Label>
                                <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs">Fecha hasta</Label>
                                <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs">Documento SAP</Label>
                                <Input
                                    type="text"
                                    placeholder="Buscar por # SAP"
                                    value={sapNumber}
                                    onChange={(e) => setSapNumber(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                />
                            </div>

                            <div className="flex items-end gap-2">
                                <Button onClick={handleSearch} className="flex-1">
                                    <Search className="mr-1.5 size-4" />
                                    Buscar
                                </Button>
                                <Button variant="outline" onClick={handleExportCsv} title="Exportar CSV">
                                    <Download className="size-4" />
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Summary */}
                {result && !loading && (
                    <div className="grid gap-4 md:grid-cols-4">
                        <SummaryCard label="Total registros" value={String(result.summary.total_records)} />
                        <SummaryCard label="Total cargos" value={formatCurrency(result.summary.total_debit)} />
                        <SummaryCard label="Total abonos" value={formatCurrency(result.summary.total_credit)} />
                        <SummaryCard label="Total pagos proveedores" value={formatCurrency(result.summary.total_amount - result.summary.total_debit - result.summary.total_credit)} />
                    </div>
                )}

                {/* Results Table */}
                <Card>
                    <CardContent className="p-0">
                        {loading ? (
                            <div className="flex flex-col gap-2 p-4">
                                {[...Array(8)].map((_, i) => (
                                    <Skeleton key={i} className="h-10 w-full" />
                                ))}
                            </div>
                        ) : result && result.data.length > 0 ? (
                            <>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-[90px]">Fecha</TableHead>
                                                <TableHead className="w-[130px]">Tipo</TableHead>
                                                <TableHead className="w-[80px] text-right">Doc SAP</TableHead>
                                                <TableHead>Descripcion</TableHead>
                                                <TableHead className="text-right">Cargo</TableHead>
                                                <TableHead className="text-right">Abono</TableHead>
                                                <TableHead className="text-right">Monto</TableHead>
                                                <TableHead>Sucursal</TableHead>
                                                <TableHead>Cuenta</TableHead>
                                                <TableHead>Usuario</TableHead>
                                                <TableHead>Lote</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {result.data.map((row) => (
                                                <TableRow key={`${row.type}-${row.id}`}>
                                                    <TableCell className="text-xs tabular-nums whitespace-nowrap">
                                                        {formatDate(row.date)}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant={row.type === 'batch' ? 'secondary' : 'outline'}
                                                            className="gap-1 text-[10px]"
                                                        >
                                                            {row.type === 'batch' ? (
                                                                <FileSpreadsheet className="size-3" />
                                                            ) : (
                                                                <Wallet className="size-3" />
                                                            )}
                                                            {row.type_label}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-right font-mono text-xs tabular-nums">
                                                        {row.sap_number}
                                                    </TableCell>
                                                    <TableCell className="max-w-[250px] truncate text-sm" title={row.description}>
                                                        {row.description}
                                                    </TableCell>
                                                    <TableCell className="text-right text-xs tabular-nums">
                                                        {row.debit > 0 ? formatCurrency(row.debit) : ''}
                                                    </TableCell>
                                                    <TableCell className="text-right text-xs tabular-nums">
                                                        {row.credit > 0 ? formatCurrency(row.credit) : ''}
                                                    </TableCell>
                                                    <TableCell className="text-right text-xs font-medium tabular-nums">
                                                        {row.type === 'vendor_payment' ? formatCurrency(row.amount) : ''}
                                                    </TableCell>
                                                    <TableCell className="max-w-[120px] truncate text-xs" title={row.branch}>
                                                        {row.branch}
                                                    </TableCell>
                                                    <TableCell className="max-w-[120px] truncate text-xs" title={row.bank_account}>
                                                        {row.bank_account}
                                                    </TableCell>
                                                    <TableCell className="text-xs">{row.user || '-'}</TableCell>
                                                    <TableCell className="max-w-[150px] truncate text-xs" title={row.batch_filename}>
                                                        {row.batch_filename}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>

                                {/* Pagination */}
                                <div className="flex items-center justify-between border-t px-4 py-3">
                                    <span className="text-muted-foreground text-sm">
                                        {result.total} registros encontrados
                                    </span>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={result.current_page <= 1}
                                            onClick={() => handlePageChange(result.current_page - 1)}
                                        >
                                            <ChevronLeft className="size-4" />
                                        </Button>
                                        <span className="text-sm tabular-nums">
                                            {result.current_page} / {result.last_page}
                                        </span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={result.current_page >= result.last_page}
                                            onClick={() => handlePageChange(result.current_page + 1)}
                                        >
                                            <ChevronRight className="size-4" />
                                        </Button>
                                    </div>
                                </div>
                            </>
                        ) : (
                            <div className="text-muted-foreground flex flex-col items-center gap-2 py-16 text-center">
                                <ClipboardList className="size-10 opacity-30" />
                                <p className="text-sm">No se encontraron transacciones con los filtros seleccionados</p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
    return (
        <Card>
            <CardContent className="flex flex-col gap-1 py-3">
                <span className="text-muted-foreground text-xs">{label}</span>
                <span className="text-lg font-bold tabular-nums">{value}</span>
            </CardContent>
        </Card>
    );
}
