<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DocumentRequestResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Documents\DocumentRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentRequestController extends Controller
{
    public function __construct(private DocumentRequestService $requestService) {}

    public function index(Request $request, Business $business): AnonymousResourceCollection
    {
        $filters = $request->validate(['status' => ['nullable', 'string', 'in:open,submitted,accepted']]);

        return DocumentRequestResource::collection($this->requestService->list($business, $filters['status'] ?? null));
    }

    public function store(Request $request, Business $business): DocumentRequestResource
    {
        $attributes = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', 'string', 'in:invoice,receipt,contract,bank_statement,payroll,tax_filing,certificate,authorization,lease,other'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new DocumentRequestResource($this->requestService->create($business, $user, $attributes));
    }

    public function submit(Request $request, Business $business, int $documentRequest): DocumentRequestResource
    {
        $attributes = $request->validate(['document_id' => ['required', 'integer']]);
        /** @var User $user */
        $user = $request->user();

        return new DocumentRequestResource($this->requestService->submit($business, $user, $documentRequest, (int) $attributes['document_id']));
    }

    public function accept(Request $request, Business $business, int $documentRequest): DocumentRequestResource
    {
        /** @var User $user */
        $user = $request->user();

        return new DocumentRequestResource($this->requestService->accept($business, $user, $documentRequest));
    }

    public function reject(Request $request, Business $business, int $documentRequest): DocumentRequestResource
    {
        $attributes = $request->validate(['review_note' => ['required', 'string', 'max:2000']]);
        /** @var User $user */
        $user = $request->user();

        return new DocumentRequestResource($this->requestService->reject($business, $user, $documentRequest, $attributes['review_note']));
    }
}
