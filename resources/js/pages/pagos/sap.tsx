import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { pagosSap } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Pagos a SAP',
        href: pagosSap().url,
    },
];

export default function PagosSap() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pagos a SAP" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Pagos a SAP</CardTitle>
                        <CardDescription>
                            Procesamiento de pagos masivos a SAP
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex items-center justify-center py-12 text-muted-foreground">
                            <p>Módulo en construcción...</p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
