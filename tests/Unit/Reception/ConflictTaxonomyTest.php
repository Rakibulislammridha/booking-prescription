<?php

declare(strict_types=1);

namespace Tests\Unit\Reception;

use App\Domain\Reception\Enums\ConflictReason;
use App\Domain\Reception\Enums\ConflictResolution;
use App\Domain\Reception\Enums\OfflineEventStatus;
use App\Domain\Reception\Enums\OfflineEventType;
use PHPUnit\Framework\TestCase;

/** OFFLINE §8: every conflict reason offers exactly its card's resolutions; the enums match SCHEMA's CHECK lists. */
final class ConflictTaxonomyTest extends TestCase
{
    public function test_resolution_matrix_matches_the_cards(): void
    {
        $this->assertSame(['link_patient', 'family_member'], self::values(ConflictReason::DuplicatePatient));
        $this->assertSame(['link_patient', 'family_member'], self::values(ConflictReason::PatientMismatch));
        $this->assertSame(['reissue', 'discard'], self::values(ConflictReason::SerialAlreadyUsed));
        $this->assertSame(['move_to_session', 'record_in_closed', 'discard'], self::values(ConflictReason::SessionClosed));
        $this->assertSame(['reinstate', 'discard'], self::values(ConflictReason::StatusRegression));
        $this->assertSame(['refund_cash', 'credit', 'discard'], self::values(ConflictReason::AlreadyPaid));
        $this->assertSame([], self::values(ConflictReason::DependencyUnresolved));
    }

    public function test_enum_values_match_schema(): void
    {
        $this->assertSame(['pending', 'accepted', 'conflict', 'rejected'], OfflineEventStatus::values());
        $this->assertSame(['register_patient', 'issue_serial', 'check_in', 'collect_cash', 'print_token', 'void_local'], OfflineEventType::supported());
        $this->assertSame(['link_patient', 'family_member', 'reissue', 'move_to_session', 'record_in_closed', 'reinstate', 'refund_cash', 'credit', 'discard'], ConflictResolution::values());
        $this->assertTrue(OfflineEventStatus::Accepted->isFinal());
        $this->assertFalse(OfflineEventStatus::Pending->isFinal());
    }

    /** @return array<int, string> */
    private static function values(ConflictReason $reason): array
    {
        return array_map(fn (ConflictResolution $r) => $r->value, $reason->resolutions());
    }
}
