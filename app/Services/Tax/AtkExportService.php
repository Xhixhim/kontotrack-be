<?php

namespace App\Services\Tax;

use App\Models\Business;
use App\Models\PayrollRun;
use App\Support\SimpleXlsxWriter;
use Illuminate\Validation\ValidationException;

/**
 * Lays out ATK EDI uploads exactly like the official ATK templates:
 * "Libri i Shitjes - FINAL MOSTRA", "Libri i Blerjes - FINAL MOSTRA" (rows 1–3 are headers, data from row 4)
 * and the EDI wage and pension list (WM) from "Udhëzues për Deklarim të Pagave dhe Pensioneve përmes EDI-t".
 */
class AtkExportService
{
    private const SALES_GROUP_HEADERS = [
        'A1' => 'Nr.', 'B1' => 'Fatura', 'D1' => 'Blerësi', 'G1' => 'Shitjet e liruara nga TVSH',
        'M1' => 'Shitjet e tatueshme me normën 18%', 'S1' => 'Shitjet e tatueshme me normën 8%', 'X1' => 'Totali i TVSH-së së llogaritur me   18% dhe 8%',
    ];

    private const SALES_HEADERS = [
        'Data', 'Numri i faturës', 'Emri i blerësit', 'Numri Fiskal i blerësit', 'Numri i TVSH-së së blerësit',
        'Shitjet e liruara pa të drejtë kreditimi', 'Shitjet e shërbimeve jashtë vendit', 'Shitjet brenda vendit me ngarkesë të kundërt  të TVSH-së',
        'Shitjet tjera të liruara me të drejtë kreditimi', 'Totali i shitjeve të liruara me të drejtë kreditimi', 'Eksportet', 'Shitjet e tatueshme ',
        'Nota debitore e lëshuar, nota kreditore e pranuar ', 'Fatura e borxhit të keq e pranuar', 'Rregullimet për të rritur TVSH-në ',
        'Blerjet që i nënshtrohen ngarkesës së kundërt', 'Totali i TVSH-së së llogaritur me normën 18%', 'Shitjet e tatueshme',
        'Nota debitore e lëshuar, nota kreditore e pranuar ', 'Fatura e borxhit të keq e pranuar', 'Rregullimet për të rritur TVSH-në ',
        'Totali i TVSH-së së llogaritur me normën 8%',
    ];

    private const SALES_FORMULAS = ['10' => 'H{r}+I{r}+J{r}', 'K1' => '(M{r}+N{r}+O{r}+P{r}+Q{r})*18%', 'K2' => '(S{r}+T{r}+U{r}+V{r})*8%', '30' => 'R{r}+W{r}'];

    private const PURCHASE_GROUP_HEADERS = [
        'A1' => 'Nr.', 'B1' => 'Fatura', 'D1' => 'Shitësi', 'G1' => 'Blerjet dhe Importet e liruara dhe me TVSH jo të zbritshme',
        'K1' => 'Blerjet dhe Importet e tatushme me 18%, si dhe rregullimet e zbritjeve', 'T1' => 'Blerjet dhe Importet e tatushme me 8%, si dhe rregullimet e zbritjeve',
        'AC1' => 'Totali i TVSH-së së zbritshme me 18% dhe 8%',
    ];

    private const PURCHASE_HEADERS = [
        'Data', 'Numri i faturës', 'Emri i shitësit', 'Numri Fiskal i shitësit', 'Numri i TVSH-së së shitësit',
        'Blerjet dhe importet pa TVSH', 'Blerjet dhe importet investive pa TVSH', 'Blerjet dhe importet me TVSH jo të zbritshme',
        'Blerjet dhe importet investive me TVSH jo të zbritshme', 'Importet', 'Importet investive  ', 'Blerjet vendore', 'Blerjet investive vendore  ',
        'Nota debitore e pranuar, nota kreditore e lëshuar ', 'Fatura e borxhit të keq e lëshuar ', 'Rregullimet për të ulur TVSH-në për pagesë',
        'E drejta e kreditimit të TVSH-së në lidhje me Ngarkesën e Kundërt', 'Totali i TVSH-së së  zbritshme me 18%', 'Importet ', 'Importet investive ',
        'Blerjet vendore', 'Blerjet investive vendore', 'Blerjet nga fermerët (aplikimi i normës së sheshtë)', 'Nota debitore e pranuar, nota kreditore e lëshuar ',
        'Fatura e borxhit të keq e lëshuar', 'Rregullimet për të ulur TVSH-në për pagesë', 'Totali i TVSH-së së  zbritshme me 8%',
    ];

    private const PURCHASE_FORMULAS = ['K1' => '(K{r}+L{r}+M{r}+N{r}+O{r}+P{r}+Q{r}+R{r})*18%', 'K2' => '(T{r}+U{r}+V{r}+W{r}+X{r}+Y{r}+Z{r}+AA{r})*8%', '67' => 'S{r}+AB{r}'];

