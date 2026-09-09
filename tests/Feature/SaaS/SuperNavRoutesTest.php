<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The super console's dead-nav sweep — the `SuperNav` twin of `Tests\Feature\Panel\PanelNavRoutesTest`. The
 * strip DROPS an entry whose route is missing rather than greying it out, so a mis-typed route name would make
 * a tab silently vanish for the whole platform team; every `routeName:` it declares must be a registered GET
 * route on the super surface, and every label a `super.nav.*` key in both languages.
 */
final class SuperNavRoutesTest extends TestCase
{
    private const NAV = 'resources/js/panel/Components/Super/SuperNav.tsx';

    public function test_every_tab_points_at_a_registered_get_route_on_the_super_surface(): void
    {
        $names = $this->declared('routeName');
        $this->assertGreaterThanOrEqual(9, count($names), 'the sweep read the strip and found its tabs');

        $missing = array_values(array_filter($names, fn (string $name): bool => ! Route::has($name)));
        $this->assertSame([], $missing, 'SuperNav tabs whose route does not exist — they vanish from the strip: '.implode(', ', $missing));

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('GET', $route->methods(), "{$name} is a tab and must answer GET");
            $this->assertStringStartsWith('super.', $name, 'the console strip links only into the super surface');
        }

        $this->assertContains('super.settings.index', $names);
        $this->assertContains('super.two-factor.show', $names);
    }

    public function test_every_tab_has_a_label_in_both_languages(): void
    {
        $labels = $this->declared('label');
        $this->assertNotEmpty($labels);

        foreach (['en', 'bn'] as $locale) {
            $messages = json_decode((string) file_get_contents(base_path("resources/lang/{$locale}.json")), true);
            $this->assertIsArray($messages);

            foreach ($labels as $label) {
                $this->assertStringStartsWith('super.nav.', $label);
                $this->assertArrayHasKey($label, $messages, "{$label} is missing from {$locale}.json");
            }
        }
    }

    /** @return array<int, string> the values of one `field: '…'` in the strip's NavItem literals, in order */
    private function declared(string $field): array
    {
        $source = (string) file_get_contents(base_path(self::NAV));
        preg_match_all("/\\b{$field}:\\s*'([^']+)'/", $source, $matches);

        return array_values(array_unique($matches[1]));
    }
}
