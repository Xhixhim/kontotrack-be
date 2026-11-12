<?php

namespace App\Services\Reports;

/**
 * Turns a generated financial report into titled table sections, so the Excel and PDF exports share one layout.
 * Amounts stay numeric strings; each writer formats them.
 *
 * A section is: heading, columns (label + whether it holds an amount), rows, and optional total rows.
 */
class ReportTableBuilder
{
    public const TITLES = [
        'trial_balance' => 'Bilanci provues · Trial balance',
        'profit_loss' => 'Pasqyra e të ardhurave dhe shpenzimeve · Profit and loss',
        'balance_sheet' => 'Bilanci i gjendjes · Balance sheet',
        'receivable_aging' => 'Mosha e borxheve të klientëve · Receivables aging',
        'payable_aging' => 'Mosha e detyrimeve ndaj furnitorëve · Payables aging',
        'vat_summary' => 'Përmbledhja e TVSH-së · VAT summary',
        'general_ledger' => 'Kartelat e llogarive · Account cards',
        'cash_book' => 'Libri i arkës · Cash book',
        'bank_reconciliation' => 'Rakordimi bankar dhe diferencat · Bank reconciliation and differences',
        'turnover' => 'Qarkullimi · Turnover',
        'stock' => 'Gjendja e stokut · Stock on hand',
        'payroll' => 'Pagat · Payroll',
        'fixed_assets' => 'Regjistri i aseteve fikse · Fixed asset register',
    ];

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{heading: string|null, columns: list<array{label: string, amount: bool}>, rows: list<list<string|int|null>>, totals: list<list<string|int|null>>}>
     */
    public function sections(string $type, array $report): array
    {
        return match ($type) {
            'trial_balance' => [$this->trialBalance($report)],
            'profit_loss' => $this->profitLoss($report),
            'balance_sheet' => $this->balanceSheet($report),
            'receivable_aging', 'payable_aging' => [$this->aging($type, $report)],
            'vat_summary' => $this->vatSummary($report),
            'general_ledger' => $this->generalLedger($report),
            'cash_book' => [$this->cashBook($report)],
            'bank_reconciliation' => $this->bankReconciliation($report),
            'turnover' => [$this->turnover($report)],
            'stock' => [$this->stock($report)],
            'payroll' => [$this->payroll($report)],
            'fixed_assets' => [$this->fixedAssets($report)],
        };
    }

    /** @param array<string, mixed> $report */
    private function trialBalance(array $report): array
    {
        return $this->section(null, ['Kodi · Code', 'Llogaria · Account', '#Debi · Debit', '#Kredi · Credit'],
            array_map(fn (array $row): array => [$row['code'], $row['name'], $row['debit'], $row['credit']], $report['rows']),
            [['', 'Totali · Total', $report['totals']['debit'], $report['totals']['credit']]]);
    }

    /** @param array<string, mixed> $report */
    private function profitLoss(array $report): array
    {
        $amount = fn (array $row): string => $this->subtract($row['account_type'] === 'revenue' ? $row['credit'] : $row['debit'], $row['account_type'] === 'revenue' ? $row['debit'] : $row['credit']);
        $sections = [];
        $totals = [];

        foreach (['revenue' => 'Të hyrat · Revenue', 'expense' => 'Shpenzimet · Expenses'] as $accountType => $heading) {
            $rows = array_values(array_filter($report['rows'], fn (array $row): bool => $row['account_type'] === $accountType));
            $totals[$accountType] = array_reduce($rows, fn (string $sum, array $row): string => $this->add($sum, $amount($row)), '0.00');
            $sections[] = $this->section($heading, ['Kodi · Code', 'Llogaria · Account', '#Shuma · Amount'],
                array_map(fn (array $row): array => [$row['code'], $row['name'], $amount($row)], $rows),
                [['', 'Totali · Total', $totals[$accountType]]]);
        }

        $sections[] = $this->section(null, ['', '', '#Shuma · Amount'], [], [['', 'Fitimi ose humbja · Profit or loss', $report['totals']['result']]]);

        return $sections;
    }

