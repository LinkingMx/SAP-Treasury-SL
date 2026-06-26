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
        Schema::table('acquirers', function (Blueprint $table) {
            $table->json('column_map')->nullable()->after('time_window_seconds');
        });

        Schema::table('settlement_uploads', function (Blueprint $table) {
            $table->json('parse_config')->nullable()->after('stored_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('acquirers', function (Blueprint $table) {
            $table->dropColumn('column_map');
        });

        Schema::table('settlement_uploads', function (Blueprint $table) {
            $table->dropColumn('parse_config');
        });
    }
};
