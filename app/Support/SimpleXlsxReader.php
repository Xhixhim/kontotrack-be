<?php

namespace App\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the cell values of the first worksheet in an .xlsx file, with no dependencies.
 *
 * Text cells come back as strings and number cells as floats (dates stay Excel serial numbers;
 * use excelDate() to convert them). Formula cells return their cached value.
 */
class SimpleXlsxReader
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const DOC_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PACKAGE_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** Largest uncompressed XML part we are willing to load. */
    private const MAX_PART_BYTES = 40 * 1024 * 1024;

    private bool $date1904 = false;

    /**
     * @return array<int, list<string|float|null>> rows keyed by their 1-based spreadsheet row number
     *
     * @throws InvalidArgumentException when the file is not a readable workbook
     */
    public function firstSheetRows(string $path, int $maxRows): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('The file is not a valid .xlsx workbook. Old .xls files must be saved as .xlsx or .csv first.');
        }

        try {
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $workbookParts = $workbook->children(self::MAIN_NS);
            $date1904 = isset($workbookParts->workbookPr) ? strtolower((string) $workbookParts->workbookPr->attributes()['date1904']) : '';
            $this->date1904 = in_array($date1904, ['1', 'true'], true);

            $sharedStrings = $zip->locateName('xl/sharedStrings.xml') === false ? [] : $this->sharedStrings($this->xml($zip, 'xl/sharedStrings.xml'));

            return $this->rows($this->xml($zip, $this->firstSheetPath($zip, $workbook)), $sharedStrings, $maxRows);
        } finally {
            $zip->close();
        }
    }

    /** Converts an Excel date serial number to Y-m-d, or null when it is not a plausible date. */
    public function excelDate(float $serial): ?string
    {
        $days = (int) floor($serial);
        if ($this->date1904) {
            $base = new DateTimeImmutable('1904-01-01');
        } else {
            // Serial 60 is Excel's fictional 29 February 1900; anything that early is not a statement date.
            if ($days < 61) {
                return null;
            }
            $base = new DateTimeImmutable('1899-12-30');
        }

        $date = $base->modify("+{$days} days");

        return $date->format('Y') >= '1990' && $date->format('Y') <= '2200' ? $date->format('Y-m-d') : null;
    }

    private function firstSheetPath(ZipArchive $zip, SimpleXMLElement $workbook): string
    {
        $parts = $workbook->children(self::MAIN_NS);
        $sheets = isset($parts->sheets) ? $parts->sheets->children(self::MAIN_NS) : null;
        $relationshipId = $sheets !== null && isset($sheets->sheet) ? (string) $sheets->sheet[0]->attributes(self::DOC_REL_NS)['id'] : '';

        if ($relationshipId !== '' && $zip->locateName('xl/_rels/workbook.xml.rels') !== false) {
            foreach ($this->xml($zip, 'xl/_rels/workbook.xml.rels')->children(self::PACKAGE_REL_NS)->Relationship as $relationship) {
                if ((string) $relationship->attributes()['Id'] === $relationshipId) {
                    $target = ltrim((string) $relationship->attributes()['Target'], '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
        }

        if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
            return 'xl/worksheets/sheet1.xml';
        }

        throw new InvalidArgumentException('The workbook has no worksheet.');
    }

    /** @return list<string> */
    private function sharedStrings(SimpleXMLElement $document): array
    {
        $strings = [];
        foreach ($document->children(self::MAIN_NS)->si as $item) {
            $strings[] = $this->text($item);
        }

        return $strings;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return array<int, list<string|float|null>>
     */
    private function rows(SimpleXMLElement $sheet, array $sharedStrings, int $maxRows): array
    {
        $rows = [];
        $previousRow = 0;

        $parts = $sheet->children(self::MAIN_NS);
        if (! isset($parts->sheetData)) {
            return [];
        }

        foreach ($parts->sheetData->children(self::MAIN_NS)->row as $row) {
            $number = (int) $row->attributes()['r'] ?: $previousRow + 1;
            $previousRow = $number;
            $cells = [];
            $previousColumn = -1;

            foreach ($row->children(self::MAIN_NS)->c as $cell) {
                $reference = (string) $cell->attributes()['r'];
                $column = $reference === '' ? $previousColumn + 1 : $this->columnIndex($reference);
                $previousColumn = $column;
                if ($column > 200) {
                    continue;
                }
                $cells[$column] = $this->cellValue($cell, $sharedStrings);
            }

            if (array_filter($cells, fn ($value): bool => $value !== null && $value !== '') === []) {
                continue;
            }
            if (count($rows) >= $maxRows) {
                throw new InvalidArgumentException("The worksheet has more than {$maxRows} rows.");
            }

            $values = array_fill(0, max(array_keys($cells)) + 1, null);
            foreach ($cells as $column => $value) {
                $values[$column] = $value;
            }
            $rows[$number] = $values;
        }

        return $rows;
    }

    /** @param list<string> $sharedStrings */
    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string|float|null
    {
        $type = (string) $cell->attributes()['t'];
        $children = $cell->children(self::MAIN_NS);

        if ($type === 'inlineStr') {
            return isset($children->is) ? $this->text($children->is) : null;
        }

        $raw = isset($children->v) ? (string) $children->v : null;
        if ($raw === null || $raw === '') {
            return null;
        }

        return match ($type) {
            's' => $sharedStrings[(int) $raw] ?? null,
            'str', 'e' => $type === 'e' ? null : $raw,
            'b' => $raw === '1' ? 'TRUE' : 'FALSE',
            default => is_numeric($raw) ? (float) $raw : $raw,
        };
    }

    /** Text of a shared or inline string, including rich-text runs but not phonetic hints. */
    private function text(SimpleXMLElement $item): string
    {
        $children = $item->children(self::MAIN_NS);
        if (isset($children->t)) {
            return (string) $children->t;
        }

        $text = '';
        foreach ($children->r as $run) {
            $text .= (string) $run->children(self::MAIN_NS)->t;
        }

        return $text;
    }

    private function columnIndex(string $reference): int
    {
        $letters = strtoupper((string) preg_replace('/\d+$/', '', $reference));
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function xml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            throw new InvalidArgumentException('The file is not a valid .xlsx workbook. Old .xls files must be saved as .xlsx or .csv first.');
        }
        if ($stat['size'] > self::MAX_PART_BYTES) {
            throw new InvalidArgumentException('The workbook is too large to read.');
        }

        $contents = $zip->getFromName($name);
        $previous = libxml_use_internal_errors(true);

        try {
            $document = $contents === false ? false : simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false) {
            throw new InvalidArgumentException('The workbook could not be read.');
        }

        return $document;
    }
}
