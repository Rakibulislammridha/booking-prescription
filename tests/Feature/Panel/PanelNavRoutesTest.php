<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Domain\Clinic\Enums\Permission;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The dead-nav sweep, made permanent. PanelLayout renders a sidebar entry whose route does not exist greyed out
 * ("rendered disabled until the module ships it"), which is how "Prescriptions" and "Live queue" shipped
 * unclickable for a whole phase. Every `routeName:` the layout declares must be a registered GET route, every
 * `permission:` a Permission case, and every `key:` a `nav.*` label in both languages — or CI fails here instead
 * of a user finding a grey menu item.
 */
final class PanelNavRoutesTest extends TestCase
{
    private const LAYOUT = 'resources/js/panel/Layouts/PanelLayout.tsx';

    public function test_every_sidebar_entry_points_at_a_registered_get_route(): void
    {
        $names = $this->declared('routeName');
        $this->assertGreaterThanOrEqual(19, count($names), 'the sweep read the layout and found its entries');

        $missing = array_values(array_filter($names, fn (string $name): bool => ! Route::has($name)));
        $this->assertSame([], $missing, 'PanelLayout entries whose route does not exist — they render greyed out: '.implode(', ', $missing));

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('GET', $route->methods(), "{$name} is a sidebar link and must answer GET");
            $this->assertStringStartsWith('panel.', $name, 'the panel shell links only into the panel surface');
        }

        // The two entries this sweep was written for.
        $this->assertContains('panel.prescriptions.index', $names);
        $this->assertContains('panel.queue.index', $names);
    }

    public function test_every_sidebar_permission_is_a_known_permission(): void
    {
        $unknown = array_values(array_diff($this->declared('permission'), Permission::values()));
        $this->assertSame([], $unknown, 'PanelLayout permissions that are not a Permission case (the entry would be hidden for everyone): '.implode(', ', $unknown));
    }

    public function test_every_sidebar_key_has_a_label_in_both_languages(): void
    {
        $keys = $this->declared('key');
        $this->assertNotEmpty($keys);

        foreach (['en', 'bn'] as $locale) {
            $messages = json_decode((string) file_get_contents(base_path("resources/lang/{$locale}.json")), true);
            $this->assertIsArray($messages);

            foreach ($keys as $key) {
                $this->assertArrayHasKey("nav.{$key}", $messages, "nav.{$key} is missing from {$locale}.json");
            }
        }
    }

    /** @return array<int, string> the values of one `field: '…'` in the layout's NavItem literals, in order */
    private function declared(string $field): array
    {
        $source = (string) file_get_contents(base_path(self::LAYOUT));
        preg_match_all("/\\b{$field}:\\s*'([^']+)'/", $source, $matches);

        return array_values(array_unique($matches[1]));
    }
}
