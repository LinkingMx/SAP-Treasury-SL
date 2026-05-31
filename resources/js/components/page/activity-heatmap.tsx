import { cn } from '@/lib/utils';
import { useMemo } from 'react';

interface ActivityHeatmapProps {
    data: Record<string, number>;
    weeks?: number;
    className?: string;
}

const LEVELS = 4;

function isoDate(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function levelFor(value: number, max: number): number {
    if (value <= 0 || max <= 0) return 0;
    const ratio = value / max;
    if (ratio > 0.75) return 4;
    if (ratio > 0.5) return 3;
    if (ratio > 0.25) return 2;
    return 1;
}

const LEVEL_CLASS: Record<number, string> = {
    0: 'bg-muted',
    1: 'bg-primary/25',
    2: 'bg-primary/50',
    3: 'bg-primary/75',
    4: 'bg-primary',
};

export function ActivityHeatmap({ data, weeks = 13, className }: ActivityHeatmapProps) {
    const { columns, max } = useMemo(() => {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const dayOfWeek = today.getDay();
        const end = new Date(today);
        end.setDate(today.getDate() + (6 - dayOfWeek));

        const totalDays = weeks * 7;
        const start = new Date(end);
        start.setDate(end.getDate() - (totalDays - 1));

        const cols: { date: string; value: number; isFuture: boolean }[][] = [];
        const cursor = new Date(start);
        let localMax = 0;
        for (let w = 0; w < weeks; w++) {
            const col: { date: string; value: number; isFuture: boolean }[] = [];
            for (let d = 0; d < 7; d++) {
                const iso = isoDate(cursor);
                const value = data[iso] ?? 0;
                if (value > localMax) localMax = value;
                col.push({ date: iso, value, isFuture: cursor > today });
                cursor.setDate(cursor.getDate() + 1);
            }
            cols.push(col);
        }
        return { columns: cols, max: localMax };
    }, [data, weeks]);

    return (
        <div data-testid="activity-heatmap" className={cn('space-y-2', className)}>
            <div className="flex gap-1">
                {columns.map((col, ci) => (
                    <div key={ci} className="flex flex-col gap-1">
                        {col.map((cell) => (
                            <div
                                key={cell.date}
                                title={`${cell.date} · ${cell.value}`}
                                className={cn(
                                    'h-3 w-3 rounded-sm',
                                    cell.isFuture ? 'bg-muted/40' : LEVEL_CLASS[levelFor(cell.value, max)],
                                )}
                            />
                        ))}
                    </div>
                ))}
            </div>
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <span>menos</span>
                {Array.from({ length: LEVELS + 1 }, (_, i) => (
                    <div key={i} className={cn('h-3 w-3 rounded-sm', LEVEL_CLASS[i])} />
                ))}
                <span>más</span>
            </div>
        </div>
    );
}
