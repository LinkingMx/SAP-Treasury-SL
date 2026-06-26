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
        Schema::create('external_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained('settlement_uploads')->cascadeOnDelete();
            $table->foreignId('acquirer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('transaction_date');
            $table->time('transaction_time')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('card_type', 20)->nullable();
            $table->string('card_brand', 20)->nullable();
            $table->string('authorization', 60)->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('terminal', 60)->nullable();
            $table->string('operation_type', 30)->nullable();
            $table->string('status', 30)->nullable();
            $table->string('match_status', 20)->default('unmatched');
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['acquirer_id', 'transaction_date', 'amount']);
            $table->index(['upload_id', 'match_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_settlements');
    }
};
