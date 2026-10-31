<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Writes a single-sheet .xlsx workbook. A cell is a string, an int or float, null, or
 * ['formula' => 'H4+I4', 'value' => 12.5] for a formula with its computed value.
 */
class SimpleXlsxWriter
{
    /**
     * @param  list<list<string|int|float|array{formula: string, value: int|float}|null>>  $rows
     * @param  list<string>  $merges  cell ranges such as "A1:A2"
     * @param  list<int>  $headerRows  1-based rows written in bold
     */
    public function build(string $sheetName, array $rows, array $merges = [], array $headerRows = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the spreadsheet file.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->escape(mb_substr($sheetName, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="0.00"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment wrapText="1" vertical="center"/></xf>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            .'</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($rows, $merges, $headerRows));
        $zip->close();

        $contents = file_get_contents($path);
        @unlink($path);

        if ($contents === false) {
            throw new RuntimeException('Could not read the spreadsheet file.');
        }

        return $contents;
    }

    /**
     * @param  list<list<string|int|float|array{formula: string, value: int|float}|null>>  $rows
     * @param  list<string>  $merges
     * @param  list<int>  $headerRows
     */
    private function sheetXml(array $rows, array $merges, array $headerRows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $isHeader = in_array($rowNumber, $headerRows, true);
            $xml .= '<row r="'.$rowNumber.'">';

            foreach ($row as $columnIndex => $cell) {
                if ($cell === null) {
                    continue;
                }

                $reference = $this->columnName($columnIndex).$rowNumber;

                if (is_array($cell)) {
                    $xml .= '<c r="'.$reference.'" s="2"><f>'.$this->escape($cell['formula']).'</f><v>'.$this->number($cell['value']).'</v></c>';
                } elseif (is_int($cell) || is_float($cell)) {
                    $xml .= '<c r="'.$reference.'" s="2"><v>'.$this->number($cell).'</v></c>';
                } else {
                    $xml .= '<c r="'.$reference.'" t="inlineStr"'.($isHeader ? ' s="1"' : '').'><is><t xml:space="preserve">'.$this->escape($cell).'</t></is></c>';
                }
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        if ($merges !== []) {
            $xml .= '<mergeCells count="'.count($merges).'">'
                .implode('', array_map(fn (string $range): string => '<mergeCell ref="'.$range.'"/>', $merges))
                .'</mergeCells>';
        }

        return $xml.'</worksheet>';
    }

    public function columnName(int $index): string
    {
        $name = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + (($index - 1) % 26)).$name;
        }

        return $name;
    }

    private function number(int|float $value): string
    {
        return is_int($value) ? (string) $value : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
