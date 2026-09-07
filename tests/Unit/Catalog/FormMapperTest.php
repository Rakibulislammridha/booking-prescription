<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Import\FormMapper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('catalog')]
final class FormMapperTest extends TestCase
{
    public function test_maps_dgda_form_text_to_codes(): void
    {
        $m = new FormMapper;
        $this->assertSame('tab', $m->code('Tablet'));
        $this->assertSame('tab', $m->code('Tab.'));
        $this->assertSame('tab', $m->code('Film Coated Tablet'));
        $this->assertSame('susp', $m->code('Powder for Suspension'));
        $this->assertSame('oral_drop', $m->code('Paediatric Drops'));
        $this->assertSame('inh_mdi', $m->code('Metered Dose Inhaler'));
        $this->assertSame('neb', $m->code('Respirator solution'));
        $this->assertSame('inj', $m->code('IV Infusion'));
        $this->assertSame('eye_drop', $m->code('eye_drop'));
        $this->assertNull($m->code('Hologram sticker'));
        $this->assertNull($m->code(null));
    }
}
