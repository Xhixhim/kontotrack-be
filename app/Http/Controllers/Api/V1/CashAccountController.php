<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashAccountRequest;
use App\Http\Resources\Api\V1\CashAccountResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\CashAccountService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CashAccountController extends Controller
{
    public function __construct(private CashAccountService $cashAccountService) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        return CashAccountResource::collection($this->cashAccountService->listForBusiness($business));
    }

    public function store(StoreCashAccountRequest $request, Business $business): CashAccountResource
    {
        /** @var User $user */
        $user = $request->user();

        return new CashAccountResource($this->cashAccountService->createForBusiness($business, $user->getKey(), $request->validated()));
    }
}
