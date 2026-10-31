<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookBankTransactionRequest;
use App\Http\Requests\CategorizeBankTransactionRequest;
use App\Http\Requests\SaveBankCategorizationRuleRequest;
use App\Http\Resources\Api\V1\BankCategorizationRuleResource;
use App\Http\Resources\Api\V1\BankTransactionResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\BankCategories;
use App\Services\Accounting\BankCategorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BankCategorizationController extends Controller
{
    public function __construct(private BankCategorizationService $categorizationService) {}

    public function categories(): JsonResponse
    {
        return response()->json(['data' => collect(BankCategories::ALL)->map(fn (array $category, string $key): array => ['key' => $key, ...$category])->values()]);
    }

    public function categorize(CategorizeBankTransactionRequest $request, Business $business, int $bankAccount, int $transaction): BankTransactionResource
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        return new BankTransactionResource($this->categorizationService->categorize($business, $user, $bankAccount, $transaction, [
            'category' => $validated['category'],
            'account_id' => isset($validated['account_id']) ? (int) $validated['account_id'] : null,
            'counterparty_id' => isset($validated['counterparty_id']) ? (int) $validated['counterparty_id'] : null,
        ], $validated['remember_as'] ?? null));
    }

    public function book(BookBankTransactionRequest $request, Business $business, int $bankAccount, int $transaction): BankTransactionResource
    {
        /** @var User $user */
        $user = $request->user();
        $allocations = $request->validated('allocations');

        return new BankTransactionResource($this->categorizationService->record($business, $user, $bankAccount, $transaction, $allocations === null ? null : array_map(
            fn (array $allocation): array => ['invoice_id' => (int) $allocation['invoice_id'], 'amount' => (string) $allocation['amount']],
            $allocations,
        )));
    }

    public function applyRules(Business $business, int $bankAccount): JsonResponse
    {
        $account = $business->bankAccounts()->whereKey($bankAccount)->firstOrFail();

        return response()->json(['data' => ['suggested_count' => $this->categorizationService->applyRules($business, $account)]]);
    }

    public function rules(Business $business): AnonymousResourceCollection
    {
        return BankCategorizationRuleResource::collection($this->categorizationService->listRules($business));
    }

    public function storeRule(SaveBankCategorizationRuleRequest $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new BankCategorizationRuleResource($this->categorizationService->createRule($business, $user, $request->validated())))->response()->setStatusCode(201);
    }

    public function updateRule(SaveBankCategorizationRuleRequest $request, Business $business, int $rule): BankCategorizationRuleResource
    {
        /** @var User $user */
        $user = $request->user();

        return new BankCategorizationRuleResource($this->categorizationService->updateRule($business, $user, $rule, $request->validated()));
    }

    public function destroyRule(Request $request, Business $business, int $rule): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->categorizationService->deleteRule($business, $user, $rule);

        return response()->noContent();
    }
}
