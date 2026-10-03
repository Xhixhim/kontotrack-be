<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

interface DocumentRepository
{
    /** @return Collection<int, Document> */
    public function listForBusiness(Business $business): Collection;

    /**
     * @param  array{category?: string|null, counterparty_id?: int|null, review_status?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Collection<int, Document>
     */
    public function filterForBusiness(Business $business, array $filters): Collection;

    public function findForUpdate(Business $business, int $documentId): Document;

    public function save(Document $document): Document;

    public function findForBusiness(Business $business, int $documentId): Document;

    public function isInvoiceForBusiness(Business $business, int $documentId): bool;

    /** @param array{category: string, counterparty_id?: int|null, document_date?: string|null, notes?: string|null} $attributes */
    public function store(Business $business, User $user, UploadedFile $file, array $attributes): Document;
}