    /** @param array<string, mixed> $report */
    private function balanceSheet(array $report): array
    {
        $sections = [];
        $grandTotals = ['asset' => '0.00', 'claims' => '0.00'];

        foreach (['asset' => 'Pasuritë · Assets', 'liability' => 'Detyrimet · Liabilities', 'equity' => 'Kapitali · Equity'] as $accountType => $heading) {
            $rows = array_values(array_filter($report['rows'], fn (array $row): bool => $row['account_type'] === $accountType));
            $amounts = array_map(fn (array $row): string => $accountType === 'asset' ? $this->subtract($row['debit'], $row['credit']) : $this->subtract($row['credit'], $row['debit']), $rows);
            $total = array_reduce($amounts, fn (string $sum, string $value): string => $this->add($sum, $value), '0.00');
            $grandTotals[$accountType === 'asset' ? 'asset' : 'claims'] = $this->add($grandTotals[$accountType === 'asset' ? 'asset' : 'claims'], $total);
            $sections[] = $this->section($heading, ['Kodi · Code', 'Llogaria · Account', '#Shuma · Amount'],
                array_map(fn (array $row, string $value): array => [$row['code'], $row['name'], $value], $rows, $amounts),
                [['', 'Totali · Total', $total]]);
        }

        $sections[] = $this->section(null, ['', '', '#Shuma · Amount'], [], [
            ['', 'Totali i pasurive · Total assets', $grandTotals['asset']],
            ['', 'Detyrimet dhe kapitali · Liabilities and equity', $grandTotals['claims']],
        ]);

        return $sections;
    }

    /** @param array<string, mixed> $report */
    private function aging(string $type, array $report): array
    {
        $party = $type === 'receivable_aging' ? 'Klienti · Customer' : 'Furnitori · Supplier';
        $buckets = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90', 'total'];

        return $this->section(null, [$party, 'Fatura · Invoices', '#Pa skaduar · Not due', '#1–30 ditë', '#31–60 ditë', '#61–90 ditë', '#Mbi 90 ditë', '#Totali · Total'],
            array_map(fn (array $row): array => [$row['counterparty_name'], (int) $row['invoice_count'], ...array_map(fn (string $bucket): string => $row[$bucket], $buckets)], $report['rows']),
            [['Totali · Total', (int) $report['totals']['invoice_count'], ...array_map(fn (string $bucket): string => $report['totals'][$bucket], $buckets)]]);
    }

    /** @param array<string, mixed> $report */
    private function vatSummary(array $report): array
    {
        $kinds = ['sale' => 'Shitje · Sales', 'purchase' => 'Blerje · Purchases'];
        $entryTypes = ['invoice' => 'Faturë · Invoice', 'credit_note' => 'Notë krediti · Credit note', 'cancellation' => 'Anulim · Cancellation'];

        return [
            $this->section('Sipas kodit të TVSH-së · By VAT code', ['Lloji · Kind', 'Kodi · Code', 'Norma · Rate', '#Baza · Net', '#TVSH · VAT', '#Totali · Gross'],
                array_map(fn (array $row): array => [$kinds[$row['kind']] ?? $row['kind'], $row['vat_label'], rtrim(rtrim($row['rate_percent'], '0'), '.').'%', $row['net_amount'], $row['tax_amount'], $row['gross_amount']], $report['summary']),
                [
                    ['Shitje · Sales', '', '', $report['totals']['sales']['net_amount'], $report['totals']['sales']['tax_amount'], $report['totals']['sales']['gross_amount']],
                    ['Blerje · Purchases', '', '', $report['totals']['purchases']['net_amount'], $report['totals']['purchases']['tax_amount'], $report['totals']['purchases']['gross_amount']],
                    ['TVSH për pagesë · VAT difference', '', '', '', $report['totals']['invoice_vat_difference'], ''],
                ]),
            $this->section('Sipas faturës · By invoice', ['Data · Date', 'Lloji · Kind', 'Numri · Number', 'Pala · Party', 'NUI', 'Kodi · Code', '#Baza · Net', '#TVSH · VAT', '#Totali · Gross'],
                array_map(fn (array $row): array => [$row['reported_on'], ($kinds[$row['kind']] ?? $row['kind']).' · '.($entryTypes[$row['entry_type']] ?? $row['entry_type']), $row['invoice_number'], $row['counterparty_name'], $row['counterparty_nui'] ?? '', $row['vat_code'], $row['net_amount'], $row['tax_amount'], $row['gross_amount']], $report['rows']),
                []),
        ];
    }

