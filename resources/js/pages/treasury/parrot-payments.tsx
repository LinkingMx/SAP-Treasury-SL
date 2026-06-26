import { FilterField, FiltersCard } from '@/components/page/filters-card';
import { PageHeader } from '@/components/page/page-header';
import { PageSection } from '@/components/page/page-section';
import { StatCard } from '@/components/page/detail-bits';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Banknote, Bike, CheckCircle2, Clock, Coins, CreditCard, FileWarning, Filter, Loader2, type LucideIcon, Receipt, Save, Search, Truck, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Panel', href: '/dashboard' },
    { title: 'Pagos Parrot', href: '/treasury/parrot-payments' },
];

interface BranchOption {
    id: number;
    name: string;
}

interface PaymentTypeTotal {
    payment_type_name: string;
    count: number;
    sum_amount: number;
    sum_tip: number;
    sum_total: number;
    has_settlements: boolean;
    matched_count: number;
    matched_sum: number;
    reconciled_pct: number;
}

interface DataResponse {
    success: boolean;
    branch: { id: number; name: string; payment_branch: string };
    period: { from: string; to: string };
    window: { from: string; to: string };
    totals: { count: number; sum_amount: number; sum_tip: number; sum_total: number };
    by_payment_type: PaymentTypeTotal[];
    reconciliation: Record<string, AcquirerRecon>;
}

interface AcquirerRecon {
    name: string;
    settlements: number;
    saved: number;
    proposed: number;
    matched: number;
    orphans: number;
    pending: number;
}

interface MatchedPair {
    settlement: { id: number; reference: string | null; transaction_date: string; transaction_time: string | null; amount: number; status: string | null };
    payment: { id: number; business_day: string | null; total: number; order_reference: string | null };
    diff: number;
    saved: boolean;
}

interface OrphanRow {
    id: number;
    transaction_date: string;
    transaction_time: string | null;
    amount: number;
    reference: string | null;
    authorization: string | null;
    status: string | null;
}

interface PendingRow {
    id: number;
    created_at_pos: string | null;
    business_day: string;
    total: number;
    order_reference: string | null;
}

interface DetailResponse {
    success: boolean;
    payment_type: string;
    matched: MatchedPair[];
    orphans: OrphanRow[];
    pending: PendingRow[];
    summary: { matched: number; orphans: number; pending: number; saved: number };
}

interface Props {
    branches: BranchOption[];
}

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(value);
}

