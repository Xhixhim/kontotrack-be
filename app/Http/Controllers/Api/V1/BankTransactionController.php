<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportBankStatementRequest;
use App\Http\Requests\ReconcileBankTransactionRequest;
use App\Http\Resources\Api\V1\BankTransactionResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\BankTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BankTransactionController extends Controller
{
    public function __construct(private BankTransactionService $bankTransactionService) {}

    public function index(Business $business, int $bankAccount): AnonymousResourceCollection
    {
        return BankTransactionResource::collection($this->bankTransactionService->listForBankAccount($business, $bankAccount));
    }

    public function import(ImportBankStatementRequest $request, Business $business, int $bankAccount): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $this->bankTransactionService->importStatement($business, $user, $bankAccount, $request->file('file'));

        return response()->json([
            'data' => $result['transactions']->map(fn ($transaction) => (new BankTransactionResource($transaction))->resolve($request))->all(),
            'meta' => ['document_id' => $result['document_id'], 'imported_count' => $result['transactions']->count(), 'matched_count' => $result['matched_count'], 'suggested_count' => $result['suggested_count']],
        ], 201);
    }

    public function reconcile(ReconcileBankTransactionRequest $request, Business $business, int $bankAccount, int $transaction): BankTransactionResource
    {
        /** @var User $user */
        $user = $request->user();
        $entryId = $request->validated('journal_entry_id');

        return new BankTransactionResource($this->bankTransactionService->reconcile(
            $business,
            $user,
            $bankAccount,
            $transaction,
            $entryId === null ? null : (int) $entryId,
        ));
    }
}
