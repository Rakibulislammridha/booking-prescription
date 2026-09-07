<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Domain\Notifications\Services\SegmentCounter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The billing-correctness suite. A Bangladeshi clinic is invoiced per SMS segment, and Bangla forces UCS-2 — 70
 * characters in a single part, 67 per part once concatenated, against 160/153 for Latin. An off-by-one here is not
 * a cosmetic bug: it is a clinic being charged three times what it expected, every day, forever.
 *
 * Every expectation below is the exact number, never a range.
 */
final class SegmentCounterTest extends TestCase
{
    private SegmentCounter $counter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->counter = new SegmentCounter;
    }

    public function test_plain_latin_is_gsm7(): void
    {
        $this->assertTrue($this->counter->isGsm7('Serial A-012 with Dr. Rahman at 10:00 am.'));
    }

    public function test_any_bengali_character_forces_ucs2(): void
    {
        $this->assertFalse($this->counter->isGsm7('Serial A-012 — সিরিয়াল'));
        $this->assertTrue($this->counter->containsBengali('সিরিয়াল'));
        $this->assertFalse($this->counter->containsBengali('Serial A-012'));
    }

    /** The single-part boundary: 160 GSM-7 characters is one segment, 161 is two. */
    public function test_gsm7_boundary_at_160_and_161(): void
    {
        $one = $this->counter->count(str_repeat('a', 160));
        $this->assertSame('GSM-7', $one->encoding);
        $this->assertSame(1, $one->segments);
        $this->assertSame(0, $one->remaining);

        $two = $this->counter->count(str_repeat('a', 161));
        $this->assertSame(2, $two->segments);
        $this->assertSame(161, $two->units);
    }

    /** Concatenated GSM-7 is 153 per part: 306 is exactly two, 307 is three. */
    public function test_gsm7_concatenated_parts_are_153(): void
    {
        $this->assertSame(2, $this->counter->count(str_repeat('a', 306))->segments);
        $this->assertSame(3, $this->counter->count(str_repeat('a', 307))->segments);
    }

    /** THE Bangla rule: 70 characters is one part, 71 is two — not 160/161. */
    public function test_bangla_single_part_boundary_is_70_not_160(): void
    {
        $seventy = $this->counter->count(str_repeat('ক', 70));
        $this->assertSame('UCS-2', $seventy->encoding);
        $this->assertSame(70, $seventy->units);
        $this->assertSame(1, $seventy->segments);
        $this->assertSame(70, $seventy->perSegment);

        $seventyOne = $this->counter->count(str_repeat('ক', 71));
        $this->assertSame(2, $seventyOne->segments, '71 Bangla characters are two segments; counting them as one under-bills by half');
    }

    /** Concatenated UCS-2 is 67 per part: 134 is two, 135 is three, 201 is three, 202 is four. */
    public function test_bangla_concatenated_parts_are_67(): void
    {
        $this->assertSame(2, $this->counter->count(str_repeat('ক', 134))->segments);
        $this->assertSame(3, $this->counter->count(str_repeat('ক', 135))->segments);
        $this->assertSame(3, $this->counter->count(str_repeat('ক', 201))->segments);
        $this->assertSame(4, $this->counter->count(str_repeat('ক', 202))->segments);
    }

    /**
     * A real rendered Bangla message: the default `three_ahead` wording. It is over 70 UTF-16 code units, so a
     * clinic that assumed 160 would budget one segment and be billed for two. Note that Bengali conjuncts and
     * nukta forms (য় = য + ◌়) cost more than one unit each, which is exactly why the count is code units and not
     * "characters as a human counts them".
     */
    public function test_a_real_bangla_alert_costs_more_than_one_segment(): void
    {
        $body = 'সেবা হাসপাতাল: আপনার আগে আর ৩ জন রোগী আছেন। এখনই চেম্বারের সামনে আসুন। সিরিয়াল A-012, ডা. রহমান। ক্রম পরিবর্তন হতে পারে।';
        $count = $this->counter->count($body);

        $this->assertSame('UCS-2', $count->encoding);
        $this->assertSame(121, $count->units);
        $this->assertSame(2, $count->segments);
    }

    /** Mixed Bangla + Latin is UCS-2 in its entirety — one Bengali character taints the whole body. */
    public function test_one_bengali_character_makes_the_whole_message_unicode(): void
    {
        $body = str_repeat('a', 80).'ক';
        $count = $this->counter->count($body);

        $this->assertSame('UCS-2', $count->encoding);
        $this->assertSame(81, $count->units);
        $this->assertSame(2, $count->segments, '81 units of UCS-2 is two parts, although 81 GSM-7 characters would have been one');
    }

    /** GSM extension characters cost two septets each (the 0x1B escape plus the character). */
    public function test_gsm_extension_characters_cost_two_septets(): void
    {
        $count = $this->counter->count(str_repeat('a', 158).'{}');

        $this->assertSame('GSM-7', $count->encoding);
        $this->assertSame(162, $count->units);
        $this->assertSame(2, $count->segments);
    }

    /** An escape pair is never split across parts, so the earlier part ends one unit short. */
    public function test_an_extension_pair_is_not_split_across_parts(): void
    {
        // 152 plain + '{' (2 units) = 154 > 153, so the brace starts part 2 and part 1 holds 152 of its 153.
        $count = $this->counter->count(str_repeat('a', 152).'{'.str_repeat('a', 200));

        $this->assertSame(354, $count->units);
        $this->assertSame(3, $count->segments);
    }

    /** An emoji is an astral code point: two UTF-16 code units, never split across parts. */
    public function test_astral_characters_cost_two_ucs2_units(): void
    {
        $count = $this->counter->count('🙂');

        $this->assertSame('UCS-2', $count->encoding);
        $this->assertSame(2, $count->units);
        $this->assertSame(1, $count->segments);
    }

    public function test_a_surrogate_pair_is_not_split_across_parts(): void
    {
        // 66 Bangla characters + an emoji = 68 units > 67, so the emoji moves to part 2 whole.
        $count = $this->counter->count(str_repeat('ক', 66).'🙂'.str_repeat('ক', 40));

        $this->assertSame(108, $count->units);
        $this->assertSame(2, $count->segments);
    }

    public function test_empty_body_is_one_empty_segment(): void
    {
        $count = $this->counter->count('');

        $this->assertSame(0, $count->units);
        $this->assertSame(1, $count->segments);
        $this->assertSame(160, $count->remaining);
    }

    #[DataProvider('bodies')]
    public function test_table_of_exact_counts(string $body, string $encoding, int $units, int $segments): void
    {
        $count = $this->counter->count($body);

        $this->assertSame($encoding, $count->encoding, $body);
        $this->assertSame($units, $count->units, $body);
        $this->assertSame($segments, $count->segments, $body);
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int}> */
    public static function bodies(): array
    {
        return [
            'latin short' => ['Serial A-012', 'GSM-7', 12, 1],
            'latin with euro (extension)' => ['€10', 'GSM-7', 4, 1],
            'bangla short (য় is two code points)' => ['সিরিয়াল', 'UCS-2', 8, 1],
            'bangla digits' => ['১২৩৪৫', 'UCS-2', 5, 1],
            'bangla exactly 67' => [str_repeat('ক', 67), 'UCS-2', 67, 1],
            'bangla exactly 70' => [str_repeat('ক', 70), 'UCS-2', 70, 1],
            'bangla 71' => [str_repeat('ক', 71), 'UCS-2', 71, 2],
            'latin 153' => [str_repeat('a', 153), 'GSM-7', 153, 1],
            'latin 160' => [str_repeat('a', 160), 'GSM-7', 160, 1],
            'latin 161' => [str_repeat('a', 161), 'GSM-7', 161, 2],
            'newline is gsm' => ["line one\nline two", 'GSM-7', 17, 1],
        ];
    }
}
