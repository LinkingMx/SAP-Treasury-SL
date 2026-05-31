import { StatCard } from '@/components/page/detail-bits';
import { FilterField, FiltersCard } from '@/components/page/filters-card';
import { PageHeader } from '@/components/page/page-header';
import { PageSection } from '@/components/page/page-section';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Deferred, Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    CheckCircle2,
    Clock,
    Filter,
    Landmark,
    LayoutGrid,
    ListChecks,
    Loader2,
    PlugZap,
    ReceiptText,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manager Dashboard', href: dashboard().url },
];

interface BranchOption {
    id: number;
    name: string;
}

interface ReconciliationData {
    cards: {
        auto_post_rate: number | null;
        posted_tx: number;
        pending_tx: number;
        failed_batches: number;
        pending_batches: number;
        avg_processing_hours: number | null;
    };
    batch_status: { source: string; status: string; count: number }[];
    recent_failures: {
        type: string;
        id: number;
        branch: string | null;
        filename: string | null;
        created_at: string | null;
    }[];
}

interface UnavailableData {
    available: false;
    reason: string;
}

interface CashCompany {
    company_db: string;
    branches: string[];
    caja: number;
    banco: number;
    total: number;
}

interface CashData {
    available: true;
    currency: string;
    consolidated: { caja: number; banco: number; total: number };
    by_company: CashCompany[];
    failed_branches: { company_db: string; branches: string[]; reason: string }[];
}

interface Props {
    branches: BranchOption[];
    filters: { branch_id: string; date_from: string; date_to: string };
    reconciliation?: ReconciliationData;
    cash?: CashData | UnavailableData;
    payables?: UnavailableData | Record<string, unknown>;
    receivables?: UnavailableData | Record<string, unknown>;
}

function formatMXN(value: number): string {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(value);
}

const STATUS_LABELS: Record<string, string> = {
    pending: 'Pendiente',
    processing: 'Procesando',
    completed: 'Completado',
    failed: 'Fallido',
    sent: 'Enviado',
    cancelled: 'Cancelado',
};

const STATUS_TONE: Record<string, string> = {
    completed: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    sent: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    processing: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    pending: 'bg-muted text-muted-foreground',
    failed: 'bg-destructive/15 text-destructive',
    cancelled: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
};

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

