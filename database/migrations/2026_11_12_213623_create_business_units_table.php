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
        Schema::create('business_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 24)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'name']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('business_unit_id')->nullable()->after('counterparty_id')->constrained('business_units')->nullOnDelete();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('business_unit_id')->nullable()->after('accounting_period_id')->constrained('business_units')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_unit_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_unit_id');
        });

        Schema::dropIfExists('business_units');
    }
};
