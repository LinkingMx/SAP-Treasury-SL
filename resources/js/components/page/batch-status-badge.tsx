import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { Loader2 } from 'lucide-react';

export type BatchStatus = 'pending' | 'processing' | 'completed' | 'failed';

const LABELS: Record<BatchStatus, string> = {
    pending: 'Pendiente',
    processing: 'Procesando',
    completed: 'Completado',
    failed: 'Fallido',
};

const STYLES: Record<BatchStatus, string> = {
    pending: 'bg-muted text-muted-foreground border-transparent',
    processing:
        'bg-blue-500/15 text-blue-700 dark:text-blue-300 border-transparent animate-pulse',
    completed:
        'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-transparent',
    failed: 'bg-destructive/15 text-destructive border-transparent',
};

interface BatchStatusBadgeProps {
    status: BatchStatus;
    className?: string;
}

export function BatchStatusBadge({ status, className }: BatchStatusBadgeProps) {
    return (
        <Badge
            className={cn(
                'rounded-full px-2.5 py-0.5 font-medium',
                STYLES[status],
                className,
            )}
        >
            {status === 'processing' ? (
                <Loader2 className="h-3 w-3 animate-spin" />
            ) : null}
            {LABELS[status]}
        </Badge>
    );
}
