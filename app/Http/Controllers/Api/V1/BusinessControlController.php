<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Controls\BusinessControlService;
use Illuminate\Http\JsonResponse;

class BusinessControlController extends Controller
{
    public function __construct(private BusinessControlService $controlService) {}

    public function index(Business $business): JsonResponse
    {
        return response()->json(['data' => $this->controlService->run($business, today()->toDateString())]);
    }
}
