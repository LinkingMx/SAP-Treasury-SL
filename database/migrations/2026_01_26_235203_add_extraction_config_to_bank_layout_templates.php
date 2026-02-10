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
        Schema::table('bank_layout_templates', function (Blueprint $table) {
            $table->json('noise_patterns')->nullable()->after('parse_config');
            $table->enum('extraction_strategy', ['DETAILED', 'BLIND'])->default('BLIND')->after('noise_patterns');
            $table->json('actor_patterns')->nullable()->after('extraction_strategy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_layout_templates', function (Blueprint $table) {
            $table->dropColumn(['noise_patterns', 'extraction_strategy', 'actor_patterns']);
        });
    }
};
