<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE bank_statements DROP CONSTRAINT bank_statements_status_check');
        DB::statement("ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_status_check CHECK (status::text = ANY (ARRAY['pending', 'sent', 'failed', 'cancelled']::text[]))");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE bank_statements DROP CONSTRAINT bank_statements_status_check');
        DB::statement("ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_status_check CHECK (status::text = ANY (ARRAY['pending', 'sent', 'failed']::text[]))");
    }
};
