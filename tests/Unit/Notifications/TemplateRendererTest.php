<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Exceptions\UnknownPlaceholder;
use App\Domain\Notifications\Services\Escaper;
use App\Domain\Notifications\Services\TemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Placeholder rendering and escaping. The interesting cases are adversarial: a patient's name is attacker-supplied
 * text that ends up inside an SSML document, an HTML email and (via a driver) a URL query string.
 */
final class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new TemplateRenderer;
    }

    public function test_replaces_placeholders_with_and_without_inner_whitespace(): void
    {
        $out = $this->renderer->render('Serial {{serial}} with {{ doctor }}', ['serial' => 'A-012', 'doctor' => 'Dr. Rahman']);

        $this->assertSame('Serial A-012 with Dr. Rahman', $out);
    }

    public function test_renders_bangla_unchanged(): void
    {
        $out = $this->renderer->render('{{clinic}}: সিরিয়াল {{serial}}', ['clinic' => 'সেবা হাসপাতাল', 'serial' => 'A-012']);

        $this->assertSame('সেবা হাসপাতাল: সিরিয়াল A-012', $out);
    }

    public function test_a_known_placeholder_with_no_value_renders_empty(): void
    {
        $this->assertSame('Serial  today', $this->renderer->render('Serial {{serial}} today', []));
    }

    /** A value that itself looks like a placeholder is NOT expanded: one pass only. */
    public function test_a_value_containing_a_placeholder_is_not_re_expanded(): void
    {
        $out = $this->renderer->render('Hello {{patient_name}}', ['patient_name' => '{{link}}', 'link' => 'https://evil.example']);

        $this->assertSame('Hello {{link}}', $out);
        $this->assertStringNotContainsString('evil.example', $out);
    }

    public function test_html_context_escapes_markup_in_values(): void
    {
        $out = $this->renderer->renderFor(NotificationChannel::Email, 'Hello {{patient_name}}', ['patient_name' => '<script>alert(1)</script>']);

        $this->assertSame('Hello &lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }

    /** IVR is the dangerous one: the value is rendered into an SSML/XML document by the telephony vendor. */
    public function test_speech_context_strips_ssml_and_xml_metacharacters(): void
    {
        $out = $this->renderer->renderFor(NotificationChannel::Ivr, 'Patient {{patient_name}}', ['patient_name' => 'Rahim <break time="10s"/> & <say-as>']);

        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('>', $out);
        $this->assertStringNotContainsString('&', $out);
        $this->assertStringNotContainsString('"', $out);
        $this->assertSame('Patient Rahim break time= 10s / say-as', $out);
    }

    /** Backticks, braces, `$`, `;`, `|` and backslashes go; ordinary punctuation a name may contain stays. */
    public function test_speech_context_strips_shell_and_template_metacharacters(): void
    {
        $out = $this->renderer->renderFor(NotificationChannel::Ivr, '{{patient_name}}', ['patient_name' => '`id`; ${x} $(y) | z']);

        $this->assertSame('id x (y) z', $out);
    }

    public function test_url_escaping_is_available_for_drivers_that_build_query_strings(): void
    {
        $this->assertSame('Rahim%26api_key%3Dstolen', Escaper::url('Rahim&api_key=stolen'));
    }

    public function test_control_characters_are_stripped_everywhere_but_newlines_survive(): void
    {
        $out = $this->renderer->render("Line one\r\nLine two\x00\x07", []);

        $this->assertSame("Line one\nLine two", $out);
    }

    public function test_placeholders_lists_names_in_order_without_duplicates(): void
    {
        $this->assertSame(['serial', 'doctor'], $this->renderer->placeholders('{{serial}} {{doctor}} {{serial}}'));
    }

    public function test_a_placeholder_outside_the_event_catalogue_is_rejected_at_save_time(): void
    {
        $this->expectException(UnknownPlaceholder::class);

        $this->renderer->assertPlaceholdersAllowed('Your invoice {{invoice_no}}', NotificationEvent::ThreeAhead);
    }

    public function test_the_documented_catalogue_of_an_event_is_accepted(): void
    {
        $this->renderer->assertPlaceholdersAllowed('{{clinic}} {{patient_name}} {{serial}} {{ahead}} {{eta}} {{doctor}} {{branch}} {{link}}', NotificationEvent::ThreeAhead);

        $this->assertContains('ahead', NotificationEvent::ThreeAhead->variables());
        $this->assertNotContains('invoice_no', NotificationEvent::ThreeAhead->variables());
    }

    public function test_unknown_placeholder_exception_carries_a_stable_domain_code(): void
    {
        try {
            $this->renderer->assertPlaceholdersAllowed('{{invoice_no}}', NotificationEvent::ThreeAhead);
            $this->fail('expected UnknownPlaceholder');
        } catch (UnknownPlaceholder $e) {
            $this->assertSame('notifications.unknown_placeholder', $e->code());
            $this->assertSame(['invoice_no'], $e->unknown);
        }
    }

    /** The "report ready" event was removed with the lab module (BRIEF §6) and must not have crept back. */
    public function test_the_event_catalogue_has_no_report_ready(): void
    {
        $this->assertNotContains('report_ready', NotificationEvent::values());
        $this->assertCount(12, NotificationEvent::cases());
    }
}
