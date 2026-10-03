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
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('business_id');
            $table->foreign(['account_id', 'business_id'])
                ->references(['id', 'business_id'])->on('accounts')->restrictOnDelete();
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('business_id');
            $table->foreign(['account_id', 'business_id'])
                ->references(['id', 'business_id'])->on('accounts')->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['business_id', 'journal_entry_id'], 'payments_business_journal_entry_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_business_journal_entry_unique');
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropForeign(['account_id', 'business_id']);
            $table->dropColumn('account_id');
        });

        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->dropForeign(['account_id', 'business_id']);
            $table->dropColumn('account_id');
        });
    }
};
