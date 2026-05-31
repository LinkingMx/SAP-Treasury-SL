import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

interface PageHeaderProps {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
    className?: string;
}

export function PageHeader({ icon: Icon, title, description, action, className }: PageHeaderProps) {
    return (
        <div
            data-testid="page-header"
            className={cn('flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between', className)}
        >
            <div className="min-w-0 flex-1 space-y-1.5">
                <h1 className="flex items-center gap-2.5 text-2xl font-semibold tracking-tight">
                    {Icon ? <Icon className="size-6 shrink-0 text-foreground/80" /> : null}
                    <span className="truncate">{title}</span>
                </h1>
                {description ? (
                    <p className="text-sm text-muted-foreground">{description}</p>
                ) : null}
            </div>
            {action ? <div className="shrink-0">{action}</div> : null}
        </div>
    );
}
