<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Safety;

use App\Domain\Prescription\Safety\Checks\AllergyCheck;
use PHPUnit\Framework\TestCase;

final class AllergyCheckTest extends TestCase
{
    public function test_direct_generic_hit_is_critical(): void
    {
        $alerts = (new AllergyCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('a', 30, '1 tds 7d', 500)], ['allergyGenericIds' => [30], 'allergySeverities' => [30 => 'severe']]));

        $this->assertCount(1, $alerts);
        $this->assertSame('allergy.direct', $alerts[0]->code);
        $this->assertSame('critical', $alerts[0]->severity->value);
        $this->assertSame('allergy:direct:30', $alerts[0]->fingerprint);
        $this->assertSame('severe', $alerts[0]->evidence['severity']);
    }

    public function test_class_hit_through_explicit_class_and_through_the_class_of_an_allergen_generic(): void
    {
        $check = new AllergyCheck(FakeCatalog::make());

        $explicit = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 60, '1 bd 7d', 625)], ['allergyClassIds' => [1]]));
        $this->assertSame('allergy.class', $explicit[0]->code);
        $this->assertSame('critical', $explicit[0]->severity->value);
        $this->assertSame('allergy:class:60:1', $explicit[0]->fingerprint);

        // allergic to amoxicillin (generic 30, penicillin class) → prescribing amoxiclav (60, same class) is a class hit
        $derived = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 60, '1 bd 7d', 625)], ['allergyGenericIds' => [30]]));
        $codes = array_map(fn ($a) => $a->code, $derived);
        $this->assertContains('allergy.class', $codes);
    }

    public function test_cross_reactivity_is_warning_or_critical_when_probable_and_severe(): void
    {
        $check = new AllergyCheck(FakeCatalog::make());

        $mild = $check->run(FakeCatalog::context([FakeCatalog::tablet('c', 31, '1 bd 7d', 200)], ['allergyClassIds' => [1], 'allergySeverities' => [1 => 'mild']]));
        $this->assertSame('allergy.cross', $mild[0]->code);
        $this->assertSame('warning', $mild[0]->severity->value);
        $this->assertSame('allergy:cross:31:2', $mild[0]->fingerprint);
        $this->assertSame(10.0, $mild[0]->evidence['probability_pct']);

        $severe = $check->run(FakeCatalog::context([FakeCatalog::tablet('c', 31, '1 bd 7d', 200)], ['allergyGenericIds' => [30], 'allergySeverities' => [30 => 'severe']]));
        $this->assertSame('critical', $severe[0]->severity->value);
    }

    public function test_free_text_allergen_fuzzy_matches_and_unmatched_text_is_one_info(): void
    {
        $check = new AllergyCheck(FakeCatalog::make());

        $matched = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 30, '1 tds 7d', name: 'Amoxicillin')], ['allergyTexts' => [['name' => 'amoxicilin', 'severity' => null, 'reaction' => 'rash']]]));
        $this->assertSame('allergy.text', $matched[0]->code);
        $this->assertSame('warning', $matched[0]->severity->value);
        $this->assertGreaterThanOrEqual(0.6, $matched[0]->evidence['similarity']);

        $unmatched = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1 tds 5d')], ['allergyTexts' => [['name' => 'sulfa', 'severity' => null, 'reaction' => null]]]));
        $this->assertCount(1, $unmatched);
        $this->assertSame('allergy.unverified', $unmatched[0]->code);
        $this->assertSame('info', $unmatched[0]->severity->value);
        $this->assertSame([], $unmatched[0]->itemKeys);
    }

    public function test_trigram_similarity_behaves_like_pg_trgm(): void
    {
        $this->assertSame(1.0, AllergyCheck::trigramSimilarity('Penicillin', 'penicillin'));
        $this->assertGreaterThan(0.6, AllergyCheck::trigramSimilarity('penicilin', 'Penicillin'));
        $this->assertLessThan(0.3, AllergyCheck::trigramSimilarity('dust', 'Paracetamol'));
    }
}
