<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePayrollRunRequest;
use App\Http\Resources\Api\V1\PayrollRunResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollRunController extends Controller
{
    public function __construct(private PayrollService $payrollService) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        return PayrollRunResource::collection($this->payrollService->listRuns($business));
    }

    public function show(Business $business, int $payrollRun): PayrollRunResource
    {
        return new PayrollRunResource($this->payrollService->findRun($business, $payrollRun));
    }

    public function store(StorePayrollRunRequest $request, Business $business): PayrollRunResource
    {
        /** @var User $user */
        $user = $request->user();

        return new PayrollRunResource($this->payrollService->createDraftRun($business, $user, $request->validated()));
    }

    public function approve(Request $request, Business $business, int $payrollRun): PayrollRunResource
    {
        /** @var User $user */
        $user = $request->user();

        return new PayrollRunResource($this->payrollService->approveRun($business, $user, $payrollRun));
    }

    public function destroy(Request $request, Business $business, int $payrollRun): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->payrollService->deleteDraftRun($business, $user, $payrollRun);

        return response()->json(['message' => 'Draft payroll deleted.']);
    }
}
