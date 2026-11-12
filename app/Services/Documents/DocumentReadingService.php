<?php

namespace App\Services\Documents;

use App\Models\Business;
use App\Models\Document;
use App\Models\User;
use App\Repositories\Contracts\DocumentRepository;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\Contracts\InvoiceReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * OCR for invoices and receipts. What is read is only a suggestion: it is stored as unreviewed data
 * and a person has to confirm it (documents/{id}/review) before anything relies on it.
 */
class DocumentReadingService
{
    private const READABLE_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private DocumentService $documentService, private DocumentRepository $documentRepository, private InvoiceReader $invoiceReader) {}

    public static function enabled(): bool
    {
        return ! empty(config('kontotrack.ai.api_key'));
    }

    public function read(Business $business, User $user, int $documentId, bool $canViewPayroll): Document
    {
        if (! self::enabled()) {
            throw new HttpException(503, 'Automatic reading is not set up on this server.');
        }

        $document = $this->documentService->findForBusiness($business, $documentId, $canViewPayroll);

        if (! in_array($document->mime_type, self::READABLE_TYPES, true)) {
            throw ValidationException::withMessages(['document' => ['Only PDF, JPG, PNG and WebP documents can be read automatically.']]);
        }

        $contents = Storage::disk($document->disk)->get($document->path);

        if ($contents === null) {
            throw ValidationException::withMessages(['document' => ['The file of this document is missing from storage.']]);
        }

        try {
            $fields = $this->invoiceReader->read($contents, (string) $document->mime_type);
        } catch (AiUnavailableException $exception) {
            throw new HttpException(503, $exception->getMessage(), $exception);
        }

        $extracted = [...$this->clean($fields), 'source' => 'ocr', 'read_at' => now()->toIso8601String()];
        $document->forceFill([
            'extracted_data' => $extracted,
            // Freshly read values have not been checked by anyone, whatever the document's state was before.
            'review_status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
        $document = $this->documentRepository->save($document);
        $business->auditLogs()->create([
            'user_id' => $user->getKey(),
            'action' => 'document.read_automatically',
            'subject_type' => Document::class,
            'subject_id' => $document->getKey(),
            'new_values' => ['extracted_data' => $extracted],
        ]);

        return $document->load(['counterparty:id,name', 'employee:id,first_name,last_name', 'payrollRun:id,period_year,period_month']);
    }

    /**
     * Keep only well-formed values, in the formats the review form uses.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function clean(array $fields): array
    {
        $text = fn (mixed $value, int $max): ?string => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
        $amount = fn (mixed $value): ?string => is_numeric($value) && abs((float) $value) < 1e12 ? number_format((float) $value, 2, '.', '') : null;
        $date = null;

        try {
            $date = is_string($fields['document_date'] ?? null) ? CarbonImmutable::createFromFormat('!Y-m-d', $fields['document_date'])->toDateString() : null;
        } catch (Throwable) {
            // An unreadable date is left for the person reviewing.
        }

        $lines = [];

        foreach (is_array($fields['lines'] ?? null) ? array_slice($fields['lines'], 0, 100) : [] as $line) {
            if (! is_array($line) || $text($line['description'] ?? null, 255) === null) {
                continue;
            }

            $lines[] = [
                'description' => $text($line['description'], 255),
                'quantity' => is_numeric($line['quantity'] ?? null) ? (string) (float) $line['quantity'] : null,
                'unit_price' => $amount($line['unit_price'] ?? null),
                'vat_rate_percent' => is_numeric($line['vat_rate_percent'] ?? null) ? (string) (float) $line['vat_rate_percent'] : null,
                'net_amount' => $amount($line['net_amount'] ?? null),
            ];
        }

        return [
            'supplier_name' => $text($fields['supplier_name'] ?? null, 255),
            'supplier_nui' => ($nui = $text($fields['supplier_nui'] ?? null, 64)) === null ? null : (mb_substr((string) preg_replace('/\s+/', '', $nui), 0, 32) ?: null),
            'document_number' => $text($fields['document_number'] ?? null, 80),
            'document_date' => $date,
            'currency' => ($currency = $text($fields['currency'] ?? null, 3)) === null ? null : strtoupper($currency),
            'net_amount' => $amount($fields['net_amount'] ?? null),
            'tax_amount' => $amount($fields['tax_amount'] ?? null),
            'gross_amount' => $amount($fields['gross_amount'] ?? null),
            'lines' => $lines,
        ];
    }
}
