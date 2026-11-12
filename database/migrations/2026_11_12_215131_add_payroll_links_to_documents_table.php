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
        Schema::table('documents', function (Blueprint $table) {
            // Contracts, payslips and payroll confirmations: kept with the employee or the payroll run they support.
            $table->foreignId('employee_id')->nullable()->after('counterparty_id')->constrained()->nullOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_run_id');
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
