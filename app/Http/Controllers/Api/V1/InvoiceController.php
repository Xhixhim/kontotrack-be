<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelInvoiceRequest;
use App\Http\Requests\IssueInvoiceRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Invoicing\InvoiceEmailService;
use App\Services\Invoicing\InvoiceService;
use App\Services\Reports\DocumentExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoiceService, private DocumentExportService $exportService) {}

    public function index(Request $request, Business $business): AnonymousResourceCollection
    {
        $kind = $request->query('kind');

        abort_if($kind !== null && ! in_array($kind, ['sale', 'purchase'], true), 422);

        return InvoiceResource::collection($this->invoiceService->listForBusiness($business, $kind));
    }

    public function show(Business $business, int $invoice): InvoiceResource
    {
        return new InvoiceResource($this->invoiceService->findForBusiness($business, $invoice));
    }

    public function pdf(Business $business, int $invoice): Response
    {
        $file = $this->exportService->invoicePdf($business, $this->invoiceService->findForBusiness($business, $invoice));

        return response($file['contents'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    public function send(Request $request, Business $business, int $invoice, InvoiceEmailService $invoiceEmailService): InvoiceResource
    {
        $attributes = $request->validate([
            'to' => ['required', 'email', 'max:255'],
            'cc' => ['nullable', 'array', 'max:5'],
            'cc.*' => ['email', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new InvoiceResource($invoiceEmailService->send($business, $user, $invoice, $attributes));
    }

    public function taxCodes(Request $request, Business $business): JsonResponse
    {
        $filters = $request->validate(['date' => ['required', 'date']]);

        return response()->json(['data' => $this->invoiceService->taxCodes($business, $filters['date'])]);
    }

    public function store(StoreInvoiceRequest $request, Business $business): InvoiceResource
    {
        /** @var User $user */
        $user = $request->user();

        return new InvoiceResource($this->invoiceService->createDraft($business, $user->getKey(), $request->validated()));
    }

    public function storeCreditNote(Request $request, Business $business, int $invoice): InvoiceResource
    {
        $attributes = $request->validate([
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'invoice_number' => ['nullable', 'string', 'max:80'],
            'document_id' => ['nullable', 'integer'],
            'notes' => ['required', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:100000'],
            'lines.*.unit' => ['nullable', 'string', 'max:24'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0', 'decimal:0,4', 'max:100000'],
            'lines.*.discount_amount' => ['sometimes', 'numeric', 'gte:0', 'decimal:0,2', 'max:99999999999999.99'],
            'lines.*.inventory_item_id' => ['nullable', 'integer'],
            'lines.*.warehouse_id' => ['nullable', 'integer'],
            'lines.*.vat_code' => ['required', 'string', 'max:40'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new InvoiceResource($this->invoiceService->createCreditNote($business, $user, $invoice, $attributes));
    }

    public function destroy(Request $request, Business $business, int $invoice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->invoiceService->deleteDraft($business, $user, $invoice);

        return response()->json(['message' => 'Draft invoice deleted.']);
    }

    public function cancel(CancelInvoiceRequest $request, Business $business, int $invoice): InvoiceResource
    {
        /** @var User $user */
        $user = $request->user();

        return new InvoiceResource($this->invoiceService->cancel($business, $user, $invoice, $request->validated()));
    }

    public function issue(IssueInvoiceRequest $request, Business $business, int $invoice): InvoiceResource
    {
        /** @var User $user */
        $user = $request->user();

        $journalEntryId = $request->validated('journal_entry_id');

        if ($journalEntryId === null) {
            return new InvoiceResource($this->invoiceService->issueWithGeneratedJournal($business, $user, $invoice));
        }

        return new InvoiceResource($this->invoiceService->issueDraft($business, $user, $invoice, (int) $journalEntryId));
    }
}
