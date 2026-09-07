<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Shorthand;

use App\Domain\Prescription\Data\ParseContext;
use App\Domain\Prescription\Shorthand\ShorthandParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** PRESCRIPTION.md §2.12: each counting family, rounding, pack sizes, overrides, cont / tf. */
final class QuantityCalculatorTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: ParseContext, 2: mixed, 3: string, 4: string, 5: string|null}> */
    public static function families(): iterable
    {
        $tab = new ParseContext(formCode: 'tab', defaultUnit: 'tab', strengthMg: 500);
        $syr = new ParseContext(formCode: 'syr', defaultUnit: 'tsp', packSize: 100, packUnit: 'bottle', strengthMg: 120, perMl: 24, isLiquid: true);
        $syrNoPack = new ParseContext(formCode: 'susp', defaultUnit: 'tsp', strengthMg: 120, perMl: 24, isLiquid: true);
        $ins = new ParseContext(formCode: 'insulin', defaultUnit: 'unit', packSize: 300, packUnit: 'pen');
        $inh = new ParseContext(formCode: 'inh_mdi', defaultUnit: 'puff', packSize: 200, packUnit: 'inhaler');
        $eye = new ParseContext(formCode: 'eye_drop', defaultUnit: 'drop', packUnit: 'bottle');
        $cream = new ParseContext(formCode: 'cream', defaultUnit: 'app', packUnit: 'tube');

        yield 'counted rounds half tablets up' => ['1/2 tds 5d', $tab, 8, 'tab', 'auto', '1.5/day × 5 d = 7.5 → 8 tab'];
        yield 'counted exact' => ['1+0+1 10d', $tab, 20, 'tab', 'auto', '2/day × 10 d = 20 tab'];
        yield 'counted stat' => ['2 stat', $tab, 2, 'tab', 'auto', 'stat = 2 tab'];
        yield 'counted continuous assumes cont_days' => ['1+0+1 cont', new ParseContext(formCode: 'tab', contDays: 15), 30, 'tab', 'auto', '2/day × 15 d = 30 tab'];
        yield 'liquid whole bottles' => ['2 tsp+0+2 tsp 5d', $syr, 1, 'bottle', 'auto', '20 ml/day × 5 d = 100 ml → 1 × 100 ml'];
        yield 'liquid second bottle when exceeded' => ['1+1+1 7d', $syr, 2, 'bottle', 'auto', '15 ml/day × 7 d = 105 ml → 2 × 100 ml'];
        yield 'liquid pack unknown → ml' => ['1 tsp tds 5d', $syrNoPack, 75, 'ml', 'auto', '15 ml/day × 5 d = 75 ml'];
        yield 'liquid tbsp = 15 ml' => ['1 tbsp bd 5d', $syr, 2, 'bottle', 'auto', '30 ml/day × 5 d = 150 ml → 2 × 100 ml'];
        yield 'insulin pens' => ['10 unit sc bd 30d', $ins, 2, 'pen', 'auto', '20 unit/day × 30 d = 600 unit → 2 pen'];
        yield 'inhaler no days → 1' => ['2 puff bd', $inh, 1, 'inhaler', 'auto', '1 inhaler'];
        yield 'inhaler 2 packs' => ['2 puff qds 60d', $inh, 3, 'inhaler', 'auto', '8 puff/day × 60 d = 480 puff → 3 inhaler'];
        yield 'packs per 28 days' => ['1 drop be tds 30d', $eye, 2, 'bottle', 'auto', '2 bottle (30 d)'];
        yield 'packs no days' => ['1 drop be bd', $eye, 1, 'bottle', 'auto', '1 bottle'];
        yield 'cream instruction only' => ['//thin layer', $cream, 1, 'tube', 'auto', '1 tube'];
        yield 'override counted' => ['1+0+1 10d x30', $tab, 30, 'tab', 'override', 'override'];
        yield 'override liquid with pack word' => ['1 tsp tds 5d x2 bottle', $syr, 2, 'bottle', 'override', 'override'];
        yield 'override without days on counted' => ['1 sos x10', $tab, 10, 'tab', 'override', 'override'];
        yield 'till finish unknown' => ['1+0+1 tf', $tab, null, 'tab', 'none', null];
        yield 'sos without max unknown' => ['1 sos', $tab, null, 'tab', 'none', null];
        yield 'missing duration unknown' => ['1 tds', $tab, null, 'tab', 'none', null];
    }

    #[DataProvider('families')]
    public function test_quantity(string $input, ParseContext $ctx, mixed $value, string $unit, string $source, ?string $basis): void
    {
        $line = (new ShorthandParser)->parse($input, $ctx);

        $this->assertFalse($line->hasErrors(), json_encode($line->toArray()['issues']));
        $this->assertSame($value, $line->quantity['value']);
        $this->assertSame($unit, $line->quantity['unit']);
        $this->assertSame($source, $line->quantity['source']);
        $this->assertSame($basis, $line->quantity['basis']);
    }

    public function test_issue_codes_follow_the_never_guess_policy(): void
    {
        $tab = new ParseContext(formCode: 'tab', defaultUnit: 'tab', strengthMg: 500);
        $parser = new ShorthandParser;

        $this->assertTrue($parser->parse('1 sos', $tab)->hasIssue('quantity_unknown'));
        $this->assertTrue($parser->parse('1+0+1', $tab)->hasIssue('missing_duration'));
        $this->assertFalse($parser->parse('1+0+1', $tab)->hasIssue('quantity_unknown'));
        $this->assertTrue($parser->parse('//water', $tab)->hasIssue('missing_schedule'));
        $this->assertTrue($parser->parse('1+0+1 cont', $tab)->hasIssue('continuous_assumed'));
        $this->assertSame('info', $parser->parse('1 tsp tds 5d', new ParseContext(formCode: 'syr', defaultUnit: 'tsp', isLiquid: true))->issues[0]->severity);
    }
}
