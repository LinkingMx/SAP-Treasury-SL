import { PageHeader } from '@/components/page/page-header';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manager Dashboard', href: dashboard().url },
];

export default function Dashboard() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Manager Dashboard" />
            <div className="space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={LayoutGrid}
                    title="Manager Dashboard"
                    description="Indicadores clave para la gerencia de tesorería. (En construcción)"
                />
                <div className="flex h-64 items-center justify-center rounded-md border border-dashed text-sm text-muted-foreground">
                    Próximamente
                </div>
            </div>
        </AppLayout>
    );
}
