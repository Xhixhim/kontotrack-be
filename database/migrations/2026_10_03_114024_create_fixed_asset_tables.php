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
        Schema::create('fixed_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('asset_code', 40);
            $table->string('name', 180);
            $table->string('category', 80);
            $table->string('location', 120)->nullable();
            $table->date('acquired_on');
            $table->decimal('cost', 16, 2);
            $table->decimal('residual_value', 16, 2)->default(0);
            $table->decimal('opening_accumulated_depreciation', 16, 2)->default(0);
            $table->string('depreciation_method', 24)->default('straight_line');
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->decimal('annual_rate_percent', 7, 4)->nullable();
            $table->string('status', 20)->default('active');
            $table->date('disposed_on')->nullable();
            $table->decimal('disposal_proceeds', 16, 2)->nullable();
            $table->foreignId('document_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'asset_code'], 'fixed_assets_business_code_unique');
            $table->unique(['id', 'business_id'], 'fixed_assets_id_business_unique');
            $table->foreign(['document_id', 'business_id'], 'fixed_assets_document_business_foreign')
                ->references(['id', 'business_id'])
                ->on('documents')
                ->restrictOnDelete();
        });

        Schema::create('depreciation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('status', 20)->default('draft');
            $table->decimal('total', 16, 2)->default(0);
            $table->foreignId('journal_entry_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'period_year', 'period_month'], 'depreciation_runs_business_period_unique');
            $table->unique(['id', 'business_id'], 'depreciation_runs_id_business_unique');
            $table->unique(['business_id', 'journal_entry_id'], 'depreciation_runs_business_journal_unique');
            $table->foreign(['journal_entry_id', 'business_id'], 'depreciation_runs_journal_business_foreign')
                ->references(['id', 'business_id'])
                ->on('journal_entries')
                ->restrictOnDelete();
        });

        Schema::create('depreciation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depreciation_run_id');
            $table->foreignId('fixed_asset_id');
            $table->decimal('opening_book_value', 16, 2);
            $table->decimal('amount', 16, 2);
            $table->decimal('closing_book_value', 16, 2);
            $table->timestamps();
            $table->unique(['depreciation_run_id', 'fixed_asset_id'], 'depreciation_lines_run_asset_unique');
            $table->foreign(['depreciation_run_id', 'business_id'], 'depreciation_lines_run_business_foreign')
                ->references(['id', 'business_id'])
                ->on('depreciation_runs')
                ->cascadeOnDelete();
            $table->foreign(['fixed_asset_id', 'business_id'], 'depreciation_lines_asset_business_foreign')
                ->references(['id', 'business_id'])
                ->on('fixed_assets')
                ->restrictOnDelete();
        });

        Schema::create('fixed_asset_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id');
            $table->string('event_type', 24);
            $table->date('occurred_on');
            $table->json('details')->nullable();
            $table->foreignId('journal_entry_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'fixed_asset_id', 'occurred_on'], 'fixed_asset_events_lookup');
            $table->foreign(['fixed_asset_id', 'business_id'], 'fixed_asset_events_asset_business_foreign')
                ->references(['id', 'business_id'])
                ->on('fixed_assets')
                ->cascadeOnDelete();
            $table->foreign(['journal_entry_id', 'business_id'], 'fixed_asset_events_journal_business_foreign')
                ->references(['id', 'business_id'])
                ->on('journal_entries')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_events');
        Schema::dropIfExists('depreciation_lines');
        Schema::dropIfExists('depreciation_runs');
        Schema::dropIfExists('fixed_assets');
    }
};
