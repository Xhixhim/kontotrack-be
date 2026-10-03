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
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->foreignId('document_id')->nullable()->after('external_id');
            $table->foreign(['document_id', 'business_id'], 'bank_transactions_document_business_foreign')
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
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropForeign('bank_transactions_document_business_foreign');
            $table->dropColumn('document_id');
        });
    }
};
