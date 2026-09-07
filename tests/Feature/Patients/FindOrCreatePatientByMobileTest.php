<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Clinic\Enums\Gender;
use App\Domain\Patients\Actions\FindOrCreatePatientByMobile;
use App\Domain\Patients\Data\PatientLookup;
use App\Domain\Patients\Enums\PatientRelation as RelationType;
use App\Domain\Patients\Enums\PatientSource;
use App\Domain\Patients\Events\PatientCreated;
use App\Domain\Patients\Exceptions\InvalidMobileNumber;
use App\Domain\Patients\Exceptions\PatientNameRequired;
use App\Models\Tenant\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class FindOrCreatePatientByMobileTest extends TestCase
{
    private FindOrCreatePatientByMobile $find;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->find = app(FindOrCreatePatientByMobile::class);
    }

    public function test_creates_the_owner_on_first_contact_and_is_idempotent_afterwards(): void
    {
        Event::fake([PatientCreated::class]);
        $first = ($this->find)(new PatientLookup(mobile: '01712345678', name: 'Rahim Uddin', ageYears: 30, gender: Gender::Male, source: PatientSource::Online));

        $this->assertTrue($first->created);
        $this->assertNotNull($first->patient);
        $this->assertTrue($first->patient->is_mobile_owner);
        $this->assertSame(PatientSource::Online, $first->patient->source);
        $this->assertTrue($first->patient->dob_is_estimated);
        $this->assertCount(1, $first->household);
        Event::assertDispatched(PatientCreated::class);

        $second = ($this->find)(new PatientLookup(mobile: '+88 017 1234 5678', name: '  rahim uddin', ageYears: 30));
        $this->assertFalse($second->created);
        $this->assertTrue($second->patient?->is($first->patient) ?? false);
        $this->assertSame(1, Patient::query()->count());
    }

    public function test_a_new_name_on_a_known_mobile_becomes_a_dependent_of_the_owner(): void
    {
        $owner = ($this->find)(new PatientLookup(mobile: '01812345678', name: 'Fatema'))->patient;
        $child = ($this->find)(new PatientLookup(mobile: '01812345678', name: 'Sumaiya', ageYears: 4, relation: RelationType::Child));

        $this->assertTrue($child->created);
        $dependent = $child->patient;
        $this->assertNotNull($dependent);
        $this->assertFalse($dependent->is_mobile_owner);
        $this->assertTrue($dependent->primaryRelation?->primary->is($owner) ?? false);
        $this->assertSame(RelationType::Child, $dependent->primaryRelation->relation);
        $this->assertCount(2, $child->household);
        $this->assertTrue($child->household->first()?->is($owner) ?? false, 'owner first');
    }

    public function test_namesakes_are_disambiguated_by_dob(): void
    {
        $senior = ($this->find)(new PatientLookup(mobile: '01912345678', name: 'Md Rahim', dob: CarbonImmutable::parse('1970-03-01')))->patient;
        $junior = ($this->find)(new PatientLookup(mobile: '01912345678', name: 'Md Rahim', dob: CarbonImmutable::parse('2012-03-01')));

        $this->assertTrue($junior->created, 'a different dob on the same name is a new person');

        $again = ($this->find)(new PatientLookup(mobile: '01912345678', name: 'md rahim', dob: CarbonImmutable::parse('1970-03-01')));
        $this->assertFalse($again->created);
        $this->assertTrue($again->patient?->is($senior) ?? false);

        $noDob = ($this->find)(new PatientLookup(mobile: '01912345678', name: 'Md Rahim'));
        $this->assertNull($noDob->patient, 'two namesakes with known dobs and no dob given cannot be matched or safely created');
        $this->assertFalse($noDob->created);
        $this->assertSame(2, Patient::query()->count());
    }

    public function test_a_single_namesake_matches_when_no_dob_is_stated(): void
    {
        $existing = ($this->find)(new PatientLookup(mobile: '01312345678', name: 'Karim', dob: CarbonImmutable::parse('1985-01-01')))->patient;

        $match = ($this->find)(new PatientLookup(mobile: '01312345678', name: 'Karim'));

        $this->assertFalse($match->created);
        $this->assertTrue($match->patient?->is($existing) ?? false);
    }

    public function test_without_a_name_the_household_is_returned_and_nobody_is_created(): void
    {
        $owner = ($this->find)(new PatientLookup(mobile: '01512345678', name: 'Owner'))->patient;
        $only = ($this->find)(new PatientLookup(mobile: '01512345678'));
        $this->assertFalse($only->ambiguous);
        $this->assertTrue($only->patient?->is($owner) ?? false);

        ($this->find)(new PatientLookup(mobile: '01512345678', name: 'Dependent'));
        $many = ($this->find)(new PatientLookup(mobile: '01512345678'));
        $this->assertTrue($many->ambiguous);
        $this->assertTrue($many->patient?->is($owner) ?? false, 'the owner is returned when ambiguous');
        $this->assertCount(2, $many->household);

        $this->assertNull(($this->find)(new PatientLookup(mobile: '01612345678', createIfMissing: false))->patient);
        $this->expectException(PatientNameRequired::class);
        ($this->find)(new PatientLookup(mobile: '01612345678'));
    }

    public function test_lookup_only_mode_never_creates(): void
    {
        $result = ($this->find)(new PatientLookup(mobile: '01712345670', name: 'Ghost', createIfMissing: false));

        $this->assertNull($result->patient);
        $this->assertFalse($result->created);
        $this->assertSame(0, Patient::query()->count());
    }

    public function test_rejects_invalid_mobiles_before_touching_the_database(): void
    {
        $this->expectException(InvalidMobileNumber::class);
        ($this->find)(new PatientLookup(mobile: '12345', name: 'X'));
    }
}
