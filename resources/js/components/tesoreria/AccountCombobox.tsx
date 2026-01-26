import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { type SapAccount } from '@/types';
import { Ban, Check, ChevronsUpDown } from 'lucide-react';
import { cn } from '@/lib/utils';

// Special code for transactions that should not be sent to SAP
export const SKIP_SAP_CODE = '__SKIP_SAP__';
export const SKIP_SAP_NAME = 'No enviar a SAP';

interface Props {
    accounts: SapAccount[];
    value: string | null;
    onChange: (code: string, name: string) => void;
    hasError?: boolean;
}

export default function AccountCombobox({ accounts, value, onChange, hasError }: Props) {
    const [open, setOpen] = useState(false);

    const selectedAccount = accounts.find((a) => a.code === value);
    const isSkipSap = value === SKIP_SAP_CODE;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    size="sm"
                    className={cn(
                        'h-7 w-full justify-between text-xs font-normal',
                        hasError && 'border-red-500',
                        isSkipSap && 'border-amber-500 bg-amber-500/10 text-amber-600 dark:text-amber-400',
                        !value && 'text-muted-foreground'
                    )}
                >
                    <span className="truncate flex items-center gap-1">
                        {isSkipSap ? (
                            <>
                                <Ban className="h-3 w-3" />
                                {SKIP_SAP_NAME}
                            </>
                        ) : selectedAccount ? (
                            `${selectedAccount.code} - ${selectedAccount.name}`
                        ) : (
                            'Buscar cuenta...'
                        )}
                    </span>
                    <ChevronsUpDown className="ml-1 h-3 w-3 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-[280px] p-0" align="start">
                <Command>
                    <CommandInput placeholder="Buscar..." className="h-8 text-xs" />
                    <CommandList>
                        <CommandEmpty className="text-xs py-2">No se encontraron cuentas.</CommandEmpty>
                        <CommandGroup>
                            <CommandItem
                                value="no enviar skip omitir"
                                onSelect={() => {
                                    onChange(SKIP_SAP_CODE, SKIP_SAP_NAME);
                                    setOpen(false);
                                }}
                                className="text-xs py-1 text-amber-600 dark:text-amber-400"
                            >
                                <Ban
                                    className={cn(
                                        'mr-1 h-3 w-3',
                                        isSkipSap ? 'opacity-100' : 'opacity-50'
                                    )}
                                />
                                <span className="truncate font-medium">
                                    {SKIP_SAP_NAME}
                                </span>
                            </CommandItem>
                        </CommandGroup>
                        <CommandSeparator />
                        <CommandGroup heading="Cuentas SAP">
                            {accounts.map((account) => (
                                <CommandItem
                                    key={account.code}
                                    value={`${account.code} ${account.name}`}
                                    onSelect={() => {
                                        onChange(account.code, account.name);
                                        setOpen(false);
                                    }}
                                    className="text-xs py-1"
                                >
                                    <Check
                                        className={cn(
                                            'mr-1 h-3 w-3',
                                            value === account.code ? 'opacity-100' : 'opacity-0'
                                        )}
                                    />
                                    <span className="truncate">
                                        {account.code} - {account.name}
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
