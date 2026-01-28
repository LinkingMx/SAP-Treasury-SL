import AppLayout from '@/layouts/app-layout';
import { type BankAccount, type Branch, type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import BankStatementUpload from '@/components/tesoreria/BankStatementUpload';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Conciliación Bancaria', href: '#' },
    { title: 'Carga de Extracto Bancario', href: '/conciliacion/carga-extracto' },
];

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
}

export default function CargaExtracto({ branches, bankAccounts }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Carga de Extracto Bancario" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="mb-2">
                    <h1 className="text-2xl font-bold tracking-tight">Carga de Extracto Bancario</h1>
                    <p className="text-muted-foreground">
                        Sube archivos de estado de cuenta bancario para enviarlos al endpoint BankStatements de SAP.
                    </p>
                </div>

                <BankStatementUpload
                    branches={branches}
                    bankAccounts={bankAccounts}
                />
            </div>
        </AppLayout>
    );
}
