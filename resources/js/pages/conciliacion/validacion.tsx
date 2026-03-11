import ReconciliationForm from '@/components/conciliacion/ReconciliationForm';
import AppLayout from '@/layouts/app-layout';
import { type BankAccount, type Branch, type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Conciliacion Bancaria', href: '#' },
    { title: 'Validacion en Conciliacion', href: '/conciliacion/validacion' },
];

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
}

export default function Validacion({ branches, bankAccounts }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Validacion en Conciliacion" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="mb-2">
                    <h1 className="text-2xl font-bold tracking-tight">Validacion en Conciliacion</h1>
                    <p className="text-muted-foreground">
                        Compara los movimientos del extracto bancario contra los registros en SAP para generar la caratula de conciliacion.
                    </p>
                </div>

                <ReconciliationForm branches={branches} bankAccounts={bankAccounts} />
            </div>
        </AppLayout>
    );
}
