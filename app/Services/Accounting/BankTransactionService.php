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
use App\Support\SimpleXlsxReader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BankTransactionService
{
    private const MAX_TRANSACTIONS = 5000;

    /** How many rows above the header (bank name, account, period…) we look through. */
    private const HEADER_SEARCH_ROWS = 25;

    /** Normalised column names (lowercase, no diacritics or brackets), in order of preference. */
    private const COLUMN_ALIASES = [
        'date' => ['date', 'data', 'data e transaksionit', 'transaction date', 'booking date', 'data e regjistrimit', 'data e postimit', 'posting date', 'data e valutes', 'value date'],
        'description' => ['description', 'pershkrimi', 'pershkrim', 'pershkrimi i transaksionit', 'detajet', 'details', 'narrative', 'qellimi i pageses', 'qellimi', 'payment details'],
        'amount' => ['amount', 'shuma', 'vlera', 'shuma e transaksionit'],
        'money_in' => ['credit', 'kredi', 'hyrje', 'hyrjet', 'deposit', 'deposits', 'money in', 'paid in'],
        'money_out' => ['debit', 'debi', 'dalje', 'daljet', 'withdrawal', 'withdrawals', 'money out', 'paid out'],
        'external_id' => ['external_id', 'external id', 'transaction id', 'id e transaksionit'],
    ];

    public function __construct(
        private BankAccountRepository $bankAccountRepository,
        private BankTransactionRepository $bankTransactionRepository,
        private JournalEntryRepository $journalEntryRepository,
        private DocumentService $documentService,
        private SimpleXlsxReader $xlsxReader,
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

        $rows = $this->parseStatement($file);
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
    private function parseStatement(UploadedFile $file): array
    {
        $isWorkbook = strtolower($file->getClientOriginalExtension()) === 'xlsx';
        $rows = $isWorkbook ? $this->workbookRows($file) : $this->csvRows($file);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => ['The statement is empty.']]);
        }

        [$headerRow, $columns] = $this->statementColumns($rows);
        $headerWidth = count($rows[$headerRow]);
        $transactions = [];

        foreach ($rows as $line => $values) {
            if ($line <= $headerRow) {
                continue;
            }
            if (! $isWorkbook && count($values) !== $headerWidth) {
                throw ValidationException::withMessages(['file' => ["Statement row {$line} has the wrong number of columns."]]);
            }

            $cell = fn (string $field): string|float|null => isset($columns[$field]) ? ($values[$columns[$field]] ?? null) : null;
            $rawDate = $cell('date');
            $date = is_float($rawDate) ? $this->xlsxReader->excelDate($rawDate) : $this->parseDate($this->cellText($rawDate));
            if ($date === null && $this->isSummaryRow($values)) {
                continue;
            }
            if (count($transactions) >= self::MAX_TRANSACTIONS) {
                throw ValidationException::withMessages(['file' => ['A statement can contain at most 5,000 transactions.']]);
            }

            $description = $this->cellText($cell('description'));
            $externalId = $this->cellText($cell('external_id'));
            $amount = isset($columns['amount']) ? $this->cellAmount($cell('amount')) : $this->debitCreditAmount($cell('money_in'), $cell('money_out'));

            if ($date === null || $description === '' || mb_strlen($description) > 255 || $amount === null || $amount === '0.00' || mb_strlen($externalId) > 160) {
                throw ValidationException::withMessages(['file' => ["Statement row {$line} has an invalid date, description, amount, or external ID."]]);
            }

            $transactions[] = ['transaction_date' => $date, 'external_id' => $externalId === '' ? null : $externalId, 'description' => $description, 'amount' => $amount];
        }

        if ($transactions === []) {
            throw ValidationException::withMessages(['file' => ['The statement contains no transactions.']]);
        }

        return $transactions;
    }

    /** @return array<int, list<string|float|null>> */
    private function workbookRows(UploadedFile $file): array
    {
        try {
            return $this->xlsxReader->firstSheetRows($file->getRealPath(), self::MAX_TRANSACTIONS + self::HEADER_SEARCH_ROWS);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => [str_contains($exception->getMessage(), 'more than') ? 'A statement can contain at most 5,000 transactions.' : $exception->getMessage()]]);
        }
    }

    /** @return array<int, list<string>> */
    private function csvRows(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['The uploaded statement could not be read.']]);
        }

        try {
            $sample = '';
            for ($i = 0; $i < 10 && ($line = fgets($handle)) !== false; $i++) {
                $sample .= $line;
            }
            $delimiter = ',';
            foreach ([';', "\t"] as $candidate) {
                if (substr_count($sample, $candidate) > substr_count($sample, $delimiter)) {
                    $delimiter = $candidate;
                }
            }
            rewind($handle);

            $rows = [];
            $line = 0;
            while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;
                $values = array_map(fn ($value): string => $this->toUtf8((string) $value), $values);
                if ($line === 1 && isset($values[0])) {
                    $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', $values[0]) ?? $values[0];
                }
                if (array_filter($values, fn (string $value): bool => trim($value) !== '') === []) {
                    continue;
                }
                if (count($rows) >= self::MAX_TRANSACTIONS + self::HEADER_SEARCH_ROWS) {
                    throw ValidationException::withMessages(['file' => ['A statement can contain at most 5,000 transactions.']]);
                }
                $rows[$line] = $values;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Finds the header row (banks often put the account name and period above it) and maps
     * the columns we need, accepting the names Kosovo banks use in Albanian and English.
     *
     * @param  array<int, list<string|float|null>>  $rows
     * @return array{int, array<string, int>}
     */
    private function statementColumns(array $rows): array
    {
        $candidates = array_slice($rows, 0, self::HEADER_SEARCH_ROWS, true);

        foreach ($candidates as $line => $values) {
            $columns = $this->mapColumns($values);
            if (isset($columns['date'], $columns['description']) && (isset($columns['amount']) || isset($columns['money_in'], $columns['money_out']))) {
                return [$line, $columns];
            }
        }

        // No recognisable header: explain what is missing from the first row.
        $line = array_key_first($rows);
        $columns = $this->mapColumns($rows[$line]);
        foreach (['date', 'description'] as $required) {
            if (! isset($columns[$required])) {
                throw ValidationException::withMessages(['file' => ["The statement must contain one '{$required}' column."]]);
            }
        }

        throw ValidationException::withMessages(['file' => ["The statement must contain one 'amount' column, or separate debit and credit columns."]]);
    }

    /**
     * @param  list<string|float|null>  $values
     * @return array<string, int>
     */
    private function mapColumns(array $values): array
    {
        $headers = array_map(fn ($value): string => $this->normalizeHeader($this->cellText($value)), $values);
        $columns = [];

        foreach (self::COLUMN_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $positions = array_keys($headers, $alias, true);
                if (count($positions) > 1) {
                    throw ValidationException::withMessages(['file' => ['The statement contains duplicate column names.']]);
                }
                if ($positions !== []) {
                    $columns[$field] = $positions[0];
                    break;
                }
            }
        }

        return $columns;
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = strtr($header, ['ë' => 'e', 'ç' => 'c', 'é' => 'e', 'è' => 'e']);
        $header = (string) preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $header);
        $header = (string) preg_replace('/[^a-z0-9_]+/', ' ', $header);

        return trim((string) preg_replace('/\s+/', ' ', $header));
    }

    /** Opening, closing and total lines that banks add around the transactions. */
    private function isSummaryRow(array $values): bool
    {
        foreach ($values as $value) {
            $text = $this->normalizeHeader($this->cellText($value));
            if ($text !== '') {
                return preg_match('/^(totali?|total|saldo|gjendja|balance|opening|closing|bilanci|shuma totale)\b/', $text) === 1;
            }
        }

        return true;
    }

    private function debitCreditAmount(string|float|null $moneyIn, string|float|null $moneyOut): ?string
    {
        $in = $this->isBlank($moneyIn) ? '0.00' : $this->cellAmount($moneyIn);
        $out = $this->isBlank($moneyOut) ? '0.00' : $this->cellAmount($moneyOut);
        if ($in === null || $out === null || ($in !== '0.00' && $out !== '0.00')) {
            return null;
        }

        return $in !== '0.00' ? ltrim($in, '-') : '-'.ltrim($out, '-');
    }

    /** Returns the amount as a 2-decimal string ("0.00" for zero), or null when it is not a valid amount. */
    private function cellAmount(string|float|null $value): ?string
    {
        if (is_float($value)) {
            $cents = round($value * 100);
            if (abs($value * 100 - $cents) > 0.0001 || abs($cents) > 999999999999999999) {
                return null;
            }

            return $this->fromCents((int) $cents);
        }

        return $this->parseAmount($this->cellText($value));
    }

    private function cellText(string|float|null $value): string
    {
        if (is_float($value)) {
            return floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
        }

        return trim((string) $value);
    }

    private function isBlank(string|float|null $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /** Bank CSV exports are often Windows-1252, which has ë and ç. */
    private function toUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        foreach (['!Y-m-d', '!d.m.Y', '!d/m/Y', '!d-m-Y', '!j.n.Y', '!Y-m-d H:i:s', '!Y-m-d H:i', '!d.m.Y H:i:s', '!d.m.Y H:i', '!d/m/Y H:i:s', '!d/m/Y H:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format(substr($format, 1)) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function parseAmount(string $value): ?string
    {
        $value = str_replace([' ', "\u{00A0}", '€', 'EUR'], '', trim($value));
        if (preg_match('/^\((.+)\)$/', $value, $parenthesised)) {
            $value = '-'.$parenthesised[1];
        }
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $decimalSeparator = strrpos($value, ',') > strrpos($value, '.') ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $value = str_replace($thousandsSeparator, '', $value);
            $value = str_replace($decimalSeparator, '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }
        if (! preg_match('/^([-+]?)(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            return null;
        }
        $integer = ltrim($matches[2], '0') ?: '0';
        if (strlen($integer) > 16 || (strlen($integer) === 16 && strcmp($integer, '9999999999999999') > 0)) {
            return null;
        }
        $fraction = str_pad($matches[3] ?? '', 2, '0');
        if ($integer === '0' && $fraction === '00') {
            return '0.00';
        }

        return ($matches[1] === '-' ? '-' : '').$integer.'.'.$fraction;
    }

    private function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $parts = explode('.', ltrim($amount, '-'));
        $cents = ((int) ($parts[0] ?? 0) * 100) + (int) str_pad($parts[1] ?? '0', 2, '0');

        return $negative ? -$cents : $cents;
    }
}
