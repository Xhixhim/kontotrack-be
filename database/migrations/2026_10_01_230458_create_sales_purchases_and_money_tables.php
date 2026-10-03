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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counterparty_id');
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable();
            $table->foreignId('compliance_rule_set_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->string('invoice_number', 80);
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->string('status', 24)->default('draft');
            $table->char('currency', 3)->default('EUR');
            $table->decimal('net_amount', 16, 2)->default(0);
            $table->decimal('tax_amount', 16, 2)->default(0);
            $table->decimal('gross_amount', 16, 2)->default(0);
            $table->json('tax_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'kind', 'invoice_number']);
            $table->unique(['id', 'business_id']);
            $table->foreign(['counterparty_id', 'business_id'])
                ->references(['id', 'business_id'])->on('counterparties')->restrictOnDelete();
            $table->foreign(['journal_entry_id', 'business_id'])
                ->references(['id', 'business_id'])->on('journal_entries')->restrictOnDelete();
            $table->index(['business_id', 'kind', 'issued_on', 'status']);
            $table->index(['business_id', 'due_on', 'status']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id');
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('description');
            $table->decimal('quantity', 16, 4)->default(1);
            $table->string('unit', 24)->nullable();
            $table->decimal('unit_price', 16, 4)->default(0);
            $table->decimal('net_amount', 16, 2)->default(0);
            $table->decimal('tax_amount', 16, 2)->default(0);
            $table->decimal('gross_amount', 16, 2)->default(0);
            $table->json('tax_snapshot')->nullable();
            $table->timestamps();
            $table->foreign(['invoice_id', 'business_id'])
                ->references(['id', 'business_id'])->on('invoices')->cascadeOnDelete();
            $table->index(['business_id', 'invoice_id', 'position']);
        });

        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('currency', 3)->default('EUR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['id', 'business_id']);
            $table->unique(['business_id', 'name']);
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name', 150);
            $table->string('account_name', 150)->nullable();
            $table->string('iban', 34)->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id');
            $table->foreignId('journal_entry_id')->nullable();
            $table->date('transaction_date');
            $table->string('external_id', 160)->nullable();
            $table->string('description');
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3)->default('EUR');
            $table->boolean('is_reconciled')->default(false);
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->foreign(['bank_account_id', 'business_id'])
                ->references(['id', 'business_id'])->on('bank_accounts')->cascadeOnDelete();
            $table->foreign(['journal_entry_id', 'business_id'])
                ->references(['id', 'business_id'])->on('journal_entries')->restrictOnDelete();
            $table->unique(['bank_account_id', 'external_id']);
            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'transaction_date', 'is_reconciled'], 'bank_tx_reconcile_idx');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_account_id')->nullable();
            $table->foreignId('bank_account_id')->nullable();
            $table->foreignId('bank_transaction_id')->nullable();
            $table->foreignId('journal_entry_id')->nullable();
            $table->date('paid_on');
            $table->string('direction', 16);
            $table->string('reference', 120)->nullable();
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3)->default('EUR');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign(['cash_account_id', 'business_id'])
                ->references(['id', 'business_id'])->on('cash_accounts')->restrictOnDelete();
            $table->foreign(['bank_account_id', 'business_id'])
                ->references(['id', 'business_id'])->on('bank_accounts')->restrictOnDelete();
            $table->foreign(['bank_transaction_id', 'business_id'])
                ->references(['id', 'business_id'])->on('bank_transactions')->restrictOnDelete();
            $table->foreign(['journal_entry_id', 'business_id'])
                ->references(['id', 'business_id'])->on('journal_entries')->restrictOnDelete();
            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'paid_on', 'direction']);
        });

        Schema::create('invoice_payment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id');
            $table->foreignId('payment_id');
            $table->decimal('amount', 16, 2);
            $table->timestamps();
            $table->foreign(['invoice_id', 'business_id'])
                ->references(['id', 'business_id'])->on('invoices')->cascadeOnDelete();
            $table->foreign(['payment_id', 'business_id'])
                ->references(['id', 'business_id'])->on('payments')->cascadeOnDelete();
            $table->unique(['invoice_id', 'payment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_payment');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('cash_accounts');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
