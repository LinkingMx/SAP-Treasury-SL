import AppLayout from '@/layouts/app-layout';
import { type BankAccount, type Branch, type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { ActivityHeatmap } from '@/components/page/activity-heatmap';
import { ColumnVisibilityMenu } from '@/components/page/column-visibility-menu';
import { FilterField, FiltersCard } from '@/components/page/filters-card';
import { InfoWidget } from '@/components/page/info-widget';
import { PageHeader } from '@/components/page/page-header';
import { PageSection } from '@/components/page/page-section';
import { TransactionTypeBadge } from '@/components/page/transaction-type-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
import { cn } from '@/lib/utils';
import {
    Activity,
    ArrowDownRight,
    ArrowUpRight,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Download,
    Filter,
    type LucideIcon,
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
    activityData?: Record<string, number>;
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

export default function TransactionsReport({ branches, bankAccounts, users, activityData = {} }: Props) {
    const defaultRange = useMemo(() => getMonthRange(), []);

    const [branchId, setBranchId] = useState<string>('all');
    const [bankAccountId, setBankAccountId] = useState<string>('all');
    const [userId, setUserId] = useState<string>('all');
    const [dateFrom, setDateFrom] = useState(defaultRange.from);
    const [dateTo, setDateTo] = useState(defaultRange.to);
    const [sapNumber, setSapNumber] = useState('');
    const [type, setType] = useState<string>('all');
    const [, setPage] = useState(1);
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<PagedResponse | null>(null);
    const [columnVisibility, setColumnVisibility] = useState<Record<string, boolean>>({
        fecha: true,
        tipo: true,
        doc_sap: true,
        descripcion: true,
        cargo: true,
        abono: true,
        monto: true,
        sucursal: false,
        cuenta: false,
        usuario: false,
        lote: false,
    });

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
            params.set('per_page', '10');

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
                    'Fecha', 'Tipo', 'Doc SAP', 'Descripcion', 'Cargo', 'Abono', 'Monto',
                    'Sucursal', 'Cuenta Bancaria', 'Usuario', 'Archivo de Lote', 'Proveedor', 'Cuenta Contrapartida',
                ];
                const csvRows = rows.map((r) => [
                    r.date, r.type_label, r.sap_number,
                    `"${(r.description || '').replace(/"/g, '""')}"`,
                    r.debit || '', r.credit || '', r.amount,
                    `"${r.branch}"`, `"${r.bank_account}"`, `"${r.user || ''}"`,
                    `"${r.batch_filename}"`,
                    r.card_code ? `"${r.card_code} - ${r.card_name}"` : '',
                    r.counterpart_account || '',
                ]);

                const csv = [headers.join(','), ...csvRows.map((r) => r.join(','))].join('\n');
                const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
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
            <div className="space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={ClipboardList}
                    title="Rastreo de Transacciones"
                    description="Consulta consolidada de movimientos bancarios y pagos a proveedores."
                    action={
                        <Button variant="outline" onClick={handleExportCsv}>
                            <Download className="h-4 w-4" />
                            Exportar CSV
                        </Button>
                    }
                />

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    {loading || !result ? (
                        <>
                            {[...Array(4)].map((_, i) => (
                                <Card key={i}>
                                    <CardContent className="flex items-center gap-3 py-4">
                                        <Skeleton className="h-8 w-8 rounded-md" />
                                        <div className="flex flex-1 flex-col gap-1.5">
                                            <Skeleton className="h-3 w-20" />
                                            <Skeleton className="h-5 w-28" />
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </>
                    ) : (
                        <>
                            <SummaryCard
                                icon={ClipboardList}
                                label="Total registros"
                                value={String(result.summary.total_records)}
                                tone="default"
                            />
                            <SummaryCard
                                icon={ArrowDownRight}
                                label="Total cargos"
                                value={formatCurrency(result.summary.total_debit)}
                                tone="danger"
                            />
                            <SummaryCard
                                icon={ArrowUpRight}
                                label="Total abonos"
                                value={formatCurrency(result.summary.total_credit)}
                                tone="success"
                            />
                            <SummaryCard
                                icon={Wallet}
                                label="Total pagos proveedores"
                                value={formatCurrency(result.summary.total_amount - result.summary.total_debit - result.summary.total_credit)}
                                tone="primary"
                            />
                        </>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <FiltersCard icon={Filter} columns={3} className="lg:col-span-2">
                        <FilterField label="Sucursal">
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
                        </FilterField>

                        <FilterField label="Cuenta bancaria">
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
                        </FilterField>

                        <FilterField label="Tipo de movimiento">
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
                        </FilterField>

                        <FilterField label="Usuario">
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
                        </FilterField>

                        <FilterField label="Fecha desde">
                            <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                        </FilterField>

                        <FilterField label="Fecha hasta">
                            <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                        </FilterField>

                        <FilterField label="Documento SAP" className="lg:col-span-2">
                            <Input
                                type="text"
                                placeholder="Buscar por # SAP"
                                value={sapNumber}
                                onChange={(e) => setSapNumber(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                            />
                        </FilterField>

                        <div className="flex items-end">
                            <Button onClick={handleSearch} className="w-full">
                                <Search className="h-4 w-4" />
                                Buscar
                            </Button>
                        </div>
                    </FiltersCard>

                    <InfoWidget
                        title="Actividad"
                        icon={Activity}
                        footer="Transacciones por día · últimas 13 semanas"
                    >
                        <ActivityHeatmap data={activityData} />
                    </InfoWidget>
                </div>

                <PageSection
                    icon={ClipboardList}
                    title="Movimientos"
                    description="Resultados ordenados por fecha descendente."
                    action={
                        <ColumnVisibilityMenu
                            columns={[
                                { key: 'fecha', label: 'Fecha' },
                                { key: 'tipo', label: 'Tipo' },
                                { key: 'doc_sap', label: 'Doc SAP' },
                                { key: 'descripcion', label: 'Descripción' },
                                { key: 'cargo', label: 'Cargo' },
                                { key: 'abono', label: 'Abono' },
                                { key: 'monto', label: 'Monto' },
                                { key: 'sucursal', label: 'Sucursal' },
                                { key: 'cuenta', label: 'Cuenta' },
                                { key: 'usuario', label: 'Usuario' },
                                { key: 'lote', label: 'Lote' },
                            ]}
                            visibility={columnVisibility}
                            onChange={(k, v) => setColumnVisibility((s) => ({ ...s, [k]: v }))}
                        />
                    }
                >
                    {loading ? (
                        <div className="flex flex-col gap-2">
                            {[...Array(8)].map((_, i) => (
                                <Skeleton key={i} className="h-10 w-full" />
                            ))}
                        </div>
                    ) : result && result.data.length > 0 ? (
                        <div className="space-y-4">
                            <div className="overflow-hidden rounded-md border">
                                <Table className="[&_td]:px-4 [&_th]:px-4">
                                        <TableHeader className="bg-muted/50">
                                            <TableRow className="hover:bg-muted/50">
                                                {columnVisibility.fecha && (
                                                    <TableHead className="h-11 w-[110px] text-xs font-semibold uppercase tracking-wide text-muted-foreground">Fecha</TableHead>
                                                )}
                                                {columnVisibility.tipo && (
                                                    <TableHead className="h-11 w-[160px] text-xs font-semibold uppercase tracking-wide text-muted-foreground">Tipo</TableHead>
                                                )}
                                                {columnVisibility.doc_sap && (
                                                    <TableHead className="h-11 w-[100px] text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Doc SAP</TableHead>
                                                )}
                                                {columnVisibility.descripcion && (
                                                    <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Descripción</TableHead>
                                                )}
                                                {columnVisibility.cargo && (
                                                    <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Cargo</TableHead>
                                                )}
                                                {columnVisibility.abono && (
                                                    <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Abono</TableHead>
                                                )}
                                                {columnVisibility.monto && (
                                                    <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Monto</TableHead>
                                                )}
                                                {columnVisibility.sucursal && (
                                                    <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Sucursal</TableHead>
                                                )}
                                                {columnVisibility.cuenta && (
                                                    <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Cuenta</TableHead>
                                                )}
                                                {columnVisibility.usuario && (
                                                    <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Usuario</TableHead>
                                                )}
                                                {columnVisibility.lote && (
                                                    <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Lote</TableHead>
                                                )}
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {result.data.map((row) => (
                                                <TableRow key={`${row.type}-${row.id}`}>
                                                    {columnVisibility.fecha && (
                                                        <TableCell className="whitespace-nowrap py-3 text-xs tabular-nums">
                                                            {formatDate(row.date)}
                                                        </TableCell>
                                                    )}
                                                    {columnVisibility.tipo && (
                                                        <TableCell className="py-3">
                                                            <TransactionTypeBadge type={row.type} label={row.type_label} />
                                                        </TableCell>
                                                    )}
                                                    {columnVisibility.doc_sap && (
                                                        <TableCell className="py-3 text-right font-mono text-xs tabular-nums">
                                                            {row.sap_number}
                                                        </TableCell>
                                                    )}
                                                    {columnVisibility.descripcion && (
                                                        <TableCell className="max-w-[280px] truncate py-3 text-sm" title={row.description}>
                                                            {row.description}
                                                        </TableCell>
                                                    )}
                                                    {columnVisibility.cargo && (
                                                        <TableCell className="py-3 text-right text-xs font-medium tabular-nums text-rose-600 dark:text-rose-400">
                                                            {row.debit > 0 ? formatCurrency(row.debit) : ''}
                                                        </TableCell>
                                                    )}
                                                    {columnVisibility.abono && (
                                                        <TableCell className="py-3 text-right text-xs font-medium tabular-nums text-emerald-600 dark:text-emerald-400">
                                                            {row.credit > 0 ? formatCurrency(row.credit) : ''}
                                                        </TableCell>
                                                    )}
                                                    {columnVisibility.monto && (
                                                        <TableCell className="py-3 text-right text-xs font-semibold tabular-nums">
                                                            {row.type === 'vendor_payment' ? formatCurrency(row.amount) : ''}
                                                        </TableCell>
                                                    )}
                                                    {columnVisibility.sucursal && (
                                                        <TableCell className="max-w-[140px] truncate py-3 text-xs" title={row.branch}>
                                                            {row.branch}
                                                        </TableCell>
                                                    )}
                                                    {columnVisibility.cuenta && (
                                                        <TableCell className="max-w-[140px] truncate py-3 text-xs" title={row.bank_account}>
                                                            {row.bank_account}
                                                        </TableCell>
                                                    )}
                                                    {columnVisibility.usuario && (
                                                        <TableCell className="py-3 text-xs">{row.user || '-'}</TableCell>
                                                    )}
                                                    {columnVisibility.lote && (
                                                        <TableCell className="max-w-[180px] truncate py-3 text-xs" title={row.batch_filename}>
                                                            {row.batch_filename}
                                                        </TableCell>
                                                    )}
                                                </TableRow>
                                            ))}
                                    </TableBody>
                                </Table>
                            </div>

                            <div className="flex items-center justify-between">
                                <span className="text-sm text-muted-foreground">
                                    {result.total} registros encontrados
                                </span>
                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={result.current_page <= 1}
                                        onClick={() => handlePageChange(result.current_page - 1)}
                                    >
                                        <ChevronLeft className="h-4 w-4" />
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
                                        <ChevronRight className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col items-center gap-2 py-16 text-center text-muted-foreground">
                            <ClipboardList className="size-10 opacity-30" />
                            <p className="text-sm">No se encontraron transacciones con los filtros seleccionados</p>
                        </div>
                    )}
                </PageSection>
            </div>
        </AppLayout>
    );
}

const TONE: Record<'default' | 'danger' | 'success' | 'primary', string> = {
    default: 'text-foreground',
    danger: 'text-rose-600 dark:text-rose-400',
    success: 'text-emerald-600 dark:text-emerald-400',
    primary: 'text-primary',
};

function SummaryCard({
    label,
    value,
    icon: Icon,
    tone = 'default',
}: {
    label: string;
    value: string;
    icon: LucideIcon;
    tone?: 'default' | 'danger' | 'success' | 'primary';
}) {
    return (
        <Card>
            <CardContent className="flex items-center gap-3 py-4">
                <div className="rounded-md bg-muted p-2">
                    <Icon className="h-4 w-4 text-muted-foreground" />
                </div>
                <div className="flex flex-col">
                    <span className="text-xs uppercase tracking-wide text-muted-foreground">{label}</span>
                    <span className={cn('text-lg font-bold tabular-nums', TONE[tone])}>{value}</span>
                </div>
            </CardContent>
        </Card>
    );
}
