import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Settings2 } from 'lucide-react';

export interface ColumnDef {
    key: string;
    label: string;
}

interface ColumnVisibilityMenuProps {
    columns: ColumnDef[];
    visibility: Record<string, boolean>;
    onChange: (key: string, value: boolean) => void;
}

export function ColumnVisibilityMenu({
    columns,
    visibility,
    onChange,
}: ColumnVisibilityMenuProps) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                    <Settings2 className="h-4 w-4" />
                    Columnas
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuLabel className="text-xs uppercase tracking-wide text-muted-foreground">
                    Mostrar columnas
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {columns.map((c) => (
                    <DropdownMenuCheckboxItem
                        key={c.key}
                        checked={visibility[c.key] ?? true}
                        onCheckedChange={(v) => onChange(c.key, !!v)}
                    >
                        {c.label}
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
