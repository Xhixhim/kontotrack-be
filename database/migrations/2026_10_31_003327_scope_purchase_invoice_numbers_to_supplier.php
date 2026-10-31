<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supplier bill numbers are chosen by each supplier, so two suppliers may both send bill "001".
     * Sales numbers stay unique across the business through a generated column that is null for purchases.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('sales_invoice_number', 80)->nullable()->virtualAs("case when kind = 'sale' then invoice_number end");
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unique(['business_id', 'sales_invoice_number'], 'invoices_business_sales_number_unique');
            $table->unique(['business_id', 'kind', 'counterparty_id', 'invoice_number'], 'invoices_business_kind_counterparty_number_unique');
            $table->dropUnique(['business_id', 'kind', 'invoice_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unique(['business_id', 'kind', 'invoice_number']);
            $table->dropUnique('invoices_business_kind_counterparty_number_unique');
            $table->dropUnique('invoices_business_sales_number_unique');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('sales_invoice_number');
        });
    }
};
