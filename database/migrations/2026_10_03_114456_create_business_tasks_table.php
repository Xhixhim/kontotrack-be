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
        Schema::create('business_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('task');
            $table->string('obligation_type', 60)->nullable();
            $table->string('period_label', 20)->nullable();
            $table->string('title', 200);
            $table->text('notes')->nullable();
            $table->date('due_on');
            $table->string('status', 20)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('compliance_rule_set_id')->nullable()->constrained()->nullOnDelete();
            $table->date('completed_on')->nullable();
            $table->string('reference', 120)->nullable();
            $table->decimal('amount', 16, 2)->nullable();
            $table->foreignId('document_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'obligation_type', 'period_label'], 'business_tasks_obligation_period_unique');
            $table->index(['business_id', 'status', 'due_on']);
            $table->foreign(['document_id', 'business_id'], 'business_tasks_document_business_foreign')
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
        Schema::dropIfExists('business_tasks');
    }
};
