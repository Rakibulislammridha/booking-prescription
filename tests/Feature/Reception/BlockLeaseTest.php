<?php

declare(strict_types=1);

namespace Tests\Feature\Reception;

use App\Domain\Serials\Actions\CloseSession;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use Tests\Feature\Reception\Concerns\ReceptionFixtures;
use Tests\TestCase;

/** OFFLINE §4 over HTTP: lease (clamp, limit of 2, branch guard), list, release (free-list), revoke, bootstrap. */
final class BlockLeaseTest extends TestCase
{
    use ReceptionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_lease_renew_release_and_limits(): void
    {
        $device = $this->device(blockSize: 10);
        $actor = $this->receptionist();
        $session = $this->openSession(30, 5, 5);

        $first = $this->asDevice($device, $actor)->postJson(route('api.reception.blocks.lease', [], false), ['session' => $session->public_id, 'size' => 50])->assertCreated()
            ->assertJsonPath('block.range_start', 1)->assertJsonPath('block.range_end', 10)->assertJsonPath('block.status', 'active')->assertJsonPath('block.session_code', 'A')
            ->assertJsonPath('pool_remaining.counter', 20)->assertJsonPath('pool_remaining.released', 0);
        $second = $this->asDevice($device, $actor)->postJson(route('api.reception.blocks.lease', [], false), ['session' => $session->public_id, 'size' => 5])->assertCreated()->assertJsonPath('block.range_start', 11)->assertJsonPath('block.range_end', 15);
        $this->asDevice($device, $actor)->postJson(route('api.reception.blocks.lease', [], false), ['session' => $session->public_id, 'size' => 5])->assertStatus(409)->assertJsonPath('code', 'reception.block_limit');

        $this->asDevice($device, $actor)->getJson(route('api.reception.blocks.index', [], false))->assertOk()->assertJsonCount(2, 'blocks');

        $this->asDevice($device, $actor)->postJson(route('api.reception.blocks.release', ['block' => (string) $first->json('block.public_id')], false))->assertOk()->assertJsonPath('released_unused', 10)->assertJsonPath('block.status', 'released');
        $third = $this->asDevice($device, $actor)->postJson(route('api.reception.blocks.lease', [], false), ['session' => $session->public_id, 'size' => 4])->assertCreated()->assertJsonPath('block.range_start', 1)->assertJsonPath('block.range_end', 4);
        $this->assertNotSame($second->json('block.public_id'), $third->json('block.public_id'));

        $closed = SessionInstance::factory()->openToday()->closed()->create(['branch_id' => $this->mainBranch()->id]);
        $this->asDevice($device, $actor)->postJson(route('api.reception.blocks.lease', [], false), ['session' => $closed->public_id, 'size' => 5])->assertStatus(409)->assertJsonPath('code', 'reception.session_not_open');

        $elsewhere = SessionInstance::factory()->openToday()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->asDevice($device, $actor)->postJson(route('api.reception.blocks.lease', [], false), ['session' => $elsewhere->public_id, 'size' => 5])->assertForbidden()->assertJsonPath('code', 'reception.session_not_at_branch');
    }

    public function test_release_and_revoke_are_ownership_checked(): void
    {
        $device = $this->device();
        $stranger = $this->device();
        $actor = $this->receptionist();
        $admin = $this->hospitalAdmin();
        $session = $this->openSession(30, 0, 0);
        $block = $this->leaseFor($device, $session, 5);

        $this->asDevice($stranger, $actor)->postJson(route('api.reception.blocks.release', ['block' => $block->public_id], false))->assertForbidden()->assertJsonPath('code', 'reception.block_not_owned');
        $this->asDevice($stranger, $actor)->postJson(route('api.reception.blocks.revoke', ['block' => $block->public_id], false))->assertForbidden();
        $this->asDevice($stranger, $admin)->postJson(route('api.reception.blocks.revoke', ['block' => $block->public_id], false))->assertOk()->assertJsonPath('block.status', 'released');
        $this->assertTrue(SerialBlock::query()->findOrFail($block->id)->isRevoked());
    }

    public function test_close_session_releases_the_device_blocks(): void
    {
        $device = $this->device();
        $session = $this->openSession(30, 0, 0);
        $block = $this->leaseFor($device, $session, 5);

        app(CloseSession::class)->handle($session, $this->staffActor());

        $this->assertSame('released', $block->fresh()->status->value);
        $this->assertSame(5, $block->fresh()->returned_count);
    }

    public function test_bootstrap_carries_sessions_blocks_doctors_settings_and_templates(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        $session = $this->openSession(30, 0, 0);
        $block = $this->leaseFor($device, $session, 5);
        $this->allocate($session);

        $json = $this->asDevice($device, $actor)->getJson(route('api.reception.bootstrap', [], false))->assertOk()->json();

        $this->assertCount(2, $json['days']);
        $this->assertSame($this->today()->toDateString(), $json['days'][0]['date']);
        $codes = array_column($json['days'][0]['sessions'], 'public_id');
        $this->assertContains($session->public_id, $codes);
        $this->assertCount(1, $json['blocks']);
        $this->assertSame($block->public_id, $json['blocks'][0]['public_id']);
        $this->assertSame($session->public_id, $json['blocks'][0]['session']);
        $this->assertContains($doctor->public_id, array_column($json['doctors'], 'public_id'));
        $this->assertSame(5, $json['settings']['serial.default_block_size']);
        $this->assertSame(15, $json['settings']['reception.pin_idle_minutes']);
        $this->assertSame('58', $json['print_format']);
        $this->assertCount(3, $json['print_templates']);
        $this->assertStringStartsWith('tenant.', (string) $json['channel']);
        $this->assertSame($actor->public_id, $json['actor']['public_id']);

        /** @var array<string, mixed> $board */
        $board = collect((array) $json['days'][0]['sessions'])->firstWhere('public_id', $session->public_id);
        $this->assertSame(1, $board['counts']['booked']);
        $this->assertSame(24, $board['remaining']['counter']);
        $this->assertSame(5, $board['remaining']['counter_in_blocks']);
        $this->assertCount(1, $board['serials']);
        $this->assertArrayHasKey('patient', $board['serials'][0]);
        $this->assertSame('A', $json['days'][0]['sessions'][0]['code']);
        $this->assertNotNull(SessionInstance::query()->where('doctor_id', $doctor->id)->first(), 'the template doctor was materialised for today');

        $this->asDevice($device, $actor)->getJson(route('api.reception.board', [], false))->assertOk()->assertJsonPath('date', $this->today()->toDateString());
        $this->asDevice($device, $actor)->getJson(route('api.reception.print-templates', [], false))->assertOk()->assertJsonCount(3, 'templates');
        $this->asDevice($device, $actor)->getJson(route('api.reception.patients.recent', [], false))->assertOk();
    }
}
