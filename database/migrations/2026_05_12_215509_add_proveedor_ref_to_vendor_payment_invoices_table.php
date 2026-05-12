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
        Schema::table('vendor_payment_invoices', function (Blueprint $table) {
            $table->string('proveedor_ref', 100)->nullable()->after('sum_applied');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_payment_invoices', function (Blueprint $table) {
            $table->dropColumn('proveedor_ref');
        });
    }
};
