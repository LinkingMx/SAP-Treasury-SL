import { StatCard } from '@/components/page/detail-bits';
import { FilterField, FiltersCard } from '@/components/page/filters-card';
import { PageHeader } from '@/components/page/page-header';
import { PageSection } from '@/components/page/page-section';
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
    Clock,
    Filter,
    Landmark,
    LayoutGrid,
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

type AgingBucketKey = 'not_due' | '0_30' | '31_60' | '61_90' | '90_plus';

interface AgingCompany {
    company_db: string;
    branches: string[];
    buckets: Record<AgingBucketKey, number>;
    open_total: number;
    doc_count: number;
}

interface AgingData {
    available: true;
    kind: 'payables' | 'receivables';
    currency: string;
    as_of: string;
    consolidated: {
        open_total: number;
        overdue_total: number;
        doc_count: number;
        buckets: Record<AgingBucketKey, number>;
    };
    by_company: AgingCompany[];
    failed_branches: { company_db: string; branches: string[]; reason: string }[];
}

interface Props {
    branches: BranchOption[];
    filters: { branch_id: string };
    cash?: CashData | UnavailableData;
    payables?: AgingData | UnavailableData;
    receivables?: AgingData | UnavailableData;
}

const BUCKET_LABELS: Record<AgingBucketKey, string> = {
    not_due: 'Por vencer',
    '0_30': '0-30 días',
    '31_60': '31-60 días',
    '61_90': '61-90 días',
    '90_plus': '90+ días',
};

const BUCKET_TONE: Record<AgingBucketKey, string> = {
    not_due: 'text-foreground',
    '0_30': 'text-amber-600 dark:text-amber-400',
    '31_60': 'text-orange-600 dark:text-orange-400',
    '61_90': 'text-rose-600 dark:text-rose-400',
    '90_plus': 'text-rose-700 dark:text-rose-300 font-semibold',
};

function formatMXN(value: number): string {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(value);
}

export default function Dashboard({ branches, filters }: Props) {
    const [branchId, setBranchId] = useState(filters.branch_id);

    const applyFilters = (nextBranch: string) => {
        router.get(
            dashboard().url,
            nextBranch === 'all' ? {} : { branch_id: nextBranch },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Manager Dashboard" />
            <div className="space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={LayoutGrid}
                    title="Manager Dashboard"
                    description="Indicadores clave de tesorería: caja, cuentas por pagar y por cobrar."
                />

                <FiltersCard icon={Filter} columns={3}>
                    <FilterField label="Sucursal" htmlFor="branch">
                        <Select
                            value={branchId}
                            onValueChange={(v) => {
                                setBranchId(v);
                                applyFilters(v);
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
                </FiltersCard>

                {/* Cash position — live from SAP */}
                <Deferred data="cash" fallback={<SapSkeleton title="Posición de Efectivo" icon={Landmark} />}>
                    <CashContent />
                </Deferred>

                {/* AP — live from SAP */}
                <Deferred data="payables" fallback={<SapSkeleton title="Cuentas por Pagar" icon={Wallet} />}>
                    <AgingContent kind="payables" />
                </Deferred>

                {/* AR — live from SAP */}
                <Deferred data="receivables" fallback={<SapSkeleton title="Cuentas por Cobrar" icon={ReceiptText} />}>
                    <AgingContent kind="receivables" />
                </Deferred>
            </div>
        </AppLayout>
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

function AgingContent({ kind }: { kind: 'payables' | 'receivables' }) {
    const props = usePage<Props>().props;
    const data = kind === 'payables' ? props.payables : props.receivables;
    const title = kind === 'payables' ? 'Cuentas por Pagar' : 'Cuentas por Cobrar';
    const icon = kind === 'payables' ? Wallet : ReceiptText;
    const counterpartyLabel = kind === 'payables' ? 'Proveedores' : 'Clientes';

    if (!data) return null;
    if (data.available === false) {
        return <SapPlaceholder title={title} icon={icon} />;
    }

    const c = data.consolidated;
    const overdueRate = c.open_total > 0 ? Math.round((c.overdue_total / c.open_total) * 100) : 0;
    const bucketKeys: AgingBucketKey[] = ['not_due', '0_30', '31_60', '61_90', '90_plus'];

    return (
        <div className="space-y-4">
            <div className="grid gap-4 md:grid-cols-4">
                <StatCard icon={icon} label="Saldo abierto" value={formatMXN(c.open_total)} tone="primary" />
                <StatCard icon={AlertTriangle} label="Vencido" value={formatMXN(c.overdue_total)} tone="danger" />
                <StatCard icon={Clock} label="% vencido" value={`${overdueRate}%`} tone={overdueRate > 50 ? 'danger' : 'default'} />
                <StatCard icon={ReceiptText} label="Facturas abiertas" value={c.doc_count.toLocaleString('es-MX')} />
            </div>

            <PageSection
                icon={icon}
                title={`${title} — aging`}
                description={`Facturas abiertas al ${data.as_of} · ${data.currency} · ${data.by_company.length} empresas`}
            >
                <div className="grid gap-3 md:grid-cols-5">
                    {bucketKeys.map((k) => (
                        <div key={k} className="rounded-md border bg-card p-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {BUCKET_LABELS[k]}
                            </p>
                            <p className={`mt-1 text-sm tabular-nums ${BUCKET_TONE[k]}`}>
                                {formatMXN(c.buckets[k])}
                            </p>
                        </div>
                    ))}
                </div>

                <div className="mt-4 overflow-hidden rounded-md border">
                    <Table className="[&_td]:px-4 [&_th]:px-4">
                        <TableHeader className="bg-muted/50">
                            <TableRow className="hover:bg-muted/50">
                                <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Empresa (SAP)</TableHead>
                                <TableHead className="h-11 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{counterpartyLabel}</TableHead>
                                {bucketKeys.map((k) => (
                                    <TableHead key={k} className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                        {BUCKET_LABELS[k]}
                                    </TableHead>
                                ))}
                                <TableHead className="h-11 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.by_company.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={bucketKeys.length + 3} className="py-8 text-center text-sm text-muted-foreground">
                                        Sin facturas abiertas
                                    </TableCell>
                                </TableRow>
                            ) : (
                                data.by_company.map((row) => (
                                    <TableRow key={row.company_db}>
                                        <TableCell className="py-3 font-mono text-xs">{row.company_db}</TableCell>
                                        <TableCell className="py-3 text-right text-xs tabular-nums">{row.doc_count.toLocaleString('es-MX')}</TableCell>
                                        {bucketKeys.map((k) => (
                                            <TableCell key={k} className={`py-3 text-right text-xs tabular-nums ${BUCKET_TONE[k]}`}>
                                                {formatMXN(row.buckets[k])}
                                            </TableCell>
                                        ))}
                                        <TableCell className="py-3 text-right text-sm font-semibold tabular-nums">{formatMXN(row.open_total)}</TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {data.failed_branches.length > 0 && (
                    <p className="mt-3 text-xs text-muted-foreground">
                        {data.failed_branches.length} empresa(s) sin respuesta de SAP.
                    </p>
                )}
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