    /** @param array<string, mixed> $report */
    private function generalLedger(array $report): array
    {
        if ($report['accounts'] === []) {
            return [$this->section(null, ['Kartelat e llogarive · Account cards'], [['Nuk ka lëvizje në këto data · No posted activity in these dates']], [])];
        }

        return array_map(fn (array $card): array => $this->section("{$card['code']} · {$card['name']}",
            ['Data · Date', 'Referenca · Reference', 'Përshkrimi · Description', '#Debi · Debit', '#Kredi · Credit', '#Saldo · Balance'],
            [
                [$report['from'], '', 'Saldo fillestare · Opening balance', null, null, $card['opening_balance']],
                ...array_map(fn (array $line): array => [$line['date'], $line['reference'] ?? '', trim($line['description'].($line['counterparty_name'] ? ' · '.$line['counterparty_name'] : '')), $line['debit'], $line['credit'], $line['balance']], $card['lines']),
            ],
            [[$report['to'], '', 'Saldo përfundimtare · Closing balance', $card['debit_total'], $card['credit_total'], $card['closing_balance']]]), $report['accounts']);
    }

    /** @param array<string, mixed> $report */
    private function cashBook(array $report): array
    {
        return $this->section($report['cash_account']['name'],
            ['Data · Date', 'Referenca · Reference', 'Përshkrimi · Description', '#Hyrje · In', '#Dalje · Out', '#Saldo · Balance'],
            [
                [$report['from'], '', 'Gjendja fillestare · Opening balance', null, null, $report['opening_balance']],
                ...array_map(fn (array $line): array => [$line['date'], $line['reference'] ?? '', trim($line['description'].($line['counterparty_name'] ? ' · '.$line['counterparty_name'] : '')), $line['money_in'], $line['money_out'], $line['balance']], $report['lines']),
            ],
            [[$report['to'], '', 'Gjendja përfundimtare · Closing balance', $report['money_in'], $report['money_out'], $report['closing_balance']]]);
    }

    /** @param array<string, mixed> $report */
    private function bankReconciliation(array $report): array
    {
        $account = $report['bank_account'];
        $notOnStatementIn = $report['not_on_statement_in'];
        $notOnStatementOut = $report['not_on_statement_out'];

        return [
            $this->section(trim($account['bank_name'].' · '.($account['iban'] ?? '').' · '.($account['ledger_account'] ?? ''), ' ·'), ['', '#Shuma · Amount'], [
                ['Saldo në libra · Balance in the books', $report['ledger_balance']],
                ['Shto: arkëtime në bankë të paregjistruara · Add: bank receipts not in the books', $report['unrecorded_in']],
                ['Zbrit: pagesa në bankë të paregjistruara · Less: bank payments not in the books', $this->negate($report['unrecorded_out'])],
                ['Zbrit: arkëtime në libra që nuk janë në ekstrakt · Less: receipts in the books not on the statement', $this->negate($notOnStatementIn)],
                ['Shto: pagesa në libra që nuk janë në ekstrakt · Add: payments in the books not on the statement', $notOnStatementOut],
            ], [
                ['Saldo e pritur në ekstrakt · Expected statement balance', $report['expected_statement_balance']],
                ['Saldo sipas ekstraktit · Statement balance', $report['statement_balance'] ?? ''],
                ['Diferenca · Difference', $report['difference'] ?? ''],
            ]),
            $this->section('Transaksione bankare të paregjistruara · Bank lines not in the books', ['Data · Date', 'Referenca · Reference', 'Përshkrimi · Description', 'Kategoria · Category', '#Shuma · Amount'],
                $report['unrecorded'] === [] ? [['', '', 'Asnjë · None', '', null]] : array_map(fn (array $line): array => [$line['date'], $line['external_id'] ?? '', $line['description'], trim(($line['category_label'] ?? '').($line['booked_against'] ? ' · '.$line['booked_against'] : '')), $line['amount']], $report['unrecorded']),
                []),
            $this->section('Regjistrime në libra që nuk janë në ekstrakt · Book entries not on the statement', ['Data · Date', 'Referenca · Reference', 'Përshkrimi · Description', '#Shuma · Amount'],
                $report['not_on_statement'] === [] ? [['', '', 'Asnjë · None', null]] : array_map(fn (array $line): array => [$line['entry_date'], $line['reference'] ?? '', $line['description'], $line['amount']], $report['not_on_statement']),
                []),
        ];
    }

