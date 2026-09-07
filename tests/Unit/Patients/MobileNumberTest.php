<?php

declare(strict_types=1);

namespace Tests\Unit\Patients;

use App\Domain\Patients\Exceptions\InvalidMobileNumber;
use App\Domain\Patients\Services\MobileNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MobileNumberTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function normalisable(): array
    {
        return [
            'local' => ['01712345678', '+8801712345678'],
            'e164' => ['+8801712345678', '+8801712345678'],
            'country without plus' => ['8801712345678', '+8801712345678'],
            'international 00' => ['008801712345678', '+8801712345678'],
            'spaces and dashes' => ['017 1234-5678', '+8801712345678'],
            'bangla digits' => ['০১৭১২৩৪৫৬৭৮', '+8801712345678'],
            'operator 13' => ['01312345678', '+8801312345678'],
            'operator 19' => ['01912345678', '+8801912345678'],
        ];
    }

    #[DataProvider('normalisable')]
    public function test_normalises_every_accepted_form_to_e164(string $input, string $expected): void
    {
        $this->assertSame($expected, MobileNumber::normalize($input));
        $this->assertTrue(MobileNumber::isValid($input));
    }

    /** @return array<string, array{0: string}> */
    public static function invalid(): array
    {
        return [
            'too short' => ['0171234567'],
            'too long' => ['017123456789'],
            'operator 0' => ['01012345678'],
            'operator 2' => ['01212345678'],
            'landline' => ['029876543'],
            'foreign' => ['+447700900123'],
            'letters' => ['017abc45678'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalid')]
    public function test_rejects_numbers_that_are_not_bangladeshi_mobiles(string $input): void
    {
        $this->assertFalse(MobileNumber::isValid($input));
        $this->assertNull(MobileNumber::tryNormalize($input));
        $this->expectException(InvalidMobileNumber::class);
        MobileNumber::normalize($input);
    }

    public function test_local_and_masked_forms(): void
    {
        $this->assertSame('01712345678', MobileNumber::toLocal('+8801712345678'));
        $this->assertSame('017*****678', MobileNumber::mask('+8801712345678'));
        $this->assertSame('017*****678', MobileNumber::mask('01712345678'));
    }
}
