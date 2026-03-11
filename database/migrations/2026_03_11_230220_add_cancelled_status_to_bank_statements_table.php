<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE bank_statements MODIFY COLUMN status ENUM('pending', 'sent', 'failed', 'cancelled') DEFAULT 'pending'");
        }
        // SQLite: the original migration already includes 'cancelled', no action needed.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE bank_statements MODIFY COLUMN status ENUM('pending', 'sent', 'failed') DEFAULT 'pending'");
        }
    }
};
