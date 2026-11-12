<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Documents\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $documentService) {}

    public function index(Request $request, Business $business): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'category' => ['nullable', 'string', 'max:40'],
            'counterparty_id' => ['nullable', 'integer'],
            'review_status' => ['nullable', 'string', 'in:pending,reviewed'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer'],
            'payroll_run_id' => ['nullable', 'integer'],
        ]);

        return DocumentResource::collection($this->documentService->filter($business, $filters, $this->canViewPayroll($request, $business)));
    }

    public function update(Request $request, Business $business, int $document): DocumentResource
    {
        $attributes = $request->validate([
            'category' => ['sometimes', 'required', 'string', 'in:invoice,receipt,contract,bank_statement,payroll,tax_filing,certificate,authorization,lease,other'],
            'counterparty_id' => ['sometimes', 'nullable', 'integer'],
            'employee_id' => ['sometimes', 'nullable', 'integer'],
            'payroll_run_id' => ['sometimes', 'nullable', 'integer'],
            'document_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new DocumentResource($this->documentService->update($business, $user, $document, $attributes, $this->canViewPayroll($request, $business)));
    }

    public function review(Request $request, Business $business, int $document): DocumentResource
    {
        $attributes = $request->validate([
            'extracted_data' => ['required', 'array'],
            'extracted_data.supplier_name' => ['nullable', 'string', 'max:255'],
            'extracted_data.supplier_nui' => ['nullable', 'string', 'max:32'],
            'extracted_data.document_number' => ['nullable', 'string', 'max:80'],
            'extracted_data.document_date' => ['nullable', 'date_format:Y-m-d'],
            'extracted_data.net_amount' => ['nullable', 'numeric', 'decimal:0,2'],
            'extracted_data.tax_amount' => ['nullable', 'numeric', 'decimal:0,2'],
            'extracted_data.gross_amount' => ['nullable', 'numeric', 'decimal:0,2'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new DocumentResource($this->documentService->review($business, $user, $document, $attributes, $this->canViewPayroll($request, $business)));
    }

    public function store(StoreDocumentRequest $request, Business $business): JsonResource
    {
        /** @var User $user */
        $user = $request->user();
        $file = $request->file('file');

        return new DocumentResource($this->documentService->store($business, $user, $file, $request->validated(), $this->canViewPayroll($request, $business)));
    }

    public function download(Request $request, Business $business, int $document): StreamedResponse
    {
        $storedDocument = $this->documentService->findForBusiness($business, $document, $this->canViewPayroll($request, $business));

        return Storage::disk($storedDocument->disk)->download($storedDocument->path, $storedDocument->original_name);
    }

    private function canViewPayroll(Request $request, Business $business): bool
    {
        return $request->user()?->can('viewPayroll', $business) === true;
    }
}
