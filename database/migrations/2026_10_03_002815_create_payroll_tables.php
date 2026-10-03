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
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('personal_number', 20)->nullable();
            $table->string('position', 120)->nullable();
            $table->string('contract_type', 24)->default('indefinite');
            $table->date('employment_start');
            $table->date('employment_end')->nullable();
            $table->decimal('gross_salary', 16, 2);
            $table->boolean('is_primary_employment')->default(true);
            $table->string('iban', 34)->nullable();
            $table->string('bank_name', 150)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['id', 'business_id'], 'employees_id_business_unique');
            $table->unique(['business_id', 'personal_number'], 'employees_business_personal_number_unique');
            $table->index(['business_id', 'status']);
        });

        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('pay_date');
            $table->string('status', 20)->default('draft');
            $table->foreignId('compliance_rule_set_id')->nullable()->constrained()->nullOnDelete();
            $table->json('rule_snapshot')->nullable();
            $table->decimal('gross_total', 16, 2)->default(0);
            $table->decimal('employee_contribution_total', 16, 2)->default(0);
            $table->decimal('employer_contribution_total', 16, 2)->default(0);
            $table->decimal('income_tax_total', 16, 2)->default(0);
            $table->decimal('net_total', 16, 2)->default(0);
            $table->foreignId('journal_entry_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'period_year', 'period_month'], 'payroll_runs_business_period_unique');
            $table->unique(['id', 'business_id'], 'payroll_runs_id_business_unique');
            $table->unique(['business_id', 'journal_entry_id'], 'payroll_runs_business_journal_unique');
            $table->foreign(['journal_entry_id', 'business_id'], 'payroll_runs_journal_business_foreign')
                ->references(['id', 'business_id'])
                ->on('journal_entries')
                ->restrictOnDelete();
        });

        Schema::create('payroll_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id');
            $table->foreignId('employee_id');
            $table->string('employee_name', 210);
            $table->boolean('is_primary_employment');
            $table->decimal('gross_salary', 16, 2);
            $table->decimal('employee_contribution', 16, 2);
            $table->decimal('employer_contribution', 16, 2);
            $table->decimal('taxable_income', 16, 2);
            $table->decimal('income_tax', 16, 2);
            $table->decimal('net_salary', 16, 2);
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id'], 'payroll_lines_run_employee_unique');
            $table->foreign(['payroll_run_id', 'business_id'], 'payroll_lines_run_business_foreign')
                ->references(['id', 'business_id'])
                ->on('payroll_runs')
                ->cascadeOnDelete();
            $table->foreign(['employee_id', 'business_id'], 'payroll_lines_employee_business_foreign')
                ->references(['id', 'business_id'])
                ->on('employees')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employees');
    }
};
