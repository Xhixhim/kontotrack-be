<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bank_categorization_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('match_text', 120);
            $table->string('direction', 8)->default('any');
            $table->string('category', 40);
            $table->foreignId('account_id')->nullable();
            $table->foreignId('counterparty_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign(['account_id', 'business_id'])->references(['id', 'business_id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['counterparty_id', 'business_id'])->references(['id', 'business_id'])->on('counterparties')->restrictOnDelete();
            $table->unique(['business_id', 'match_text', 'direction']);
            $table->unique(['id', 'business_id']);
        });

        Schema::table('bank_transactions', function (Blueprint $table): void {
            // 'import' rows come from a bank statement; 'payment' rows were created when a payment was recorded in the books.
            $table->string('source', 16)->default('import')->after('bank_account_id');
            $table->decimal('balance_after', 16, 2)->nullable()->after('amount');
            $table->string('category', 40)->nullable()->after('balance_after');
            $table->foreignId('account_id')->nullable()->after('category');
            $table->foreignId('counterparty_id')->nullable()->after('account_id');
            $table->foreignId('categorization_rule_id')->nullable()->after('counterparty_id');
            $table->foreignId('categorized_by')->nullable()->after('categorization_rule_id')->constrained('users')->nullOnDelete();
            $table->timestamp('categorized_at')->nullable()->after('categorized_by');
            $table->foreign(['account_id', 'business_id'], 'bank_transactions_account_business_foreign')->references(['id', 'business_id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['counterparty_id', 'business_id'], 'bank_transactions_counterparty_business_foreign')->references(['id', 'business_id'])->on('counterparties')->restrictOnDelete();
            $table->foreign(['categorization_rule_id', 'business_id'], 'bank_transactions_rule_business_foreign')->references(['id', 'business_id'])->on('bank_categorization_rules')->restrictOnDelete();
            $table->index(['bank_account_id', 'source', 'transaction_date'], 'bank_tx_source_date_idx');
        });

        DB::table('bank_transactions')
            ->whereIn('id', DB::table('payments')->whereNotNull('bank_transaction_id')->select('bank_transaction_id'))
            ->update(['source' => 'payment']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropForeign('bank_transactions_account_business_foreign');
            $table->dropForeign('bank_transactions_counterparty_business_foreign');
            $table->dropForeign('bank_transactions_rule_business_foreign');
            $table->dropForeign(['categorized_by']);
            $table->dropIndex('bank_tx_source_date_idx');
            $table->dropColumn(['source', 'balance_after', 'category', 'account_id', 'counterparty_id', 'categorization_rule_id', 'categorized_by', 'categorized_at']);
        });

        Schema::dropIfExists('bank_categorization_rules');
    }
};
