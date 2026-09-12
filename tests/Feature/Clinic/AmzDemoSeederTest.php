<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Prescription\Data\Letterhead;
use App\Domain\Prescription\Data\LetterheadLine;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use Database\Seeders\Tenant\AmzDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The AMZ Hospital reference clinic (BRIEF §5.A) — the pad the product owner photographed, kept as a runnable
 * seeder so the designer has something real to be checked against.
 *
 * The seeder is a demo tool, so the thing worth testing about it is that it is safe to run again: re-running it on
 * a clinic that already exists must not issue a second prescription, allocate a second serial or duplicate a
 * patient. And that the letterhead it writes is still the transcription of the photograph rather than whatever the
 * defaults drifted to.
 */
final class AmzDemoSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_the_seeder_builds_the_clinic_and_running_it_again_changes_nothing(): void
    {
        (new AmzDemoSeeder)->run();

        $doctor = Doctor::query()->where('slug', AmzDemoSeeder::DOCTOR_SLUG)->first();
        $this->assertNotNull($doctor, 'the seeder did not create Dr. Mostakim Billah');
        $this->assertSame('A79319', $doctor->profile->bmdc_reg_no);
        $this->assertSame('Consultant, Internal Medicine & Critical Care Unit', $doctor->profile->designation);

        $before = $this->census();
        $this->assertSame(count(self::patients()), $before['patients']);
        $this->assertSame(1, $before['issued'], 'the demo prescription was not issued');

        (new AmzDemoSeeder)->run();

        $this->assertSame($before, $this->census(), 'a second run of the seeder changed the clinic');
    }

    public function test_the_seeded_pad_is_the_photographed_letterhead(): void
    {
        (new AmzDemoSeeder)->run();

        $pad = Doctor::query()->where('slug', AmzDemoSeeder::DOCTOR_SLUG)->firstOrFail()->padSetting;
        $this->assertNotNull($pad);
        $this->assertSame('A4', $pad->paper_size->value);
        $this->assertSame('en', $pad->default_language);

        // Exactly the contract shape: what the seeder wrote is what the DTO reads back, field for field. Compared
        // with assertEquals rather than assertSame because jsonb does not preserve key order — which is precisely
        // why `fromArray()` re-imposes one.
        $letterhead = Letterhead::fromArray($pad->letterhead);
        $this->assertEquals($pad->letterhead, $letterhead->toArray(), 'the seeded letterhead is not in the contract shape');

        $this->assertSame('#B03A2E', $letterhead->accentColor);
        $this->assertSame('left', $letterhead->headerAlign);
        $this->assertTrue($letterhead->headerRule);
        $this->assertTrue($letterhead->footerRule);

        $header = array_map(fn (LetterheadLine $l): string => $l->text, $letterhead->headerLines);
        $this->assertSame([
            'Dr. Md. Mostakim Billah',
            'MBBS (Dhaka Medical College)',
            'MRCP (UK), FCPS (Medicine)',
            'Medicine Specialist',
            'Dhaka Medical College Hospital',
            'Consultant',
            'Internal Medicine & Critical Care Unit',
            'AMZ Hospital Ltd.',
            'BMDC No: A79319',
        ], $header);

        // The maroon of the photograph falls on the doctor's name, "Medicine Specialist" and "Consultant".
        $accent = array_values(array_map(fn (LetterheadLine $l): string => $l->text, array_filter($letterhead->headerLines, fn (LetterheadLine $l): bool => $l->color === 'accent')));
        $this->assertSame(['Dr. Md. Mostakim Billah', 'Medicine Specialist', 'Consultant'], $accent);
        $this->assertSame('bold', $letterhead->headerLines[2]->weight, 'MRCP (UK), FCPS (Medicine) is the bold qualification line');

        $columns = $letterhead->columns();
        $this->assertCount(3, $columns);
        $this->assertSame(['left', 'center', 'right'], array_map(fn ($c): string => $c->align, $columns));
        $this->assertTrue($columns[0]->logo, 'the hospital mark belongs in the left footer column');

        $text = fn (int $column): array => array_map(fn (LetterheadLine $l): string => $l->text, $columns[$column]->lines);
        $this->assertSame(['AMZ Hospital Ltd.', 'For Amazing Care', AmzDemoSeeder::ADDRESS], $text(0));
        // The real pad prints THUSDAY. The typo is the printer's, not the data's.
        $this->assertSame(['Chamber Time', 'Saturday - Thursday', '04 PM-09 PM', 'Friday', '09:30AM-12:30PM'], $text(1));
        $this->assertSame(['Call For Serial', AmzDemoSeeder::HOTLINE_PRINTED, 'or', AmzDemoSeeder::SHORT_CODE], $text(2));
    }

    public function test_the_demo_prescription_is_issued_with_two_drugs_and_carries_the_pad_it_was_issued_under(): void
    {
        (new AmzDemoSeeder)->run();

        $rx = Prescription::query()->where('status', 'issued')->latest('id')->first();
        $this->assertNotNull($rx);
        $this->assertCount(2, $rx->items);
        $this->assertSame('en', $rx->language->value);

        $frozen = (array) $rx->snapshot?->get('pad.letterhead');
        $this->assertCount(9, (array) ($frozen['header']['lines'] ?? []));
        $this->assertCount(3, (array) ($frozen['footer']['columns'] ?? []));
        $this->assertSame('A79319', $rx->snapshot?->get('doctor.bmdc_reg_no'));

        $html = $this->get('/rx/'.$rx->verification_code)->assertOk()->getContent();
        $this->assertStringContainsString('Dr. Md. Mostakim Billah', $html);
        $this->assertStringContainsString('Call For Serial', $html);
        $this->assertStringContainsString(AmzDemoSeeder::SHORT_CODE, $html);
    }

    /** @return array<string, int> */
    private function census(): array
    {
        return [
            'doctors' => Doctor::query()->count(),
            'patients' => Patient::query()->whereIn('mobile', self::patients())->count(),
            'visits' => Visit::query()->count(),
            'vitals' => Vital::query()->count(),
            'sessions' => SessionInstance::query()->count(),
            'serials' => DB::table('serials')->count(),
            'schedules' => DB::table('doctor_schedules')->count(),
            'issued' => Prescription::query()->where('status', 'issued')->count(),
            'prescriptions' => Prescription::query()->count(),
        ];
    }

    /** @return list<string> */
    private static function patients(): array
    {
        return ['+8801711450001', '+8801711450002', '+8801711450003', '+8801711450004'];
    }
}
