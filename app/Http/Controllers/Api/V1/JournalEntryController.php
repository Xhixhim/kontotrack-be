<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Requests\StoreJournalReversalRequest;
use App\Http\Resources\Api\V1\JournalEntryResource;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class JournalEntryController extends Controller
{
    public function __construct(private JournalEntryService $journalEntryService) {}

    public function index(Request $request, Business $business): AnonymousResourceCollection
    {
        $status = $request->query('status');

        abort_if($status !== null && ! in_array($status, ['draft', 'posted'], true), 422);

        return JournalEntryResource::collection($this->journalEntryService->listForBusiness($business, $status));
    }

    public function store(StoreJournalEntryRequest $request, Business $business): JournalEntryResource
    {
        /** @var User $user */
        $user = $request->user();

        return new JournalEntryResource($this->journalEntryService->createDraft($business, $user, $request->validated()));
    }

    public function post(Request $request, Business $business, int $journalEntry): JournalEntryResource
    {
        /** @var User $user */
        $user = $request->user();

        return new JournalEntryResource($this->journalEntryService->post($business, $user, $journalEntry));
    }

    public function destroy(Request $request, Business $business, int $journalEntry): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->journalEntryService->deleteDraft($business, $user, $journalEntry);

        return response()->noContent();
    }

    public function reverse(StoreJournalReversalRequest $request, Business $business, int $journalEntry): JournalEntryResource
    {
        /** @var User $user */
        $user = $request->user();

        return new JournalEntryResource($this->journalEntryService->createReversalDraft($business, $user, $journalEntry, $request->validated()));
    }
}