function formatInt(value: number): string {
    return new Intl.NumberFormat('es-MX').format(value);
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

function iconForType(type: string): LucideIcon {
    const t = type.toUpperCase();
    if (t.includes('EFECTIVO') || t.includes('TRANSFER')) return Banknote;
    if (t.includes('RAPPI')) return Bike;
    if (t.includes('UBER')) return Truck;
    if (t.includes('CRED') || t.includes('DEB') || t.includes('AMEX')) return CreditCard;
    return Coins;
}

export default function ParrotPayments({ branches }: Props) {
    const defaultRange = useMemo(() => getMonthRange(), []);

    const [branchId, setBranchId] = useState<string>('');
    const [dateFrom, setDateFrom] = useState(defaultRange.from);
    const [dateTo, setDateTo] = useState(defaultRange.to);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [result, setResult] = useState<DataResponse | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailType, setDetailType] = useState<string>('');
    const [detail, setDetail] = useState<DetailResponse | null>(null);

    const handleConsultar = async () => {
        if (!branchId) return;
        setLoading(true);
        setError(null);
        try {
            const params = new URLSearchParams({ branch_id: branchId, date_from: dateFrom, date_to: dateTo });
            const res = await fetch(`/treasury/parrot-payments/data?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                },
            });
            const json = await res.json();
            if (res.ok && json.success) {
                setResult(json);
            } else {
                setResult(null);
                setError(json.error ?? 'No se pudo consultar el API de pagos.');
            }
        } catch {
            setResult(null);
            setError('Error de red al consultar el API de pagos.');
        } finally {
            setLoading(false);
        }
    };

    const handleSave = async () => {
        if (!branchId) return;
        setSaving(true);
        setError(null);
        try {
            const res = await fetch('/treasury/parrot-payments/reconcile', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ branch_id: branchId, date_from: dateFrom, date_to: dateTo }),
            });
            const json = await res.json();
            if (res.ok && json.success) {
                await handleConsultar();
            } else {
                setError(json.error ?? 'No se pudo guardar la conciliación.');
            }
        } catch {
            setError('Error de red al guardar la conciliación.');
        } finally {
            setSaving(false);
        }
    };

    const openDetail = async (paymentType: string) => {
        setDetailType(paymentType);
        setDetail(null);
        setDetailLoading(true);
        setDetailOpen(true);
        try {
            const params = new URLSearchParams({ branch_id: branchId, date_from: dateFrom, date_to: dateTo, payment_type: paymentType });
            const res = await fetch(`/treasury/parrot-payments/detail?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                },
            });
            const json = await res.json();
            if (res.ok && json.success) setDetail(json);
        } catch {
            // keep modal open; empty detail
        } finally {
            setDetailLoading(false);
        }
    };

    const sectionDescription = result
        ? `${result.branch.name} · día operativo ${result.window.from.replace('T', ' ')} → ${result.window.to.replace('T', ' ')}`
        : 'Selecciona una sucursal y un rango de fechas para consultar.';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pagos Parrot" />
            <div className="space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={Receipt}
                    title="Pagos Parrot"
                    description="Totales de pagos del POS (Parrot) por tipo de pago, consultados en vivo desde gCore."
                />

                <FiltersCard icon={Filter} columns={3}>
                    <FilterField label="Sucursal" htmlFor="branch">
                        <Select value={branchId} onValueChange={setBranchId}>
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

                    <FilterField label="Fecha desde" htmlFor="date-from">
                        <Input id="date-from" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                    </FilterField>

                    <FilterField label="Fecha hasta" htmlFor="date-to">
                        <Input id="date-to" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                    </FilterField>

                    <div className="flex flex-col items-start gap-2 pt-1 sm:flex-row sm:items-center sm:justify-between lg:col-span-3">
                        <p className="text-xs text-muted-foreground">
                            Día operativo de restaurante: de 5:00 a.m. a 5:00 a.m. del día siguiente. El 31 de mayo cierra el 1 de junio a las 5:00 a.m.
                        </p>
                        <Button onClick={handleConsultar} disabled={!branchId || loading}>
                            <Search className="h-4 w-4" />
                            {loading ? 'Consultando…' : 'Consultar'}
                        </Button>
                    </div>
                </FiltersCard>

                <PageSection icon={Wallet} title="Totales por tipo de pago" description={sectionDescription}>
                    {loading ? (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            {Array.from({ length: 8 }).map((_, i) => (
                                <Skeleton key={i} className="h-20 rounded-md" />
                            ))}
                        </div>
                    ) : error ? (
                        <p className="rounded-md bg-rose-500/10 p-4 text-sm text-rose-600 dark:text-rose-400">{error}</p>
                    ) : !result ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            Sin datos todavía. Elige los filtros y presiona <span className="font-medium">Consultar</span>.
                        </p>
                    ) : result.by_payment_type.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted-foreground">Sin pagos cobrados en el periodo seleccionado.</p>
                    ) : (
                        <div className="space-y-5">
                            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                                <StatCard icon={Wallet} label="Total general" value={formatCurrency(result.totals.sum_total)} tone="primary" />
                                <StatCard icon={Receipt} label="# Pagos" value={formatInt(result.totals.count)} />
                                <StatCard icon={Coins} label="Monto sin propina" value={formatCurrency(result.totals.sum_amount)} />
                                <StatCard icon={Coins} label="Propinas" value={formatCurrency(result.totals.sum_tip)} tone="success" />
                            </div>

                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                {result.by_payment_type.map((t) => {
                                    const Icon = iconForType(t.payment_type_name);
                                    const fully = t.has_settlements && t.matched_count >= t.count;

                                    return (
                                        <div
                                            key={t.payment_type_name}
                                            className={cn(
                                                'rounded-md bg-muted/40 p-3',
                                                t.has_settlements && 'cursor-pointer transition-colors hover:bg-muted/70',
                                            )}
                                            role={t.has_settlements ? 'button' : undefined}
                                            onClick={t.has_settlements ? () => openDetail(t.payment_type_name) : undefined}
                                        >
                                            <div className="flex items-center gap-1.5 text-xs uppercase tracking-wide text-muted-foreground">
                                                <Icon className="h-3 w-3" />
                                                {t.payment_type_name} · {formatInt(t.count)}
                                            </div>
                                            <p className="mt-1 text-base font-semibold tabular-nums">{formatCurrency(t.sum_total)}</p>
                                            {t.has_settlements ? (
                                                <p
                                                    className={cn(
                                                        'mt-1.5 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium tabular-nums',
                                                        fully
                                                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                            : 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                                                    )}
                                                >
                                                    {fully ? <CheckCircle2 className="h-3 w-3" /> : null}
                                                    {fully
                                                        ? `Conciliado ${formatInt(t.matched_count)}/${formatInt(t.count)}`
                                                        : `${formatInt(t.matched_count)}/${formatInt(t.count)} · ${t.reconciled_pct}%`}
                                                </p>
                                            ) : (
                                                <p className="mt-1.5 text-xs text-muted-foreground/60">Sin estado de cuenta</p>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>

                            {(() => {
                                const rec = Object.values(result.reconciliation ?? {});
                                if (rec.length === 0) return null;
                                const sum = (k: 'settlements' | 'matched' | 'saved' | 'proposed' | 'pending') =>
                                    rec.reduce((a, r) => a + r[k], 0);
                                const settlements = sum('settlements');
                                const matched = sum('matched');
                                const saved = sum('saved');
                                const proposed = sum('proposed');
                                const pending = sum('pending');

                                return (
                                    <div className="flex flex-col gap-2 rounded-md border bg-muted/30 p-3 sm:flex-row sm:items-center sm:justify-between">
                                        <p className="text-sm text-muted-foreground">
                                            Conciliación:{' '}
                                            <span className="font-medium tabular-nums text-foreground">
                                                {formatInt(matched)}/{formatInt(settlements)}
                                            </span>{' '}
                                            · Guardados <span className="tabular-nums">{formatInt(saved)}</span> · Sin guardar{' '}
                                            <span className="tabular-nums">{formatInt(proposed)}</span> · Pendientes{' '}
                                            <span className="tabular-nums">{formatInt(pending)}</span>
                                        </p>
                                        {proposed > 0 ? (
                                            <Button onClick={handleSave} disabled={saving}>
                                                {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                                                {saving ? 'Guardando…' : `Conciliar y guardar (${formatInt(proposed)})`}
                                            </Button>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 text-sm text-emerald-600 dark:text-emerald-400">
                                                <CheckCircle2 className="h-4 w-4" /> Todo guardado
                                            </span>
                                        )}
                                    </div>
                                );
                            })()}
                        </div>
                    )}
                </PageSection>
            </div>

            <Dialog open={detailOpen} onOpenChange={setDetailOpen}>
                <DialogContent className="flex max-h-[85vh] max-w-3xl flex-col overflow-hidden">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <CheckCircle2 className="h-4 w-4 text-muted-foreground" />
                            Conciliación — {detailType}
                        </DialogTitle>
                        <DialogDescription className="text-xs">
                            {result ? `${result.branch.name} · ${result.period.from} a ${result.period.to}` : ''}
                        </DialogDescription>
                    </DialogHeader>

                    {detailLoading ? (
                        <div className="flex items-center justify-center py-12">
                            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                        </div>
                    ) : detail ? (
                        <div className="flex-1 space-y-5 overflow-y-auto pr-1">
                            <div className="grid grid-cols-3 gap-3">
                                <StatCard icon={CheckCircle2} label="Conciliados" value={formatInt(detail.summary.matched)} tone="success" />
                                <StatCard icon={FileWarning} label="Huérfanos" value={formatInt(detail.summary.orphans)} tone={detail.summary.orphans > 0 ? 'danger' : 'default'} />
                                <StatCard icon={Clock} label="Pendientes" value={formatInt(detail.summary.pending)} tone={detail.summary.pending > 0 ? 'danger' : 'default'} />
                            </div>

                            {detail.pending.length > 0 ? (
                                <div>
                                    <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">
                                        Pendientes — pagos sin estado de cuenta ({detail.pending.length})
                                    </h4>
                                    <div className="space-y-2">
                                        {detail.pending.map((row) => (
                                            <div key={row.id} className="rounded-md border bg-card p-3 text-sm">
                                                <p className="font-medium tabular-nums">{formatCurrency(row.total)}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {row.business_day} · Parrot #{row.id} · {row.order_reference ?? '—'}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : null}

                            {detail.orphans.length > 0 ? (
                                <div>
                                    <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-rose-600 dark:text-rose-400">
                                        Huérfanos — estado de cuenta sin pago ({detail.orphans.length})
                                    </h4>
                                    <div className="space-y-2">
                                        {detail.orphans.map((row) => (
                                            <div key={row.id} className="rounded-md border bg-card p-3 text-sm">
                                                <p className="font-medium tabular-nums">{formatCurrency(row.amount)}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {row.transaction_date}
                                                    {row.transaction_time ? ` ${row.transaction_time}` : ''} · ref {row.reference ?? '—'}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : null}

                            <div>
                                <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Conciliados ({detail.matched.length})
                                </h4>
                                <div className="max-h-64 space-y-2 overflow-y-auto pr-1">
                                    {detail.matched.map((m) => (
                                        <div key={m.settlement.id} className="flex items-start justify-between gap-3 rounded-md border bg-card p-3 text-sm">
                                            <div className="min-w-0">
                                                <p className="tabular-nums">
                                                    {formatCurrency(m.settlement.amount)}{' '}
                                                    <span className="text-muted-foreground">· orden {m.settlement.reference ?? '—'}</span>
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {m.settlement.transaction_date} → Parrot #{m.payment.id}
                                                    {m.diff > 0 ? ` · dif ${formatCurrency(m.diff)}` : ''}
                                                </p>
                                            </div>
                                            <span className={cn('shrink-0 text-xs', m.saved ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground')}>
                                                {m.saved ? 'guardado' : 'propuesto'}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    ) : (
                        <p className="py-8 text-center text-sm text-muted-foreground">Sin detalle.</p>
                    )}

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDetailOpen(false)}>
                            Cerrar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
