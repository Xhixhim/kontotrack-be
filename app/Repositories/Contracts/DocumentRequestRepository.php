<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\DocumentRequest;
use Illuminate\Database\Eloquent\Collection;

interface DocumentRequestRepository
{
    /** @return Collection<int, DocumentRequest> */
    public function listForBusiness(Business $business, ?string $status): Collection;

    /** @param array<string, mixed> $attributes */
    public function create(Business $business, array $attributes): DocumentRequest;

    public function findForUpdate(Business $business, int $requestId): DocumentRequest;

    public function save(DocumentRequest $request): DocumentRequest;

    /**
     * @param  list<int>  $businessIds
     * @return Collection<int, DocumentRequest>
     */
    public function withStatus(array $businessIds, string $status): Collection;
}
