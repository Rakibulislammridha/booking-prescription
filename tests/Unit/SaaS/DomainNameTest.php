<?php

declare(strict_types=1);

namespace Tests\Unit\SaaS;

use App\Domain\SaaS\Services\DomainName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Hostname handling, with no database and no network — the parts that are pure. */
final class DomainNameTest extends TestCase
{
    #[DataProvider('hostnames')]
    public function test_normalise(string $input, string $expected): void
    {
        $this->assertSame($expected, DomainName::normalise($input));
    }

    /** @return array<int, array{0: string, 1: string}> */
    public static function hostnames(): array
    {
        return [
            ['Queue.Hospital.COM', 'queue.hospital.com'],
            ['  hospital.com.  ', 'hospital.com'],
            ['https://hospital.com/booking', 'hospital.com'],
            ['hospital.com:8443', 'hospital.com'],
            ['HTTP://Queue.Hospital.com:80/x?y=1', 'queue.hospital.com'],
            ['', ''],
        ];
    }

    #[DataProvider('validity')]
    public function test_is_valid(string $host, bool $valid): void
    {
        $this->assertSame($valid, DomainName::isValid($host));
    }

    /** @return array<int, array{0: string, 1: bool}> */
    public static function validity(): array
    {
        return [
            ['hospital.com', true],
            ['queue.hospital.com.bd', true],
            ['a-b.hospital.com', true],
            ['hospital', false],
            ['-hospital.com', false],
            ['hospital..com', false],
            ['hospital.c', false],
            ['has space.com', false],
            [str_repeat('a', 250).'.com', false],
        ];
    }

    #[DataProvider('apexes')]
    public function test_apex_detection_drives_the_dns_advice(string $host, bool $apex): void
    {
        $this->assertSame($apex, DomainName::isApex($host));
    }

    /** @return array<int, array{0: string, 1: bool}> */
    public static function apexes(): array
    {
        return [
            ['hospital.com', true],
            ['queue.hospital.com', false],
            // The two-label public suffixes a Bangladeshi clinic actually buys.
            ['hospital.com.bd', true],
            ['queue.hospital.com.bd', false],
            ['clinic.gov.bd', true],
            ['hospital.co.uk', true],
        ];
    }

    public function test_the_txt_candidates_prefer_the_dedicated_label_then_the_host_itself(): void
    {
        $this->assertSame(
            ['_bp-verify.hospital.com', 'hospital.com'],
            DomainName::txtCandidates('hospital.com'),
        );
        $this->assertSame('bp-verify=abc123', DomainName::expectedTxt('abc123'));
    }

    public function test_the_platforms_own_hosts_can_never_be_claimed(): void
    {
        $this->assertTrue(DomainName::isPlatformHost('bp.localhost', 'bp.localhost'));
        $this->assertTrue(DomainName::isPlatformHost('super.bp.localhost', 'bp.localhost'));
        $this->assertTrue(DomainName::isPlatformHost('someclinic.bp.localhost', 'bp.localhost'));
        $this->assertFalse(DomainName::isPlatformHost('bp.localhost.evil.com', 'bp.localhost'));
        $this->assertFalse(DomainName::isPlatformHost('hospital.com', 'bp.localhost'));
    }
}
