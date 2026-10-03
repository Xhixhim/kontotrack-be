<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\PaymentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $paymentService) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        return PaymentResource::collection($this->paymentService->listForBusiness($business));
    }

    public function store(StorePaymentRequest $request, Business $business): PaymentResource
    {
        /** @var User $user */
        $user = $request->user();

        return new PaymentResource($this->paymentService->createForBusiness($business, $user, $request->validated()));
    }
}
