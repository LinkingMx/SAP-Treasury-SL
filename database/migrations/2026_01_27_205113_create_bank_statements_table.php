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
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('statement_date');
            $table->string('statement_number', 50)->unique();
            $table->string('original_filename');
            $table->unsignedInteger('rows_count');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->unsignedBigInteger('sap_doc_entry')->nullable();
            $table->text('sap_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'statement_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};
