<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Resources\Api\V1\AccountResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\AccountService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    public function __construct(private AccountService $accountService) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        return AccountResource::collection($this->accountService->listForBusiness($business));
    }

    public function store(StoreAccountRequest $request, Business $business): AccountResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AccountResource($this->accountService->createForBusiness($business, $user->getKey(), $request->validated()));
    }
}
