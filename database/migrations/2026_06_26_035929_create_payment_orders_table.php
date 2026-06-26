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
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained('settlement_uploads')->cascadeOnDelete();
            $table->foreignId('external_settlement_id')->unique()->constrained('external_settlements')->cascadeOnDelete();
            $table->foreignId('acquirer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('parrot_payment_id');
            $table->string('order_reference', 80)->nullable();
            $table->decimal('payment_total', 14, 2);
            $table->string('external_reference', 80)->nullable();
            $table->string('match_method', 30);
            $table->decimal('match_diff', 14, 2)->default(0);
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'parrot_payment_id']);
            $table->index('external_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_orders');
    }
};
