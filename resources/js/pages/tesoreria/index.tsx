import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import { tesoreria } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'AC Tesorería',
        href: tesoreria().url,
    },
];

export default function Tesoreria() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="AC Tesorería" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Heading
                    title="Automatización de asientos contables"
                    description="Carga de asientos contables con contrapartidas para movimientos bancarios desde Extractos bancarios"
                />
            </div>
        </AppLayout>
    );
}
