<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A learned file layout per acquirer, keyed by a fingerprint of its header names.
 *
 * `acquirers.column_map` only held ONE mapping per acquirer, but the same acquirer
 * ships several formats — MIFEL sends xlsx and csv with different header rows and
 * delimiters, which is exactly what broke the csv upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquirer_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acquirer_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('label')->nullable();
            $table->json('column_map');
            $table->string('date_format', 20)->nullable();
            $table->string('delimiter', 8)->nullable();
            $table->unsignedSmallInteger('header_row')->default(0);
            $table->string('source', 10)->default('manual');
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['acquirer_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquirer_layouts');
    }
};
