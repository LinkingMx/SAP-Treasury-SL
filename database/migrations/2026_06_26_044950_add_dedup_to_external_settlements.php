<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('external_settlements', function (Blueprint $table) {
            $table->string('row_hash', 32)->nullable()->after('raw');
            $table->unique(['acquirer_id', 'branch_id', 'row_hash']);
        });

        Schema::table('settlement_uploads', function (Blueprint $table) {
            $table->unsignedInteger('inserted_rows')->default(0)->after('total_rows');
            $table->unsignedInteger('duplicate_rows')->default(0)->after('inserted_rows');
            $table->date('period_start')->nullable()->change();
            $table->date('period_end')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_settlements', function (Blueprint $table) {
            $table->dropUnique(['acquirer_id', 'branch_id', 'row_hash']);
            $table->dropColumn('row_hash');
        });

        Schema::table('settlement_uploads', function (Blueprint $table) {
            $table->dropColumn(['inserted_rows', 'duplicate_rows']);
        });
    }
};
