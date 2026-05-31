import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

interface FiltersCardProps {
    title?: string;
    icon?: LucideIcon;
    children: ReactNode;
    className?: string;
    columns?: 1 | 2 | 3 | 4;
}

const COLS = {
    1: 'grid-cols-1',
    2: 'grid-cols-1 md:grid-cols-2',
    3: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3',
    4: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4',
} as const;

export function FiltersCard({ title = 'Filtros', icon: Icon, children, className, columns = 3 }: FiltersCardProps) {
    return (
        <Card data-testid="filters-card" className={className}>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    {Icon ? <Icon className="size-4" /> : null}
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className={cn('grid gap-4', COLS[columns])}>{children}</div>
            </CardContent>
        </Card>
    );
}

interface FilterFieldProps {
    label: string;
    htmlFor?: string;
    children: ReactNode;
    className?: string;
}

export function FilterField({ label, htmlFor, children, className }: FilterFieldProps) {
    return (
        <div className={cn('space-y-2', className)}>
            <Label
                htmlFor={htmlFor}
                className="text-xs font-semibold uppercase tracking-wide text-muted-foreground"
            >
                {label}
            </Label>
            {children}
        </div>
    );
}
