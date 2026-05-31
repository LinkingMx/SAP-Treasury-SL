import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

interface InfoWidgetProps {
    title: string;
    icon?: LucideIcon;
    footer?: ReactNode;
    children: ReactNode;
    className?: string;
}

export function InfoWidget({ title, icon: Icon, footer, children, className }: InfoWidgetProps) {
    return (
        <Card data-testid="info-widget" className={cn('flex flex-col', className)}>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    {Icon ? <Icon className="size-4" /> : null}
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col justify-between gap-3">
                <div className="flex-1">{children}</div>
                {footer ? (
                    <p className="text-xs text-muted-foreground">{footer}</p>
                ) : null}
            </CardContent>
        </Card>
    );
}
