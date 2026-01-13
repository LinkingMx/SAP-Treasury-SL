import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useActiveUrl } from '@/hooks/use-active-url';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';

interface NavMainProps {
    items: NavItem[];
    label?: string;
}

export function NavMain({ items = [], label = 'Platform' }: NavMainProps) {
    const { urlIsActive } = useActiveUrl();

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={urlIsActive(item.href)}
                            tooltip={{ children: item.title }}
                        >
                            {item.external ? (
                                <a href={item.href as string}>
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </a>
                            ) : (
                                <Link href={item.href} prefetch>
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </Link>
                            )}
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
