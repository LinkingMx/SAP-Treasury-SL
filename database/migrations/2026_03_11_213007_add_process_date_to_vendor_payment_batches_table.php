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
        Schema::table('vendor_payment_batches', function (Blueprint $table) {
            $table->date('process_date')->nullable()->after('filename');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_payment_batches', function (Blueprint $table) {
            $table->dropColumn('process_date');
        });
    }
};
