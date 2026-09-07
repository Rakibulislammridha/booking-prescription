<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Import\StrengthLabelParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('catalog')]
final class StrengthLabelParserTest extends TestCase
{
    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function labels(): array
    {
        return [
            'tablet' => ['500 mg', ['label' => '500 mg', 'amount_value' => 500.0, 'amount_unit' => 'mg', 'strength_mg' => 500.0, 'per_ml' => null, 'per_unit' => null]],
            'no space' => ['500mg', ['label' => '500 mg', 'strength_mg' => 500.0]],
            'modifier' => ['665 mg XR', ['label' => '665 mg XR', 'modifier' => 'XR', 'strength_mg' => 665.0]],
            'syrup' => ['120 mg/5 ml', ['label' => '120 mg/5 ml', 'per_value' => 5.0, 'per_unit' => 'ml', 'strength_mg' => 120.0, 'per_ml' => 24.0]],
            'drops' => ['80 mg/ml', ['per_value' => 1.0, 'per_unit' => 'ml', 'per_ml' => 80.0]],
            'combination' => ['25/125 mcg', ['amount_unit' => 'mcg', 'components' => [25.0, 125.0], 'strength_mg' => 0.025]],
            'percent' => ['0.5 %', ['amount_unit' => '%', 'strength_mg' => null, 'per_ml' => 5.0]],
            'iu' => ['100 IU/ml', ['amount_unit' => 'IU', 'strength_mg' => 100.0, 'per_ml' => 100.0]],
            'gram per volume' => ['1 g/100 ml', ['amount_unit' => 'g', 'strength_mg' => 1000.0, 'per_ml' => 10.0]],
            'fraction volume' => ['2.5 mg/2.5 ml', ['strength_mg' => 2.5, 'per_ml' => 1.0]],
            'inhaler' => ['100 mcg/actuation', ['per_unit' => 'actuation', 'strength_mg' => 0.1, 'per_ml' => null]],
            'leading word' => ['ORS 20.5 g', ['label' => '20.5 g', 'strength_mg' => 20500.0]],
        ];
    }

    /** @param  array<string, mixed>  $expected */
    #[DataProvider('labels')]
    public function test_parses_labels(string $label, array $expected): void
    {
        $parsed = (new StrengthLabelParser)->parse($label)->toArray();

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $parsed[$key], "{$label}: {$key}");
        }
    }

    /** @return array<string, array{0: string}> */
    public static function garbage(): array
    {
        return ['word' => ['garbage'], 'bare number' => ['500'], 'empty' => ['   '], 'unknown unit' => ['500 zz'], 'unknown per unit' => ['5 mg/foo']];
    }

    #[DataProvider('garbage')]
    public function test_rejects_garbage(string $label): void
    {
        $this->assertNull((new StrengthLabelParser)->tryParse($label));
        $this->expectException(InvalidArgumentException::class);
        (new StrengthLabelParser)->parse($label);
    }

    public function test_strength_unit_and_per_volume(): void
    {
        $p = (new StrengthLabelParser)->parse('120 mg/5 ml');
        $this->assertSame('mg/ml', $p->strengthUnit());
        $this->assertSame(5.0, $p->perVolumeMl());
        $this->assertSame('mg', (new StrengthLabelParser)->parse('500 mg')->strengthUnit());
    }

    public function test_parses_pack_sizes(): void
    {
        $p = new StrengthLabelParser;
        $this->assertSame([100.0, 'ml'], $p->parsePack('100 ml'));
        $this->assertSame([100.0, 'tab'], $p->parsePack('10x10', 'tab'));
        $this->assertSame([200.0, 'actuation'], $p->parsePack('200 doses'));
        $this->assertSame([3.0, 'ml'], $p->parsePack('3 ml pen'));
        $this->assertSame([null, null], $p->parsePack(null));
    }
}
