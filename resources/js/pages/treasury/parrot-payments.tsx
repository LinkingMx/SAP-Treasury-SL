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
import { Banknote, Bike, Coins, CreditCard, Filter, type LucideIcon, Receipt, Search, Truck, Wallet } from 'lucide-react';
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
}

interface DataResponse {
    success: boolean;
    branch: { id: number; name: string; payment_branch: string };
    period: { from: string; to: string };
    totals: { count: number; sum_amount: number; sum_tip: number; sum_total: number };
    by_payment_type: PaymentTypeTotal[];
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
    const [result, setResult] = useState<DataResponse | null>(null);
    const [error, setError] = useState<string | null>(null);

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

    const sectionDescription = result
        ? `${result.branch.name} · ${result.period.from} a ${result.period.to}`
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

                    <div className="flex justify-end pt-1 lg:col-span-3">
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
                                {result.by_payment_type.map((t) => (
                                    <StatCard
                                        key={t.payment_type_name}
                                        icon={iconForType(t.payment_type_name)}
                                        label={`${t.payment_type_name} · ${formatInt(t.count)}`}
                                        value={formatCurrency(t.sum_total)}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </PageSection>
            </div>
        </AppLayout>
    );
}
