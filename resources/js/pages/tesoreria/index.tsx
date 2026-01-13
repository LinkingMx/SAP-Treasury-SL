import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'AC Tesorería',
        href: '/tesoreria',
    },
];

export default function Tesoreria() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="AC Tesorería" />
            <div className="px-4 py-6">
                <Heading
                    title="Automatización de asientos contables"
                    description="Carga de asientos contables con contrapartidas para movimientos bancarios desde Extractos bancarios"
                />
            </div>
        </AppLayout>
    );
}