export default function Dashboard({ branches, filters }: Props) {
    const [branchId, setBranchId] = useState(filters.branch_id);
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);

    const applyFilters = (next: Partial<{ branch_id: string; date_from: string; date_to: string }>) => {
        const params = {
            branch_id: next.branch_id ?? branchId,
            date_from: next.date_from ?? dateFrom,
            date_to: next.date_to ?? dateTo,
        };
        router.get(dashboard().url, params.branch_id === 'all' ? { ...params, branch_id: undefined } : params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Manager Dashboard" />
            <div className="space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={LayoutGrid}
                    title="Manager Dashboard"
                    description="Indicadores clave de tesorería: conciliación, caja, pagos y cobros."
                />

                <FiltersCard icon={Filter} columns={3}>
                    <FilterField label="Sucursal" htmlFor="branch">
                        <Select
                            value={branchId}
                            onValueChange={(v) => {
                                setBranchId(v);
                                applyFilters({ branch_id: v });
                            }}
                        >
                            <SelectTrigger id="branch">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Todas las sucursales</SelectItem>
                                {branches.map((b) => (
                                    <SelectItem key={b.id} value={String(b.id)}>
                                        {b.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FilterField>
                    <FilterField label="Desde" htmlFor="from">
                        <Input
                            id="from"
                            type="date"
                            value={dateFrom}
                            onChange={(e) => setDateFrom(e.target.value)}
                            onBlur={() => applyFilters({ date_from: dateFrom })}
                        />
                    </FilterField>
                    <FilterField label="Hasta" htmlFor="to">
                        <Input
                            id="to"
                            type="date"
                            value={dateTo}
                            onChange={(e) => setDateTo(e.target.value)}
                            onBlur={() => applyFilters({ date_to: dateTo })}
                        />
                    </FilterField>
                </FiltersCard>

                {/* Reconciliation / operations — local, fast */}
                <Deferred
                    data="reconciliation"
                    fallback={
                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                            {[...Array(4)].map((_, i) => (
                                <Skeleton key={i} className="h-20 w-full rounded-md" />
                            ))}
                        </div>
                    }
                >
                    <ReconciliationContent />
                </Deferred>

                {/* Cash position — live from SAP */}
                <Deferred data="cash" fallback={<SapSkeleton title="Posición de Efectivo" icon={Landmark} />}>
                    <CashContent />
                </Deferred>

                {/* AP / AR — SAP-backed, next phases */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <Deferred data="payables" fallback={<SapSkeleton title="Cuentas por Pagar" icon={Wallet} />}>
                        <SapPlaceholder title="Cuentas por Pagar" icon={Wallet} />
                    </Deferred>
                    <Deferred data="receivables" fallback={<SapSkeleton title="Cuentas por Cobrar" icon={ReceiptText} />}>
                        <SapPlaceholder title="Cuentas por Cobrar" icon={ReceiptText} />
                    </Deferred>
                </div>
            </div>
        </AppLayout>
    );
}

function ReconciliationContent() {
    const { reconciliation: data } = usePage<Props>().props;
    if (!data) return null;
    const c = data.cards;
    return (
        <div className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    icon={CheckCircle2}
                    label="Tasa de posteo automático"
                    value={c.auto_post_rate !== null ? `${c.auto_post_rate}%` : '—'}
                    tone="success"
                />
                <StatCard
                    icon={Clock}
                    label="Transacciones pendientes"
                    value={c.pending_tx.toLocaleString('es-MX')}
                    tone={c.pending_tx > 0 ? 'primary' : 'default'}
                />
                <StatCard
                    icon={AlertTriangle}
                    label="Lotes fallidos"
                    value={c.failed_batches.toLocaleString('es-MX')}
                    tone={c.failed_batches > 0 ? 'danger' : 'default'}
                />
                <StatCard
                    icon={Loader2}
                    label="Horas prom. de proceso"
                    value={c.avg_processing_hours !== null ? `${c.avg_processing_hours} h` : '—'}
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <PageSection icon={ListChecks} title="Lotes por estado" description="Distribución del rango seleccionado.">
                    <div className="overflow-hidden rounded-md border">
                        <Table className="[&_td]:px-4 [&_th]:px-4">
                            <TableHeader className="bg-muted/50">
                                <TableRow className="hover:bg-muted/50">
                                    <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Origen</TableHead>
                                    <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Estado</TableHead>
                                    <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Lotes</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.batch_status.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={3} className="py-8 text-center text-sm text-muted-foreground">
                                            Sin lotes en el rango
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    data.batch_status.map((row, i) => (
                                        <TableRow key={`${row.source}-${row.status}-${i}`}>
                                            <TableCell className="py-3 text-sm">{row.source}</TableCell>
                                            <TableCell className="py-3">
                                                <Badge className={`rounded-full border-transparent ${STATUS_TONE[row.status] ?? 'bg-muted text-muted-foreground'}`}>
                                                    {STATUS_LABELS[row.status] ?? row.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="py-3 text-right tabular-nums">{row.count}</TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </PageSection>

                <PageSection icon={AlertTriangle} title="Fallos recientes" description="Últimos lotes/extractos con error.">
                    <div className="overflow-hidden rounded-md border">
                        <Table className="[&_td]:px-4 [&_th]:px-4">
                            <TableHeader className="bg-muted/50">
                                <TableRow className="hover:bg-muted/50">
                                    <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Tipo</TableHead>
                                    <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Sucursal</TableHead>
                                    <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Archivo</TableHead>
                                    <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Fecha</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.recent_failures.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={4} className="py-8 text-center text-sm text-muted-foreground">
                                            Sin fallos recientes
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    data.recent_failures.map((f, i) => (
                                        <TableRow key={`${f.type}-${f.id}-${i}`}>
                                            <TableCell className="py-3">
                                                <Badge variant="outline" className="rounded-full text-xs">{f.type}</Badge>
                                            </TableCell>
                                            <TableCell className="max-w-[140px] truncate py-3 text-xs">{f.branch ?? '—'}</TableCell>
                                            <TableCell className="max-w-[180px] truncate py-3 text-xs" title={f.filename ?? ''}>
                                                {f.filename ?? '—'}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap py-3 text-xs tabular-nums">{formatDate(f.created_at)}</TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </PageSection>
            </div>
        </div>
    );
}

function CashContent() {
    const { cash } = usePage<Props>().props;
    if (!cash) return null;
    if (cash.available === false) {
        return <SapPlaceholder title="Posición de Efectivo" icon={Landmark} />;
    }

    const c = cash.consolidated;
    return (
        <div className="space-y-4">
            <div className="grid gap-4 md:grid-cols-3">
                <StatCard icon={Banknote} label="Caja / efectivo" value={formatMXN(c.caja)} tone="default" />
                <StatCard icon={Landmark} label="Bancos" value={formatMXN(c.banco)} tone={c.banco < 0 ? 'danger' : 'success'} />
                <StatCard icon={Wallet} label="Posición total" value={formatMXN(c.total)} tone={c.total < 0 ? 'danger' : 'primary'} />
            </div>

            <PageSection
                icon={Landmark}
                title="Posición por empresa"
                description={`Saldo GL (cuentas 1010 caja / 1020 banco) · ${cash.currency} · ${cash.by_company.length} empresas`}
            >
                <div className="overflow-hidden rounded-md border">
                    <Table className="[&_td]:px-4 [&_th]:px-4">
                        <TableHeader className="bg-muted/50">
                            <TableRow className="hover:bg-muted/50">
                                <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Empresa (SAP)</TableHead>
                                <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Sucursales</TableHead>
                                <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Caja</TableHead>
                                <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Bancos</TableHead>
                                <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {cash.by_company.map((row) => (
                                <TableRow key={row.company_db}>
                                    <TableCell className="py-3 font-mono text-xs">{row.company_db}</TableCell>
                                    <TableCell className="max-w-[260px] py-3 text-xs text-muted-foreground">
                                        <span className="line-clamp-2">{row.branches.join(', ')}</span>
                                    </TableCell>
                                    <TableCell className="py-3 text-right text-xs tabular-nums">{formatMXN(row.caja)}</TableCell>
                                    <TableCell className={`py-3 text-right text-xs tabular-nums ${row.banco < 0 ? 'text-rose-600 dark:text-rose-400' : ''}`}>
                                        {formatMXN(row.banco)}
                                    </TableCell>
                                    <TableCell className="py-3 text-right text-sm font-semibold tabular-nums">{formatMXN(row.total)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </PageSection>
        </div>
    );
}

function SapSkeleton({ title, icon: Icon }: { title: string; icon: typeof Landmark }) {
    return (
        <PageSection icon={Icon} title={title} description="Cargando desde SAP…">
            <div className="space-y-2">
                {[...Array(3)].map((_, i) => (
                    <Skeleton key={i} className="h-8 w-full" />
                ))}
            </div>
        </PageSection>
    );
}

function SapPlaceholder({
    title,
    icon: Icon,
}: {
    title: string;
    icon: typeof Landmark;
}) {
    return (
        <PageSection icon={Icon} title={title} description="Requiere conexión a SAP Business One.">
            <div className="flex flex-col items-center justify-center gap-2 py-10 text-center text-muted-foreground">
                <PlugZap className="size-8 opacity-30" />
                <p className="text-sm">Sin conexión a SAP en este entorno</p>
                <p className="text-xs">Se habilitará al conectar con la base SAP de producción.</p>
            </div>
        </PageSection>
    );
}