    private const WM_HEADERS = [
        'Emri', 'Mbiemri', 'Numri individual i punëtorit', 'Bruto Paga per muaj', 'Kontributi Pensional i te punësuarit',
        'Kontributi Pensional i punëdhënësit', 'Kontributi suplementar i të punësuarit', 'Kontributi suplementar i punëdhënësit', 'Punë Primare', 'Përfshihen Kontributet',
    ];

    public function __construct(private SimpleXlsxWriter $writer) {}

    /** @param array<string, mixed> $book */
    public function salesBook(array $book): string
    {
        return $this->bookWorkbook('Libri i Shitjes', $book, self::SALES_GROUP_HEADERS, self::SALES_HEADERS, AtkVatBoxes::SALES_COLUMNS, self::SALES_FORMULAS,
            ['A3:F3', 'X1:X2', 'B1:C1', 'D1:F1', 'M1:R1', 'S1:W1', 'G1:L1', 'A1:A2'],
            ['10' => '[10] = [10a]+[10b]+[10c]']);
    }

    /** @param array<string, mixed> $book */
    public function purchaseBook(array $book): string
    {
        return $this->bookWorkbook('Libri i Blerjes', $book, self::PURCHASE_GROUP_HEADERS, self::PURCHASE_HEADERS, AtkVatBoxes::PURCHASE_COLUMNS, self::PURCHASE_FORMULAS,
            ['G1:J1', 'K1:S1', 'T1:AB1', 'AC1:AC2', 'A3:F3', 'A1:A2', 'B1:C1', 'D1:F1'],
            ['67' => '[67] ']);
    }

    /**
     * The wage and pension list uploaded with the monthly WM declaration in EDI. Only approved payroll runs can be declared.
     *
     * @return array{contents: string, problems: list<string>}
     */
    public function payrollList(Business $business, PayrollRun $run): array
    {
        if ($run->status !== 'approved') {
            throw ValidationException::withMessages(['payroll_run' => ['Approve the payroll run before exporting it for the WM declaration.']]);
        }

        $rows = [self::WM_HEADERS, ['a', 'b', 'c', 'd', 'e=(d*5%)', 'f=(d*5%)', 'g', 'h', 'i', 'j']];
        $problems = [];

        foreach ($run->lines()->with('employee')->orderBy('employee_name')->get() as $line) {
            $employee = $line->employee;
            $personalNumber = preg_replace('/\s+/', '', (string) ($employee?->personal_number ?? ''));

            if (preg_match('/^\d{10}$/', $personalNumber) !== 1) {
                $problems[] = "{$line->employee_name} needs a 10-digit personal number. EDI identifies every employee by it.";
            }

            $rows[] = [
                (string) ($employee?->first_name ?? $line->employee_name),
                (string) ($employee?->last_name ?? ''),
                $personalNumber,
                (float) $line->gross_salary,
                (float) $line->employee_contribution,
                (float) $line->employer_contribution,
                0,
                0,
                $line->is_primary_employment ? 'PO' : 'JO',
                'PO',
            ];
        }

        return ['contents' => $this->writer->build('Lista e pagave', $rows, [], [1, 2]), 'problems' => $problems];
    }

    /**
     * @param  array<string, mixed>  $book
     * @param  array<string, string>  $groupHeaders
     * @param  list<string>  $headers
     * @param  list<string>  $columns
     * @param  array<string, string>  $formulas
     * @param  list<string>  $merges
     * @param  array<string, string>  $boxLabels
     */
    private function bookWorkbook(string $sheetName, array $book, array $groupHeaders, array $headers, array $columns, array $formulas, array $merges, array $boxLabels): string
    {
        $width = 6 + count($columns);
        $groupRow = array_fill(0, $width, null);

        foreach ($groupHeaders as $reference => $label) {
            $groupRow[$this->columnIndex(preg_replace('/\d+$/', '', $reference))] = $label;
        }

        $boxRow = array_fill(0, $width, null);
        $boxRow[0] = 'Numri i kutisë në Deklaratën e TVSH-së';

        foreach ($columns as $offset => $box) {
            $boxRow[6 + $offset] = $boxLabels[$box] ?? "[{$box}]";
        }

        $rows = [$groupRow, [null, ...$headers], $boxRow];

        foreach ($book['rows'] as $index => $row) {
            $rowNumber = $index + 4;
            $cells = [$index + 1, $row['date'], $row['invoice_number'], $row['name'], $row['identifier'], $row['vat_number'] === '' ? null : $row['vat_number']];

            foreach ($columns as $offset => $box) {
                $value = (float) $row['boxes'][$box];
                $cells[6 + $offset] = isset($formulas[$box])
                    ? ['formula' => str_replace('{r}', (string) $rowNumber, $formulas[$box]), 'value' => $value]
                    : ($value === 0.0 ? null : $value);
            }

            $rows[] = $cells;
        }

        return $this->writer->build($sheetName, $rows, $merges, [1, 2, 3]);
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
