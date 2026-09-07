<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Notifications;

use App\Domain\Notifications\Actions\RetryNotification;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Resources\Notifications\NotificationLogResource;
use App\Http\Resources\Notifications\NotificationResource;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The outbound log: filterable index (channel / status / event / date range / recipient) and a detail endpoint that
 * returns every attempt with its provider exchange for the drawer.
 *
 * `show` calls AuditLog::view because a notification body is patient data (CONVENTIONS §5 — reviewers grep for it).
 */
final class NotificationLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Notification::class);

        $filters = $this->filters($request);

        $notifications = Notification::query()
            ->with('patient')
            ->when($filters['channel'] !== null, fn ($q) => $q->where('channel', $filters['channel']))
            ->when($filters['status'] !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['event_key'] !== null, fn ($q) => $q->where('event_key', $filters['event_key']))
            ->when($filters['from'] !== null, fn ($q) => $q->where('created_at', '>=', $filters['from'].' 00:00:00'))
            ->when($filters['to'] !== null, fn ($q) => $q->where('created_at', '<=', $filters['to'].' 23:59:59'))
            ->when($filters['q'] !== '', fn ($q) => $q->where('recipient', 'ilike', '%'.$filters['q'].'%'))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Notifications/Index', [
            'filters' => $filters,
            'notifications' => NotificationResource::collection($notifications)->response()->getData(true),
            'options' => [
                'channels' => NotificationChannel::values(),
                'statuses' => NotificationStatus::values(),
                'events' => NotificationEvent::values(),
            ],
            'stats' => $this->stats(),
            'can' => ['retry' => $request->user('web')?->can('retry', new Notification) ?? false],
        ]);
    }

    public function show(Request $request, Notification $notification): JsonResponse
    {
        $this->authorize('view', $notification);
        AuditLog::view($notification, ['screen' => 'panel.notifications.show']);

        $notification->load('patient');

        return response()->json([
            'data' => (new NotificationResource($notification))->resolve(),
            'attempts' => NotificationLogResource::collection($notification->logs()->orderBy('attempt_no')->get())->resolve(),
        ]);
    }

    public function retry(Request $request, Notification $notification, RetryNotification $retry): RedirectResponse
    {
        $this->authorize('retry', $notification);
        $retry->handle($notification, Actor::fromRequest($request));

        return redirect()->route('panel.notifications.index')->with('flash.success', __('notifications.flash.retried'));
    }

    /** @return array{channel: string|null, status: string|null, event_key: string|null, from: string|null, to: string|null, q: string} */
    private function filters(Request $request): array
    {
        $one = function (string $key, array $allowed) use ($request): ?string {
            $value = trim((string) $request->query($key, ''));

            return $value !== '' && in_array($value, $allowed, true) ? $value : null;
        };

        $date = function (string $key) use ($request): ?string {
            $value = trim((string) $request->query($key, ''));

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
        };

        return [
            'channel' => $one('channel', NotificationChannel::values()),
            'status' => $one('status', NotificationStatus::values()),
            'event_key' => $one('event_key', NotificationEvent::values()),
            'from' => $date('from'),
            'to' => $date('to'),
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 40),
        ];
    }

    /** @return array<string, int> counts per status for the header chips (the dead-letter count is the useful one) */
    private function stats(): array
    {
        $counts = Notification::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stats = [];

        foreach (NotificationStatus::values() as $status) {
            $stats[$status] = (int) ($counts[$status] ?? 0);
        }

        return $stats;
    }
}
