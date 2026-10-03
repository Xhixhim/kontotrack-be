<?php

namespace App\Providers;

use App\Models\Business;
use App\Policies\BusinessPolicy;
use App\Repositories\Contracts\AccountingPeriodRepository;
use App\Repositories\Contracts\AccountRepository;
use App\Repositories\Contracts\AuditLogRepository;
use App\Repositories\Contracts\BankAccountRepository;
use App\Repositories\Contracts\BankTransactionRepository;
use App\Repositories\Contracts\BusinessControlRepository;
use App\Repositories\Contracts\BusinessDashboardRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CashAccountRepository;
use App\Repositories\Contracts\ComplianceRuleSetRepository;
use App\Repositories\Contracts\ConversationRepository;
use App\Repositories\Contracts\CounterpartyRepository;
use App\Repositories\Contracts\DocumentRepository;
use App\Repositories\Contracts\DocumentRequestRepository;
use App\Repositories\Contracts\FinancialReportRepository;
use App\Repositories\Contracts\FixedAssetRepository;
use App\Repositories\Contracts\InventoryRepository;
use App\Repositories\Contracts\InvoiceRepository;
use App\Repositories\Contracts\JournalEntryRepository;
use App\Repositories\Contracts\PaymentRepository;
use App\Repositories\Contracts\PayrollRepository;
use App\Repositories\Contracts\PostingAccountRepository;
use App\Repositories\Contracts\TaskRepository;
use App\Repositories\Eloquent\EloquentAccountingPeriodRepository;
use App\Repositories\Eloquent\EloquentAccountRepository;
use App\Repositories\Eloquent\EloquentAuditLogRepository;
use App\Repositories\Eloquent\EloquentBankAccountRepository;
use App\Repositories\Eloquent\EloquentBankTransactionRepository;
use App\Repositories\Eloquent\EloquentBusinessControlRepository;
use App\Repositories\Eloquent\EloquentBusinessDashboardRepository;
use App\Repositories\Eloquent\EloquentBusinessRepository;
use App\Repositories\Eloquent\EloquentCashAccountRepository;
use App\Repositories\Eloquent\EloquentComplianceRuleSetRepository;
use App\Repositories\Eloquent\EloquentConversationRepository;
use App\Repositories\Eloquent\EloquentCounterpartyRepository;
use App\Repositories\Eloquent\EloquentDocumentRepository;
use App\Repositories\Eloquent\EloquentDocumentRequestRepository;
use App\Repositories\Eloquent\EloquentFinancialReportRepository;
use App\Repositories\Eloquent\EloquentFixedAssetRepository;
use App\Repositories\Eloquent\EloquentInventoryRepository;
use App\Repositories\Eloquent\EloquentInvoiceRepository;
use App\Repositories\Eloquent\EloquentJournalEntryRepository;
use App\Repositories\Eloquent\EloquentPaymentRepository;
use App\Repositories\Eloquent\EloquentPayrollRepository;
use App\Repositories\Eloquent\EloquentPostingAccountRepository;
use App\Repositories\Eloquent\EloquentTaskRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AccountRepository::class, EloquentAccountRepository::class);
        $this->app->bind(AccountingPeriodRepository::class, EloquentAccountingPeriodRepository::class);
        $this->app->bind(AuditLogRepository::class, EloquentAuditLogRepository::class);
        $this->app->bind(BankAccountRepository::class, EloquentBankAccountRepository::class);
        $this->app->bind(BankTransactionRepository::class, EloquentBankTransactionRepository::class);
        $this->app->bind(BusinessRepository::class, EloquentBusinessRepository::class);
        $this->app->bind(BusinessControlRepository::class, EloquentBusinessControlRepository::class);
        $this->app->bind(BusinessDashboardRepository::class, EloquentBusinessDashboardRepository::class);
        $this->app->bind(CashAccountRepository::class, EloquentCashAccountRepository::class);
        $this->app->bind(ComplianceRuleSetRepository::class, EloquentComplianceRuleSetRepository::class);
        $this->app->bind(ConversationRepository::class, EloquentConversationRepository::class);
        $this->app->bind(CounterpartyRepository::class, EloquentCounterpartyRepository::class);
        $this->app->bind(DocumentRepository::class, EloquentDocumentRepository::class);
        $this->app->bind(DocumentRequestRepository::class, EloquentDocumentRequestRepository::class);
        $this->app->bind(FinancialReportRepository::class, EloquentFinancialReportRepository::class);
        $this->app->bind(FixedAssetRepository::class, EloquentFixedAssetRepository::class);
        $this->app->bind(InvoiceRepository::class, EloquentInvoiceRepository::class);
        $this->app->bind(InventoryRepository::class, EloquentInventoryRepository::class);
        $this->app->bind(JournalEntryRepository::class, EloquentJournalEntryRepository::class);
        $this->app->bind(PaymentRepository::class, EloquentPaymentRepository::class);
        $this->app->bind(PayrollRepository::class, EloquentPayrollRepository::class);
        $this->app->bind(PostingAccountRepository::class, EloquentPostingAccountRepository::class);
        $this->app->bind(TaskRepository::class, EloquentTaskRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Business::class, BusinessPolicy::class);
    }
}
