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
        Schema::table('learning_rules', function (Blueprint $table) {
            $table->enum('rule_type', ['ACTOR', 'RFC', 'CONCEPTO'])->default('CONCEPTO')->after('match_type');
            $table->unsignedTinyInteger('priority')->default(2)->after('rule_type');
            $table->string('actor', 100)->nullable()->index()->after('pattern');
            $table->string('rfc', 15)->nullable()->index()->after('actor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('learning_rules', function (Blueprint $table) {
            $table->dropColumn(['rule_type', 'priority', 'actor', 'rfc']);
        });
    }
};
