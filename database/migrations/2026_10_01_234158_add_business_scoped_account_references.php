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
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->foreign(['parent_id', 'business_id'], 'accounts_parent_business_fk')
                ->references(['id', 'business_id'])->on('accounts')->restrictOnDelete();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_id']);
            $table->foreign(['reversal_of_id', 'business_id'], 'journal_reversal_business_fk')
                ->references(['id', 'business_id'])->on('journal_entries')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign('journal_reversal_business_fk');
            $table->foreign('reversal_of_id')->references('id')->on('journal_entries')->nullOnDelete();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign('accounts_parent_business_fk');
            $table->foreign('parent_id')->references('id')->on('accounts')->nullOnDelete();
        });
    }
};
