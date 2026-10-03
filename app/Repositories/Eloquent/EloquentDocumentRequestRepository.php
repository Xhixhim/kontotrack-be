<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\DocumentRequest;
use App\Repositories\Contracts\DocumentRequestRepository;
use Illuminate\Database\Eloquent\Collection;

class EloquentDocumentRequestRepository implements DocumentRequestRepository
{
    /** @return Collection<int, DocumentRequest> */
    public function listForBusiness(Business $business, ?string $status): Collection
    {
        return $business->documentRequests()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with(['requester:id,name', 'submitter:id,name', 'document:id,original_name,category'])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'submitted' THEN 1 ELSE 2 END")
            ->orderBy('due_on')
            ->latest('id')
            ->get();
    }

    /** @param array<string, mixed> $attributes */
    public function create(Business $business, array $attributes): DocumentRequest
    {
        return $business->documentRequests()->create($attributes)->load(['requester:id,name', 'submitter:id,name', 'document:id,original_name,category']);
    }

    public function findForUpdate(Business $business, int $requestId): DocumentRequest
    {
        return $business->documentRequests()->whereKey($requestId)->lockForUpdate()->firstOrFail();
    }

    public function save(DocumentRequest $request): DocumentRequest
    {
        $request->save();

        return $request->load(['requester:id,name', 'submitter:id,name', 'document:id,original_name,category']);
    }

    /**
     * @param  list<int>  $businessIds
     * @return Collection<int, DocumentRequest>
     */
    public function withStatus(array $businessIds, string $status): Collection
    {
        return DocumentRequest::query()
            ->whereIn('business_id', $businessIds)
            ->where('status', $status)
            ->with('business:id,legal_name,trade_name')
            ->orderBy('due_on')
            ->get();
    }
}
