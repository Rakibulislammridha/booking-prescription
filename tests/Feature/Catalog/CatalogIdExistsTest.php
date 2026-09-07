<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Rules\CatalogIdExists;
use App\Domain\Catalog\Rules\StrengthBelongsToBrand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('catalog')]
final class CatalogIdExistsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_missing_inactive_and_active_ids(): void
    {
        $c = DB::connection('catalog');
        $paracetamol = (int) $c->table('generics')->where('slug', 'paracetamol')->value('id');
        $ranitid = (int) $c->table('brands')->where('name', 'Ranitid')->value('id');
        $napa = (int) $c->table('brands')->where('name', 'Napa')->value('id');

        $this->assertTrue(Validator::make(['generic_id' => $paracetamol], ['generic_id' => [new CatalogIdExists('generics')]])->passes());
        $this->assertTrue(Validator::make(['generic_id' => $paracetamol], ['generic_id' => [Rule::catalog('generics')]])->passes());

        $v = Validator::make(['generic_id' => 999999], ['generic_id' => [new CatalogIdExists('generics')]]);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('does not exist in the drug catalog', $v->errors()->first('generic_id'));

        $v = Validator::make(['brand_id' => $ranitid], ['brand_id' => [new CatalogIdExists('brands')]]);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('discontinued', $v->errors()->first('brand_id'));
        $this->assertTrue(Validator::make(['brand_id' => $ranitid], ['brand_id' => [new CatalogIdExists('brands', requireActive: false)]])->passes());
        $this->assertTrue(Validator::make(['brand_id' => $napa], ['brand_id' => [new CatalogIdExists('brands')]])->passes());

        $this->assertTrue(Validator::make(['code' => 'E11.9'], ['code' => [new CatalogIdExists('icd10_codes')]])->passes());
        $this->assertTrue(Validator::make(['code' => 'Q99.9'], ['code' => [new CatalogIdExists('icd10_codes')]])->fails());
        $this->assertTrue(Validator::make(['code' => 'abc'], ['code' => [new CatalogIdExists('generics')]])->fails());
        $this->assertTrue(Validator::make(['x' => null], ['x' => ['nullable', new CatalogIdExists('generics')]])->passes());
    }

    public function test_strength_must_belong_to_brand_and_generic(): void
    {
        $c = DB::connection('catalog');
        $napa = $c->table('brands')->where('name', 'Napa')->first();
        $strength = $c->table('strengths')->where('brand_id', $napa->id)->where('strength_label', '500 mg')->first();
        $seclo = $c->table('brands')->where('name', 'Seclo')->first();

        $rules = ['items.*.strength_id' => [new CatalogIdExists('strengths'), new StrengthBelongsToBrand]];

        $this->assertTrue(Validator::make(['items' => [['brand_id' => $napa->id, 'generic_id' => $napa->generic_id, 'strength_id' => $strength->id]]], $rules)->passes());

        $v = Validator::make(['items' => [['brand_id' => $seclo->id, 'generic_id' => $seclo->generic_id, 'strength_id' => $strength->id]]], $rules);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('does not belong to the chosen brand', $v->errors()->first('items.0.strength_id'));

        $v = Validator::make(['items' => [['brand_id' => $napa->id, 'generic_id' => $seclo->generic_id, 'strength_id' => $strength->id]]], $rules);
        $this->assertStringContainsString('chosen generic', $v->errors()->first('items.0.strength_id'));
    }
}
