<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** catalog:migrate records its batch in the catalog database's own public.migrations and creates the exact SCHEMA §4 shape. */
#[Group('catalog')]
final class CatalogMigrationsTest extends TestCase
{
    public function test_catalog_migrations_recorded_in_catalog_database(): void
    {
        $recorded = DB::connection('catalog')->table('migrations')->pluck('migration')->all();

        $this->assertCount(17, $recorded);
        $this->assertContains('2026_03_01_001700_create_catalog_import_issues_table', $recorded);
        $this->assertNotContains('2026_03_01_000500_create_generics_table', DB::table('migrations')->pluck('migration')->all());   // not in booking
    }

    public function test_canonical_keys_and_checks_exist(): void
    {
        $indexes = collect(DB::connection('catalog')->select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public'"))->keyBy('indexname');

        $this->assertArrayHasKey('brands_lower_name_generic_id_uniq', $indexes->all());
        $this->assertStringContainsString('lower((name)::text), generic_id', $indexes['brands_lower_name_generic_id_uniq']->indexdef);
        $this->assertArrayHasKey('strengths_brand_id_dosage_form_id_strength_label_uniq', $indexes->all());
        $this->assertArrayHasKey('pregnancy_categories_generic_id_trimester_uniq', $indexes->all());
        $this->assertArrayHasKey('generics_name_trgm', $indexes->all());
        $this->assertArrayHasKey('custom_brands_identity_uniq', collect(DB::select('SELECT indexname FROM pg_indexes WHERE schemaname = ?', [self::TENANT_A]))->keyBy('indexname')->all());

        $checks = DB::connection('catalog')->table('pg_constraint')->where('contype', 'c')->pluck('conname')->all();

        foreach (['drug_interactions_severity_check', 'drug_interactions_pair_order_check', 'dosage_forms_code_check', 'routes_code_check',
            'pregnancy_categories_category_check', 'max_daily_doses_any_max_check', 'catalog_import_issues_kind_check', 'catalog_versions_status_check'] as $check) {
            $this->assertContains($check, $checks, "missing check {$check}");
        }

        $this->assertTrue(Schema::connection('catalog')->hasColumns('strengths', ['strength_mg', 'per_ml', 'pack_size_value', 'pack_unit']));
        $this->assertTrue(Schema::connection('catalog')->hasColumns('generics', ['components', 'needs_review', 'name_bn']));
        $this->assertTrue(Schema::connection('catalog')->hasTable('drug_information'));
    }
}
