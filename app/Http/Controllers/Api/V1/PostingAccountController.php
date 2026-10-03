<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePostingAccountsRequest;
use App\Http\Resources\Api\V1\PostingAccountResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\PostingAccountService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostingAccountController extends Controller
{
    public function __construct(private PostingAccountService $postingAccountService) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        return PostingAccountResource::collection($this->postingAccountService->listForBusiness($business));
    }

    public function update(UpdatePostingAccountsRequest $request, Business $business): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $accountIdsByPurpose = collect($request->validated('accounts'))
            ->map(fn ($accountId): ?int => $accountId === null ? null : (int) $accountId)
            ->all();

        return PostingAccountResource::collection($this->postingAccountService->updateForBusiness($business, $user, $accountIdsByPurpose));
    }
}
