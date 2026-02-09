import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { afirme, dashboard, tesoreria } from '@/routes';
import { cargaExtracto } from '@/routes/conciliacion';
import { sap as pagosSap } from '@/routes/pagos';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { Building2, FileSpreadsheet, FileText, Landmark, LayoutGrid, Wallet } from 'lucide-react';
import AppLogo from './app-logo';

const platformItems: NavItem[] = [
    {
        title: 'Panel',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const bankingItems: NavItem[] = [
    {
        title: 'AC Tesorería',
        href: tesoreria(),
        icon: Landmark,
    },
    {
        title: 'Integración Afirme',
        href: afirme(),
        icon: FileText,
    },
];

const conciliacionItems: NavItem[] = [
    {
        title: 'Carga de Extracto Bancario',
        href: cargaExtracto(),
        icon: FileSpreadsheet,
    },
];

const pagosItems: NavItem[] = [
    {
        title: 'Pagos a SAP',
        href: pagosSap(),
        icon: Wallet,
    },
];

const intercompaniaItems: NavItem[] = [];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={platformItems} label="Plataforma" />
                <NavMain items={bankingItems} label="Opciones de bancos" />
                <NavMain items={conciliacionItems} label="Conciliación Bancaria" />
                <NavMain items={pagosItems} label="Pagos" />
                <NavMain items={intercompaniaItems} label="Procesos Intercompañía" />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
