<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\SapAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SapAccountSyncService
{
    /**
     * Sync SAP accounts from SAP SQL Server to local database.
     *
     * @return array{created: int, updated: int, deactivated: int, total: int}
     */
    public function syncFromSap(Branch $branch): array
    {
        $companyDB = $branch->sap_database;

        if (empty($companyDB)) {
            throw new \InvalidArgumentException("La sucursal {$branch->name} no tiene base de datos SAP configurada.");
        }

        // Configure SAP database connection
        config(['database.connections.sap_sqlsrv.database' => $companyDB]);
        DB::purge('sap_sqlsrv');

        // Fetch accounts from SAP
        $sapAccounts = DB::connection('sap_sqlsrv')
            ->table('OACT')
            ->select([
                'AcctCode as code',
                'AcctName as name',
                'ActType as account_type',
            ])
            ->where('Postable', 'Y') // Only accounts that accept postings
            ->orderBy('AcctCode')
            ->get();

        if ($sapAccounts->isEmpty()) {
            throw new \RuntimeException("No se encontraron cuentas en la base de datos SAP: {$companyDB}");
        }

        $created = 0;
        $updated = 0;

        foreach ($sapAccounts as $sap) {
            $account = SapAccount::updateOrCreate(
                [
                    'branch_id' => $branch->id,
                    'code' => $sap->code,
                ],
                [
                    'name' => $sap->name,
                    'account_type' => $this->normalizeAccountType($sap->account_type),
                    'is_active' => true,
                ]
            );

            $account->wasRecentlyCreated ? $created++ : $updated++;
        }

        // Deactivate accounts that no longer exist in SAP
        $sapCodes = $sapAccounts->pluck('code')->toArray();
        $deactivated = SapAccount::where('branch_id', $branch->id)
            ->where('is_active', true)
            ->whereNotIn('code', $sapCodes)
            ->update(['is_active' => false]);

        Log::info('SAP accounts synced', [
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'sap_database' => $companyDB,
            'created' => $created,
            'updated' => $updated,
            'deactivated' => $deactivated,
            'total' => count($sapAccounts),
        ]);

        return [
            'created' => $created,
            'updated' => $updated,
            'deactivated' => $deactivated,
            'total' => count($sapAccounts),
        ];
    }

    /**
     * Normalize SAP account type to Spanish.
     */
    protected function normalizeAccountType(?string $type): ?string
    {
        return match ($type) {
            'A' => 'Activo',
            'L' => 'Pasivo',
            'E' => 'Capital',
            'I' => 'Ingreso',
            'C' => 'Costo',
            'X' => 'Gasto',
            default => $type,
        };
    }

    /**
     * Get sync statistics for a branch.
     *
     * @return array{total: int, active: int, inactive: int, last_sync: string|null}
     */
    public function getStats(Branch $branch): array
    {
        $total = SapAccount::where('branch_id', $branch->id)->count();
        $active = SapAccount::where('branch_id', $branch->id)->where('is_active', true)->count();
        $lastSync = SapAccount::where('branch_id', $branch->id)
            ->orderByDesc('updated_at')
            ->value('updated_at');

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'last_sync' => $lastSync?->format('d/m/Y H:i'),
        ];
    }
}
