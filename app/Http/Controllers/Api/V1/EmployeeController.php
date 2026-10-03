<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\Api\V1\EmployeeResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function __construct(private PayrollService $payrollService) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        return EmployeeResource::collection($this->payrollService->listEmployees($business));
    }

    public function store(StoreEmployeeRequest $request, Business $business): EmployeeResource
    {
        /** @var User $user */
        $user = $request->user();

        return new EmployeeResource($this->payrollService->createEmployee($business, $user, $request->validated()));
    }

    public function update(UpdateEmployeeRequest $request, Business $business, int $employee): EmployeeResource
    {
        /** @var User $user */
        $user = $request->user();

        return new EmployeeResource($this->payrollService->updateEmployee($business, $user, $employee, $request->validated()));
    }
}
