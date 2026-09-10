<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Notifications;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Notifications\Drivers\LogChannelDriver;
use App\Domain\SaaS\Actions\Settings\ResetPlatformSetting;
use App\Domain\SaaS\Actions\Settings\UpdatePlatformSetting;
use App\Domain\SaaS\Queries\PlatformSettingsScreen;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\PlatformMailer;
use App\Domain\SaaS\Services\PlatformMailTemplates;
use App\Domain\SaaS\Services\PlatformSmsGateway;
use App\Domain\SaaS\Services\PlatformTestSender;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Notifications\PreviewTemplateRequest;
use App\Http\Requests\Super\Notifications\SendTestMessageRequest;
use App\Http\Requests\Super\Notifications\UpdateNotificationSettingsRequest;
use App\Models\Central\PlatformMessage;
use App\Models\Central\SuperAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform notification defaults (BRIEF §5.J from the platform's side): the outgoing email identity, the SMS
 * gateway tenants inherit when they have none, the platform → owner mail templates with preview and reset, a
 * test send through the real drivers, and the outbound ledger. Every value is a `notifications`-screen key of
 * `PlatformSettingsRegistry`, written through the same audited action as the Platform settings page.
 */
final class PlatformNotificationController extends Controller
{
    public function index(Request $request, PlatformSettingsScreen $screen, PlatformMailer $mailer, PlatformSmsGateway $gateway): Response
    {
        $validated = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'kind' => ['nullable', 'string', 'max:32'], 'channel' => ['nullable', 'string', 'in:email,sms']]);
        $groups = collect($screen->groups('notifications'))->keyBy('key');
        $templates = [];

        foreach (PlatformSettingsRegistry::TEMPLATES as $template) {
            foreach (['en', 'bn'] as $locale) {
                foreach (['subject', 'body'] as $field) {
                    $templates[$template][$locale][$field] = $screen->row(PlatformSettingsRegistry::templateKey($template, $field, $locale));
                }
            }
        }

        $log = PlatformMessage::query()->with(['tenant:id,public_id,name', 'sentBy:id,name'])
            ->when(isset($validated['kind']), fn ($q) => $q->where('kind', (string) $validated['kind']))
            ->when(isset($validated['channel']), fn ($q) => $q->where('channel', (string) $validated['channel']))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return Inertia::render('Super/Notifications/Index', [
            'identity' => $groups->get('mail')['settings'] ?? [],
            'effective_identity' => $mailer->identity(),
            'sms' => $groups->get('sms')['settings'] ?? [],
            'sms_summary' => $gateway->summary(),
            'templates' => $templates,
            'placeholders' => PlatformSettingsRegistry::TEMPLATE_PLACEHOLDERS,
            'admin_email' => $request->user('super')?->getAttribute('email'),
            'log' => $log->through(fn (PlatformMessage $m) => [
                'id' => $m->id, 'channel' => $m->channel, 'kind' => $m->kind, 'recipient' => $m->recipient, 'subject' => $m->subject, 'locale' => $m->locale,
                'status' => $m->status, 'provider' => $m->provider, 'error' => $m->error,
                'tenant' => $m->tenant === null ? null : ['public_id' => $m->tenant->public_id, 'name' => $m->tenant->name],
                'sent_by' => $m->sentBy?->name, 'created_at' => $m->created_at?->toIso8601String(),
            ])->items(),
            'log_meta' => ['current_page' => $log->currentPage(), 'last_page' => $log->lastPage(), 'total' => $log->total()],
            'log_filters' => ['kind' => $validated['kind'] ?? '', 'channel' => $validated['channel'] ?? ''],
            'log_kinds' => PlatformMessage::query()->select('kind')->distinct()->orderBy('kind')->pluck('kind')->all(),
        ]);
    }

    public function update(UpdateNotificationSettingsRequest $request, UpdatePlatformSetting $update): RedirectResponse
    {
        foreach ($request->values() as $key => $value) {
            $update->handle($key, $value, $request->admin());
        }

        return redirect()->route('super.notifications.index')->with('flash.success', __('super.notifications.flash.saved'));
    }

    public function reset(Request $request, string $key, ResetPlatformSetting $reset): RedirectResponse
    {
        abort_unless(PlatformSettingsRegistry::has($key) && PlatformSettingsRegistry::screenOf($key) === 'notifications', 404);
        $admin = $request->user('super');
        $reset->handle($key, $admin instanceof SuperAdmin ? $admin : null);

        return redirect()->route('super.notifications.index')->with('flash.success', __('super.notifications.flash.reset'));
    }

    public function preview(PreviewTemplateRequest $request, PlatformMailTemplates $templates): JsonResponse
    {
        $subject = $request->validated('subject');
        $body = $request->validated('body');

        return response()->json($templates->preview((string) $request->validated('template'), (string) $request->validated('locale'), is_string($subject) ? $subject : null, is_string($body) ? $body : null));
    }

    public function test(SendTestMessageRequest $request, PlatformTestSender $sender, CentralAudit $audit): RedirectResponse
    {
        $admin = $request->admin();
        $message = $request->validated('message');
        $result = $request->channel() === 'sms'
            ? $sender->sms($admin, $request->recipient(), is_string($message) ? $message : null)
            : $sender->email($admin, $request->recipient(), is_string($message) ? $message : null);

        $audit->record(CentralAuditAction::Create, null, null, null, [
            'test_message' => true, 'channel' => $request->channel(), 'recipient' => LogChannelDriver::mask($request->recipient()), 'status' => $result['status'], 'provider' => $result['provider'],
        ], $admin->id);

        $ok = $result['status'] === 'sent';

        return redirect()->route('super.notifications.index')->with($ok ? 'flash.success' : 'flash.error', __($ok ? 'super.notifications.flash.test_sent' : 'super.notifications.flash.test_failed', [
            'recipient' => LogChannelDriver::mask($request->recipient()), 'provider' => $result['provider'], 'error' => $result['error'] ?? '',
        ]));
    }
}
