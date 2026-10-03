<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Counterparty;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Repositories\Contracts\AuditLogRepository;
use Illuminate\Pagination\LengthAwarePaginator;

class AuditLogService
{
    /**
     * Public subject names accepted by the API, mapped to the stored subject types.
     *
     * @var array<string, class-string>
     */
    public const SUBJECTS = [
        'business' => Business::class,
        'counterparty' => Counterparty::class,
        'invoice' => Invoice::class,
        'journal_entry' => JournalEntry::class,
        'payment' => Payment::class,
    ];

    public function __construct(private AuditLogRepository $auditLogRepository) {}

    /** @return LengthAwarePaginator<int, AuditLog> */
    public function listForBusiness(Business $business, ?string $subject, ?int $subjectId): LengthAwarePaginator
    {
        if ($subject === 'business') {
            $subjectId = (int) $business->getKey();
        }

        return $this->auditLogRepository->listForBusiness($business, $subject === null ? null : self::SUBJECTS[$subject], $subjectId);
    }
}
