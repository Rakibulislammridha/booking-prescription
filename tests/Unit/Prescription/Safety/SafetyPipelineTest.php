<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Safety;

use App\Domain\Prescription\Safety\Checks\CatalogReferenceCheck;
use App\Domain\Prescription\Safety\Checks\CustomBrandLinkCheck;
use App\Domain\Prescription\Safety\Checks\InteractionCheck;
use App\Domain\Prescription\Safety\Checks\MaxDailyDoseCheck;
use App\Domain\Prescription\Safety\Checks\PediatricDoseCheck;
use App\Domain\Prescription\Safety\SafetyPipeline;
use PHPUnit\Framework\TestCase;

/** Override application, issueBlockedBy, non-overridable codes, dropped override on fingerprint change (PRESCRIPTION.md §5.2). */
final class SafetyPipelineTest extends TestCase
{
    private function pipeline(): SafetyPipeline
    {
        $catalog = FakeCatalog::make();

        return new SafetyPipeline([new CatalogReferenceCheck($catalog), new CustomBrandLinkCheck($catalog), new InteractionCheck($catalog), new PediatricDoseCheck($catalog), new MaxDailyDoseCheck($catalog)], $catalog);
    }

    public function test_critical_alert_blocks_until_overridden_with_reason(): void
    {
        $items = [FakeCatalog::tablet('a', 203, '1 od 30d', 5), FakeCatalog::tablet('b', 5, '1 od 30d', 75)];

        $blocked = $this->pipeline()->run(FakeCatalog::context($items));
        $this->assertSame(['interaction:contraindicated:5:203'], $blocked->issueBlockedBy);
        $this->assertTrue($blocked->blocked());

        $overridden = $this->pipeline()->run(FakeCatalog::context($items, [], ['interaction:contraindicated:5:203' => ['reason' => 'Cardiology advised, INR monitored', 'by' => 7, 'at' => '2026-09-06T10:00:00+06:00']]));
        $this->assertSame([], $overridden->issueBlockedBy);
        $this->assertSame('Cardiology advised, INR monitored', $overridden->alerts[0]->overridden['reason']);
        $this->assertSame(7, $overridden->alerts[0]->overridden['by']);
        $this->assertSame('interaction:contraindicated:5:203', $overridden->alerts[0]->toArray()['fingerprint']);
    }

    public function test_non_overridable_codes_block_absolutely(): void
    {
        $item = FakeCatalog::tablet('c', 17, '1 tds 5d', customBrandId: 55, customBrand: ['id' => 55, 'exists' => false]);
        $report = $this->pipeline()->run(FakeCatalog::context([$item], [], ['custom_brand:unlinked:c55' => ['reason' => 'we always use this brand', 'by' => 1, 'at' => null]]));

        $this->assertSame(['custom_brand:unlinked:c55'], $report->issueBlockedBy);
        $this->assertNull($report->alerts[0]->overridden);
        $this->assertContains('custom_brand.unlinked', SafetyPipeline::NON_OVERRIDABLE);
        $this->assertContains('catalog.ref_missing', SafetyPipeline::NON_OVERRIDABLE);
        $this->assertContains('parse.error', SafetyPipeline::NON_OVERRIDABLE);
    }

    public function test_override_is_dropped_when_the_dose_bucket_crosses_a_threshold(): void
    {
        $child = ['ageMonths' => 60, 'weightKg' => 15.0];
        $overrides = ['pediatric:pediatric.over_max:17:warning' => ['reason' => 'Short course under supervision', 'by' => 7, 'at' => null]];

        $warning = $this->pipeline()->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1/2 qds 5d')], $child, $overrides));
        $this->assertNotNull($warning->alerts[0]->overridden);

        $critical = $this->pipeline()->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1 qds 5d')], $child, $overrides));
        $this->assertSame('pediatric:pediatric.over_max:17:critical', $critical->alerts[0]->fingerprint);
        $this->assertNull($critical->alerts[0]->overridden);
        $this->assertSame(['pediatric:pediatric.over_max:17:critical'], $critical->issueBlockedBy);
    }

    public function test_alerts_are_sorted_critical_first_and_computed_is_reported(): void
    {
        $report = $this->pipeline()->run(FakeCatalog::context([FakeCatalog::tablet('w', 203, '1 od 30d', 5), FakeCatalog::tablet('p', 17, '2 tds 5d'), FakeCatalog::tablet('i', 40, '1 tds 5d', 400)]));
        $severities = array_map(fn ($a) => $a->severity->value, $report->alerts);

        $this->assertSame($severities, array_merge(array_filter($severities, fn ($s) => $s === 'critical'), array_filter($severities, fn ($s) => $s === 'warning'), array_filter($severities, fn ($s) => $s === 'info')));
        $this->assertSame(4000.0, $report->computed['items']['p']['adult_max_mg_day']);
        $this->assertArrayHasKey('alerts', $report->toArray());
        $this->assertArrayHasKey('issue_blocked_by', $report->toArray());
    }
}
