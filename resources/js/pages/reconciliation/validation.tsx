import { PageHeader } from '@/components/page/page-header';
import ReconciliationForm from '@/components/reconciliation/ReconciliationForm';
import AppLayout from '@/layouts/app-layout';
import { type BankAccount, type Branch, type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { FileSearch } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Conciliacion Bancaria', href: '#' },
    { title: 'Validacion en Conciliacion', href: '/reconciliation/validation' },
];

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
}

export default function Validacion({ branches, bankAccounts }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Validacion en Conciliacion" />
            <div className="space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={FileSearch}
                    title="Validacion en Conciliacion"
                    description="Compara los movimientos del extracto bancario contra los registros en SAP para generar la caratula de conciliacion."
                />
                <ReconciliationForm branches={branches} bankAccounts={bankAccounts} />
            </div>
        </AppLayout>
    );
}
