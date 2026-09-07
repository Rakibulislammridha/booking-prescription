<?php

declare(strict_types=1);

namespace App\Domain\Queue;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Exceptions\TenancyNotInitialized;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Model;

/**
 * Channel names (REALTIME.md §2, ARCHITECTURE §4.8): `tenant.{tenantPublicId}.{kind}.{publicId}` — every segment is a
 * public id (ULID), never a bigint. Events never build names by hand.
 */
final class TenantChannel
{
    public static function queue(SessionInstance $session): Channel
    {
        return new Channel(self::queueName(self::tenantPublicId(), $session->public_id));
    }

    public static function reception(Branch $branch): PrivateChannel
    {
        return new PrivateChannel(self::receptionName(self::tenantPublicId(), $branch->public_id));
    }

    public static function doctor(Doctor $doctor): PrivateChannel
    {
        return new PrivateChannel(self::doctorName(self::tenantPublicId(), $doctor->public_id));
    }

    public static function display(Branch $branch): PrivateChannel
    {
        return new PrivateChannel(self::displayName(self::tenantPublicId(), $branch->public_id));
    }

    /** The Prescription module's channel (PRESCRIPTION.md §7.5) — listed here so there is one inventory of names. */
    public static function prescription(Model $prescription): PrivateChannel
    {
        return new PrivateChannel(self::prescriptionName(self::tenantPublicId(), (string) $prescription->getAttribute('public_id')));
    }

    public static function queueName(string $tenantPublicId, string $sessionPublicId): string
    {
        return "tenant.{$tenantPublicId}.queue.{$sessionPublicId}";
    }

    public static function receptionName(string $tenantPublicId, string $branchPublicId): string
    {
        return "tenant.{$tenantPublicId}.reception.{$branchPublicId}";
    }

    public static function doctorName(string $tenantPublicId, string $doctorPublicId): string
    {
        return "tenant.{$tenantPublicId}.doctor.{$doctorPublicId}";
    }

    public static function displayName(string $tenantPublicId, string $branchPublicId): string
    {
        return "tenant.{$tenantPublicId}.display.{$branchPublicId}";
    }

    public static function prescriptionName(string $tenantPublicId, string $prescriptionPublicId): string
    {
        return "tenant.{$tenantPublicId}.prescription.{$prescriptionPublicId}";
    }

    public static function tenantPublicId(): string
    {
        $tenant = Tenancy::current() ?? throw new TenancyNotInitialized(self::class);

        return $tenant->public_id;
    }
}
