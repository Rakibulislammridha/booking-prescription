<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** C1: no table is ever named `drugs` on any connection — molecule = generics, product = brands, presentation = strengths. */
#[Group('catalog')]
final class CatalogNamingTest extends TestCase
{
    public function test_no_table_named_drugs_anywhere(): void
    {
        $catalog = DB::connection('catalog')->table('information_schema.tables')->where('table_schema', 'public')->pluck('table_name')->all();
        $this->assertNotContains('drugs', $catalog);

        foreach (['generics', 'brands', 'strengths', 'dosage_forms', 'routes', 'icd10_codes', 'drug_interactions', 'allergy_classes', 'allergy_class_generics',
            'pregnancy_categories', 'renal_cautions', 'hepatic_cautions', 'max_daily_doses', 'drug_information', 'catalog_versions', 'catalog_import_issues'] as $table) {
            $this->assertContains($table, $catalog, "catalog table {$table} missing");
        }

        $public = DB::table('information_schema.tables')->where('table_schema', 'public')->pluck('table_name')->all();
        $this->assertNotContains('drugs', $public);

        $this->asTenant('a');
        $tenant = DB::table('information_schema.tables')->where('table_schema', self::TENANT_A)->pluck('table_name')->all();
        $this->assertNotContains('drugs', $tenant);
        $this->assertContains('custom_brands', $tenant);
        Tenancy::end();
    }
}
