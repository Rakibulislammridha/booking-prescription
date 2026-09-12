<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Prescription\Data\Letterhead;
use App\Domain\Prescription\Data\LetterheadLine;
use App\Domain\Prescription\Data\PrescriptionSnapshot;
use App\Domain\Prescription\Render\PrescriptionRenderer;
use App\Domain\Prescription\Render\RenderOptions;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Specialty;
use App\Tenancy\Facades\Tenancy;
use Tests\TestCase;

/**
 * The printed letterhead end to end (PRESCRIPTION.md §7.1–§7.2, BRIEF §5.A): what `Letterhead::defaults()` builds
 * for a doctor who has never opened the designer, what `SnapshotBuilder` freezes into `pad_snapshot`, what a
 * snapshot written before the column existed still renders — and the six defects the product owner found on the
 * sheet we used to print, each pinned so it cannot come back.
 */
final class PrintLetterheadTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_defaults_build_a_real_letterhead_out_of_the_doctors_own_profile(): void
    {
        $user = $this->actingAsDoctor(['degrees' => 'MBBS, FCPS (Medicine)', 'degrees_bn' => 'এমবিবিএস', 'bmdc_reg_no' => 'A-45210', 'designation' => 'Consultant Physician']);
        $doctor = $user->doctor()->firstOrFail();
        $doctor->specialties()->attach(Specialty::query()->firstOrCreate(['slug' => 'medicine'], ['name' => 'Medicine', 'sort_order' => 1])->id, ['is_primary' => true]);
        $doctor->update(['name' => 'Dr. Md. Abdur Rahman', 'name_bn' => 'ডা. মোঃ আব্দুর রহমান']);

        $letterhead = Letterhead::defaults($doctor->fresh() ?? $doctor, Tenancy::current());
        $left = array_map(fn (LetterheadLine $l): string => $l->text, $letterhead->headerSide('left'));

        $this->assertSame('split', $letterhead->headerAlign);
        $this->assertSame(Letterhead::DEFAULT_ACCENT, $letterhead->accentColor);
        $this->assertSame(['Dr. Md. Abdur Rahman', 'MBBS, FCPS (Medicine)', 'Consultant Physician', 'Medicine', 'BMDC A-45210'], $left);
        $this->assertSame('ডা. মোঃ আব্দুর রহমান', $letterhead->headerSide('left')[0]->textBn);
        $this->assertSame('accent', $letterhead->headerSide('left')[0]->color);

        // The clinic side comes off the tenant and its main branch, right-aligned so `split` knows where it goes.
        $right = array_map(fn (LetterheadLine $l): string => $l->text, $letterhead->headerSide('right'));
        $this->assertNotSame([], $right);
        $this->assertSame('Test Clinic A', $right[0]);

        foreach ($letterhead->headerSide('right') as $line) {
            $this->assertSame('right', $line->align);
        }

        // Whatever it built has to survive a save/load cycle unchanged.
        $this->assertSame($letterhead->toArray(), Letterhead::fromArray($letterhead->toArray())->toArray());
    }

    public function test_the_designed_letterhead_is_frozen_into_the_snapshot_and_printed_from_there(): void
    {
        [$rx] = $this->issuedWithContent(['letterhead' => $this->amzLetterhead()]);
        $snapshot = $rx->snapshot;
        $this->assertNotNull($snapshot);

        // §6.2: the pad copy inside the snapshot, not the live row.
        $frozen = (array) $snapshot->get('pad.letterhead');
        $this->assertSame('#B03A2E', $frozen['accent_color'] ?? null);
        $this->assertCount(2, (array) ($frozen['header']['lines'] ?? []));
        $this->assertCount(3, (array) ($frozen['footer']['columns'] ?? []));
        // The tracing underlay is a designer aid and never part of the document.
        $this->assertArrayNotHasKey('sample_path', $snapshot->pad());

        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();

        // The pad shouts through `text-transform`, so the MARKUP still carries the casing the doctor typed —
        // which is what makes the same letterhead reusable in a lower-case design.
        $this->assertStringContainsString('Dr. Md. Mostakim Billah', $html);
        $this->assertStringContainsString('text-transform:uppercase', $html);
        $this->assertStringContainsString('color:#B03A2E', $html);
        $this->assertStringContainsString('Call For Serial', $html);
        $this->assertStringContainsString('Saturday - Thursday', $html);
        $this->assertStringContainsString('data-footer-columns="3"', $html);

        // Immutability (I5): redesigning the live pad cannot change a prescription already issued.
        $rx->doctor->padSetting()->update(['letterhead' => []]);
        $this->assertStringContainsString('Dr. Md. Mostakim Billah', $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent());
    }

    public function test_a_snapshot_written_before_the_column_existed_still_prints_its_header(): void
    {
        [$rx] = $this->issuedWithContent();
        $snapshot = $rx->snapshot;
        $this->assertNotNull($snapshot);

        $document = $snapshot->toArray();
        unset($document['pad']['letterhead']);                       // a 2026 snapshot, before the letterhead existed
        $legacy = new PrescriptionSnapshot($document);

        $html = app(PrescriptionRenderer::class)->render($legacy, RenderOptions::fromPad($legacy->pad()));

        $this->assertStringContainsString('class="letterhead letterhead-split"', $html);
        $this->assertStringContainsString((string) $snapshot->get('doctor.name'), $html);
        $this->assertStringContainsString((string) $snapshot->get('doctor.degrees'), $html);
        $this->assertStringContainsString('BMDC '.$snapshot->get('doctor.bmdc_reg_no'), $html);
        $this->assertStringContainsString((string) $snapshot->get('clinic.name'), $html);
    }

    public function test_the_free_html_letterhead_is_no_longer_rendered_by_any_print_path(): void
    {
        [$rx] = $this->issuedWithContent(['header_html' => '<div id="legacy-header">Old markup</div>', 'footer_html' => '<div id="legacy-footer">Old footer</div>']);

        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();

        $this->assertStringNotContainsString('legacy-header', $html);
        $this->assertStringNotContainsString('legacy-footer', $html);
        $this->assertStringNotContainsString('letterhead-html', $html);
        // The columns are still carried in the frozen pad copy — nothing is lost while doctors migrate.
        $this->assertSame('<div id="legacy-header">Old markup</div>', $rx->snapshot?->get('pad.header_html'));
    }

    /** FLAW 2 — the clinic name printed twice, as title and as subtitle, whenever the branch repeated it. */
    public function test_the_clinic_name_prints_once_when_the_branch_is_named_after_the_clinic(): void
    {
        $tenant = Tenancy::current();
        $this->assertNotNull($tenant);
        Branch::query()->where('is_main', true)->update(['name' => $tenant->name]);

        [$rx] = $this->issuedWithContent();
        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();
        $header = $this->headerOf($html);

        $this->assertSame(1, substr_count($header, (string) $tenant->name), "the clinic name appears more than once in:\n".$header);
    }

    /** FLAW 3 — weight printed in the patient bar AND in the vitals grid. It belongs with the measurements. */
    public function test_weight_prints_once_and_only_in_the_vitals_block(): void
    {
        [$rx] = $this->issuedWithContent();
        $weight = (string) $rx->snapshot?->get('visit.vitals.weight_kg');
        $this->assertNotSame('', $weight);

        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, $weight.'<span class="vital-u"> kg'), 'the weight is printed more than once');
        $this->assertStringContainsString($weight, $this->sectionOf($html, 'vitals'));
        $this->assertStringNotContainsString('kg', $this->barOf($html));
    }

    /** FLAW 4 — `FEMALE` in shouting capitals, the only raw enum value on the sheet. */
    public function test_sex_prints_like_every_other_field_not_as_a_shouted_enum(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit(['name' => 'Rehana Begum', 'gender' => 'female']);
        $draft = $this->draftFor($visit, $doctor);
        $this->savedDraft($draft, $this->itemsPayload([['slug' => 'paracetamol', 'shorthand' => '1+0+1 5d af', 'label' => '500 mg']]));
        $rx = $this->issued($draft->fresh() ?? $draft);

        $this->assertSame('female', $rx->snapshot?->get('patient.gender'));

        $bar = $this->barOf($this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent());

        $this->assertStringNotContainsString('FEMALE', $bar);
        $this->assertStringContainsString('Female', $bar);
        $this->assertStringContainsString('মহিলা', $bar, 'the bilingual sheet names the sex in both languages, like every other label');

        // The pharmacy copy is the same document with less of it, so it shouts no louder.
        $this->assertStringNotContainsString('FEMALE', $this->get('/panel/prescriptions/'.$rx->public_id.'/pharmacy')->assertOk()->getContent());
    }

    /** FLAW 5 — two wrapped lines of blue URL per drug. A marker and one line naming the domain replace them. */
    public function test_the_drug_information_link_is_a_footnote_on_paper_and_a_full_url_where_it_is_clickable(): void
    {
        [$rx] = $this->issuedWithContent(['show_drug_info_url' => true]);
        $full = (string) $rx->snapshot?->get('items.0.info_url');
        $this->assertNotSame('', $full);
        $host = (string) parse_url($full, PHP_URL_HOST);

        $sheet = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();
        $this->assertStringNotContainsString($full, $sheet, 'the full drug-information URL is still being printed on the sheet');
        $this->assertStringContainsString('class="info-mark"', $sheet);
        $this->assertSame(1, substr_count($sheet, 'class="info-note'), 'the domain is named once, not once per drug');
        $this->assertStringContainsString($host, $sheet);

        // The dispensing copy and the verification page are read on a screen, where a link is worth having.
        $this->assertStringContainsString($full, $this->get('/panel/prescriptions/'.$rx->public_id.'/pharmacy')->assertOk()->getContent());
        $this->assertStringContainsString($full, $this->get('/rx/'.$rx->verification_code)->assertOk()->getContent());

        // The pad setting still decides whether any of it prints.
        [$off] = $this->issuedWithContent(['show_drug_info_url' => false]);
        $plain = $this->get('/panel/prescriptions/'.$off->public_id.'/print')->assertOk()->getContent();
        $this->assertStringNotContainsString('class="info-mark"', $plain);
        $this->assertStringNotContainsString('class="info-note', $plain);
    }

    /** FLAW 6 — vitals as a dense grey run-on line; now a labelled grid, capped at two rows. */
    public function test_vitals_print_as_a_labelled_grid_of_at_most_two_rows(): void
    {
        [$rx] = $this->issuedWithContent();
        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();
        $vitals = $this->sectionOf($html, 'vitals');

        $this->assertStringContainsString('class="vitals-grid"', $vitals);
        $this->assertStringNotContainsString('class="kv small"', $vitals, 'the old run-on line is back');
        $this->assertStringContainsString('grid-template-columns: repeat(4, 1fr)', $html);

        // Every value carries its own caption, and seven measurements in a four-column grid is two rows.
        $cells = substr_count($vitals, 'class="vital"');
        $this->assertGreaterThanOrEqual(3, $cells);
        $this->assertLessThanOrEqual(8, $cells, 'more than eight cells cannot fit two rows of four');
        $this->assertSame($cells, substr_count($vitals, 'class="vital-k"'));
        $this->assertSame($cells, substr_count($vitals, 'class="vital-v num"'));
    }

    /** The letterhead reaches the sheet as text, never as markup, even when the column holds markup. */
    public function test_a_letterhead_line_cannot_inject_markup_into_the_printed_document(): void
    {
        [$rx] = $this->issuedWithContent(['letterhead' => [
            'accent_color' => '#B03A2E',
            'header' => ['align' => 'left', 'rule' => true, 'lines' => [
                ['text' => '<script>alert(1)</script>Clinic', 'color' => 'accent', 'weight' => 'bold', 'size' => 1.4, 'transform' => 'none', 'align' => null],
            ]],
            'footer' => ['columns' => [], 'rule' => false],
        ]]);

        $html = $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('alert(1)Clinic', $html);
    }

    /** @return array<string, mixed> the AMZ pad, trimmed to what these assertions need */
    private function amzLetterhead(): array
    {
        $line = fn (string $text, string $color = 'text', string $weight = 'normal', float $size = 1.0): array => [
            'text' => $text, 'text_bn' => null, 'color' => $color, 'weight' => $weight, 'size' => $size, 'transform' => 'uppercase', 'align' => null,
        ];

        return [
            'accent_color' => '#B03A2E', 'text_color' => '#1A1A1A', 'muted_color' => '#666666',
            'header' => ['align' => 'left', 'rule' => true, 'lines' => [
                $line('Dr. Md. Mostakim Billah', 'accent', 'bold', 1.5),
                $line('BMDC No: A79319', 'muted', 'normal', 0.82),
            ]],
            'footer' => ['rule' => true, 'columns' => [
                ['align' => 'left', 'logo' => true, 'lines' => [$line('AMZ Hospital Ltd.', 'accent', 'bold', 0.95)]],
                ['align' => 'center', 'logo' => false, 'lines' => [$line('Chamber Time', 'text', 'bold', 0.88), $line('Saturday - Thursday', 'muted', 'normal', 0.78)]],
                ['align' => 'right', 'logo' => false, 'lines' => [$line('Call For Serial', 'text', 'bold', 0.88), $line('10699', 'accent', 'bold', 0.95)]],
            ]],
        ];
    }

    private function headerOf(string $html): string
    {
        return $this->between($html, '<div class="letterhead', '<hr class="rule"');
    }

    private function barOf(string $html): string
    {
        return $this->between($html, '<div class="patient-bar"', '<hr class="rule-soft">');
    }

    /** One `.section` block of the rendered sheet — from its opening div to wherever the next section starts. */
    private function sectionOf(string $html, string $key): string
    {
        $from = strpos($html, '<div class="section" data-section="'.$key.'"');
        $this->assertNotFalse($from, "no {$key} section in the rendered sheet");
        $next = strpos($html, '<div class="section"', $from + 10);
        $foot = strpos($html, '<div class="sheet-foot">', $from);
        $to = min(array_filter([$next === false ? PHP_INT_MAX : $next, $foot === false ? PHP_INT_MAX : $foot]));

        return substr($html, $from, $to - $from);
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "markup not found: {$start}");
        $to = strpos($html, $end, $from);

        return $to === false ? substr($html, $from) : substr($html, $from, $to - $from);
    }
}
