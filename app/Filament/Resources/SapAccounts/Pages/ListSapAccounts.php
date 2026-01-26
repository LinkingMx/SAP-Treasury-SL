<?php

namespace App\Filament\Resources\SapAccounts\Pages;

use App\Filament\Resources\SapAccounts\SapAccountResource;
use App\Models\Branch;
use App\Services\SapAccountSyncService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSapAccounts extends ListRecords
{
    protected static string $resource = SapAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sincronizar desde SAP')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->form([
                    Select::make('branch_id')
                        ->label('Sucursal')
                        ->options(
                            Branch::whereNotNull('sap_database')
                                ->pluck('name', 'id')
                        )
                        ->required()
                        ->searchable()
                        ->preload()
                        ->helperText('Selecciona la sucursal para sincronizar sus cuentas SAP')
                        ->prefixIcon('heroicon-o-building-office-2'),
                ])
                ->modalHeading('Sincronizar Cuentas SAP')
                ->modalDescription('Esta acción descargará todas las cuentas contables de SAP y las guardará localmente.')
                ->modalSubmitActionLabel('Sincronizar')
                ->action(function (array $data, SapAccountSyncService $syncService): void {
                    $branch = Branch::findOrFail($data['branch_id']);

                    try {
                        $result = $syncService->syncFromSap($branch);

                        Notification::make()
                            ->title('Sincronización completada')
                            ->body("Sucursal: {$branch->name}\nCreadas: {$result['created']}\nActualizadas: {$result['updated']}\nDesactivadas: {$result['deactivated']}\nTotal: {$result['total']}")
                            ->success()
                            ->icon('heroicon-o-check-circle')
                            ->duration(10000)
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Error de sincronización')
                            ->body($e->getMessage())
                            ->danger()
                            ->icon('heroicon-o-x-circle')
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }
}
