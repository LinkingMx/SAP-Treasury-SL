import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BankAccount, type Branch, type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    AlertTriangle,
    ArrowDownRight,
    ArrowUpRight,
    Banknote,
    CircleDollarSign,
    Clock,
    FileSpreadsheet,
    Layers,
    TrendingUp,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Panel', href: dashboard().url },
];

interface DashboardProps {
    branches: Branch[];
    bankAccounts: BankAccount[];
}

interface KpiData {
    pending_batches: number;
    failed_items: number;
    processed_amount: { debit: number; credit: number };
    vendor_payments_amount: number;
}

interface CashflowPoint {
    date: string;
    debit: number;
    credit: number;
}

interface BatchStatusData {
    completed: number;
    pending: number;
    processing: number;
    failed: number;
}

interface ActivityItem {
    id: number;
    type: 'batch' | 'vendor_payment';
    filename: string;
    status: string;
    total_records: number;
    amount: number;
    user: string | null;
    created_at: string;
}

interface FailedBatch {
    id: number;
    uuid: string;
    type: 'batch' | 'vendor_payment';
    filename: string;
    error: string | null;
    total_records: number;
    branch: string | null;
    bank_account: string | null;
    created_at: string;
}

interface StatsResponse {
    kpis: KpiData;
    cashflow: CashflowPoint[];
    batch_status: BatchStatusData;
    recent_activity: ActivityItem[];
    failed_batches: FailedBatch[];
}

const STATUS_COLORS: Record<string, string> = {
    completed: 'hsl(var(--color-chart-2, 142 71% 45%))',
    pending: 'hsl(var(--color-chart-4, 43 74% 66%))',
    processing: 'hsl(var(--color-chart-1, 220 70% 50%))',
    failed: 'hsl(var(--color-chart-5, 0 84% 60%))',
};

const STATUS_LABELS: Record<string, string> = {
    completed: 'Completado',
    pending: 'Pendiente',
    processing: 'Procesando',
    failed: 'Fallido',
};

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value);
}

function formatCompact(value: number): string {
    if (value >= 1_000_000) return `$${(value / 1_000_000).toFixed(1)}M`;
    if (value >= 1_000) return `$${(value / 1_000).toFixed(0)}K`;
    return formatCurrency(value);
}

function timeAgo(dateStr: string): string {
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 60) return `hace ${mins}m`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `hace ${hours}h`;
    const days = Math.floor(hours / 24);
    return `hace ${days}d`;
}

