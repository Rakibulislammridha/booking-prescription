<?php

declare(strict_types=1);

namespace App\Domain\Reports\Export;

use App\Domain\Reports\Data\ReportTable;
use RuntimeException;
use ZipArchive;

/**
 * Excel (.xlsx), written by hand and streamed to disk.
 *
 * Why by hand: `composer.json` is foundation-owned (CONVENTIONS §2.1) and adding PhpSpreadsheet is both a
 * dependency request and, for this job, the wrong tool — it builds the whole workbook in memory, which is
 * exactly what BRIEF §5.L's "large result sets" forbids. An .xlsx is a zip of a handful of XML parts, so the
 * sheet is written row by row to a temp file and only then handed to `ZipArchive::addFile()`, which deflates it
 * from disk at `close()`. Peak memory is one row, whatever the export's size.
 *
 * Strings are INLINE (`t="inlineStr"`), not a shared-string table: a shared table would have to be complete
 * before the sheet can be written, i.e. everything in memory again. Inline strings cost a few bytes per cell
 * and are read by Excel, LibreOffice and Google Sheets alike.
 *
 * Bangla needs no special handling here — the file is UTF-8 XML and the reader picks the font — but the header
 * row is bold and frozen so a Bangla doctor-name column stays legible while scrolling.
 */
final class XlsxWriter
{
    /** @return array{path: string, rows: int} the temp file and how many data rows it holds */
    public function toFile(ReportTable $table, string $path): array
    {
        $sheetPath = tempnam(sys_get_temp_dir(), 'bp-sheet-');

        if ($sheetPath === false) {
            throw new RuntimeException('Could not create a temporary sheet file.');
        }

        try {
            $rows = $this->writeSheet($table, $sheetPath);
            $this->zip($path, $sheetPath, $rows + self::HEADER_ROWS);

            return ['path' => $path, 'rows' => $rows];
        } finally {
            @unlink($sheetPath);
        }
    }

    /** Title, subtitle, blank, header = the four rows before the data. */
    private const HEADER_ROWS = 4;

    private function writeSheet(ReportTable $table, string $sheetPath): int
    {
        $handle = fopen($sheetPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Could not open the temporary sheet file.');
        }

        $columns = max(1, count($table->columns));
        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        fwrite($handle, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        fwrite($handle, '<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>');
        fwrite($handle, '<cols><col min="1" max="'.$columns.'" width="22" customWidth="1"/></cols>');
        fwrite($handle, '<sheetData>');

        fwrite($handle, $this->row(1, [$table->title], style: 2));
        fwrite($handle, $this->row(2, [$table->subtitle], style: 0));
        fwrite($handle, $this->row(3, [], style: 0));
        fwrite($handle, $this->row(self::HEADER_ROWS, $table->columns, style: 1));

        $index = self::HEADER_ROWS;
        $dataRows = 0;

        foreach ($table->rows() as $row) {
            $index++;
            $dataRows++;
            fwrite($handle, $this->row($index, $row, style: 0));
        }

        if ($table->notes !== []) {
            $index++;   // one blank row between the data and the definitions

            foreach ($table->notes as $note) {
                $index++;
                fwrite($handle, $this->row($index, [$note], style: 0));
            }
        }

        fwrite($handle, '</sheetData></worksheet>');
        fclose($handle);

        return $dataRows;
    }

    /** @param array<int, string|int|float|null> $cells */
    private function row(int $index, array $cells, int $style): string
    {
        $xml = '<row r="'.$index.'">';
        $column = 0;

        foreach ($cells as $value) {
            $column++;
            $ref = self::columnName($column).$index;

            if ($value === null || $value === '') {
                continue;
            }

            $styleAttr = $style > 0 ? ' s="'.$style.'"' : '';

            if (is_int($value) || is_float($value)) {
                $xml .= '<c r="'.$ref.'"'.$styleAttr.'><v>'.$value.'</v></c>';

                continue;
            }

            $xml .= '<c r="'.$ref.'"'.$styleAttr.' t="inlineStr"><is><t xml:space="preserve">'.self::escape($value).'</t></is></c>';
        }

        return $xml.'</row>';
    }

    private function zip(string $path, string $sheetPath, int $lastRow): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create the workbook at {$path}.");
        }

        $zip->addFromString('[Content_Types].xml', self::CONTENT_TYPES);
        $zip->addFromString('_rels/.rels', self::ROOT_RELS);
        $zip->addFromString('xl/workbook.xml', self::WORKBOOK);
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::WORKBOOK_RELS);
        $zip->addFromString('xl/styles.xml', self::STYLES);
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');

        if ($zip->close() !== true) {
            throw new RuntimeException("Could not finalise the workbook at {$path}. (last row {$lastRow})");
        }
    }

    /** 1 → A, 27 → AA. */
    public static function columnName(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $index--;
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26);
        }

        return $name === '' ? 'A' : $name;
    }

    private static function escape(string $value): string
    {
        // Control characters other than tab/newline/carriage return are illegal in XML 1.0 and make Excel
        // refuse the whole file; a stray one in free-text notes must not cost the user the export.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private const CONTENT_TYPES = <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>
        XML;

    private const ROOT_RELS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>
        XML;

    private const WORKBOOK = <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>
        XML;

    private const WORKBOOK_RELS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>
        XML;

    /** Three cell formats: 0 body, 1 bold header, 2 bold title. */
    private const STYLES = <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>
        XML;
}
