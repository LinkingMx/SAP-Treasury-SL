import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

interface InfoCellProps {
    label: string;
    children: ReactNode;
    className?: string;
}

export function InfoCell({ label, children, className }: InfoCellProps) {
    return (
        <div className={cn('min-w-0 space-y-1', className)}>
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {label}
            </p>
            <div className="text-sm">{children}</div>
        </div>
    );
}

type StatTone = 'default' | 'primary' | 'success' | 'danger';

const TONE: Record<StatTone, string> = {
    default: 'text-foreground',
    primary: 'text-primary',
    success: 'text-emerald-600 dark:text-emerald-400',
    danger: 'text-rose-600 dark:text-rose-400',
};

interface StatCardProps {
    icon: LucideIcon;
    label: string;
    value: string | number;
    tone?: StatTone;
    className?: string;
}

export function StatCard({
    icon: Icon,
    label,
    value,
    tone = 'default',
    className,
}: StatCardProps) {
    return (
        <div className={cn('rounded-md bg-muted/40 p-3', className)}>
            <div className="flex items-center gap-1.5 text-xs uppercase tracking-wide text-muted-foreground">
                <Icon className="h-3 w-3" />
                {label}
            </div>
            <p className={cn('mt-1 text-base font-semibold tabular-nums', TONE[tone])}>
                {value}
            </p>
        </div>
    );
}
