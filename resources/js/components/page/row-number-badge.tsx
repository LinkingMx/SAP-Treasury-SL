import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

interface RowNumberBadgeProps {
    id: number | string;
    className?: string;
}

export function RowNumberBadge({ id, className }: RowNumberBadgeProps) {
    return (
        <Badge
            variant="outline"
            className={cn(
                'rounded-full px-2.5 py-0.5 font-mono text-xs text-muted-foreground',
                className,
            )}
        >
            #{id}
        </Badge>
    );
}
