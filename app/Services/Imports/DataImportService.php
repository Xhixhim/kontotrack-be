<?php

namespace App\Services\Imports;

use App\Models\Business;
use App\Models\User;
use App\Rules\CounterpartyIdentifier;
use App\Services\Counterparties\CounterpartyService;
use App\Services\Inventory\InventoryService;
use App\Support\TabularFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Imports master data (customers and suppliers, stock items) from an Excel or CSV file.
 * The whole file is checked first: one bad row means nothing is imported, so a file is never half-loaded.
 */
class DataImportService
{
    public const TYPES = ['counterparties', 'inventory-items'];

    private const MAX_ROWS = 2000;

    /**
     * Column names people use in Albanian and English, mapped to our fields.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const COLUMNS = [
        'counterparties' => [
            'name' => ['name', 'emri', 'emertimi', 'klienti', 'furnitori', 'subjekti', 'company', 'kompania'],
            'type' => ['type', 'lloji', 'tipi'],
            'nui' => ['nui', 'numri unik identifikues', 'numri fiskal', 'nr fiskal', 'fiscal number', 'numri personal', 'nr personal', 'numri i biznesit'],
            'email' => ['email', 'e mail', 'emaili', 'posta elektronike'],
            'phone' => ['phone', 'telefoni', 'tel', 'nr telefonit', 'telefon'],
            'address' => ['address', 'adresa'],
            'municipality' => ['municipality', 'komuna', 'qyteti', 'city', 'vendi'],
            'country_code' => ['country code', 'country', 'shteti', 'kodi i shtetit'],
        ],
        'inventory-items' => [
            'sku' => ['sku', 'code', 'kodi', 'shifra', 'kodi i artikullit'],
            'name' => ['name', 'emri', 'artikulli', 'emertimi', 'pershkrimi', 'produkti', 'item'],
            'barcode' => ['barcode', 'barkodi', 'bar kodi', 'ean'],
            'unit' => ['unit', 'njesia', 'njesia matese', 'nj'],
            'purchase_price' => ['purchase price', 'cmimi i blerjes', 'cmimi blerjes', 'kosto', 'cost'],
            'sale_price' => ['sale price', 'cmimi i shitjes', 'cmimi shitjes', 'cmimi', 'price'],
            'minimum_quantity' => ['minimum quantity', 'minimum', 'minimumi', 'stoku minimal', 'sasia minimale'],
        ],
    ];

    private const REQUIRED = ['counterparties' => ['name'], 'inventory-items' => ['sku', 'name']];

    private const TYPE_WORDS = [
        'customer' => 'customer', 'client' => 'customer', 'klient' => 'customer', 'bleres' => 'customer',
        'supplier' => 'supplier', 'vendor' => 'supplier', 'furnitor' => 'supplier', 'shites' => 'supplier',
        'both' => 'both', 'te dy' => 'both', 'te dyja' => 'both',
    ];

    public function __construct(
        private TabularFile $tabularFile,
        private CounterpartyService $counterpartyService,
        private InventoryService $inventoryService,
    ) {}

    /**
     * @param  array{default_type?: string|null}  $options
     * @return array{created: int, skipped: list<array{line: int, reason: string}>}
     */
    public function import(Business $business, User $user, string $type, UploadedFile $file, array $options = []): array
    {
        try {
            $rows = $this->tabularFile->rows($file, self::MAX_ROWS + 1);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => [$exception->getMessage()]]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => ['The file is empty.']]);
        }

        $headerLine = array_key_first($rows);
        $columns = $this->mapColumns($type, $rows[$headerLine]);
        unset($rows[$headerLine]);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => ['The file has a header row but no data rows.']]);
        }

        $records = [];
        $errors = [];

        foreach ($rows as $line => $values) {
            $record = [];

            foreach ($columns as $field => $index) {
                $record[$field] = isset($values[$index]) && $values[$index] !== '' ? $values[$index] : null;
            }

            $record = $type === 'counterparties' ? $this->prepareCounterparty($record, $options['default_type'] ?? 'customer') : $this->prepareItem($record);
            $validator = Validator::make($record, $this->rules($type, $record));

            if ($validator->fails()) {
                $errors[] = ['line' => $line, 'message' => implode(' ', $validator->errors()->all())];

                continue;
            }

            $records[$line] = $record;
        }

        [$records, $skipped, $duplicateErrors] = $this->withoutDuplicates($business, $type, $records);
        $errors = [...$errors, ...$duplicateErrors, ...($type === 'inventory-items' ? $this->barcodeErrors($business, $records) : [])];

        if ($errors !== []) {
            usort($errors, fn (array $left, array $right): int => $left['line'] <=> $right['line']);

            throw ValidationException::withMessages(['rows' => array_map(fn (array $error): string => "Line {$error['line']}: {$error['message']}", array_slice($errors, 0, 50))]);
        }

        DB::transaction(function () use ($business, $user, $type, $records, $skipped, $file): void {
            foreach ($records as $record) {
                if ($type === 'counterparties') {
                    $this->counterpartyService->createForBusiness($business, (int) $user->getKey(), $record);
                } else {
                    $this->inventoryService->createItem($business, $user, $record);
                }
            }

            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'import.completed',
                'subject_type' => Business::class,
                'subject_id' => $business->getKey(),
                'new_values' => ['type' => $type, 'file' => $file->getClientOriginalName(), 'created' => count($records), 'skipped' => count($skipped)],
            ]);
        });

        return ['created' => count($records), 'skipped' => $skipped];
    }

    /** @return array{filename: string, contents: string} */
    public function template(string $type): array
    {
        $rows = $type === 'counterparties'
            ? [['Emri', 'Lloji', 'NUI', 'Email', 'Telefoni', 'Adresa', 'Komuna', 'Shteti'], ['Shembull SH.P.K.', 'klient', '811234567', 'info@shembull.com', '044123456', 'Rr. Nëna Terezë 1', 'Prishtinë', 'XK']]
            : [['Kodi', 'Emri', 'Barkodi', 'Njësia', 'Çmimi i blerjes', 'Çmimi i shitjes', 'Minimumi'], ['KAFE-1', 'Kafe 1 kg', '3850000000017', 'copë', '8.50', '12.00', '10']];
        // The BOM makes Excel open the file as UTF-8, so ë and ç survive.
        $contents = "\xEF\xBB\xBF".implode("\r\n", array_map(fn (array $row): string => implode(';', array_map(fn (string $cell): string => '"'.str_replace('"', '""', $cell).'"', $row)), $rows))."\r\n";

        return ['filename' => ($type === 'counterparties' ? 'klientet-furnitoret' : 'artikujt').'-shabllon.csv', 'contents' => $contents];
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function mapColumns(string $type, array $header): array
    {
        $columns = [];

        foreach ($header as $index => $label) {
            $normalized = $this->normalize($label);

            foreach (self::COLUMNS[$type] as $field => $aliases) {
                if (! isset($columns[$field]) && in_array($normalized, $aliases, true)) {
                    $columns[$field] = $index;
                }
            }
        }

        $missing = array_diff(self::REQUIRED[$type], array_keys($columns));

        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => ['The first row must name the columns. Missing: '.implode(', ', array_map(fn (string $field): string => self::COLUMNS[$type][$field][1] ?? $field, $missing)).'. Download the template to see the expected columns.']]);
        }

        return $columns;
    }

    /**
     * @param  array<string, string|null>  $record
     * @return array<string, mixed>
     */
    private function prepareCounterparty(array $record, string $defaultType): array
    {
        $typeWord = $this->normalize((string) ($record['type'] ?? ''));
        $record['type'] = $typeWord === '' ? $defaultType : (self::TYPE_WORDS[$typeWord] ?? $record['type']);
        $record['country_code'] = strtoupper((string) ($record['country_code'] ?? '')) ?: 'XK';

        if (isset($record['nui'])) {
            $record['nui'] = (string) preg_replace('/\s+/', '', $record['nui']);
        }

        return $record;
    }

    /**
     * @param  array<string, string|null>  $record
     * @return array<string, mixed>
     */
    private function prepareItem(array $record): array
    {
        foreach (['purchase_price', 'sale_price', 'minimum_quantity'] as $field) {
            // Accept "8,50" as well as "8.50".
            $record[$field] = isset($record[$field]) ? str_replace([' ', ','], ['', '.'], $record[$field]) : '0';
        }

        $record['unit'] ??= 'copë';

        return $record;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, list<mixed>>
     */
    private function rules(string $type, array $record): array
    {
        return $type === 'counterparties'
            ? [
                'type' => ['required', 'string', 'in:customer,supplier,both'],
                'name' => ['required', 'string', 'max:255'],
                'nui' => ['nullable', 'string', 'max:32', new CounterpartyIdentifier((string) $record['country_code'])],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:40'],
                'address' => ['nullable', 'string', 'max:255'],
                'municipality' => ['nullable', 'string', 'max:100'],
                'country_code' => ['required', 'string', 'size:2'],
            ]
            : [
                'sku' => ['required', 'string', 'max:80'],
                'barcode' => ['nullable', 'string', 'max:80'],
                'name' => ['required', 'string', 'max:180'],
                'unit' => ['required', 'string', 'max:24'],
                'purchase_price' => ['required', 'numeric', 'gte:0', 'decimal:0,4', 'max:999999999999.9999'],
                'sale_price' => ['required', 'numeric', 'gte:0', 'decimal:0,4', 'max:999999999999.9999'],
                'minimum_quantity' => ['required', 'numeric', 'gte:0', 'decimal:0,4', 'max:999999999999.9999'],
            ];
    }

    /**
     * Rows already in the business are skipped; the same key twice in one file is an error, because we cannot tell which row is right.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array{array<int, array<string, mixed>>, list<array{line: int, reason: string}>, list<array{line: int, message: string}>}
     */
    private function withoutDuplicates(Business $business, string $type, array $records): array
    {
        $keyField = $type === 'counterparties' ? 'nui' : 'sku';
        $label = $type === 'counterparties' ? 'NUI' : 'code';
        $keys = array_values(array_filter(array_map(fn (array $record): ?string => isset($record[$keyField]) ? mb_strtolower((string) $record[$keyField]) : null, $records)));
        $existing = $keys === [] ? [] : ($type === 'counterparties' ? $business->counterparties() : $business->inventoryItems())
            ->whereIn($keyField, array_unique(array_filter(array_map(fn (array $record): ?string => $record[$keyField] ?? null, $records))))
            ->pluck($keyField)
            ->map(fn ($value): string => mb_strtolower((string) $value))
            ->flip()
            ->all();
        $seen = [];
        $skipped = [];
        $errors = [];

        foreach ($records as $line => $record) {
            $key = isset($record[$keyField]) ? mb_strtolower((string) $record[$keyField]) : null;

            if ($key === null) {
                continue;
            }

            if (isset($seen[$key])) {
                $errors[] = ['line' => $line, 'message' => "The {$label} {$record[$keyField]} is also on line {$seen[$key]}."];
            } elseif (isset($existing[$key])) {
                $skipped[] = ['line' => $line, 'reason' => "{$record['name']}: the {$label} {$record[$keyField]} is already in this business."];
                unset($records[$line]);
            }

            $seen[$key] ??= $line;
        }

        return [$records, $skipped, $errors];
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return list<array{line: int, message: string}>
     */
    private function barcodeErrors(Business $business, array $records): array
    {
        $barcodes = array_filter(array_map(fn (array $record): ?string => $record['barcode'] ?? null, $records));
        $taken = $barcodes === [] ? [] : $business->inventoryItems()->whereIn('barcode', array_unique($barcodes))->pluck('barcode')->flip()->all();
        $seen = [];
        $errors = [];

        foreach ($barcodes as $line => $barcode) {
            if (isset($taken[$barcode])) {
                $errors[] = ['line' => $line, 'message' => "The barcode {$barcode} already belongs to another item."];
            } elseif (isset($seen[$barcode])) {
                $errors[] = ['line' => $line, 'message' => "The barcode {$barcode} is also on line {$seen[$barcode]}."];
            }

            $seen[$barcode] ??= $line;
        }

        return $errors;
    }

    private function normalize(string $text): string
    {
        $text = strtr(mb_strtolower(trim($text)), ['ë' => 'e', 'ç' => 'c', 'é' => 'e']);
        $text = (string) preg_replace('/\([^)]*\)/u', ' ', $text);
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        return trim($text);
    }
}
