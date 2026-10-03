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
            $table->unique(['business_id', 'account_id'], 'cash_accounts_business_ledger_account_unique');
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->unique(['business_id', 'account_id'], 'bank_accounts_business_ledger_account_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropUnique('bank_accounts_business_ledger_account_unique');
        });

        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->dropUnique('cash_accounts_business_ledger_account_unique');
        });
    }
};
