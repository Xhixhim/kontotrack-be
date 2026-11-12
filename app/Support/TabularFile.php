<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Reads an uploaded .xlsx or .csv file into rows of text cells, keyed by their line number in the file.
 */
class TabularFile
{
    public function __construct(private SimpleXlsxReader $xlsxReader) {}

    /**
     * @return array<int, list<string>>
     *
     * @throws InvalidArgumentException when the file cannot be read or has too many rows
     */
    public function rows(UploadedFile $file, int $maxRows): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'xls') {
            throw new InvalidArgumentException('Old .xls files cannot be read. Save the file as .xlsx or .csv and upload it again.');
        }

        if ($extension === 'xlsx') {
            return array_filter(
                array_map(fn (array $row): array => array_map($this->cellText(...), $row), $this->xlsxReader->firstSheetRows($file->getRealPath(), $maxRows)),
                fn (array $row): bool => array_filter($row, fn (string $value): bool => $value !== '') !== [],
            );
        }

        return $this->csvRows($file, $maxRows);
    }

    /** @return array<int, list<string>> */
    private function csvRows(UploadedFile $file, int $maxRows): array
    {
        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            throw new InvalidArgumentException('The uploaded file could not be read.');
        }

        try {
            $sample = '';

            for ($index = 0; $index < 10 && ($line = fgets($handle)) !== false; $index++) {
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
                $values = array_map(fn ($value): string => trim($this->toUtf8((string) $value)), $values);

                if ($line === 1 && isset($values[0])) {
                    $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', $values[0]) ?? $values[0];
                }

                if (array_filter($values, fn (string $value): bool => $value !== '') === []) {
                    continue;
                }

                if (count($rows) >= $maxRows) {
                    throw new InvalidArgumentException('The file has more than '.number_format($maxRows).' rows.');
                }

                $rows[$line] = $values;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function cellText(string|float|null $value): string
    {
        if (is_float($value)) {
            return floor($value) === $value && abs($value) < 1e15 ? (string) (int) $value : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    /** Spreadsheet programs on Windows often save CSV as Windows-1252, which has ë and ç. */
    private function toUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
