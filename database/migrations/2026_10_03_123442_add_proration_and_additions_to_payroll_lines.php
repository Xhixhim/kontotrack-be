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
        Schema::table('payroll_lines', function (Blueprint $table): void {
            $table->unsignedTinyInteger('worked_days')->nullable()->after('is_primary_employment');
            $table->unsignedTinyInteger('days_in_month')->nullable()->after('worked_days');
            $table->decimal('base_salary', 16, 2)->default(0)->after('days_in_month');
            $table->decimal('additions', 16, 2)->default(0)->after('base_salary');
            $table->string('additions_note', 120)->nullable()->after('additions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table): void {
            $table->dropColumn(['worked_days', 'days_in_month', 'base_salary', 'additions', 'additions_note']);
        });
    }
};
