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
import { afirme, dashboard, treasury } from '@/routes';
import { upload } from '@/routes/reconciliation';
import { sap as pagosSap } from '@/routes/payments';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { ClipboardList, FileSearch, FileSpreadsheet, FileText, HandCoins, Landmark, LayoutGrid, Wallet } from 'lucide-react';
import AppLogo from './app-logo';

const platformItems: NavItem[] = [
    {
        title: 'Manager Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const cargasMasivasItems: NavItem[] = [
    {
        title: 'AC Tesorería',
        href: treasury(),
        icon: Landmark,
    },
    {
        title: 'Carga de Extracto Bancario',
        href: upload(),
        icon: FileSpreadsheet,
    },
    {
        title: 'Pagos a SAP',
        href: pagosSap(),
        icon: Wallet,
    },
    {
        title: 'Cobros de Clientes',
        href: '/payments/customers',
        icon: HandCoins,
    },
];

const reportItems: NavItem[] = [
    {
        title: 'Integración Afirme',
        href: afirme(),
        icon: FileText,
    },
    {
        title: 'Rastreo de Transacciones',
        href: '/reports/transactions',
        icon: ClipboardList,
    },
    {
        title: 'Validación en Conciliación',
        href: '/reconciliation/validation',
        icon: FileSearch,
    },
];

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
                <NavMain items={cargasMasivasItems} label="Cargas Masivas" />
                <NavMain items={reportItems} label="Reportes" />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
