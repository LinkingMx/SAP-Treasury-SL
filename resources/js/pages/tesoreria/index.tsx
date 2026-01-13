import { useMemo, useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { tesoreria } from '@/routes';
import { type BankAccount, type Branch, type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'AC Tesorería',
        href: tesoreria().url,
    },
];

interface Props {
    branches: Branch[];
    bankAccounts: BankAccount[];
}

export default function Tesoreria({ branches, bankAccounts }: Props) {
    const [selectedBranch, setSelectedBranch] = useState<string>('');
    const [selectedBankAccount, setSelectedBankAccount] = useState<string>('');

    const filteredBankAccounts = useMemo(() => {
        if (!selectedBranch) return [];
        return bankAccounts.filter((account) => account.branch_id === Number(selectedBranch));
    }, [selectedBranch, bankAccounts]);

    const handleBranchChange = (value: string) => {
        setSelectedBranch(value);
        setSelectedBankAccount('');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="AC Tesorería" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Automatización de asientos contables</CardTitle>
                        <CardDescription>
                            Carga de asientos contables con contrapartidas para movimientos
                            bancarios desde Extractos bancarios
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="branch">Sucursal</Label>
                                <Select value={selectedBranch} onValueChange={handleBranchChange}>
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
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="bankAccount">Cuenta Bancaria</Label>
                                <Select
                                    value={selectedBankAccount}
                                    onValueChange={setSelectedBankAccount}
                                    disabled={!selectedBranch}
                                >
                                    <SelectTrigger id="bankAccount">
                                        <SelectValue placeholder="Selecciona una cuenta" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {filteredBankAccounts.map((account) => (
                                            <SelectItem key={account.id} value={String(account.id)}>
                                                {account.name} - {account.account}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
