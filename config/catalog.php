<?php

declare(strict_types=1);

/*
 * Catalog module configuration (CATALOG.md §4), config('catalog.*'). Moved here from app/Domain/Catalog/config by the
 * foundation (config/* is foundation-owned); the Catalog module still owns its contents.
 */
return [

    // Directory of the bundled development sample imported by catalog:seed (CATALOG.md §3).
    'seed_path' => database_path('data/catalog'),

    // Read-through cache TTLs (PRESCRIPTION.md §5.6).
    'cache' => [
        'row_ttl' => 86400,          // 24 h for row/interaction/caution lookups
        'version_ttl' => 60,         // catalog:current_version
        'store' => null,                         // null = default cache store (redis in runtime, array in tests)
    ],

    'import' => [
        'chunk' => 1000,
        'trigram_threshold' => 0.92,
    ],

    'search' => [
        // Common Bangladeshi OPD diagnoses get a seed popularity so the plain-language aliases rank them first.
        'icd10_popularity' => [
            'R50.9' => 100, 'J06.9' => 98, 'I10' => 97, 'E11.9' => 96, 'K29.7' => 95, 'A09' => 94, 'J00' => 93,
            'R05' => 92, 'K21.9' => 91, 'N39.0' => 90, 'J45.9' => 89, 'R51' => 88, 'M54.5' => 87, 'J02.9' => 86,
            'A01.0' => 85, 'A90' => 84, 'R10.4' => 83, 'L20.9' => 82, 'J30.4' => 81, 'E78.5' => 80, 'D50.9' => 79,
            'K30' => 78, 'R11' => 77, 'M79.1' => 76, 'H10.9' => 75, 'B86' => 74, 'J18.9' => 73, 'N94.6' => 72,
            'G43.9' => 71, 'K59.0' => 70, 'K64.9' => 69, 'M10.9' => 68, 'F41.1' => 67, 'F32.9' => 66, 'I25.9' => 65,
        ],

        'catalog_drugs' => [
            'searchableAttributes' => ['brand_name', 'generic_name', 'generic_aliases', 'brand_aliases', 'strength_label', 'form', 'manufacturer', 'label'],
            'filterableAttributes' => ['source', 'doc_type', 'generic_id', 'brand_id', 'dosage_form_id', 'route_id', 'form_code', 'route_code',
                'strength_mg', 'is_active', 'is_controlled', 'therapeutic_class'],
            'sortableAttributes' => ['popularity', 'brand_name'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'popularity:desc'],
            'typoTolerance' => ['enabled' => true, 'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
                'disableOnAttributes' => ['strength_label'], 'disableOnWords' => ['od', 'bd', 'tds', 'qds', 'sos']],
            'synonyms' => ['acetaminophen' => ['paracetamol'], 'নাপা' => ['napa'], 'প্যারাসিটামল' => ['paracetamol'],
                'সেকলো' => ['seclo'], 'ওমিপ্রাজল' => ['omeprazole'], 'অ্যামোক্সিসিলিন' => ['amoxicillin'],
                'এজিথ্রোমাইসিন' => ['azithromycin'], 'মেটফরমিন' => ['metformin'], 'সালবিউটামল' => ['salbutamol'],
                'সিরাপ' => ['syrup'], 'ট্যাবলেট' => ['tablet'], 'ক্যাপসুল' => ['capsule'], 'ইনজেকশন' => ['injection'],
                'ড্রপ' => ['drops'], 'ইনহেলার' => ['inhaler'], 'xr' => ['extended release'], 'sr' => ['sustained release']],
            'localizedAttributes' => [['attributePatterns' => ['generic_aliases', 'brand_aliases', 'generic_name', 'brand_name'], 'locales' => ['eng', 'ben']]],
            'separatorTokens' => ['/', '+'],
            'stopWords' => [],
            'distinctAttribute' => null,
            'pagination' => ['maxTotalHits' => 200],
        ],

        // t{tenant_id}_custom_brands: same shape minus label/brand_aliases, ranked by use_count (CATALOG.md §4.2).
        'custom_brands' => [
            'searchableAttributes' => ['brand_name', 'generic_name', 'generic_aliases', 'strength_label', 'form', 'manufacturer'],
            'filterableAttributes' => ['source', 'doc_type', 'generic_id', 'dosage_form_id', 'route_id', 'form_code', 'route_code', 'strength_mg',
                'is_active', 'review_status', 'promoted_to_master'],
            'sortableAttributes' => ['use_count'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'use_count:desc'],
            'typoTolerance' => ['enabled' => true, 'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
                'disableOnAttributes' => ['strength_label'], 'disableOnWords' => ['od', 'bd', 'tds', 'qds', 'sos']],
            'synonyms' => ['acetaminophen' => ['paracetamol'], 'প্যারাসিটামল' => ['paracetamol'], 'ওমিপ্রাজল' => ['omeprazole'],
                'অ্যামোক্সিসিলিন' => ['amoxicillin'], 'এজিথ্রোমাইসিন' => ['azithromycin'], 'মেটফরমিন' => ['metformin'],
                'সালবিউটামল' => ['salbutamol'], 'সিরাপ' => ['syrup'], 'ট্যাবলেট' => ['tablet'], 'ক্যাপসুল' => ['capsule'],
                'ইনজেকশন' => ['injection'], 'ড্রপ' => ['drops'], 'ইনহেলার' => ['inhaler'], 'xr' => ['extended release'], 'sr' => ['sustained release']],
            'localizedAttributes' => [['attributePatterns' => ['generic_aliases', 'generic_name', 'brand_name'], 'locales' => ['eng', 'ben']]],
            'separatorTokens' => ['/', '+'],
            'stopWords' => [],
            'distinctAttribute' => null,
            'pagination' => ['maxTotalHits' => 200],
        ],

        'catalog_icd10' => [
            'searchableAttributes' => ['code', 'title', 'aliases', 'title_bn'],
            'filterableAttributes' => ['chapter', 'is_billable', 'parent_code'],
            'sortableAttributes' => ['popularity'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'popularity:desc'],
            'typoTolerance' => ['enabled' => true, 'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8], 'disableOnAttributes' => ['code']],
            'synonyms' => ['sugar' => ['diabetes'], 'dm' => ['diabetes'], 'pressure' => ['hypertension'], 'bp' => ['hypertension'],
                'gastric' => ['gastritis', 'reflux'], 'acidity' => ['reflux'], 'piles' => ['haemorrhoids'],
                'cold' => ['nasopharyngitis', 'upper respiratory'], 'loose motion' => ['gastroenteritis'],
                'সুগার' => ['diabetes'], 'প্রেসার' => ['hypertension'], 'গ্যাস্ট্রিক' => ['gastritis'], 'জ্বর' => ['fever'],
                'কাশি' => ['cough'], 'সর্দি' => ['cold'], 'মাথাব্যথা' => ['headache'], 'পাইলস' => ['haemorrhoids']],
            'localizedAttributes' => [['attributePatterns' => ['title_bn', 'aliases'], 'locales' => ['ben', 'eng']]],
            'pagination' => ['maxTotalHits' => 100],
        ],
    ],

    // Tenant soft references scanned by catalog:reconcile (CATALOG.md §6). Other modules register more at boot
    // via SoftReferenceRegistry::register(); tables absent from a tenant schema are skipped.
    'reconcile' => [
        'sample_size' => 50,
    ],
];
