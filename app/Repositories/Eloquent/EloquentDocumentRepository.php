<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\Document;
use App\Models\User;
use App\Repositories\Contracts\DocumentRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class EloquentDocumentRepository implements DocumentRepository
{
    /** @return Collection<int, Document> */
    public function listForBusiness(Business $business): Collection
    {
        return $business->documents()->latest('id')->get();
    }

    /**
     * @param  array{category?: string|null, counterparty_id?: int|null, review_status?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Collection<int, Document>
     */
    public function filterForBusiness(Business $business, array $filters): Collection
    {
        return $business->documents()
            ->when(isset($filters['category']), fn ($query) => $query->where('category', $filters['category']))
            ->when(isset($filters['counterparty_id']), fn ($query) => $query->where('counterparty_id', $filters['counterparty_id']))
            ->when(isset($filters['review_status']), fn ($query) => $query->where('review_status', $filters['review_status']))
            ->when(isset($filters['from']), fn ($query) => $query->whereDate('document_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->whereDate('document_date', '<=', $filters['to']))
            ->when(isset($filters['employee_id']), fn ($query) => $query->where('employee_id', $filters['employee_id']))
            ->when(isset($filters['payroll_run_id']), fn ($query) => $query->where('payroll_run_id', $filters['payroll_run_id']))
            ->when(($filters['hide_payroll_linked'] ?? false) === true, fn ($query) => $query->whereNull('employee_id')->whereNull('payroll_run_id'))
            ->with(['counterparty:id,name', 'employee:id,first_name,last_name', 'payrollRun:id,period_year,period_month'])
            ->latest('id')
            ->get();
    }

    public function findForUpdate(Business $business, int $documentId): Document
    {
        return $business->documents()->whereKey($documentId)->lockForUpdate()->firstOrFail();
    }

    public function save(Document $document): Document
    {
        $document->save();

        return $document->load('counterparty:id,name');
    }

    public function findForBusiness(Business $business, int $documentId): Document
    {
        return $business->documents()->whereKey($documentId)->firstOrFail();
    }

    public function isInvoiceForBusiness(Business $business, int $documentId): bool
    {
        return $business->documents()
            ->whereKey($documentId)
            ->where('category', 'invoice')
            ->exists();
    }

    /** @param array{category: string, counterparty_id?: int|null, document_date?: string|null, notes?: string|null} $attributes */
    public function store(Business $business, User $user, UploadedFile $file, array $attributes): Document
    {
        $path = $file->store('businesses/'.$business->getKey().'/documents', 'local');

        if ($path === false) {
            throw new RuntimeException('The document could not be stored.');
        }

        try {
            return $business->documents()->create([
                'uploaded_by' => $user->getKey(),
                'category' => $attributes['category'],
                'counterparty_id' => $attributes['counterparty_id'] ?? null,
                'employee_id' => $attributes['employee_id'] ?? null,
                'payroll_run_id' => $attributes['payroll_run_id'] ?? null,
                'document_date' => $attributes['document_date'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'original_name' => $file->getClientOriginalName(),
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }
}
