<?php

namespace App\Services\Documents;

use App\Models\Business;
use App\Models\DocumentRequest;
use App\Models\User;
use App\Repositories\Contracts\DocumentRequestRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The accountant asks the business for a document; the business answers by attaching one; the accountant accepts or sends it back.
 */
class DocumentRequestService
{
    public function __construct(private DocumentRequestRepository $requestRepository) {}

    /** @return Collection<int, DocumentRequest> */
    public function list(Business $business, ?string $status): Collection
    {
        return $this->requestRepository->listForBusiness($business, $status);
    }

    /** @param array{title: string, description?: string|null, category: string, due_on?: string|null} $attributes */
    public function create(Business $business, User $user, array $attributes): DocumentRequest
    {
        return DB::transaction(function () use ($business, $user, $attributes): DocumentRequest {
            $request = $this->requestRepository->create($business, $attributes + ['status' => 'open', 'requested_by' => $user->getKey()]);
            $this->audit($business, $user, 'document_request.created', $request, null, $request->only(['title', 'category', 'due_on']));

            return $request;
        });
    }

    public function submit(Business $business, User $user, int $requestId, int $documentId): DocumentRequest
    {
        return DB::transaction(function () use ($business, $user, $requestId, $documentId): DocumentRequest {
            $request = $this->requestRepository->findForUpdate($business, $requestId);

            if ($request->status !== 'open') {
                throw ValidationException::withMessages(['document_request' => ['Only open requests can receive a document.']]);
            }

            if (! $business->documents()->whereKey($documentId)->exists()) {
                throw ValidationException::withMessages(['document_id' => ['Choose a document that belongs to this business.']]);
            }

            $request->forceFill([
                'status' => 'submitted',
                'document_id' => $documentId,
                'submitted_by' => $user->getKey(),
                'submitted_at' => now(),
            ]);
            $request = $this->requestRepository->save($request);
            $this->audit($business, $user, 'document_request.submitted', $request, ['status' => 'open'], ['status' => 'submitted', 'document_id' => $documentId]);

            return $request;
        });
    }

    public function accept(Business $business, User $user, int $requestId): DocumentRequest
    {
        return $this->decide($business, $user, $requestId, 'accepted', null);
    }

    public function reject(Business $business, User $user, int $requestId, string $note): DocumentRequest
    {
        return $this->decide($business, $user, $requestId, 'open', $note);
    }

    private function decide(Business $business, User $user, int $requestId, string $newStatus, ?string $note): DocumentRequest
    {
        return DB::transaction(function () use ($business, $user, $requestId, $newStatus, $note): DocumentRequest {
            $request = $this->requestRepository->findForUpdate($business, $requestId);

            if ($request->status !== 'submitted') {
                throw ValidationException::withMessages(['document_request' => ['Only submitted requests can be accepted or sent back.']]);
            }

            $previousDocumentId = $request->document_id;
            $request->forceFill([
                'status' => $newStatus,
                'reviewed_by' => $user->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
                'document_id' => $newStatus === 'open' ? null : $request->document_id,
                'submitted_by' => $newStatus === 'open' ? null : $request->submitted_by,
                'submitted_at' => $newStatus === 'open' ? null : $request->submitted_at,
            ]);
            $request = $this->requestRepository->save($request);
            $this->audit($business, $user, $newStatus === 'accepted' ? 'document_request.accepted' : 'document_request.sent_back', $request, ['status' => 'submitted', 'document_id' => $previousDocumentId], ['status' => $newStatus, 'review_note' => $note]);

            return $request;
        });
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(Business $business, User $user, string $action, DocumentRequest $request, ?array $old, ?array $new): void
    {
        $business->auditLogs()->create([
            'user_id' => $user->getKey(),
            'action' => $action,
            'subject_type' => DocumentRequest::class,
            'subject_id' => $request->getKey(),
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