    /** @param array<string, mixed> $report */
    private function turnover(array $report): array
    {
        $amounts = ['sales_net', 'sales_tax', 'sales_gross', 'purchases_net', 'purchases_tax', 'purchases_gross'];

        return $this->section(implode(' · ', array_filter([$report['counterparty']['name'] ?? null, $report['business_unit']['name'] ?? null])) ?: null,
            [$report['group_by'] === 'counterparty' ? 'Klienti / Furnitori · Customer / Supplier' : 'Muaji · Month', '#Shitje pa TVSH · Sales net', '#TVSH e shitjeve · Sales VAT', '#Shitje gjithsej · Sales total', '#Blerje pa TVSH · Purchases net', '#TVSH e blerjeve · Purchase VAT', '#Blerje gjithsej · Purchases total'],
            array_map(fn (array $row): array => [$row['label'], ...array_map(fn (string $column): string => $row[$column], $amounts)], $report['rows']),
            [['Totali · Total', ...array_map(fn (string $column): string => $report['totals'][$column], $amounts)]]);
    }

    /** @param array<string, mixed> $report */
    private function stock(array $report): array
    {
        return $this->section($report['warehouse']['name'] ?? null,
            ['Kodi · Code', 'Artikulli · Item', 'Njësia · Unit', 'Fillimi · Opening', 'Hyrje · In', 'Dalje · Out', 'Gjendja · On hand', 'Minimumi · Minimum', '#Vlera · Value'],
            array_map(fn (array $row): array => [
                $row['sku'] ?? '', $row['name'], $row['unit'] ?? '', $row['opening_quantity'], $row['quantity_in'], $row['quantity_out'],
                $row['closing_quantity'].($row['below_minimum'] ? ' ▼' : ''), $row['minimum_quantity'], $row['value'],
            ], $report['rows']),
            [['', 'Vlera me çmim blerjeje · Value at purchase price', '', '', '', '', '', '', $report['totals']['value']]]);
    }

    /** @param array<string, mixed> $report */
    private function payroll(array $report): array
    {
        $amounts = ['gross_salary', 'employee_contribution', 'employer_contribution', 'income_tax', 'net_salary'];

        return $this->section(null,
            ['Punëtori · Employee', 'Muaj · Months', '#Bruto · Gross', '#Kontributi i punëtorit · Employee pension', '#Kontributi i punëdhënësit · Employer pension', '#Tatimi · Income tax', '#Neto · Net'],
            array_map(fn (array $row): array => [$row['employee_name'], (int) $row['months'], ...array_map(fn (string $column): string => $row[$column], $amounts)], $report['rows']),
            [['Totali · Total', count($report['months']), ...array_map(fn (string $column): string => $report['totals'][$column], $amounts)]]);
    }

    /** @param array<string, mixed> $report */
    private function fixedAssets(array $report): array
    {
        return $this->section($report['category'],
            ['Kodi · Code', 'Aseti · Asset', 'Kategoria · Category', 'Blerë më · Acquired', 'Statusi · Status', '#Kosto · Cost', '#Amortizimi i akumuluar · Accumulated depreciation', '#Vlera e mbetur · Book value'],
            array_map(fn (array $row): array => [
                $row['asset_code'], $row['name'], $row['category'] ?? '', $row['acquired_on'],
                $row['disposed_on'] === null ? 'Në përdorim · In use' : "Çregjistruar · Disposed {$row['disposed_on']}",
                $row['cost'], $row['accumulated_depreciation'], $row['book_value'],
            ], $report['rows']),
            [['', 'Totali i aseteve në përdorim · Total of assets in use', '', '', '', $report['totals']['cost'], $report['totals']['accumulated_depreciation'], $report['totals']['book_value']]]);
    }

    private function negate(string $amount): string
    {
        return $amount === '0.00' ? $amount : $this->subtract('0.00', $amount);
    }

    /**
     * Column labels starting with "#" hold amounts.
     *
     * @param  list<string>  $labels
     * @param  list<list<string|int|null>>  $rows
     * @param  list<list<string|int|null>>  $totals
     * @return array{heading: string|null, columns: list<array{label: string, amount: bool}>, rows: list<list<string|int|null>>, totals: list<list<string|int|null>>}
     */
    private function section(?string $heading, array $labels, array $rows, array $totals): array
    {
        return [
            'heading' => $heading,
            'columns' => array_map(fn (string $label): array => ['label' => ltrim($label, '#'), 'amount' => str_starts_with($label, '#')], $labels),
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    private function add(string $left, string $right): string
    {
        return $this->fromCents($this->toCents($left) + $this->toCents($right));
    }

    private function subtract(string $left, string $right): string
    {
        return $this->fromCents($this->toCents($left) - $this->toCents($right));
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $amount = abs($amount);

        return $sign.intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
