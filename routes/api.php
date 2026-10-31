<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AccountingPeriodController;
use App\Http\Controllers\Api\V1\AtkExportController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\BankAccountController;
use App\Http\Controllers\Api\V1\BankCategorizationController;
use App\Http\Controllers\Api\V1\BankTransactionController;
use App\Http\Controllers\Api\V1\BusinessControlController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessDashboardController;
use App\Http\Controllers\Api\V1\CashAccountController;
use App\Http\Controllers\Api\V1\ComplianceRuleSetController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\CounterpartyController;
use App\Http\Controllers\Api\V1\DepreciationRunController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentRequestController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\FinancialReportController;
use App\Http\Controllers\Api\V1\FixedAssetController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PayrollRunController;
use App\Http\Controllers\Api\V1\PostingAccountController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Models\User;
use App\Services\Security\SecurityLogService;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    Route::post('/login', function (Request $request, SecurityLogService $securityLog, TwoFactorService $twoFactor) {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        if (! Auth::guard('web')->validate(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            $securityLog->loginFailed($credentials['email'], $request, 'web');

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $user = User::query()->where('email', $credentials['email'])->firstOrFail();

        if ($user->hasTwoFactorEnabled()) {
            if (empty($credentials['code']) && empty($credentials['recovery_code'])) {
                return response()->json([
                    'message' => 'Enter the 6-digit code from your authenticator app.',
                    'two_factor_required' => true,
                    'errors' => ['code' => ['Enter the 6-digit code from your authenticator app.']],
                ], 422);
            }

            if (! $twoFactor->passes($user, $credentials['code'] ?? null, $credentials['recovery_code'] ?? null)) {
                $securityLog->loginFailed($credentials['email'], $request, 'web');

                throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
            }
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $securityLog->loginSucceeded($request->user(), $request, 'web');

        return response()->json([
            'data' => $request->user()->only(['id', 'name', 'email', 'is_system_admin']),
        ]);
    })->middleware('throttle:6,1');

    Route::post('/mobile/token', function (Request $request, SecurityLogService $securityLog, TwoFactorService $twoFactor) {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        if (! Auth::guard('web')->validate(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            $securityLog->loginFailed($credentials['email'], $request, 'mobile');

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $user = User::query()->where('email', $credentials['email'])->firstOrFail();

        if ($user->hasTwoFactorEnabled()) {
            if (empty($credentials['code']) && empty($credentials['recovery_code'])) {
                return response()->json([
                    'message' => 'Enter the 6-digit code from your authenticator app.',
                    'two_factor_required' => true,
                    'errors' => ['code' => ['Enter the 6-digit code from your authenticator app.']],
                ], 422);
            }

            if (! $twoFactor->passes($user, $credentials['code'] ?? null, $credentials['recovery_code'] ?? null)) {
                $securityLog->loginFailed($credentials['email'], $request, 'mobile');

                throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
            }
        }

        $securityLog->loginSucceeded($user, $request, 'mobile');

        return response()->json([
            'token' => $user->createToken($credentials['device_name'])->plainTextToken,
            'data' => $user->only(['id', 'name', 'email', 'is_system_admin']),
        ]);
    })->middleware('throttle:6,1');

    Route::delete('/mobile/token', function (Request $request, SecurityLogService $securityLog) {
        $securityLog->loggedOut($request->user(), $request, 'mobile');
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    })->middleware('auth:sanctum');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/compliance/rule-sets/effective', [ComplianceRuleSetController::class, 'effective']);
        Route::get('/compliance/rule-sets', [ComplianceRuleSetController::class, 'index']);
        Route::post('/compliance/rule-sets', [ComplianceRuleSetController::class, 'store']);
        Route::put('/compliance/rule-sets/{ruleSet}', [ComplianceRuleSetController::class, 'update']);
        Route::post('/compliance/rule-sets/{ruleSet}/approve', [ComplianceRuleSetController::class, 'approve']);
        Route::get('/businesses', [BusinessController::class, 'index']);
        Route::post('/businesses', [BusinessController::class, 'store']);
        Route::put('/businesses/{business}', [BusinessController::class, 'update'])
            ->middleware('can:update,business');
        Route::get('/businesses/{business}/audit-logs', [AuditLogController::class, 'index'])
            ->middleware('can:viewAuditTrail,business');
        Route::get('/businesses/{business}/members', [BusinessController::class, 'members'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/team', [BusinessController::class, 'team'])
            ->middleware('can:manageMembers,business');
        Route::post('/businesses/{business}/team', [BusinessController::class, 'invite'])
            ->middleware('can:manageMembers,business');
        Route::post('/businesses/{business}/invitations/accept', [BusinessController::class, 'acceptInvitation']);
        Route::get('/me/business-invitations', [BusinessController::class, 'invitations']);
        Route::get('/businesses/{business}/controls', [BusinessControlController::class, 'index'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/dashboard', [BusinessDashboardController::class, 'show'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/reports/financial', [FinancialReportController::class, 'index'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/reports/financial/export', [FinancialReportController::class, 'export'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/atk/vat-books', [AtkExportController::class, 'vatBooks'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/atk/vat-books/{book}.xlsx', [AtkExportController::class, 'vatBookFile'])
            ->whereIn('book', ['sales', 'purchases'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/payroll-runs/{payrollRun}/atk-wm', [AtkExportController::class, 'payrollList'])
            ->whereNumber('payrollRun')
            ->middleware('can:viewPayroll,business');
        Route::get('/businesses/{business}/counterparties', [CounterpartyController::class, 'index'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/counterparties', [CounterpartyController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/counterparties/{counterparty}/statement', [CounterpartyController::class, 'statement'])
            ->middleware('can:view,business');
        Route::put('/businesses/{business}/counterparties/{counterparty}', [CounterpartyController::class, 'update'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/invoices', [InvoiceController::class, 'index'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/invoices/{invoice}', [InvoiceController::class, 'show'])
            ->whereNumber('invoice')
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])
            ->whereNumber('invoice')
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/vat-codes', [InvoiceController::class, 'taxCodes'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/inventory', [InventoryController::class, 'index'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/inventory/items', [InventoryController::class, 'storeItem'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/inventory/warehouses', [InventoryController::class, 'storeWarehouse'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/inventory/movements', [InventoryController::class, 'storeMovement'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/inventory/transfers', [InventoryController::class, 'transfer'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/inventory/counts', [InventoryController::class, 'count'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/invoices', [InvoiceController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])
            ->middleware('can:manageBookkeeping,business');
        Route::delete('/businesses/{business}/invoices/{invoice}', [InvoiceController::class, 'destroy'])
            ->whereNumber('invoice')
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/invoices/{invoice}/credit-notes', [InvoiceController::class, 'storeCreditNote'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/employees', [EmployeeController::class, 'index'])
            ->middleware('can:viewPayroll,business');
        Route::post('/businesses/{business}/employees', [EmployeeController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::put('/businesses/{business}/employees/{employee}', [EmployeeController::class, 'update'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/payroll-runs', [PayrollRunController::class, 'index'])
            ->middleware('can:viewPayroll,business');
        Route::get('/businesses/{business}/payroll-runs/{payrollRun}', [PayrollRunController::class, 'show'])
            ->middleware('can:viewPayroll,business');
        Route::post('/businesses/{business}/payroll-runs', [PayrollRunController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/payroll-runs/{payrollRun}/approve', [PayrollRunController::class, 'approve'])
            ->middleware('can:manageBookkeeping,business');
        Route::delete('/businesses/{business}/payroll-runs/{payrollRun}', [PayrollRunController::class, 'destroy'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/fixed-assets', [FixedAssetController::class, 'index'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/fixed-assets/{fixedAsset}', [FixedAssetController::class, 'show'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/fixed-assets', [FixedAssetController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/fixed-assets/{fixedAsset}/transfer', [FixedAssetController::class, 'transfer'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/fixed-assets/{fixedAsset}/dispose', [FixedAssetController::class, 'dispose'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/depreciation-runs', [DepreciationRunController::class, 'index'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/depreciation-runs/{depreciationRun}', [DepreciationRunController::class, 'show'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/depreciation-runs', [DepreciationRunController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/depreciation-runs/{depreciationRun}/approve', [DepreciationRunController::class, 'approve'])
            ->middleware('can:manageBookkeeping,business');
        Route::delete('/businesses/{business}/depreciation-runs/{depreciationRun}', [DepreciationRunController::class, 'destroy'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/me/notifications', [NotificationController::class, 'index']);
        Route::get('/businesses/{business}/tasks', [TaskController::class, 'index'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/tasks', [TaskController::class, 'store'])
            ->middleware('can:update,business');
        Route::post('/businesses/{business}/tasks/generate-tax-calendar', [TaskController::class, 'generate'])
            ->middleware('can:manageBookkeeping,business');
        Route::put('/businesses/{business}/tasks/{task}', [TaskController::class, 'update'])
            ->middleware('can:update,business');
        Route::post('/businesses/{business}/tasks/{task}/complete', [TaskController::class, 'complete'])
            ->middleware('can:update,business');
        Route::get('/businesses/{business}/accounts', [AccountController::class, 'index'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/accounts', [AccountController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/accounts/kosovo-template', [AccountController::class, 'applyKosovoTemplate'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/posting-accounts', [PostingAccountController::class, 'index'])
            ->middleware('can:view,business');
        Route::put('/businesses/{business}/posting-accounts', [PostingAccountController::class, 'update'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/periods', [AccountingPeriodController::class, 'index'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/periods/{period}/closing-checks', [AccountingPeriodController::class, 'closingChecks'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/periods', [AccountingPeriodController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/periods/{period}/close', [AccountingPeriodController::class, 'close'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/cash-accounts', [CashAccountController::class, 'index'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/cash-accounts', [CashAccountController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/bank-accounts', [BankAccountController::class, 'index'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/bank-accounts', [BankAccountController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/bank-accounts/{bankAccount}/transactions', [BankTransactionController::class, 'index'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/bank-accounts/{bankAccount}/statement-import', [BankTransactionController::class, 'import'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/bank-accounts/{bankAccount}/transactions/{transaction}/reconcile', [BankTransactionController::class, 'reconcile'])
            ->middleware('can:manageBookkeeping,business');
        Route::put('/businesses/{business}/bank-accounts/{bankAccount}/transactions/{transaction}/category', [BankCategorizationController::class, 'categorize'])
            ->whereNumber(['bankAccount', 'transaction'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/bank-accounts/{bankAccount}/transactions/{transaction}/book', [BankCategorizationController::class, 'book'])
            ->whereNumber(['bankAccount', 'transaction'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/bank-accounts/{bankAccount}/apply-rules', [BankCategorizationController::class, 'applyRules'])
            ->whereNumber('bankAccount')
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/bank-categories', [BankCategorizationController::class, 'categories'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/bank-rules', [BankCategorizationController::class, 'rules'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/bank-rules', [BankCategorizationController::class, 'storeRule'])
            ->middleware('can:manageBookkeeping,business');
        Route::put('/businesses/{business}/bank-rules/{rule}', [BankCategorizationController::class, 'updateRule'])
            ->whereNumber('rule')
            ->middleware('can:manageBookkeeping,business');
        Route::delete('/businesses/{business}/bank-rules/{rule}', [BankCategorizationController::class, 'destroyRule'])
            ->whereNumber('rule')
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/payments', [PaymentController::class, 'index'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/payments', [PaymentController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/journal-entries', [JournalEntryController::class, 'index'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/journal-entries', [JournalEntryController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])
            ->middleware('can:manageBookkeeping,business');
        Route::delete('/businesses/{business}/journal-entries/{journalEntry}', [JournalEntryController::class, 'destroy'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/documents', [DocumentController::class, 'store'])
            ->middleware('can:uploadDocuments,business');
        Route::get('/businesses/{business}/documents', [DocumentController::class, 'index'])
            ->middleware('can:view,business');
        Route::put('/businesses/{business}/documents/{document}', [DocumentController::class, 'update'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/documents/{document}/review', [DocumentController::class, 'review'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/document-requests', [DocumentRequestController::class, 'index'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/document-requests', [DocumentRequestController::class, 'store'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/document-requests/{documentRequest}/submit', [DocumentRequestController::class, 'submit'])
            ->middleware('can:uploadDocuments,business');
        Route::post('/businesses/{business}/document-requests/{documentRequest}/accept', [DocumentRequestController::class, 'accept'])
            ->middleware('can:manageBookkeeping,business');
        Route::post('/businesses/{business}/document-requests/{documentRequest}/reject', [DocumentRequestController::class, 'reject'])
            ->middleware('can:manageBookkeeping,business');
        Route::get('/businesses/{business}/documents/{document}/download', [DocumentController::class, 'download'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/conversations', [ConversationController::class, 'index'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/conversations', [ConversationController::class, 'store'])
            ->middleware('can:view,business');
        Route::get('/businesses/{business}/conversations/{conversation}/messages', [ConversationController::class, 'messages'])
            ->middleware('can:view,business');
        Route::post('/businesses/{business}/conversations/{conversation}/messages', [ConversationController::class, 'send'])
            ->middleware('can:view,business');
        Route::get('/me', fn (Request $request) => response()->json([
            'data' => [...$request->user()->only(['id', 'name', 'email', 'is_system_admin']), 'two_factor_enabled' => $request->user()->hasTwoFactorEnabled()],
        ]));
        Route::post('/me/two-factor', fn (Request $request, TwoFactorService $twoFactor) => response()->json(['data' => $twoFactor->begin($request->user())]));
        Route::post('/me/two-factor/confirm', function (Request $request, TwoFactorService $twoFactor) {
            $attributes = $request->validate(['code' => ['required', 'string', 'max:10']]);

            return response()->json(['data' => ['recovery_codes' => $twoFactor->confirm($request->user(), $attributes['code'])]]);
        });
        Route::delete('/me/two-factor', function (Request $request, TwoFactorService $twoFactor) {
            $attributes = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:10']]);
            $twoFactor->disable($request->user(), $attributes['password'], $attributes['code']);

            return response()->json(['message' => 'Two-factor sign-in turned off.']);
        });

        Route::post('/logout', function (Request $request, SecurityLogService $securityLog) {
            $securityLog->loggedOut($request->user(), $request, 'web');
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Logged out.']);
        });
    });
});
