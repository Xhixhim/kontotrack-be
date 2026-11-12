<?php

namespace App\Services\Reports;

use App\Models\Business;
use App\Models\Invoice;
use App\Support\DocumentFormat;
use App\Support\SimpleXlsxWriter;
use Barryvdh\DomPDF\Facade\Pdf;

class DocumentExportService
{
    public function __construct(private ReportTableBuilder $tableBuilder, private SimpleXlsxWriter $xlsxWriter) {}

    /** @return array{contents: string, filename: string} */
    public function invoicePdf(Business $business, Invoice $invoice): array
    {
        $invoice->loadMissing(['counterparty', 'lines', 'creditedInvoice']);
        $groups = [];

        foreach ($invoice->lines as $line) {
            $label = $line->tax_snapshot['label'] ?? $line->vat_code;
            $rate = (string) ($line->tax_snapshot['rate_percent'] ?? '0');
            $key = "{$label}|{$rate}";
            $groups[$key] ??= ['label' => $label, 'rate' => $rate, 'net' => 0.0, 'tax' => 0.0];
            $groups[$key]['net'] += (float) $line->net_amount;
            $groups[$key]['tax'] += (float) $line->tax_amount;
        }

        $pdf = Pdf::loadView('pdf.invoice', [
            'business' => $business,
            'invoice' => $invoice,
            'vatBreakdown' => array_values($groups),
            'bankAccounts' => $business->bankAccounts()->where('is_active', true)->orderBy('bank_name')->get(),
        ])->setPaper('a4');
        $prefix = $invoice->document_type === 'credit_note' ? 'Note-krediti' : ($invoice->kind === 'sale' ? 'Fature' : 'Fature-blerje');

        return ['contents' => $pdf->output(), 'filename' => $prefix.'-'.$this->safeFilename($invoice->invoice_number).'.pdf'];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{contents: string, filename: string, mime: string}
     */
    public function report(Business $business, string $type, array $report, string $format): array
    {
        $title = ReportTableBuilder::TITLES[$type];
        $sections = $this->tableBuilder->sections($type, $report);
        $period = isset($report['as_of'])
            ? 'Më · As of '.DocumentFormat::date($report['as_of'])
            : DocumentFormat::date($report['from']).' – '.DocumentFormat::date($report['to']);
        $filename = str_replace('_', '-', $type).'-'.$this->safeFilename($business->nui).'-'.($report['as_of'] ?? $report['from'].'_'.$report['to']);

        if ($format === 'pdf') {
            $contents = Pdf::loadView('pdf.report', [
                'business' => $business,
                'title' => $title,
                'period' => $period,
                'sections' => $sections,
                'generatedAt' => now(config('kontotrack.display_timezone'))->format('d.m.Y H:i'),
            ])->setPaper('a4', in_array($type, ['vat_summary', 'receivable_aging', 'payable_aging', 'turnover', 'stock', 'payroll', 'fixed_assets'], true) ? 'landscape' : 'portrait')->output();

            return ['contents' => $contents, 'filename' => "{$filename}.pdf", 'mime' => 'application/pdf'];
        }

        $rows = [[$title], ["{$business->legal_name} · NUI {$business->nui}"], ["{$period} · {$business->currency}"]];
        $headerRows = [1];

        foreach ($sections as $section) {
            $rows[] = [];

            if ($section['heading'] !== null) {
                $rows[] = [$section['heading']];
                $headerRows[] = count($rows);
            }

            if (collect($section['columns'])->contains(fn (array $column): bool => $column['label'] !== '')) {
                $rows[] = array_column($section['columns'], 'label');
                $headerRows[] = count($rows);
            }

            foreach ([...$section['rows'], ...$section['totals']] as $row) {
                $rows[] = array_map(fn ($value, int $index) => $this->xlsxCell($value, $section['columns'][$index]['amount'] ?? false), $row, array_keys($row));
            }

            if ($section['totals'] !== []) {
                array_push($headerRows, ...range(count($rows) - count($section['totals']) + 1, count($rows)));
            }
        }

        return [
            'contents' => $this->xlsxWriter->build(mb_substr(explode(' · ', $title)[1] ?? $title, 0, 31), $rows, [], $headerRows),
            'filename' => "{$filename}.xlsx",
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    private function xlsxCell(string|int|null $value, bool $isAmount): string|int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($isAmount) {
            return (float) $value;
        }

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? DocumentFormat::date($value) : $value;
    }

    private function safeFilename(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value), '-') ?: 'document';
    }
}
