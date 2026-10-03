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
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('counterparty_id')->nullable()->after('uploaded_by');
            $table->date('document_date')->nullable()->after('category');
            $table->text('notes')->nullable()->after('document_date');
            $table->foreign(['counterparty_id', 'business_id'], 'documents_counterparty_business_foreign')
                ->references(['id', 'business_id'])
                ->on('counterparties')
                ->restrictOnDelete();
            $table->index(['business_id', 'document_date'], 'documents_business_date_index');
        });

        Schema::create('document_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('category', 40)->default('other');
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('document_id')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status', 'due_on']);
            $table->foreign(['document_id', 'business_id'], 'document_requests_document_business_foreign')
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
        Schema::dropIfExists('document_requests');

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign('documents_counterparty_business_foreign');
            $table->dropIndex('documents_business_date_index');
            $table->dropColumn(['counterparty_id', 'document_date', 'notes']);
        });
    }
};
