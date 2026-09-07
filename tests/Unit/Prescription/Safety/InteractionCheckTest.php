<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Safety;

use App\Domain\Prescription\Safety\Checks\InteractionCheck;
use PHPUnit\Framework\TestCase;

final class InteractionCheckTest extends TestCase
{
    public function test_contraindicated_pair_is_critical_with_a_stable_fingerprint_and_evidence(): void
    {
        $check = new InteractionCheck(FakeCatalog::make());
        $alerts = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 203, '1 od 30d', 5), FakeCatalog::tablet('b', 5, '1 od 30d', 75)]));

        $this->assertCount(1, $alerts);
        $a = $alerts[0];
        $this->assertSame('interaction.contraindicated', $a->code);
        $this->assertSame('critical', $a->severity->value);
        $this->assertSame('interaction:contraindicated:5:203', $a->fingerprint);
        $this->assertTrue($a->overridable);
        $this->assertEqualsCanonicalizing(['a', 'b'], $a->itemKeys);
        $this->assertSame([5, 203], $a->genericIds);
        $this->assertSame(['Aspirin', 'Warfarin'], $a->evidence['pair']);
        $this->assertSame('contraindicated', $a->evidence['severity_source']);
        $this->assertStringContainsString('Management: Avoid; if unavoidable monitor INR', $a->message);
        $this->assertNotSame('', $a->messageBn);
        $this->assertTrue($a->blocksIssue());
    }

    public function test_severity_grading_major_is_warning_and_minor_is_info(): void
    {
        $check = new InteractionCheck(FakeCatalog::make());
        $alerts = $check->run(FakeCatalog::context([FakeCatalog::tablet('w', 203, '1 od 30d', 5), FakeCatalog::tablet('i', 40, '1 tds 5d', 400), FakeCatalog::tablet('p', 17, '1 tds 5d')]));
        $byCode = [];

        foreach ($alerts as $a) {
            $byCode[$a->code] = $a->severity->value;
        }

        $this->assertSame(['interaction.major' => 'warning', 'interaction.minor' => 'info'], $byCode);
    }

    public function test_pairs_with_a_current_medication_are_flagged_but_medication_only_pairs_are_not(): void
    {
        $check = new InteractionCheck(FakeCatalog::make());
        $alerts = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 5, '1 od 30d', 75)], ['currentMedicationGenericIds' => [203, 40], 'currentMedicationNames' => [203 => 'Warf (Warfarin)']]));

        $this->assertCount(1, $alerts);
        $this->assertSame('Warfarin', $alerts[0]->evidence['with_current_medication']);
        $this->assertStringStartsWith('With current medication Warfarin', $alerts[0]->message);
        $this->assertSame(['a'], $alerts[0]->itemKeys);
    }

    public function test_combination_generics_expand_through_components(): void
    {
        $catalog = FakeCatalog::make(['drug_interactions' => ['30:203' => ['generic_a_id' => 30, 'generic_b_id' => 203, 'severity' => 'moderate', 'effect' => 'INR up', 'management' => null, 'mechanism' => null, 'evidence_level' => null, 'source' => null]]]);
        $alerts = (new InteractionCheck($catalog))->run(FakeCatalog::context([FakeCatalog::tablet('c', 60, '1 bd 7d', 625), FakeCatalog::tablet('w', 203, '1 od 30d', 5)]));

        $this->assertCount(1, $alerts);
        $this->assertSame('interaction:moderate:30:203', $alerts[0]->fingerprint);
        $this->assertEqualsCanonicalizing(['c', 'w'], $alerts[0]->itemKeys);
    }

    public function test_no_alert_without_a_pair(): void
    {
        $this->assertSame([], (new InteractionCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('p', 17, '1 tds 5d')])));
    }
}
