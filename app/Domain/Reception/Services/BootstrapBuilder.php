<?php

declare(strict_types=1);

namespace App\Domain\Reception\Services;

use App\Domain\Clinic\Services\Settings;
use App\Http\Resources\Reception\ReceptionDeviceResource;
use App\Http\Resources\Serials\SerialBlockResource;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * GET /api/reception/bootstrap — one round trip with everything the PWA caches (OFFLINE §5.1): today's and
 * tomorrow's sessions at the device's branch with serials and remaining, the device's active blocks, doctors with
 * fee tables, the tenant settings the desk needs, the actor and the reception channel name.
 */
final class BootstrapBuilder
{
    public function __construct(
        private readonly BoardBuilder $board,
        private readonly Settings $settings,
        private readonly PrintTemplates $templates,
    ) {}

    /** @return array<string, mixed> */
    public function build(ReceptionDevice $device, User $actor, ?CarbonImmutable $date = null): array
    {
        $device->loadMissing('branch');
        $branch = $device->branch;
        $today = $date ?? Clock::today();
        $tenant = Tenancy::current();

        $days = [$this->board->build($branch, $today), $this->board->build($branch, $today->addDay())];
        $sessionIds = collect($days)->flatMap(fn (array $d) => array_column($d['sessions'], 'public_id'))->all();
        $ids = SessionInstance::query()->whereIn('public_id', $sessionIds)->pluck('id', 'public_id');

        $blocks = SerialBlock::query()
            ->where('reception_device_id', $device->id)
            ->whereIn('session_instance_id', $ids->values()->all())
            ->active()
            ->with('sessionInstance')
            ->orderBy('range_start')
            ->get();

        $doctors = Doctor::query()->active()->with('profile')->orderBy('sort_order')->orderBy('name')->get();

        return [
            'server_time' => CarbonImmutable::now()->toIso8601String(),
            'tenant' => ['public_id' => $tenant?->public_id, 'name' => $tenant?->name, 'timezone' => Clock::timezone()],
            'branch' => ['public_id' => $branch->public_id, 'name' => $branch->name, 'code' => $branch->code, 'slug' => $branch->slug, 'phone' => $branch->phone],
            'device' => (new ReceptionDeviceResource($device))->toArray(app(Request::class)),
            'actor' => ['public_id' => $actor->public_id, 'name' => $actor->name, 'roles' => $actor->getRoleNames()->values()->all()],
            'channel' => $tenant === null ? null : "tenant.{$tenant->public_id}.reception.{$branch->public_id}",
            'days' => $days,
            'blocks' => $blocks->map(fn (SerialBlock $b) => (new SerialBlockResource($b))->toArray(app(Request::class)) + [
                'session' => $b->sessionInstance->public_id,
                'session_code' => $b->sessionInstance->session_code,
            ])->values()->all(),
            'doctors' => $doctors->map(fn (Doctor $d) => [
                'public_id' => $d->public_id, 'slug' => $d->slug, 'name' => $d->name, 'name_bn' => $d->name_bn, 'room' => $d->room_label,
                'fees' => [
                    'new_paisa' => (int) ($d->profile->new_fee_paisa ?? 0),
                    'followup_paisa' => (int) ($d->profile->followup_fee_paisa ?? 0),
                    'free_followup_within_days' => (int) ($d->profile->free_followup_within_days ?? 0),
                    'followup_within_days' => (int) ($d->profile->followup_within_days ?? 0),
                ],
            ])->values()->all(),
            'settings' => array_merge($this->settings->withPrefix('serial.'), $this->settings->withPrefix('reception.'), $this->settings->withPrefix('kiosk.')),
            'print_format' => $this->templates->defaultFormat($branch),
            'print_templates' => $this->templates->all($branch),
        ];
    }
}
