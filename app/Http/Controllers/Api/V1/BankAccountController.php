<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBankAccountRequest;
use App\Http\Resources\Api\V1\BankAccountResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\BankAccountService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BankAccountController extends Controller
{
    public function __construct(private BankAccountService $bankAccountService) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        return BankAccountResource::collection($this->bankAccountService->listForBusiness($business));
    }

    public function store(StoreBankAccountRequest $request, Business $business): BankAccountResource
    {
        /** @var User $user */
        $user = $request->user();

        return new BankAccountResource($this->bankAccountService->createForBusiness($business, $user->getKey(), $request->validated()));
    }
}
