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
        Schema::table('invoices', function (Blueprint $table): void {
            $table->date('cancelled_on')->nullable()->after('issued_at');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_on');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
            $table->foreignId('cancellation_journal_entry_id')->nullable()->after('cancellation_reason');
            $table->unique(['business_id', 'cancellation_journal_entry_id'], 'invoices_business_cancellation_journal_unique');
            $table->foreign(['cancellation_journal_entry_id', 'business_id'], 'invoices_cancellation_journal_business_foreign')
                ->references(['id', 'business_id'])
                ->on('journal_entries')
                ->restrictOnDelete();
            $table->index(['business_id', 'status', 'cancelled_on'], 'invoices_business_status_cancelled_on_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign('invoices_cancellation_journal_business_foreign');
            $table->dropUnique('invoices_business_cancellation_journal_unique');
            $table->dropIndex('invoices_business_status_cancelled_on_index');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_on', 'cancelled_at', 'cancellation_reason', 'cancellation_journal_entry_id']);
        });
    }
};
