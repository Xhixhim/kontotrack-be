<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCounterpartyRequest;
use App\Http\Requests\UpdateCounterpartyRequest;
use App\Http\Resources\Api\V1\CounterpartyResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Counterparties\CounterpartyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class CounterpartyController extends Controller
{
    public function __construct(private CounterpartyService $counterpartyService) {}

    public function index(Request $request, Business $business): AnonymousResourceCollection
    {
        $type = $request->query('type');

        abort_if($type !== null && ! in_array($type, ['customer', 'supplier'], true), 422);

        return CounterpartyResource::collection($this->counterpartyService->listForBusiness($business, $type));
    }

    public function statement(Request $request, Business $business, int $counterparty): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $today = today()->toDateString();
        $to = $filters['to'] ?? $today;
        $from = $filters['from'] ?? Carbon::parse($to)->startOfYear()->toDateString();
        $statement = $this->counterpartyService->statement($business, $counterparty, $from, $to, $today);
        $statement['counterparty'] = new CounterpartyResource($statement['counterparty']);

        return response()->json(['data' => $statement]);
    }

    public function update(UpdateCounterpartyRequest $request, Business $business, int $counterparty): CounterpartyResource
    {
        /** @var User $user */
        $user = $request->user();

        return new CounterpartyResource($this->counterpartyService->update($business, $user, $counterparty, $request->validated()));
    }

    public function store(StoreCounterpartyRequest $request, Business $business): CounterpartyResource
    {
        /** @var User $user */
        $user = $request->user();

        return new CounterpartyResource($this->counterpartyService->createForBusiness($business, $user->getKey(), $request->validated()));
    }
}
