<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountingPeriodRequest;
use App\Http\Resources\Api\V1\AccountingPeriodResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountingPeriodController extends Controller
{
    public function __construct(private AccountingPeriodService $periodService) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        return AccountingPeriodResource::collection($this->periodService->listForBusiness($business));
    }

    public function store(StoreAccountingPeriodRequest $request, Business $business): AccountingPeriodResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AccountingPeriodResource($this->periodService->createForBusiness($business, $user->getKey(), $request->validated()));
    }

    public function close(Request $request, Business $business, int $period): AccountingPeriodResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AccountingPeriodResource($this->periodService->close($business, $user, $period));
    }

    public function closingChecks(Business $business, int $period): JsonResponse
    {
        return response()->json(['data' => $this->periodService->closingChecks($business, $period)]);
    }
}
