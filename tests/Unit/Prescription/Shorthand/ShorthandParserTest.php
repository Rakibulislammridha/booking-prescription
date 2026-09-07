<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Shorthand;

use App\Domain\Prescription\Data\ParseContext;
use App\Domain\Prescription\Shorthand\Normalizer;
use App\Domain\Prescription\Shorthand\ShorthandParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Table-driven from tests/Fixtures/shorthand_cases.json (PRESCRIPTION.md §9.1): every §2.16 row plus edge cases,
 * asserting both the hand-written table columns (`expect`) and the complete `dose_json` the TS parser must match
 * byte-for-byte. Also: Bangla digits, case, and normalisation idempotence.
 */
final class ShorthandParserTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function fixture(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/shorthand_cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function cases(): iterable
    {
        foreach (self::fixture()['cases'] as $case) {
            yield $case['id'].' `'.$case['input'].'` ['.$case['ctx'].']' => [$case];
        }
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('cases')]
    public function test_fixture_row(array $case): void
    {
        $ctx = self::context($case['ctx']);
        $got = (new ShorthandParser)->parse($case['input'], $ctx)->toArray();
        $expect = $case['expect'];

        if (array_key_exists('has_errors', $expect)) {
            $this->assertSame($expect['has_errors'], array_filter($got['issues'], fn ($i) => $i['severity'] === 'error') !== [], 'has_errors');
        }

        foreach ($expect as $key => $value) {
            if ($key === 'has_errors') {
                continue;
            }

            if ($key === 'issues') {
                $summary = array_map(fn ($i) => ['severity' => $i['severity'], 'code' => $i['code']] + ($i['suggestion'] !== null ? ['suggestion' => $i['suggestion']] : []), $got['issues']);
                $this->assertEquals($value, $summary, 'issues');

                continue;
            }

            if ($key === 'quantity') {
                $this->assertEquals($value, array_intersect_key($got['quantity'], array_flip(['value', 'unit', 'source'])), 'quantity');

                continue;
            }

            $this->assertArrayHasKey($key, $got);
            $this->assertEquals($value, $got[$key], $key);
        }

        // The full ParsedLine is the cross-implementation contract (PHP + TS agree on dose_json).
        $this->assertSame($case['dose_json'], $got, 'dose_json byte parity');
        $this->assertSame(1, $got['v']);
    }

    public function test_fixture_has_every_printed_table_row_and_at_least_47_cases(): void
    {
        $ids = array_map(fn ($c) => $c['id'], self::fixture()['cases']);
        $this->assertGreaterThanOrEqual(47, count($ids));

        foreach ([...range(1, 46), 62] as $row) {
            $this->assertContains((string) $row, $ids, "§2.16 row {$row} missing from the fixture");
        }
    }

    public function test_bangla_digits_define_raw_and_case_is_ignored(): void
    {
        $line = (new ShorthandParser)->parse('১+০+১ ১০D AF', self::context('tab'))->toArray();

        $this->assertSame('1+0+1 10D AF', $line['raw']);
        $this->assertSame('1+0+1 10d af', $line['normalized']);
        $this->assertSame('after', $line['timing']);
    }

    public function test_normalisation_is_idempotent_for_every_clean_case(): void
    {
        $parser = new ShorthandParser;

        foreach (self::fixture()['cases'] as $case) {
            $ctx = self::context($case['ctx']);
            $first = $parser->parse($case['input'], $ctx)->toArray();

            if (array_filter($first['issues'], fn ($i) => $i['severity'] === 'error') !== []) {
                continue;
            }

            $again = $parser->parse($first['normalized'], $ctx)->toArray();
            $this->assertSame($first['normalized'], $again['normalized'], "normalized(normalized) for `{$case['input']}`");

            foreach (['schedule', 'unit', 'daily_total', 'duration', 'timing', 'timing_code', 'route_code', 'quantity', 'instruction'] as $k) {
                $this->assertEquals($first[$k], $again[$k], "{$k} for `{$case['input']}`");
            }
        }
    }

    public function test_instruction_split_keeps_case_and_script(): void
    {
        $n = Normalizer::normalize('1+0+1 5d //After BREAKFAST with পানি');

        $this->assertSame('1+0+1 5d', $n['body']);
        $this->assertSame('After BREAKFAST with পানি', $n['instruction']);
    }

    public function test_unknown_token_carries_span_and_suggestion(): void
    {
        $issues = (new ShorthandParser)->parse('1+0+1 10d aff', self::context('tab'))->toArray()['issues'];

        $this->assertCount(1, $issues);
        $this->assertSame('unknown_token', $issues[0]['code']);
        $this->assertSame('aff', $issues[0]['token']);
        $this->assertSame([10, 13], $issues[0]['span']);
        $this->assertSame('af', $issues[0]['suggestion']);
        $this->assertStringContainsString('"af"', $issues[0]['message']);
        $this->assertNotSame('', $issues[0]['message_bn']);
    }

    private static function context(string $name): ?ParseContext
    {
        $ctx = self::fixture()['contexts'][$name] ?? null;

        return $ctx === null ? null : ParseContext::fromArray($ctx);
    }
}
