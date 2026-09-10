<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The super console's dead-nav sweep — the console twin of `Tests\Feature\Panel\PanelNavRoutesTest`. The console's
 * drawer (`Components/Super/SuperSidebar.tsx`) reads ONE list, `Components/Super/nav.tsx`, and DROPS an entry whose
 * route is missing rather than greying it out, so a mis-typed route name would make an entry silently vanish for
 * the whole platform team; every `routeName:` that file declares must be a registered GET route on the super
 * surface, and every `label:` — entries, Billing's sub-entries and the section headers alike — a key in both
 * languages. The last test is the leak the drawer was rebuilt for: the clinic shell's own list must name no super
 * route, and the console's list no clinic route.
 */
final class SuperNavRoutesTest extends TestCase
{
    private const NAV = 'resources/js/panel/Components/Super/nav.tsx';

    private const LAYOUT = 'resources/js/panel/Layouts/PanelLayout.tsx';

    public function test_every_entry_points_at_a_registered_get_route_on_the_super_surface(): void
    {
        $names = $this->declared(self::NAV, 'routeName');
        // 14 entries + Billing's five desks, one of which (the overview) is Billing's own route.
        $this->assertGreaterThanOrEqual(18, count($names), 'the sweep read nav.tsx and found its entries');

        $missing = array_values(array_filter($names, fn (string $name): bool => ! Route::has($name)));
        $this->assertSame([], $missing, 'console entries whose route does not exist — they vanish from the drawer: '.implode(', ', $missing));

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('GET', $route->methods(), "{$name} is a drawer entry and must answer GET");
            $this->assertStringStartsWith('super.', $name, 'the console drawer links only into the super surface');
        }

        foreach ([
            'super.dashboard', 'super.tenants.index', 'super.plans.index', 'super.billing.index', 'super.usage.index',
            'super.catalog.index', 'super.catalog.imports.index', 'super.catalog.review', 'super.catalog.reconciliation.index',
            'super.audit.index', 'super.admins.index', 'super.notifications.index', 'super.settings.index', 'super.two-factor.show',
        ] as $entry) {
            $this->assertContains($entry, $names, "{$entry} is one of the fourteen console entries");
        }

        foreach (['super.billing.subscriptions.index', 'super.billing.invoices.index', 'super.billing.payments.index', 'super.billing.dunning.index'] as $desk) {
            $this->assertContains($desk, $names, "{$desk} is one of Billing's desks under the Billing entry");
        }
    }

    public function test_every_label_and_section_header_exists_in_both_languages(): void
    {
        $labels = $this->declared(self::NAV, 'label');
        $this->assertNotEmpty($labels);

        $sections = array_values(array_filter($labels, fn (string $label): bool => str_starts_with($label, 'super.nav.section.')));
        $this->assertCount(6, $sections, 'the drawer has six section headers: overview, clinics, revenue, catalogue, platform, account');

        foreach (['en', 'bn'] as $locale) {
            $messages = json_decode((string) file_get_contents(base_path("resources/lang/{$locale}.json")), true);
            $this->assertIsArray($messages);

            foreach ($labels as $label) {
                $this->assertTrue(
                    str_starts_with($label, 'super.nav.') || str_starts_with($label, 'super.billing.nav.'),
                    "{$label} is not a console navigation key",
                );
                $this->assertArrayHasKey($label, $messages, "{$label} is missing from {$locale}.json");
                $this->assertNotSame('', trim((string) $messages[$label]));
            }
        }
    }

    public function test_the_two_drawers_do_not_share_a_route(): void
    {
        $console = $this->declared(self::NAV, 'routeName');
        $clinic = $this->declared(self::LAYOUT, 'routeName');

        $this->assertNotEmpty($clinic, 'the clinic shell still declares its own entries in PanelLayout.tsx');
        $this->assertSame([], array_values(array_intersect($console, $clinic)));

        foreach ($clinic as $name) {
            $this->assertStringStartsNotWith('super.', $name, 'PanelLayout.tsx must declare no console entry; those live in nav.tsx');
        }

        // The layout reaches the console's entries through one door only: the lazily loaded sidebar behind the
        // `surface === 'super'` switch. A static import here would put the super entries into every clinic route.
        $layout = (string) file_get_contents(base_path(self::LAYOUT));
        $this->assertStringContainsString("shared.surface === 'super'", $layout);
        $this->assertStringContainsString("lazy(() => import('@panel/Components/Super/SuperSidebar'))", $layout);
        $this->assertDoesNotMatchRegularExpression('/^import .*Components\/Super\//m', $layout);
    }

    /** @return array<int, string> the values of one `field: '…'` in a file's NavItem literals, in order */
    private function declared(string $file, string $field): array
    {
        $source = (string) file_get_contents(base_path($file));
        preg_match_all("/\\b{$field}:\\s*'([^']+)'/", $source, $matches);

        return array_values(array_unique($matches[1]));
    }
}
