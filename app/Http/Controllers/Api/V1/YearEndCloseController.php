<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\JournalEntryResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\YearEndCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YearEndCloseController extends Controller
{
    public function __construct(private YearEndCloseService $yearEndCloseService) {}

    public function show(Business $business, int $year): JsonResponse
    {
        return response()->json(['data' => $this->yearEndCloseService->preview($business, $year)]);
    }

    public function close(Request $request, Business $business, int $year): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new JournalEntryResource($this->yearEndCloseService->close($business, $user, $year)))->response()->setStatusCode(201);
    }
}
