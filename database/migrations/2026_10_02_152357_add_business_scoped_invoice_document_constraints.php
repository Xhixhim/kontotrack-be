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
        Schema::table('documents', function (Blueprint $table): void {
            $table->unique(['id', 'business_id'], 'documents_id_business_id_unique');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['document_id']);
            $table->unique(['business_id', 'document_id'], 'invoices_business_document_unique');
            $table->foreign(['document_id', 'business_id'], 'invoices_document_business_foreign')
                ->references(['id', 'business_id'])
                ->on('documents')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign('invoices_document_business_foreign');
            $table->dropUnique('invoices_business_document_unique');
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropUnique('documents_id_business_id_unique');
        });
    }
};
