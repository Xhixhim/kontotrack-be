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
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('vat_code', 40)->nullable()->after('unit_price');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('issued_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable()->after('issued_by');
            $table->unique(['business_id', 'journal_entry_id'], 'invoices_business_journal_entry_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_business_journal_entry_unique');
            $table->dropConstrainedForeignId('issued_by');
            $table->dropColumn('issued_at');
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('vat_code');
        });
    }
};
