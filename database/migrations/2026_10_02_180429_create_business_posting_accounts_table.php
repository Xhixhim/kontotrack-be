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
        Schema::create('business_posting_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->foreignId('account_id');
            $table->timestamps();
            $table->unique(['business_id', 'purpose'], 'business_posting_accounts_business_purpose_unique');
            $table->foreign(['account_id', 'business_id'], 'business_posting_accounts_account_business_foreign')
                ->references(['id', 'business_id'])
                ->on('accounts')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_posting_accounts');
    }
};
