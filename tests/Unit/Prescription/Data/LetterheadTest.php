<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Data;

use App\Domain\Prescription\Data\Letterhead;
use App\Domain\Prescription\Data\LetterheadColumn;
use App\Domain\Prescription\Data\LetterheadLine;
use PHPUnit\Framework\TestCase;

/**
 * `doctor_pad_settings.letterhead` — the contract the pad designer writes and the print partials render
 * (PRESCRIPTION.md §7.2, BRIEF §5.A).
 *
 * Two things are being defended here. The first is that the column is USER DATA that must always render: every
 * malformed field falls back rather than throwing, because the alternative is a clinic that cannot print. The
 * second is that a letterhead is TEXT: it is interpolated into a document that is also served, unauthenticated, at
 * /rx/{code}, so markup surviving `fromArray()` would be stored XSS on a public page.
 */
final class LetterheadTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function line(string $text, array $overrides = []): array
    {
        // array_merge, not `+`: the stored shape has a fixed key order and assertSame on arrays compares it.
        return array_merge(['text' => $text, 'text_bn' => null, 'color' => 'text', 'weight' => 'normal', 'size' => 1.0, 'transform' => 'none', 'align' => null], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function letterhead(array $overrides = []): array
    {
        return array_merge([
            'accent_color' => '#B03A2E',
            'text_color' => '#1A1A1A',
            'muted_color' => '#666666',
            'header' => [
                'align' => 'left',
                'lines' => [
                    $this->line('Dr. Md. Mostakim Billah', ['color' => 'accent', 'weight' => 'bold', 'size' => 1.5, 'transform' => 'uppercase']),
                    $this->line('MRCP (UK), FCPS (Medicine)', ['weight' => 'bold', 'size' => 0.92]),
                ],
                'rule' => true,
            ],
            'footer' => [
                'columns' => [
                    ['align' => 'left', 'logo' => true, 'lines' => [$this->line('AMZ Hospital Ltd.', ['color' => 'accent', 'weight' => 'bold'])]],
                    ['align' => 'center', 'logo' => false, 'lines' => [$this->line('Chamber Time', ['weight' => 'bold'])]],
                ],
                'rule' => true,
            ],
        ], $overrides);
    }

    public function test_a_well_formed_letterhead_round_trips_unchanged(): void
    {
        $stored = $this->letterhead();
        $once = Letterhead::fromArray($stored)->toArray();

        $this->assertSame($stored, $once, 'a valid letterhead must survive fromArray()->toArray() byte for byte');
        $this->assertSame($once, Letterhead::fromArray($once)->toArray(), 'normalisation must be idempotent');
    }

    public function test_the_dto_exposes_the_structure_the_partials_render(): void
    {
        $letterhead = Letterhead::fromArray($this->letterhead());

        $this->assertSame('left', $letterhead->headerAlign);
        $this->assertTrue($letterhead->headerRule);
        $this->assertCount(2, $letterhead->headerLines);
        $this->assertInstanceOf(LetterheadLine::class, $letterhead->headerLines[0]);
        $this->assertSame('Dr. Md. Mostakim Billah', $letterhead->headerLines[0]->text);
        $this->assertCount(2, $letterhead->columns());
        $this->assertInstanceOf(LetterheadColumn::class, $letterhead->columns()[0]);
        $this->assertTrue($letterhead->columns()[0]->logo);
        $this->assertFalse($letterhead->isEmpty());

        // Palette tokens, so one colour edit restyles the whole pad.
        $this->assertSame('#B03A2E', $letterhead->color('accent'));
        $this->assertSame('#666666', $letterhead->color('muted'));
        $this->assertSame('#1A1A1A', $letterhead->color('text'));
        $this->assertSame('#1A1A1A', $letterhead->color('nonsense'));
    }

    public function test_a_bad_hex_colour_falls_back_and_a_good_one_is_normalised(): void
    {
        $bad = Letterhead::fromArray($this->letterhead([
            'accent_color' => 'red',
            'text_color' => '#12345',              // five digits
            'muted_color' => '#0000ZZ',            // not hex
        ]));

        $this->assertSame(Letterhead::DEFAULT_ACCENT, $bad->accentColor);
        $this->assertSame(Letterhead::DEFAULT_TEXT, $bad->textColor);
        $this->assertSame(Letterhead::DEFAULT_MUTED, $bad->mutedColor);

        // A three-digit shorthand is not what the print CSS is handed, so it is not accepted either.
        $this->assertSame(Letterhead::DEFAULT_ACCENT, Letterhead::fromArray($this->letterhead(['accent_color' => '#abc']))->accentColor);
        $this->assertSame('#0F766E', Letterhead::fromArray($this->letterhead(['accent_color' => '#0f766e']))->accentColor);
    }

    public function test_sizes_are_clamped_to_the_em_range_the_pad_can_actually_set(): void
    {
        $letterhead = Letterhead::fromArray($this->letterhead(['header' => ['align' => 'left', 'rule' => true, 'lines' => [
            $this->line('huge', ['size' => 9.0]),
            $this->line('tiny', ['size' => 0.01]),
            $this->line('junk', ['size' => 'enormous']),
            $this->line('fine', ['size' => 1.234]),
        ]]]));

        $this->assertSame(LetterheadLine::SIZE_MAX, $letterhead->headerLines[0]->size);
        $this->assertSame(LetterheadLine::SIZE_MIN, $letterhead->headerLines[1]->size);
        $this->assertSame(1.0, $letterhead->headerLines[2]->size);
        $this->assertSame(1.23, $letterhead->headerLines[3]->size);
    }

    public function test_html_never_survives_into_a_line(): void
    {
        $letterhead = Letterhead::fromArray($this->letterhead(['header' => ['align' => 'left', 'rule' => true, 'lines' => [
            $this->line('<b>Dr</b> Billah<script>alert(1)</script>', ['text_bn' => '<img src=x onerror=alert(1)>ডা.']),
            $this->line("  spaced   \n  out  "),
            $this->line('<br><hr/><span></span>'),
        ]]]));

        $this->assertSame('Dr Billahalert(1)', $letterhead->headerLines[0]->text);
        $this->assertStringNotContainsString('<', $letterhead->headerLines[0]->text);
        $this->assertSame('ডা.', $letterhead->headerLines[0]->textBn);
        $this->assertSame('spaced out', $letterhead->headerLines[1]->text, 'whitespace is collapsed to one space');
        // A row that was nothing but markup is nothing at all, and must not print as a blank line.
        $this->assertCount(2, $letterhead->headerLines);
    }

    public function test_text_is_capped_at_the_printable_length(): void
    {
        $long = str_repeat('অ', LetterheadLine::MAX_TEXT + 50);
        $letterhead = Letterhead::fromArray($this->letterhead(['header' => ['align' => 'left', 'rule' => true, 'lines' => [$this->line($long, ['text_bn' => $long])]]]));

        $this->assertSame(LetterheadLine::MAX_TEXT, mb_strlen($letterhead->headerLines[0]->text));
        $this->assertSame(LetterheadLine::MAX_TEXT, mb_strlen((string) $letterhead->headerLines[0]->textBn));
    }

    public function test_line_and_column_counts_are_bounded(): void
    {
        $lines = array_fill(0, Letterhead::MAX_LINES + 8, $this->line('line'));
        $columns = array_fill(0, Letterhead::MAX_COLUMNS + 4, ['align' => 'left', 'logo' => false, 'lines' => $lines]);

        $letterhead = Letterhead::fromArray($this->letterhead([
            'header' => ['align' => 'left', 'rule' => true, 'lines' => $lines],
            'footer' => ['columns' => $columns, 'rule' => true],
        ]));

        $this->assertCount(Letterhead::MAX_LINES, $letterhead->headerLines);
        $this->assertCount(Letterhead::MAX_COLUMNS, $letterhead->footerColumns);
        $this->assertCount(Letterhead::MAX_LINES, $letterhead->footerColumns[0]->lines);
    }

    public function test_unknown_enum_values_fall_back_instead_of_throwing(): void
    {
        $letterhead = Letterhead::fromArray([
            'accent_color' => '#B03A2E',
            'header' => ['align' => 'diagonal', 'rule' => 'yes', 'lines' => [$this->line('x', ['color' => 'puce', 'weight' => 'heavy', 'transform' => 'smallcaps', 'align' => 'justify'])]],
            'footer' => ['columns' => [['align' => 'middle', 'logo' => 1, 'lines' => []]], 'rule' => 0],
        ]);

        $this->assertSame('left', $letterhead->headerAlign);
        $this->assertTrue($letterhead->headerRule);
        $this->assertSame('text', $letterhead->headerLines[0]->color);
        $this->assertSame('normal', $letterhead->headerLines[0]->weight);
        $this->assertSame('none', $letterhead->headerLines[0]->transform);
        $this->assertNull($letterhead->headerLines[0]->align);
        $this->assertSame('left', $letterhead->footerColumns[0]->align, 'a junk alignment takes the column position');
        $this->assertTrue($letterhead->footerColumns[0]->logo);
        $this->assertFalse($letterhead->footerRule);
    }

    public function test_garbage_in_the_column_reads_as_an_empty_letterhead_not_an_exception(): void
    {
        foreach ([null, [], 'not an array', 42, ['header' => 'nope', 'footer' => 7]] as $stored) {
            $letterhead = Letterhead::fromArray($stored);

            $this->assertTrue($letterhead->isEmpty(), 'empty stored data must be reported as empty so the caller falls back');
            $this->assertSame(Letterhead::DEFAULT_ACCENT, $letterhead->accentColor);
            $this->assertSame([], $letterhead->toArray()['header']['lines']);
        }
    }

    public function test_empty_footer_columns_are_dropped_from_the_rendered_set_but_kept_in_the_stored_shape(): void
    {
        $letterhead = Letterhead::fromArray($this->letterhead(['footer' => ['rule' => true, 'columns' => [
            ['align' => 'left', 'logo' => false, 'lines' => []],
            ['align' => 'center', 'logo' => false, 'lines' => [$this->line('Chamber Time')]],
        ]]]));

        $this->assertCount(2, $letterhead->footerColumns, 'the designer keeps its empty column');
        $this->assertCount(1, $letterhead->columns(), 'the sheet does not print a column that has nothing in it');
        $this->assertSame('center', $letterhead->columns()[0]->align);
    }

    public function test_the_snapshot_fallback_builds_a_split_header_from_the_frozen_clinic_and_doctor(): void
    {
        $letterhead = Letterhead::fromSnapshot(
            ['name' => 'Demo Hospital', 'name_bn' => 'ডেমো হাসপাতাল', 'branch' => ['name' => 'Dhanmondi', 'address' => 'House 7, Road 2', 'phone' => '+8801711000002']],
            ['name' => 'Dr. Md. Abdur Rahman', 'name_bn' => 'ডা. রহমান', 'degrees' => 'MBBS, FCPS (Medicine)', 'degrees_bn' => 'এমবিবিএস', 'designation' => 'Consultant Physician', 'bmdc_reg_no' => 'A-45210', 'specialties' => ['Medicine', 'Cardiology']],
        );

        $this->assertSame('split', $letterhead->headerAlign);
        $this->assertTrue($letterhead->generated);
        $this->assertFalse($letterhead->isEmpty());

        $left = array_map(fn (LetterheadLine $l): string => $l->text, $letterhead->headerSide('left'));
        $right = array_map(fn (LetterheadLine $l): string => $l->text, $letterhead->headerSide('right'));

        $this->assertSame(['Dr. Md. Abdur Rahman', 'MBBS, FCPS (Medicine)', 'Consultant Physician', 'Medicine, Cardiology', 'BMDC A-45210'], $left);
        $this->assertSame(['Demo Hospital', 'Dhanmondi', 'House 7, Road 2', '+8801711000002'], $right);
        $this->assertSame('ডা. রহমান', $letterhead->headerSide('left')[0]->textBn);
        $this->assertSame('ডেমো হাসপাতাল', $letterhead->headerSide('right')[0]->textBn);
        // No generated footer band: the signature, QR and verification code already close the sheet.
        $this->assertSame([], $letterhead->columns());
    }

    public function test_the_fallback_prints_the_clinic_name_once_when_the_branch_repeats_it(): void
    {
        $letterhead = Letterhead::fromSnapshot(
            ['name' => 'Demo Hospital', 'branch' => ['name' => 'demo hospital  ', 'address' => 'Uttar Badda']],
            ['name' => 'Dr. X'],
        );

        $texts = array_map(fn (LetterheadLine $l): string => $l->text, $letterhead->headerLines);

        $this->assertSame(['Demo Hospital'], array_values(array_filter($texts, fn (string $t): bool => strcasecmp($t, 'Demo Hospital') === 0)));
        $this->assertContains('Uttar Badda', $texts);
    }

    public function test_the_fallback_drops_every_field_the_doctor_has_not_filled_in(): void
    {
        $letterhead = Letterhead::fromSnapshot(['name' => '', 'branch' => []], ['name' => 'Dr. Solo', 'specialties' => []]);

        $this->assertSame(['Dr. Solo'], array_map(fn (LetterheadLine $l): string => $l->text, $letterhead->headerLines));
    }
}
