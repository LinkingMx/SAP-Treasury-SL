import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { Landmark } from 'lucide-react';

export function NavTesoreria() {
    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>AC Tesorería</SidebarGroupLabel>
            <SidebarMenu>
                <SidebarMenuItem>
                    <div className="flex items-start gap-3 rounded-md p-3 text-sm">
                        <Landmark className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" />
                        <div className="space-y-1">
                            <p className="leading-none font-medium">
                                Automatización de asientos contables
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Carga de asientos contables con contrapartidas
                                para movimientos bancarios desde Extractos
                                bancarios
                            </p>
                        </div>
                    </div>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroup>
    );
}
