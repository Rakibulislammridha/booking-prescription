<?php

declare(strict_types=1);

namespace Tests\Feature\Reception\Concerns;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Reception\Actions\LeaseBlock;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Patient;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Serials\Concerns\SerialFixtures;

/**
 * Device-guard helpers (OFFLINE §12.2 `actingAsDevice()` lives here because tests/Concerns is foundation-owned):
 * a registered device with a real Sanctum token, a receptionist at the same branch, wire-shaped offline events
 * and the POST /api/reception/sync call.
 */
trait ReceptionFixtures
{
    use SerialFixtures;

    /** @var array<int, string> plain tokens by device id */
    private array $deviceTokens = [];

    /**
     * Runs after RefreshDatabase's rollback: token rows vanish but the tenant's personal_access_tokens sequence does
     * not — put it back so the foundation's cross-tenant token-id collision test (CrossTenantIdentityTest) still holds.
     */
    public function tearDownReceptionFixtures(): void
    {
        if ($this->deviceTokens === []) {
            return;
        }

        $this->deviceTokens = [];
        Tenancy::check() && Tenancy::end();

        foreach (['a', 'b'] as $which) {
            Tenancy::run($this->tenant($which), function (): void {
                DB::statement("select setval(pg_get_serial_sequence('personal_access_tokens', 'id'), coalesce((select max(id) from personal_access_tokens), 1), (select count(*) > 0 from personal_access_tokens))");
            });
        }
    }

    protected function device(?Branch $branch = null, int $blockSize = 10): ReceptionDevice
    {
        return ReceptionDevice::factory()->create(['branch_id' => ($branch ?? $this->mainBranch())->id, 'block_size' => $blockSize]);
    }

    protected function deviceToken(ReceptionDevice $device): string
    {
        return $this->deviceTokens[$device->id] ??= $device->createToken("device:{$device->public_id}", ReceptionDevice::ABILITIES, now()->addDays(ReceptionDevice::TOKEN_DAYS))->plainTextToken;
    }

    /** A receptionist whose default branch is the device's branch (the X-Actor-User of every device request). */
    protected function receptionist(?Branch $branch = null): User
    {
        $user = User::factory()->create(['default_branch_id' => ($branch ?? $this->mainBranch())->id]);
        $user->assignRole(Role::Receptionist->value);

        return $user;
    }

    protected function hospitalAdmin(?Branch $branch = null): User
    {
        $user = User::factory()->create(['default_branch_id' => ($branch ?? $this->mainBranch())->id]);
        $user->assignRole(Role::HospitalAdmin->value);

        return $user;
    }

    /** Bearer device token + X-Actor-User for the next request (OFFLINE §2.2). */
    protected function asDevice(ReceptionDevice $device, User $actor): static
    {
        return $this->withToken($this->deviceToken($device))->withHeader('X-Actor-User', $actor->public_id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return TestResponse<Response>
     */
    protected function sync(ReceptionDevice $device, User $actor, array $events, ?string $appVersion = '1.0.0'): TestResponse
    {
        return $this->asDevice($device, $actor)->postJson(route('api.reception.sync', [], false), ['events' => $events, 'app_version' => $appVersion]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return TestResponse<Response>
     */
    protected function resolve(ReceptionDevice $device, User $actor, string $clientEventId, string $resolution, array $params = []): TestResponse
    {
        return $this->asDevice($device, $actor)->postJson(route('api.reception.sync.resolve', [], false), ['client_event_id' => $clientEventId, 'resolution' => $resolution, 'params' => $params]);
    }

    protected function ulid(): string
    {
        return (string) Str::ulid();
    }

    /** @return array<string, mixed> */
    protected function registerEvent(int $seq, string $localId, string $mobile, string $name, ?string $sex = 'f', ?int $age = 40, ?string $id = null): array
    {
        return ['client_event_id' => $id ?? $this->ulid(), 'sequence_no' => $seq, 'type' => 'register_patient', 'client_occurred_at' => now()->toIso8601String(), 'depends_on' => null,
            'payload' => ['localId' => $localId, 'mobile' => $mobile, 'name' => $name, 'sex' => $sex, 'ageYears' => $age]];
    }

    /** @return array<string, mixed> */
    protected function issueEvent(int $seq, SessionInstance $session, SerialBlock $block, int $number, string $patientRef, ?string $dependsOn = null, ?string $id = null, string $type = 'new', string $priority = 'normal'): array
    {
        return ['client_event_id' => $id ?? $this->ulid(), 'sequence_no' => $seq, 'type' => 'issue_serial', 'client_occurred_at' => now()->toIso8601String(), 'depends_on' => $dependsOn, 'session_id' => $session->public_id,
            'payload' => ['sessionId' => $session->public_id, 'blockId' => $block->public_id, 'number' => $number, 'displayCode' => $session->session_code.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'patientRef' => $patientRef, 'priority' => $priority, 'walkIn' => false, 'appointmentType' => $type, 'feeSnapshot' => ['amount' => $session->fee_new_paisa, 'currency' => 'BDT']]];
    }

    /** @return array<string, mixed> */
    protected function checkInEvent(int $seq, string $serialRef, ?string $dependsOn = null, ?string $id = null): array
    {
        return ['client_event_id' => $id ?? $this->ulid(), 'sequence_no' => $seq, 'type' => 'check_in', 'client_occurred_at' => now()->toIso8601String(), 'depends_on' => $dependsOn, 'payload' => ['serialRef' => $serialRef]];
    }

    /** @return array<string, mixed> */
    protected function cashEvent(int $seq, string $serialRef, int $amount, string $receiptNo, ?string $dependsOn = null, ?string $id = null): array
    {
        return ['client_event_id' => $id ?? $this->ulid(), 'sequence_no' => $seq, 'type' => 'collect_cash', 'client_occurred_at' => now()->toIso8601String(), 'depends_on' => $dependsOn, 'payload' => ['serialRef' => $serialRef, 'amount' => $amount, 'currency' => 'BDT', 'receiptNo' => $receiptNo, 'note' => null]];
    }

    /** @return array<string, mixed> */
    protected function printEvent(int $seq, string $serialRef, ?string $dependsOn = null, ?string $id = null): array
    {
        return ['client_event_id' => $id ?? $this->ulid(), 'sequence_no' => $seq, 'type' => 'print_token', 'client_occurred_at' => now()->toIso8601String(), 'depends_on' => $dependsOn, 'payload' => ['serialRef' => $serialRef, 'format' => '58', 'copies' => 1]];
    }

    /** @return array<string, mixed> */
    protected function voidEvent(int $seq, SessionInstance $session, string $voided, ?string $id = null): array
    {
        return ['client_event_id' => $id ?? $this->ulid(), 'sequence_no' => $seq, 'type' => 'void_local', 'client_occurred_at' => now()->toIso8601String(), 'depends_on' => null, 'session_id' => $session->public_id, 'payload' => ['voidedClientEventId' => $voided, 'reason' => 'misprint']];
    }

    protected function patientWithMobile(string $mobile, string $name = 'Rahima Begum'): Patient
    {
        return Patient::factory()->create(['mobile' => $mobile, 'name' => $name]);
    }

    /** A leased block for the device on the session (through the Reception action → the engine). */
    protected function leaseFor(ReceptionDevice $device, SessionInstance $session, int $size = 10): SerialBlock
    {
        return app(LeaseBlock::class)->handle($device, $session, $size, $this->staffActor());
    }
}
