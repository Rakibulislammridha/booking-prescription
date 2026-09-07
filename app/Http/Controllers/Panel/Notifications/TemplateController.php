<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Notifications;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Actions\SaveTemplate;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Services\DefaultTemplates;
use App\Domain\Notifications\Services\SegmentCounter;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Notifications\PreviewTemplateRequest;
use App\Http\Requests\Panel\Notifications\SaveTemplateRequest;
use App\Http\Resources\Notifications\NotificationTemplateResource;
use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Template management with a live preview. The index ships the full variable catalogue and every built-in default
 * so the editor can show "what you would send if you customised nothing" beside "what you are sending".
 */
final class TemplateController extends Controller
{
    public function index(Request $request, DefaultTemplates $defaults, SegmentCounter $segments): Response
    {
        $this->authorize('viewAny', NotificationTemplate::class);

        $rows = NotificationTemplate::query()->orderBy('event_key')->orderBy('channel')->orderBy('locale')->get();

        return Inertia::render('Notifications/Templates', [
            'templates' => NotificationTemplateResource::collection($rows)->resolve(),
            'defaults' => $this->defaults($defaults, $segments),
            'catalogue' => $this->catalogue(),
            'options' => [
                'channels' => NotificationChannel::values(),
                'events' => NotificationEvent::values(),
                'locales' => Locale::values(),
            ],
        ]);
    }

    public function store(SaveTemplateRequest $request, SaveTemplate $save): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user('web');

        $save->handle(
            $request->event(),
            $request->channel(),
            $request->locale(),
            (string) $request->input('body'),
            $request->input('subject') === null ? null : (string) $request->input('subject'),
            $request->input('provider_template_id') === null ? null : (string) $request->input('provider_template_id'),
            $request->boolean('is_active', true),
            $user,
        );

        return redirect()->route('panel.notifications.templates.index')->with('flash.success', __('notifications.flash.template_saved'));
    }

    public function destroy(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $this->authorize('delete', $template);
        $template->delete();

        return redirect()->route('panel.notifications.templates.index')->with('flash.success', __('notifications.flash.template_reset'));
    }

    /** Renders an unsaved body against the event's sample values, with the exact segment maths the gateway bills. */
    public function preview(PreviewTemplateRequest $request, DefaultTemplates $defaults, TemplateRenderer $renderer, SegmentCounter $segments): JsonResponse
    {
        $event = $request->event();
        $channel = $request->channel();
        $locale = $request->locale();
        $variables = $defaults->sampleVariables($event, $locale);
        $body = $renderer->renderFor($channel, (string) $request->input('body'), $variables);
        $subject = $request->input('subject') === null ? null : $renderer->render((string) $request->input('subject'), $variables);

        return response()->json([
            'body' => $body,
            'subject' => $subject,
            'variables' => $variables,
            'segments' => $segments->count($body)->toArray(),
            'unknown_placeholders' => array_values(array_diff($renderer->placeholders((string) $request->input('body')), $event->variables())),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function defaults(DefaultTemplates $defaults, SegmentCounter $segments): array
    {
        $out = [];

        foreach (NotificationEvent::cases() as $event) {
            foreach (NotificationChannel::cases() as $channel) {
                foreach (Locale::cases() as $locale) {
                    $template = $defaults->for($event, $channel, $locale);
                    $preview = $defaults->preview($event, $channel, $locale, $template->body);

                    $out[] = [
                        'event_key' => $event->value,
                        'channel' => $channel->value,
                        'locale' => $locale->value,
                        'subject' => $template->subject,
                        'body' => $template->body,
                        'preview' => $preview,
                        'segments' => $segments->count($preview)->toArray(),
                    ];
                }
            }
        }

        return $out;
    }

    /** @return array<string, array<int, string>> event → its documented placeholder catalogue */
    private function catalogue(): array
    {
        $catalogue = [];

        foreach (NotificationEvent::cases() as $event) {
            $catalogue[$event->value] = $event->variables();
        }

        return $catalogue;
    }
}