export default function Dashboard({ branches, bankAccounts }: DashboardProps) {
    const [branchId, setBranchId] = useState<string>('all');
    const [loading, setLoading] = useState(true);
    const [stats, setStats] = useState<StatsResponse | null>(null);

    const csrfToken = usePage().props._token as string | undefined;

    const fetchStats = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (branchId !== 'all') params.set('branch_id', branchId);

            const res = await fetch(`/dashboard/stats?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken || document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                },
            });

            if (res.ok) {
                setStats(await res.json());
            }
        } catch {
            // Silently fail - dashboard will show empty state
        } finally {
            setLoading(false);
        }
    }, [branchId, csrfToken]);

    useEffect(() => {
        fetchStats();
    }, [fetchStats]);

    const pieData = useMemo(() => {
        if (!stats) return [];
        return Object.entries(stats.batch_status)
            .filter(([, count]) => count > 0)
            .map(([status, count]) => ({
                name: STATUS_LABELS[status] || status,
                value: count,
                color: STATUS_COLORS[status] || '#94a3b8',
            }));
    }, [stats]);

    const totalBatches = useMemo(() => {
        if (!stats) return 0;
        return Object.values(stats.batch_status).reduce((a, b) => a + b, 0);
    }, [stats]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Panel" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
                {/* Filters */}
                <div className="flex items-center gap-3">
                    <Select value={branchId} onValueChange={setBranchId}>
                        <SelectTrigger className="w-full max-w-md">
                            <SelectValue placeholder="Todas las sucursales" />
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
                    <span className="text-muted-foreground text-sm">Periodo: mes actual</span>
                </div>

                {/* KPI Cards */}
                <div className="grid gap-4 md:grid-cols-4">
                    <KpiCard
                        title="Lotes pendientes"
                        icon={<Clock className="size-4" />}
                        loading={loading}
                        value={stats?.kpis.pending_batches ?? 0}
                        format="number"
                        description="Pendientes o con error"
                        variant={stats && stats.kpis.pending_batches > 0 ? 'warning' : 'default'}
                    />
                    <KpiCard
                        title="Items fallidos"
                        icon={<AlertTriangle className="size-4" />}
                        loading={loading}
                        value={stats?.kpis.failed_items ?? 0}
                        format="number"
                        description="Transacciones con error"
                        variant={stats && stats.kpis.failed_items > 0 ? 'destructive' : 'default'}
                    />
                    <KpiCard
                        title="Procesado (mes)"
                        icon={<TrendingUp className="size-4" />}
                        loading={loading}
                        value={(stats?.kpis.processed_amount.debit ?? 0) + (stats?.kpis.processed_amount.credit ?? 0)}
                        format="currency"
                        description="Movimientos bancarios a SAP"
                    />
                    <KpiCard
                        title="Pagos a proveedores"
                        icon={<Banknote className="size-4" />}
                        loading={loading}
                        value={stats?.kpis.vendor_payments_amount ?? 0}
                        format="currency"
                        description="Vendor payments procesados"
                    />
                </div>

                {/* Charts Row */}
                <div className="grid gap-4 md:grid-cols-3">
                    {/* Cashflow Chart */}
                    <Card className="md:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <CircleDollarSign className="size-4" />
                                Flujo de efectivo
                            </CardTitle>
                            <CardDescription>Cargos y abonos procesados por dia</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {loading ? (
                                <Skeleton className="h-[280px] w-full" />
                            ) : stats && stats.cashflow.length > 0 ? (
                                <ResponsiveContainer width="100%" height={280}>
                                    <AreaChart data={stats.cashflow} margin={{ top: 5, right: 10, left: 0, bottom: 0 }}>
                                        <defs>
                                            <linearGradient id="debitGrad" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="hsl(0 84% 60%)" stopOpacity={0.3} />
                                                <stop offset="95%" stopColor="hsl(0 84% 60%)" stopOpacity={0} />
                                            </linearGradient>
                                            <linearGradient id="creditGrad" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="hsl(142 71% 45%)" stopOpacity={0.3} />
                                                <stop offset="95%" stopColor="hsl(142 71% 45%)" stopOpacity={0} />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                                        <XAxis dataKey="date" className="text-xs" tick={{ fontSize: 11 }} />
                                        <YAxis
                                            className="text-xs"
                                            tick={{ fontSize: 11 }}
                                            tickFormatter={(v) => formatCompact(v)}
                                        />
                                        <Tooltip
                                            contentStyle={{
                                                backgroundColor: 'hsl(var(--popover))',
                                                border: '1px solid hsl(var(--border))',
                                                borderRadius: '8px',
                                                fontSize: '12px',
                                            }}
                                            formatter={(value) => [formatCurrency(Number(value))]}
                                            labelStyle={{ fontWeight: 600 }}
                                        />
                                        <Area
                                            type="monotone"
                                            dataKey="debit"
                                            name="Cargos"
                                            stroke="hsl(0 84% 60%)"
                                            fill="url(#debitGrad)"
                                            strokeWidth={2}
                                        />
                                        <Area
                                            type="monotone"
                                            dataKey="credit"
                                            name="Abonos"
                                            stroke="hsl(142 71% 45%)"
                                            fill="url(#creditGrad)"
                                            strokeWidth={2}
                                        />
                                    </AreaChart>
                                </ResponsiveContainer>
                            ) : (
                                <div className="text-muted-foreground flex h-[280px] items-center justify-center text-sm">
                                    Sin movimientos en el periodo
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Batch Status Donut */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Layers className="size-4" />
                                Estado de lotes
                            </CardTitle>
                            <CardDescription>{totalBatches} lotes en el periodo</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {loading ? (
                                <Skeleton className="mx-auto size-[200px] rounded-full" />
                            ) : pieData.length > 0 ? (
                                <div className="flex flex-col items-center gap-4">
                                    <ResponsiveContainer width="100%" height={200}>
                                        <PieChart>
                                            <Pie
                                                data={pieData}
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={55}
                                                outerRadius={85}
                                                paddingAngle={3}
                                                dataKey="value"
                                            >
                                                {pieData.map((entry, i) => (
                                                    <Cell key={i} fill={entry.color} />
                                                ))}
                                            </Pie>
                                            <Tooltip
                                                contentStyle={{
                                                    backgroundColor: 'hsl(var(--popover))',
                                                    border: '1px solid hsl(var(--border))',
                                                    borderRadius: '8px',
                                                    fontSize: '12px',
                                                }}
                                            />
                                        </PieChart>
                                    </ResponsiveContainer>
                                    <div className="flex flex-wrap justify-center gap-3">
                                        {pieData.map((entry) => (
                                            <div key={entry.name} className="flex items-center gap-1.5 text-xs">
                                                <div
                                                    className="size-2.5 rounded-full"
                                                    style={{ backgroundColor: entry.color }}
                                                />
                                                <span className="text-muted-foreground">
                                                    {entry.name}: {entry.value}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : (
                                <div className="text-muted-foreground flex h-[200px] items-center justify-center text-sm">
                                    Sin lotes en el periodo
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Tables Row */}
                <div className="grid gap-4 md:grid-cols-2">
                    {/* Recent Activity */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileSpreadsheet className="size-4" />
                                Actividad reciente
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {loading ? (
                                <div className="flex flex-col gap-3">
                                    {[...Array(5)].map((_, i) => (
                                        <Skeleton key={i} className="h-12 w-full" />
                                    ))}
                                </div>
                            ) : stats && stats.recent_activity.length > 0 ? (
                                <div className="flex flex-col gap-2">
                                    {stats.recent_activity.map((item) => (
                                        <div
                                            key={`${item.type}-${item.id}`}
                                            className="flex items-center justify-between rounded-lg border px-3 py-2"
                                        >
                                            <div className="flex flex-col gap-0.5">
                                                <div className="flex items-center gap-2">
                                                    <span className="max-w-[200px] truncate text-sm font-medium">
                                                        {item.filename}
                                                    </span>
                                                    <StatusBadge status={item.status} />
                                                </div>
                                                <span className="text-muted-foreground text-xs">
                                                    {item.type === 'vendor_payment' ? 'Pago a proveedor' : 'Lote'} &middot;{' '}
                                                    {item.total_records} registros &middot; {item.user} &middot;{' '}
                                                    {timeAgo(item.created_at)}
                                                </span>
                                            </div>
                                            <span className="text-sm font-medium tabular-nums">
                                                {formatCurrency(item.amount)}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-muted-foreground py-8 text-center text-sm">Sin actividad reciente</div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Failed Batches */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AlertTriangle className="size-4 text-destructive" />
                                Lotes con errores
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {loading ? (
                                <div className="flex flex-col gap-3">
                                    {[...Array(3)].map((_, i) => (
                                        <Skeleton key={i} className="h-12 w-full" />
                                    ))}
                                </div>
                            ) : stats && stats.failed_batches.length > 0 ? (
                                <div className="flex flex-col gap-2">
                                    {stats.failed_batches.map((item) => (
                                        <div
                                            key={`${item.type}-${item.id}`}
                                            className="flex flex-col gap-1 rounded-lg border border-destructive/20 bg-destructive/5 px-3 py-2"
                                        >
                                            <div className="flex items-center justify-between">
                                                <span className="max-w-[250px] truncate text-sm font-medium">
                                                    {item.filename}
                                                </span>
                                                <span className="text-muted-foreground text-xs">
                                                    {timeAgo(item.created_at)}
                                                </span>
                                            </div>
                                            <span className="text-muted-foreground text-xs">
                                                {item.branch} &middot; {item.bank_account} &middot; {item.total_records}{' '}
                                                registros
                                            </span>
                                            {item.error && (
                                                <span className="text-xs text-destructive">{item.error}</span>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="py-8 text-center text-sm text-emerald-600 dark:text-emerald-400">
                                    Sin errores pendientes
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

function KpiCard({
    title,
    icon,
    loading,
    value,
    format,
    description,
    variant = 'default',
}: {
    title: string;
    icon: React.ReactNode;
    loading: boolean;
    value: number;
    format: 'number' | 'currency';
    description: string;
    variant?: 'default' | 'warning' | 'destructive';
}) {
    const variantClasses = {
        default: '',
        warning: value > 0 ? 'border-yellow-500/30 bg-yellow-50/50 dark:bg-yellow-950/20' : '',
        destructive: value > 0 ? 'border-destructive/30 bg-destructive/5' : '',
    };

    return (
        <Card className={variantClasses[variant]}>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardDescription className="text-sm font-medium">{title}</CardDescription>
                <span className="text-muted-foreground">{icon}</span>
            </CardHeader>
            <CardContent>
                {loading ? (
                    <Skeleton className="h-8 w-24" />
                ) : (
                    <>
                        <div className="text-2xl font-bold tabular-nums">
                            {format === 'currency' ? formatCurrency(value) : value}
                        </div>
                        <p className="text-muted-foreground mt-1 text-xs">{description}</p>
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function StatusBadge({ status }: { status: string }) {
    const config: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string }> = {
        completed: { variant: 'default', label: 'Completado' },
        pending: { variant: 'outline', label: 'Pendiente' },
        processing: { variant: 'secondary', label: 'Procesando' },
        failed: { variant: 'destructive', label: 'Fallido' },
    };

    const c = config[status] || { variant: 'outline' as const, label: status };

    return (
        <Badge variant={c.variant} className="text-[10px]">
            {c.label}
        </Badge>
    );
}
