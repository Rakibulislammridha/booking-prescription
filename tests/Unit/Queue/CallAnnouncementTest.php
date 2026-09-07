<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use App\Domain\Queue\Services\CallAnnouncement;
use PHPUnit\Framework\TestCase;

/** REALTIME.md §9.2: the server spells the numbers so the TV never needs a number-to-words library. */
final class CallAnnouncementTest extends TestCase
{
    public function test_the_documented_example_is_produced_verbatim(): void
    {
        $this->assertSame(
            ['bn' => 'সিরিয়াল এ বিয়াল্লিশ, রুম তিন', 'en' => 'Serial A forty-two, room three'],
            CallAnnouncement::speak('A', 42, 'Room 3'),
        );
    }

    public function test_a_missing_room_is_simply_left_out(): void
    {
        $this->assertSame(['bn' => 'সিরিয়াল বি সাত', 'en' => 'Serial B seven'], CallAnnouncement::speak('B', 7, null));
        $this->assertSame(['bn' => 'সিরিয়াল বি সাত', 'en' => 'Serial B seven'], CallAnnouncement::speak('b', 7, ''));
    }

    public function test_bangla_numbers_cover_zero_to_ninety_nine_and_compose_hundreds(): void
    {
        $this->assertSame('শূন্য', CallAnnouncement::bnNumber(0));
        $this->assertSame('উনিশ', CallAnnouncement::bnNumber(19));
        $this->assertSame('নিরানব্বই', CallAnnouncement::bnNumber(99));
        $this->assertSame('এক শ', CallAnnouncement::bnNumber(100));
        $this->assertSame('দুই শ পাঁচ', CallAnnouncement::bnNumber(205));

        for ($n = 0; $n <= 999; $n++) {
            $this->assertNotSame('', CallAnnouncement::bnNumber($n), "no Bangla word for {$n}");
        }
    }

    public function test_english_numbers(): void
    {
        $this->assertSame('zero', CallAnnouncement::enNumber(0));
        $this->assertSame('forty-two', CallAnnouncement::enNumber(42));
        $this->assertSame('ninety-nine', CallAnnouncement::enNumber(99));
        $this->assertSame('one hundred', CallAnnouncement::enNumber(100));
        $this->assertSame('two hundred five', CallAnnouncement::enNumber(205));
    }

    public function test_a_room_label_without_the_word_room_still_reads_its_digits(): void
    {
        $spoken = CallAnnouncement::speak('C', 3, '12');
        $this->assertSame('সিরিয়াল সি তিন, রুম বারো', $spoken['bn']);
        $this->assertSame('Serial C three, room twelve', $spoken['en']);
    }
}
