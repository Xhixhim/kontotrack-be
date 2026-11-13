<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Tax\GrossIncomeTaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GrossIncomeTaxController extends Controller
{
    public function __construct(private GrossIncomeTaxService $grossIncomeTaxService) {}

    public function show(Request $request, Business $business): JsonResponse
    {
        $filters = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2099'],
            'quarter' => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        return response()->json(['data' => $this->grossIncomeTaxService->worksheet($business, (int) $filters['year'], (int) $filters['quarter'])]);
    }

    public function assignActivity(Request $request, Business $business, int $account): AccountResource
    {
        $attributes = $request->validate(['activity' => ['present', 'nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/']]);
        /** @var User $user */
        $user = $request->user();

        return new AccountResource($this->grossIncomeTaxService->assignActivity($business, $user, $account, $attributes['activity']));
    }
}
