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
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('document_type', 20)->default('invoice')->after('kind');
            $table->foreignId('credited_invoice_id')->nullable()->after('document_type');
            $table->decimal('credited_total', 16, 2)->default(0)->after('gross_amount');
            $table->foreign(['credited_invoice_id', 'business_id'], 'invoices_credited_invoice_business_foreign')
                ->references(['id', 'business_id'])
                ->on('invoices')
                ->restrictOnDelete();
            $table->index(['business_id', 'document_type', 'status'], 'invoices_business_document_type_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign('invoices_credited_invoice_business_foreign');
            $table->dropIndex('invoices_business_document_type_status_index');
            $table->dropColumn(['document_type', 'credited_invoice_id', 'credited_total']);
        });
    }
};
