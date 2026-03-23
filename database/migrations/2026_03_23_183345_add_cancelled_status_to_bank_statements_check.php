<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bank_statements DROP CONSTRAINT bank_statements_status_check');
        DB::statement("ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_status_check CHECK (status::text = ANY (ARRAY['pending', 'sent', 'failed', 'cancelled']::text[]))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bank_statements DROP CONSTRAINT bank_statements_status_check');
        DB::statement("ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_status_check CHECK (status::text = ANY (ARRAY['pending', 'sent', 'failed']::text[]))");
    }
};
