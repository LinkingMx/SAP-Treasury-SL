import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { FileSpreadsheet, Wallet } from 'lucide-react';

export type TransactionType = 'batch' | 'vendor_payment';

const STYLES: Record<TransactionType, string> = {
    batch: 'bg-blue-500/15 text-blue-700 dark:text-blue-300 border-transparent',
    vendor_payment:
        'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-transparent',
};

const ICONS: Record<TransactionType, typeof FileSpreadsheet> = {
    batch: FileSpreadsheet,
    vendor_payment: Wallet,
};

interface TransactionTypeBadgeProps {
    type: TransactionType;
    label: string;
    className?: string;
}

export function TransactionTypeBadge({
    type,
    label,
    className,
}: TransactionTypeBadgeProps) {
    const Icon = ICONS[type];
    return (
        <Badge
            className={cn(
                'rounded-full px-2 py-0.5 font-medium',
                STYLES[type],
                className,
            )}
        >
            <Icon className="h-3 w-3" />
            <span>{label}</span>
        </Badge>
    );
}
