<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Safety;

use App\Domain\Prescription\Safety\Checks\DuplicateTherapyCheck;
use PHPUnit\Framework\TestCase;

final class DuplicateTherapyCheckTest extends TestCase
{
    public function test_same_generic_twice_systemic_same_route_is_critical(): void
    {
        $alerts = (new DuplicateTherapyCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1 tds 5d', brandId: 88), FakeCatalog::tablet('b', 17, '1 bd 5d', brandId: 89)]));
        $this->assertSame('duplicate.generic', $alerts[0]->code);
        $this->assertSame('critical', $alerts[0]->severity->value);
        $this->assertSame('duplicate:generic:17', $alerts[0]->fingerprint);
        $this->assertEqualsCanonicalizing(['a', 'b'], $alerts[0]->itemKeys);
    }

    public function test_same_generic_on_different_routes_is_a_warning_and_components_count(): void
    {
        $check = new DuplicateTherapyCheck(FakeCatalog::make());
        $routes = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1 tds 5d'), FakeCatalog::tablet('b', 17, '1 bd 5d pr', routeCode: 'pr')]));
        $this->assertSame('warning', $routes[0]->severity->value);

        $components = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 30, '1 tds 7d'), FakeCatalog::tablet('b', 60, '1 bd 7d', 625)]));
        $generic = array_values(array_filter($components, fn ($x) => $x->code === 'duplicate.generic'));
        $this->assertSame('duplicate:generic:30', $generic[0]->fingerprint);
    }

    public function test_same_therapeutic_class_is_info_and_already_on_is_info(): void
    {
        $check = new DuplicateTherapyCheck(FakeCatalog::make());
        $class = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 40, '1 tds 5d', 400), FakeCatalog::tablet('b', 50, '1 bd 5d', 50)]));
        $this->assertSame('duplicate.class', $class[0]->code);
        $this->assertSame('info', $class[0]->severity->value);
        $this->assertSame('duplicate:class:nsaid:40:50', $class[0]->fingerprint);

        $on = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1 tds 5d')], ['currentMedicationGenericIds' => [17], 'currentMedicationNames' => [17 => 'Napa (Paracetamol)']]));
        $this->assertSame('duplicate.already_on', $on[0]->code);
        $this->assertSame('info', $on[0]->severity->value);
    }
}
