<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BusinessUnitResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Units\BusinessUnitService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BusinessUnitController extends Controller
{
    public function __construct(private BusinessUnitService $unitService) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        return BusinessUnitResource::collection($this->unitService->listForBusiness($business));
    }

    public function store(Request $request, Business $business): BusinessUnitResource
    {
        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:24'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new BusinessUnitResource($this->unitService->create($business, $user, $attributes));
    }

    public function update(Request $request, Business $business, int $unit): BusinessUnitResource
    {
        $attributes = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'code' => ['sometimes', 'nullable', 'string', 'max:24'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new BusinessUnitResource($this->unitService->update($business, $user, $unit, $attributes));
    }
}
