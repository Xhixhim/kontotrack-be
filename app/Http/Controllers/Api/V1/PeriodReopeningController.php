<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecidePeriodReopeningRequest;
use App\Http\Requests\RequestPeriodReopeningRequest;
use App\Http\Resources\Api\V1\PeriodReopenRequestResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\PeriodReopeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PeriodReopeningController extends Controller
{
    public function __construct(private PeriodReopeningService $reopeningService) {}

    public function index(Business $business, int $period): AnonymousResourceCollection
    {
        return PeriodReopenRequestResource::collection($this->reopeningService->listForPeriod($business, $period));
    }

    public function store(RequestPeriodReopeningRequest $request, Business $business, int $period): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new PeriodReopenRequestResource($this->reopeningService->request($business, $user, $period, $request->validated('reason'))))->response()->setStatusCode(201);
    }

    public function approve(DecidePeriodReopeningRequest $request, Business $business, int $period, int $reopenRequest): PeriodReopenRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return new PeriodReopenRequestResource($this->reopeningService->approve($business, $user, $period, $reopenRequest, $request->validated('note')));
    }

    public function reject(DecidePeriodReopeningRequest $request, Business $business, int $period, int $reopenRequest): PeriodReopenRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return new PeriodReopenRequestResource($this->reopeningService->reject($business, $user, $period, $reopenRequest, (string) $request->validated('note')));
    }

    public function cancel(Request $request, Business $business, int $period, int $reopenRequest): PeriodReopenRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return new PeriodReopenRequestResource($this->reopeningService->cancel($business, $user, $period, $reopenRequest));
    }
}
