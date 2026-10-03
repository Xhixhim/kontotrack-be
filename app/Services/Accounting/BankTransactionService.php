<?php

namespace App\Services\Accounting;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Business;
use App\Models\User;
use App\Repositories\Contracts\BankAccountRepository;
use App\Repositories\Contracts\BankTransactionRepository;
use App\Repositories\Contracts\JournalEntryRepository;
use App\Services\Documents\DocumentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankTransactionService
{
    public function __construct(
        private BankAccountRepository $bankAccountRepository,
        private BankTransactionRepository $bankTransactionRepository,
        private JournalEntryRepository $journalEntryRepository,
        private DocumentService $documentService,
    ) {}

    /** @return LengthAwarePaginator<int, BankTransaction> */
    public function listForBankAccount(Business $business, int $bankAccountId): LengthAwarePaginator
    {
        return $this->bankTransactionRepository->listForBankAccount(
            $this->bankAccountRepository->findForBusiness($business, $bankAccountId),
        );
    }

    /** @return array{document_id: int, transactions: Collection<int, BankTransaction>} */
    public function importStatement(Business $business, User $user, int $bankAccountId, UploadedFile $file): array
    {
        $bankAccount = $this->bankAccountRepository->findForBusiness($business, $bankAccountId);
        if ($bankAccount->currency !== $business->currency) {
            throw ValidationException::withMessages(['file' => ['The bank account currency must match the business currency.']]);
        }

        $rows = $this->parseCsv($file);
        $externalIds = array_values(array_filter(array_column($rows, 'external_id')));
        if (count($externalIds) !== count(array_unique($externalIds))) {
            throw ValidationException::withMessages(['file' => ['The statement contains duplicate external IDs.']]);
        }

        $existing = $this->bankTransactionRepository->existingExternalIds($bankAccount, $externalIds);
        if ($existing !== []) {
            throw ValidationException::withMessages(['file' => ['Some external IDs have already been imported for this bank account.']]);
        }

        $document = $this->documentService->store($business, $user, $file, ['category' => 'bank_statement']);
        $attributes = array_map(fn (array $row): array => [
            ...$row,
            'business_id' => $business->getKey(),
            'currency' => $bankAccount->currency,
            'document_id' => $document->getKey(),
            'journal_entry_id' => null,
            'is_reconciled' => false,
        ], $rows);

        $transactions = DB::transaction(function () use ($business, $user, $bankAccount, $attributes, $document): Collection {
            $transactions = $this->bankTransactionRepository->createImported($bankAccount, $attributes);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'bank_statement.imported',
                'subject_type' => BankAccount::class,
                'subject_id' => $bankAccount->getKey(),
                'new_values' => ['document_id' => $document->getKey(), 'transaction_count' => $transactions->count()],
            ]);

            return $transactions;
        });

        return ['document_id' => (int) $document->getKey(), 'transactions' => $transactions];
    }

    public function reconcile(Business $business, User $user, int $bankAccountId, int $transactionId, ?int $journalEntryId): BankTransaction
    {
        return DB::transaction(function () use ($business, $user, $bankAccountId, $transactionId, $journalEntryId): BankTransaction {
            $bankAccount = $this->bankAccountRepository->findForBusiness($business, $bankAccountId);
            $transaction = $this->bankTransactionRepository->findForReconciliation($business, $bankAccount, $transactionId);
            if ($transaction->is_reconciled) {
                throw ValidationException::withMessages(['transaction' => ['This transaction has already been reconciled.']]);
            }

            $linkedEntryId = $transaction->journal_entry_id === null ? null : (int) $transaction->journal_entry_id;
            if ($linkedEntryId !== null && $journalEntryId !== null && $linkedEntryId !== $journalEntryId) {
                throw ValidationException::withMessages(['journal_entry_id' => ['This bank transaction is already linked to a different journal entry.']]);
            }
            $entryId = $journalEntryId ?? $linkedEntryId;
            if ($entryId === null) {
                throw ValidationException::withMessages(['journal_entry_id' => ['Choose a posted journal entry to reconcile this transaction.']]);
            }

            $entry = $this->journalEntryRepository->findForPosting($business, $entryId);
            if ($entry->status !== 'posted' || $entry->entry_date->toDateString() !== $transaction->transaction_date->toDateString()) {
                throw ValidationException::withMessages(['journal_entry_id' => ['Choose a posted journal entry dated on the bank transaction date.']]);
            }
            if ($this->bankTransactionRepository->hasOtherTransactionForJournalEntry($business, $entryId, $transactionId)) {
                throw ValidationException::withMessages(['journal_entry_id' => ['This journal entry is already linked to another bank transaction.']]);
            }

            $bankNetCents = 0;
            foreach ($entry->lines->where('account_id', $bankAccount->account_id) as $line) {
                $bankNetCents += $this->toCents((string) $line->debit) - $this->toCents((string) $line->credit);
            }
            if ($bankNetCents !== $this->toCents((string) $transaction->amount)) {
                throw ValidationException::withMessages(['journal_entry_id' => ['The journal entry movement does not match this bank transaction amount and direction.']]);
            }

            $reconciled = $this->bankTransactionRepository->markReconciled($transaction, $entry);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'bank_transaction.reconciled',
                'subject_type' => BankTransaction::class,
                'subject_id' => $transaction->getKey(),
                'old_values' => ['is_reconciled' => false, 'journal_entry_id' => $linkedEntryId],
                'new_values' => ['is_reconciled' => true, 'journal_entry_id' => $entry->getKey()],
            ]);

            return $reconciled;
        });
    }

    /** @return list<array{transaction_date: string, external_id: ?string, description: string, amount: string}> */
    private function parseCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['The uploaded statement could not be read.']]);
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw ValidationException::withMessages(['file' => ['The statement is empty.']]);
            }
            $delimiter = ',';
            foreach ([';', "\t"] as $candidate) {
                if (substr_count($firstLine, $candidate) > substr_count($firstLine, $delimiter)) {
                    $delimiter = $candidate;
                }
            }
            rewind($handle);
            $headers = fgetcsv($handle, 0, $delimiter, '"', '');
            $headers = array_map(static function ($header): string {
                $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header) ?? (string) $header;

                return strtolower(trim($header));
            }, $headers ?: []);
            foreach (['date', 'description', 'amount'] as $required) {
                if (count(array_keys($headers, $required, true)) !== 1) {
                    throw ValidationException::withMessages(['file' => ["The statement must contain one '{$required}' column."]]);
                }
            }
            if (count($headers) !== count(array_unique($headers))) {
                throw ValidationException::withMessages(['file' => ['The statement contains duplicate column names.']]);
            }

            $index = array_flip($headers);
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if ($values === [null] || count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if (count($rows) >= 5000) {
                    throw ValidationException::withMessages(['file' => ['A statement can contain at most 5,000 transactions.']]);
                }
                if (count($values) !== count($headers)) {
                    throw ValidationException::withMessages(['file' => ["Statement row {$line} has the wrong number of columns."]]);
                }
                $date = $this->parseDate((string) ($values[$index['date']] ?? ''));
                $description = trim((string) ($values[$index['description']] ?? ''));
                $amount = $this->parseAmount((string) ($values[$index['amount']] ?? ''));
                $externalId = isset($index['external_id']) ? trim((string) ($values[$index['external_id']] ?? '')) : '';
                if ($date === null || $description === '' || mb_strlen($description) > 255 || $amount === null || ($externalId !== '' && mb_strlen($externalId) > 160)) {
                    throw ValidationException::withMessages(['file' => ["Statement row {$line} has an invalid date, description, amount, or external ID."]]);
                }
                $rows[] = ['transaction_date' => $date, 'external_id' => $externalId === '' ? null : $externalId, 'description' => $description, 'amount' => $amount];
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => ['The statement contains no transactions.']]);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function parseDate(string $value): ?string
    {
        foreach (['!Y-m-d', '!d.m.Y', '!d/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, trim($value));
            if ($date !== false && $date->format(substr($format, 1)) === trim($value)) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function parseAmount(string $value): ?string
    {
        $value = str_replace([' ', "\u{00A0}", '€'], '', trim($value));
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $decimalSeparator = strrpos($value, ',') > strrpos($value, '.') ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $value = str_replace($thousandsSeparator, '', $value);
            $value = str_replace($decimalSeparator, '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            return null;
        }
        $integer = ltrim($matches[2], '0') ?: '0';
        if (strlen($integer) > 16 || (strlen($integer) === 16 && strcmp($integer, '9999999999999999') > 0)) {
            return null;
        }
        $fraction = str_pad($matches[3] ?? '', 2, '0');
        if ($integer === '0' && $fraction === '00') {
            return null;
        }

        return ($matches[1] ?? '').$integer.'.'.$fraction;
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $parts = explode('.', ltrim($amount, '-'));
        $cents = ((int) ($parts[0] ?? 0) * 100) + (int) str_pad($parts[1] ?? '0', 2, '0');

        return $negative ? -$cents : $cents;
    }
}
