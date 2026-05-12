<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payment_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('customer_payment_batches')->cascadeOnDelete();
            $table->string('card_code', 50);
            $table->string('card_name')->nullable();
            $table->date('doc_date');
            $table->date('transfer_date');
            $table->string('transfer_account', 50);
            $table->integer('line_num');
            $table->bigInteger('doc_entry');
            $table->string('invoice_type', 50)->default('it_Invoice');
            $table->decimal('sum_applied', 15, 2);
            $table->bigInteger('sap_doc_num')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'card_code']);
            $table->index(['batch_id', 'sap_doc_num']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_invoices');
    }
};
