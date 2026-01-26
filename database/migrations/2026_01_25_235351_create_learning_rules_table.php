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
        Schema::create('learning_rules', function (Blueprint $table) {
            $table->id();
            $table->string('pattern', 150)->index();
            $table->enum('match_type', ['exact', 'contains'])->default('contains');
            $table->string('sap_account_code', 50);
            $table->string('sap_account_name', 150)->nullable();
            $table->unsignedTinyInteger('confidence_score')->default(100);
            $table->enum('source', ['user_correction', 'ai_high_confidence'])->default('user_correction');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('learning_rules');
    }
};
