<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Dashboard\BusinessDashboardService;
use App\Services\Units\BusinessUnitService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BusinessDashboardController extends Controller
{
    public function __construct(private BusinessDashboardService $dashboardService, private BusinessUnitService $unitService) {}

    public function show(Request $request, Business $business): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'business_unit_id' => ['nullable', 'integer'],
        ]);
        $to = CarbonImmutable::parse($filters['to'] ?? today())->toDateString();
        $from = CarbonImmutable::parse($filters['from'] ?? CarbonImmutable::parse($to)->startOfMonth())->toDateString();

        if ($from > $to) {
            throw ValidationException::withMessages(['from' => ['The start date must not be after the end date.']]);
        }

        $includePayroll = $request->user()?->can('viewPayroll', $business) === true;

        $unit = $this->unitService->assertBelongs($business, isset($filters['business_unit_id']) ? (int) $filters['business_unit_id'] : null);

        return response()->json(['data' => $this->dashboardService->summaryForBusiness($business, $from, $to, $includePayroll, $unit?->getKey())]);
    }
}
