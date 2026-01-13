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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->date('due_date');
            $table->string('memo');
            $table->decimal('debit_amount', 15, 2)->nullable();
            $table->decimal('credit_amount', 15, 2)->nullable();
            $table->string('counterpart_account');
            $table->timestamps();

            $table->index(['batch_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
