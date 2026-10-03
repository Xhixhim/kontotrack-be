<?php

namespace App\Services\Documents;

use App\Models\Business;
use App\Models\Document;
use App\Models\User;
use App\Repositories\Contracts\DocumentRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentService
{
    public function __construct(private DocumentRepository $documentRepository) {}

    /** @return Collection<int, Document> */
    public function listForBusiness(Business $business): Collection
    {
        return $this->documentRepository->listForBusiness($business);
    }

    /**
     * @param  array{category?: string|null, counterparty_id?: int|null, review_status?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Collection<int, Document>
     */
    public function filter(Business $business, array $filters): Collection
    {
        return $this->documentRepository->filterForBusiness($business, $filters);
    }

    /**
     * Change the category, contact, date, or notes of a document.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Business $business, User $user, int $documentId, array $attributes): Document
    {
        return DB::transaction(function () use ($business, $user, $documentId, $attributes): Document {
            $this->assertCounterpartyBelongs($business, $attributes['counterparty_id'] ?? null);
            $document = $this->documentRepository->findForUpdate($business, $documentId);
            $document->fill($attributes);
            $changed = array_keys($document->getDirty());

            if ($changed === []) {
                return $document->load('counterparty:id,name');
            }

            $old = [];
            $new = [];

            foreach ($changed as $attribute) {
                $old[$attribute] = $this->auditValue($document->getOriginal($attribute));
                $new[$attribute] = $this->auditValue($document->getAttribute($attribute));
            }

            $document = $this->documentRepository->save($document);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'document.updated',
                'subject_type' => Document::class,
                'subject_id' => $document->getKey(),
                'old_values' => $old,
                'new_values' => $new,
            ]);

            return $document;
        });
    }

    /**
     * Record the human check of a document's extracted data (from OCR or typed in). Nothing is posted from unreviewed data.
     *
     * @param  array{extracted_data: array<string, mixed>}  $attributes
     */
    public function review(Business $business, User $user, int $documentId, array $attributes): Document
    {
        return DB::transaction(function () use ($business, $user, $documentId, $attributes): Document {
            $document = $this->documentRepository->findForUpdate($business, $documentId);
            $oldValues = ['review_status' => $document->review_status, 'extracted_data' => $document->extracted_data];
            $document->forceFill([
                'extracted_data' => $attributes['extracted_data'],
                'review_status' => 'reviewed',
                'reviewed_by' => $user->getKey(),
                'reviewed_at' => now(),
            ]);
            $document = $this->documentRepository->save($document);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'document.reviewed',
                'subject_type' => Document::class,
                'subject_id' => $document->getKey(),
                'old_values' => $oldValues,
                'new_values' => ['review_status' => 'reviewed', 'extracted_data' => $attributes['extracted_data']],
            ]);

            return $document;
        });
    }

    public function findForBusiness(Business $business, int $documentId): Document
    {
        return $this->documentRepository->findForBusiness($business, $documentId);
    }

    /** @param array{category: string, counterparty_id?: int|null, document_date?: string|null, notes?: string|null} $attributes */
    public function store(Business $business, User $user, UploadedFile $file, array $attributes): Document
    {
        $this->assertCounterpartyBelongs($business, $attributes['counterparty_id'] ?? null);
        $document = null;

        try {
            return DB::transaction(function () use ($business, $user, $file, $attributes, &$document): Document {
                $document = $this->documentRepository->store($business, $user, $file, $attributes);
                $document->business->auditLogs()->create([
                    'user_id' => $user->getKey(),
                    'action' => 'document.uploaded',
                    'subject_type' => Document::class,
                    'subject_id' => $document->getKey(),
                    'new_values' => $document->only(['category', 'original_name', 'mime_type', 'size_bytes', 'sha256']),
                ]);

                return $document;
            });
        } catch (Throwable $exception) {
            if ($document instanceof Document) {
                Storage::disk($document->disk)->delete($document->path);
            }

            throw $exception;
        }
    }

    private function assertCounterpartyBelongs(Business $business, ?int $counterpartyId): void
    {
        if ($counterpartyId !== null && ! $business->counterparties()->whereKey($counterpartyId)->exists()) {
            throw ValidationException::withMessages(['counterparty_id' => ['Choose a client or supplier that belongs to this business.']]);
        }
    }

    private function auditValue(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }
}
