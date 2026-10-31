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
        Schema::create('period_reopen_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accounting_period_id');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            // When and by whom the period had been closed, kept because reopening clears it on the period.
            $table->timestamp('period_closed_at')->nullable();
            $table->foreignId('period_closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign(['accounting_period_id', 'business_id'])->references(['id', 'business_id'])->on('accounting_periods')->cascadeOnDelete();
            $table->index(['business_id', 'status']);
            $table->index(['accounting_period_id', 'status']);
        });

        Schema::table('accounting_periods', function (Blueprint $table): void {
            $table->timestamp('reopened_at')->nullable()->after('closed_at');
            $table->foreignId('reopened_by')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table): void {
            $table->dropForeign(['reopened_by']);
            $table->dropColumn(['reopened_at', 'reopened_by']);
        });
        Schema::dropIfExists('period_reopen_requests');
    }
};
