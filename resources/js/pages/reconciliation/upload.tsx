import { PageHeader } from '@/components/page/page-header';
import AppLayout from '@/layouts/app-layout';
import { type BankAccount, type Branch, type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import BankStatementUpload from '@/components/treasury/BankStatementUpload';
import { FileSpreadsheet } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Conciliación Bancaria', href: '#' },
    { title: 'Carga de Extracto Bancario', href: '/reconciliation/upload' },
];

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
}

export default function CargaExtracto({ branches, bankAccounts }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Carga de Extracto Bancario" />
            <div className="space-y-6 p-4 md:p-6">
                <PageHeader
                    icon={FileSpreadsheet}
                    title="Carga de Extracto Bancario"
                    description="Sube archivos de estado de cuenta bancario para enviarlos al endpoint BankStatements de SAP."
                />
                <BankStatementUpload branches={branches} bankAccounts={bankAccounts} />
            </div>
        </AppLayout>
    );
}
