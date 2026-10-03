<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DepreciationRunResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Assets\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepreciationRunController extends Controller
{
    public function __construct(private FixedAssetService $assetService) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        return DepreciationRunResource::collection($this->assetService->listRuns($business));
    }

    public function show(Business $business, int $depreciationRun): DepreciationRunResource
    {
        return new DepreciationRunResource($this->assetService->findRun($business, $depreciationRun));
    }

    public function store(Request $request, Business $business): DepreciationRunResource
    {
        $period = $request->validate([
            'period_year' => ['required', 'integer', 'between:2000,2100'],
            'period_month' => ['required', 'integer', 'between:1,12'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new DepreciationRunResource($this->assetService->createDraftRun($business, $user, (int) $period['period_year'], (int) $period['period_month']));
    }

    public function approve(Request $request, Business $business, int $depreciationRun): DepreciationRunResource
    {
        /** @var User $user */
        $user = $request->user();

        return new DepreciationRunResource($this->assetService->approveRun($business, $user, $depreciationRun));
    }

    public function destroy(Request $request, Business $business, int $depreciationRun): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->assetService->deleteDraftRun($business, $user, $depreciationRun);

        return response()->json(['message' => 'Draft depreciation run deleted.']);
    }
}
