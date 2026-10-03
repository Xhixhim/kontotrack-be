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
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('nui', 32)->unique();
            $table->string('legal_form', 40)->nullable();
            $table->string('vat_status', 32)->default('unknown');
            $table->string('municipality', 100)->nullable();
            $table->string('address')->nullable();
            $table->string('activity_code', 32)->nullable();
            $table->date('registered_at')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 24)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('business_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('status', 24)->default('active');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('counterparties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name');
            $table->string('nui', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('municipality', 100)->nullable();
            $table->string('country_code', 2)->default('XK');
            $table->string('status', 20)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'type', 'name']);
            $table->index(['business_id', 'nui']);
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'starts_on', 'ends_on']);
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('account_type', 32);
            $table->string('normal_balance', 8);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'code']);
            $table->unique(['id', 'business_id']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 40)->default('other');
            $table->string('original_name');
            $table->string('disk', 40)->default('private');
            $table->string('path');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('review_status', 24)->default('pending');
            $table->json('extracted_data')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'category', 'created_at']);
            $table->index(['business_id', 'sha256']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });

        Schema::create('conversation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['message_id', 'document_id']);
        });

        Schema::create('compliance_rule_sets', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction', 8)->default('XK');
            $table->string('domain', 40);
            $table->string('version', 40);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('source_title');
            $table->string('source_url', 2048)->nullable();
            $table->string('approval_status', 24)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['jurisdiction', 'domain', 'version']);
            $table->index(['jurisdiction', 'domain', 'valid_from', 'valid_to'], 'compliance_rules_effective_dates_idx');
        });

        Schema::create('compliance_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_rule_set_id')->constrained()->cascadeOnDelete();
            $table->string('rule_key', 100);
            $table->json('configuration');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['compliance_rule_set_id', 'rule_key']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accounting_period_id');
            $table->date('entry_date');
            $table->string('reference', 100)->nullable();
            $table->string('description');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['id', 'business_id']);
            $table->foreign(['accounting_period_id', 'business_id'])
                ->references(['id', 'business_id'])->on('accounting_periods')->restrictOnDelete();
            $table->index(['business_id', 'entry_date', 'status']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id');
            $table->foreignId('account_id');
            $table->foreignId('counterparty_id')->nullable();
            $table->string('description')->nullable();
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
            $table->char('currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->timestamps();
            $table->foreign(['journal_entry_id', 'business_id'])
                ->references(['id', 'business_id'])->on('journal_entries')->cascadeOnDelete();
            $table->foreign(['account_id', 'business_id'])
                ->references(['id', 'business_id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['counterparty_id', 'business_id'])
                ->references(['id', 'business_id'])->on('counterparties')->restrictOnDelete();
            $table->index(['business_id', 'account_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('compliance_rules');
        Schema::dropIfExists('compliance_rule_sets');
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_user');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('counterparties');
        Schema::dropIfExists('business_user');
        Schema::dropIfExists('businesses');
    }
};
