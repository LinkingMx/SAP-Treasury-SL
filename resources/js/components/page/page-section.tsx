import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

interface PageSectionProps {
    title: string;
    icon?: LucideIcon;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
    contentClassName?: string;
}

export function PageSection({
    title,
    icon: Icon,
    description,
    action,
    children,
    className,
    contentClassName,
}: PageSectionProps) {
    return (
        <Card data-testid="page-section" className={className}>
            <CardHeader className="flex flex-row items-start justify-between gap-4">
                <div className="space-y-1">
                    <CardTitle className="flex items-center gap-2 text-base font-semibold">
                        {Icon ? <Icon className="size-4 text-foreground/80" /> : null}
                        {title}
                    </CardTitle>
                    {description ? <CardDescription>{description}</CardDescription> : null}
                </div>
                {action ? <div className="shrink-0">{action}</div> : null}
            </CardHeader>
            <CardContent className={cn(contentClassName)}>{children}</CardContent>
        </Card>
    );
}
