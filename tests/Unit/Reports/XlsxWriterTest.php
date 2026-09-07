<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Domain\Reports\Data\ReportTable;
use App\Domain\Reports\Export\CsvWriter;
use App\Domain\Reports\Export\XlsxWriter;
use Generator;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** The hand-written workbook: a real zip, real parts, and Bangla and XML-hostile text survive both writers. */
final class XlsxWriterTest extends TestCase
{
    private function table(): ReportTable
    {
        return new ReportTable(
            title: 'রিপোর্ট',
            subtitle: '2026-03-01 to 2026-03-31',
            columns: ['Doctor', 'Booked'],
            rows: function (): Generator {
                yield ['ডা. রহমান', 8];
                yield ['A & B <tag> "quoted"', 3];
                yield [null, 0];
            },
            align: [1 => 'right'],
            notes: ['A no-show is …'],
            knownRowCount: 3,
        );
    }

    public function test_rows_can_be_walked_more_than_once(): void
    {
        $table = $this->table();

        $this->assertCount(3, iterator_to_array($table->rows(), false));
        $this->assertCount(3, iterator_to_array($table->rows(), false), 'a second pass must see the whole table');
    }

    public function test_the_workbook_is_a_valid_zip_with_the_parts_excel_needs(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bp-unit-xlsx');
        $result = (new XlsxWriter)->toFile($this->table(), $path);

        $this->assertSame(3, $result['rows']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('ডা. রহমান', $sheet);
        $this->assertStringContainsString('<v>8</v>', $sheet, 'a number is written as a number, not as text');
        $this->assertStringContainsString('A &amp; B &lt;tag&gt;', $sheet);
        $this->assertStringContainsString('A no-show is', $sheet);
        // Header row is frozen so a long Bangla name column stays readable while scrolling.
        $this->assertStringContainsString('state="frozen"', $sheet);
    }

    public function test_control_characters_cannot_make_excel_reject_the_whole_file(): void
    {
        $table = new ReportTable('t', 's', ['c'], fn (): Generator => yield ["bad\x07value"], knownRowCount: 1);
        $path = tempnam(sys_get_temp_dir(), 'bp-unit-xlsx');
        (new XlsxWriter)->toFile($table, $path);

        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('badvalue', $sheet);
        $this->assertStringNotContainsString("\x07", $sheet);
    }

    public function test_the_csv_writer_produces_the_same_cells(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bp-unit-csv');
        $rows = (new CsvWriter)->toFile($this->table(), $path);
        $csv = (string) file_get_contents($path);
        @unlink($path);

        $this->assertSame(3, $rows);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('ডা. রহমান', $csv);
        $this->assertStringContainsString('Doctor,Booked', $csv);
    }
}
