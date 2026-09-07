# Catalog Database — Build Specification

Scope: BRIEF §3.2, §3.3, §3.4 and the search/import/reconcile machinery
behind PRESCRIPTION.md §3 and §5. The `catalog` PostgreSQL database is shared
by every tenant, read-only at runtime, written only by super-admin tooling.

## 0. Invariants

| # | Rule | Enforced by |
|---|---|---|
| C1 | No table is ever named `drugs`. Molecule = `generics`, product = `brands`, presentation = `strengths`. | migrations, `CatalogNamingTest` |
| C2 | The application runtime connects with a role granted `SELECT` only. Writes go through `catalog_admin`, a separate connection/role used by super-admin commands. | `config/database.php`, `CatalogWriteContext` |
| C3 | Every catalog write is versioned (`catalog_versions`) and idempotent by canonical key. Nothing is hard-deleted; discontinued rows are `is_active = false`. | `CatalogImporter` |
| C4 | Tenants reference catalog ids softly; existence is validated in application code (`CatalogIdExists`) and audited nightly (`catalog:reconcile`), never by FK. | rules, reconcile job |
| C5 | Autocomplete reads Meilisearch only. | `DrugSearchService` |
| C6 | Custom brands need a live generic to be usable; promotion to master is a super-admin decision. | PRESCRIPTION.md §5.3, §8 here |

---

## 1. Read-only enforcement

### 1.1 Connections

The two connections `catalog` (runtime, SELECT-only role) and `catalog_admin` (write role) are defined
verbatim in ARCHITECTURE.md §3.1 (`config/database.php` is foundation-owned); both resolve to
`App\Tenancy\Database\TenantAwarePostgresConnection` but never have `setSearchPath()` called — their
search path is always `public`.

Production roles (`database/sql/catalog_roles.sql`, applied by ops):
`catalog_reader` → `GRANT CONNECT; GRANT USAGE ON SCHEMA public; GRANT SELECT ON ALL TABLES; ALTER DEFAULT PRIVILEGES … GRANT SELECT`;
`catalog_admin` → full DML + DDL (migrations run as this role). Local dev uses
`root` for both; `RuntimeRoleReadOnlyTest` (§9) runs only where the reader
role exists. Octane: both connections are long-lived; the catalog pool is
sized at 2 per worker.

### 1.2 `CatalogModel` base class

The base class is foundation-owned and reproduced in ARCHITECTURE.md §5.1; restated here for what it
means to this module:

```php
namespace App\Models\Catalog;

abstract class CatalogModel extends \Illuminate\Database\Eloquent\Model
{
    public $timestamps = true;                      // created_at/updated_at written by the importer under catalog_admin (SCHEMA §4); catalog_version_id marks the release
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return app(CatalogWriteContext::class)->isOpen() ? 'catalog_admin' : 'catalog';   // writes always go through the admin role
    }

    public static function bootCatalogModel(): void
    {
        foreach (['creating', 'updating', 'deleting', 'saving'] as $event) {
            static::registerModelEvent($event, fn () => app(CatalogWriteContext::class)->isOpen()
                || throw new \App\Domain\Catalog\Exceptions\CatalogIsReadOnly(static::class));
        }
    }
    // Not Laravel\Scout\Searchable — the shared indexes are joins built by CatalogSearchIndexer (§4).
}
```

Models (all `final`, all extend `CatalogModel`, names exactly SCHEMA.md §4's `**Model**` lines): `Generic`, `Brand`, `Strength`,
`DosageForm`, `Route`, `Icd10Code`, `DrugInteraction`, `AllergyClass`,
`AllergyClassGeneric`, `PregnancyCategory`, `RenalCaution`, `HepaticCaution`,
`MaxDailyDose`, `DrugInformation`, `CatalogVersion`, `CatalogImportIssue`.
No `SoftDeletes`; `is_active` scopes (`scopeActive`); every row
carries `catalog_version_id` (SCHEMA §4). Query-builder writes on the `catalog` connection are
forbidden and caught by the PHPStan rule `NoCatalogQueryBuilderWrites` (ARCHITECTURE.md §5.1).

### 1.3 `CatalogWriteContext`

`App\Domain\Catalog\Services\CatalogWriteContext` — a container **singleton** listed in
`config/octane.php` `flush` (ARCHITECTURE.md §4.5), so a leaked context can never survive into the
next request; no static state (forbidden in `App\*`, ARCHITECTURE.md §4.5):

```php
namespace App\Domain\Catalog\Services;

final class CatalogWriteContext
{
    private int $depth = 0;
    public function isOpen(): bool { return $this->depth > 0; }

    /** Only super-admin console commands, the importer, the promotion approver and the dev seeder call this. */
    public function run(\Closure $fn, ?string $reason = null): mixed
    {
        abort_unless(app()->runningInConsole() || auth('super')->check(), 403);   // guard `super` = SuperAdmin (ARCHITECTURE.md §6.1)
        $this->depth++;
        try { return DB::connection('catalog_admin')->transaction($fn); }
        finally { $this->depth--; }
    }
}
```

Callers resolve it from the container (`app(CatalogWriteContext::class)->run(...)` / `->isOpen()`);
there is no static accessor. `DB::connection('catalog')->beforeExecuting()` in `AppServiceProvider` (non-production) throws on any
statement that does not start with `SELECT`/`WITH`/`EXPLAIN` — a second net under the role grant.

---

## 2. Schema — migrations on the `catalog` connection

Path `database/migrations/catalog/2026_03_01_HHMMSS_*.php` (CONVENTIONS.md §3.1); run only by
`php artisan catalog:migrate {--fresh} {--seed} {--pretend}`, which binds `Migrator::usingConnection('catalog_admin', …)`
and records in the `catalog` database's `public.migrations` (ARCHITECTURE.md §3.4/§4.3). Migration classes
use plain `Schema::create()` and **never** set `$connection` or call `Schema::connection()` — the
command supplies the connection (CONVENTIONS.md §3.1). Lookup keys (`slug`, `code`) are `varchar` with plain or
`lower()` expression unique indexes (SCHEMA §4) — the `citext` extension is not used.

Column names are SCHEMA.md §4's; rows marked ＋ are additions requested in §10.
Every table also has `id bigint identity`, `catalog_version_id`, `is_active` and `timestampsTz()` (SCHEMA §4).

| Table | Columns |
|---|---|
| `generics` | `name` (INN), `slug` unique (canonical key), `atc_code`, `aliases jsonb` (`["Acetaminophen","প্যারাসিটামল"]`), `therapeutic_class` (used by duplicate-class check), `is_controlled`, `is_pediatric_weight_based`; ＋ `components jsonb` (`[{generic_id, mg}]` for combinations), ＋ `needs_review bool` (auto-created by import), ＋ `name_bn` |
| `brands` | `generic_id NOT NULL`, `name`, `slug` unique, `manufacturer` (text), `dar_number` (DGDA registration), `popularity`; unique `(lower(name), generic_id)`; ＋ `aliases jsonb` (`["নাপা"]`), ＋ `discontinued_at` |
| `strengths` | `brand_id`, `generic_id` (denormalised), `dosage_form_id`, `route_id`, `strength_label` (`500 mg`, `120 mg/5 ml`), `strength_value`, `strength_unit`, `per_volume_ml`, `pack_size` (text `10x10`, `100 ml`, `200 doses`), `unit_price_paisa`; unique `(brand_id, dosage_form_id, strength_label)`; ＋ `strength_mg numeric` (mg per counting unit, importer-computed), ＋ `per_ml numeric` (mg per ml for liquids), ＋ `pack_size_value numeric` + `pack_unit` (parsed from `pack_size` for quantity maths) |
| `dosage_forms` | `name`, `name_bn`, `abbreviation` (`Tab`), `default_unit` (`tab ml drop puff …`), `default_route_id`; ＋ `code` unique (the grammar's `form_code` taxonomy: `tab cap syr susp sol oral_drop eye_drop ear_drop nasal_drop nasal_spray inh_mdi inh_dpi neb inj insulin cream oint gel lotion powder shampoo mouthwash paint supp pessary sachet`), ＋ `is_liquid`, ＋ `pack_unit` |
| `routes` | `name`, `name_bn`, `abbreviation`; ＋ `code` unique (`po sl buccal pr pv top iv im sc id inh neb ng le re be lear rear bear nasal`), ＋ `is_systemic` |
| `icd10_codes` | `code` unique (`E11.9`), `title`, `title_bn`, `chapter`, `block`, `parent_code`, `aliases jsonb`, `is_billable` (leaf) |
| `drug_interactions` | `generic_a_id < generic_b_id`, `severity` (`minor moderate major contraindicated`), `mechanism`, `effect` (shown in the alert), `management`, `evidence_level`, `source`; unique pair |
| `allergy_classes` | `name`, `slug` unique, `description`, `cross_reacts_with jsonb` (`[{allergy_class_id, probability_pct}]`) |
| `allergy_class_generics` | `allergy_class_id`, `generic_id`; unique pair |
| `pregnancy_categories` | `generic_id`, `trimester` (null = all, 1–3 for overrides), `category` (`A B C D X N`), `lactation` (`safe caution avoid unknown`), `notes`; unique `(generic_id, COALESCE(trimester,0))` |
| `renal_cautions` | `generic_id`, `egfr_below` (null = any impairment), `level` (`caution adjust_dose avoid`), `advice` |
| `hepatic_cautions` | `generic_id`, `child_pugh_class` (`A B C`, null = any), `level`, `advice` |
| `max_daily_doses` | `generic_id`, `route_id` (null = any), `population` (`adult pediatric elderly`), `max_mg_per_day`, `max_mg_per_kg_per_day`, `max_mg_per_dose`, `min_age_months`, `max_age_months`, `notes`; unique `(generic_id, route, population, min_age)` |
| `drug_information` | `generic_id` unique, `public_slug` unique (never changed once published), `indications[_bn]`, `side_effects[_bn]`, `contraindications`, `precautions`, `patient_advice_bn`, `published_at` |
| `catalog_versions` | `version` unique (`2026.09.1`), `dgda_release_ref`, `status` (`draft released applied superseded`), `released_at`, `applied_at`, `row_counts jsonb`, `checksum_sha256`, `notes`, `applied_by` — "current" = latest `applied` |
| ＋ `catalog_import_issues` | `catalog_version_id`, `kind` (`unknown_generic unparsable_strength unknown_form duplicate_brand`), `payload jsonb`, `resolved_at`, `resolution jsonb` |

Indexes (SCHEMA §4 plus): trigram GIN on `generics.name`, `brands.name`,
`icd10_codes.title` (admin/reconcile search only — runtime search is
Meilisearch); `strengths(generic_id)`, `strengths(brand_id)`,
`drug_interactions(generic_b_id)`, `allergy_class_generics(generic_id)`,
`icd10_codes(parent_code)`.

Tenant-side tables that reference the catalog (owned by SCHEMA.md; listed
here because the reconcile job scans them): `prescription_items(generic_id, brand_id, strength_id)`,
`prescription_template_items(…)`, `custom_brands(generic_id)`,
`patient_allergies(generic_id, allergy_class_id)`, `patient_medications(generic_id, brand_id)`,
`doctor_drug_usage(…)`, `doctor_favourites(…)`, `visits.diagnoses[].icd10_code`, `patient_conditions(icd10_code)`.

---

## 3. Representative development seed

`Database\Seeders\Catalog\CatalogSampleSeeder` (`database/seeders/Catalog/`, run by `catalog:seed` and by
`catalog:migrate --seed` — ARCHITECTURE.md §4.3, CONVENTIONS.md §10) does **not** insert rows itself:
it calls the importer (`catalog:import database/seeders/Catalog/data --source=seed --version=dev.$(date)`),
so the seed exercises the same code path as a DGDA update. Data files
(`database/seeders/Catalog/data/*.csv`, UTF-8, header row):
`generics.csv`, `brands.csv` (with `manufacturer` and `presentations` columns), `forms.csv`,
`routes.csv`, `icd10.csv`, `interactions.csv`, `allergy_classes.csv`,
`allergy_class_generics.csv`, `pregnancy.csv`, `renal.csv`, `hepatic.csv`,
`max_doses.csv`, `drug_information.csv`. Counts asserted by `DevSeedTest`:
generics ≥ 40, brands ≥ 120, strengths ≥ 200, ICD codes ≥ 60, interactions ≥ 30,
allergy classes ≥ 10.

### 3.1 Generics, brands and per-generic safety data (one row per generic)

Columns: pregnancy category / lactation (Hale L-code in the CSV; the importer
maps L1–L2 → `safe`, L3 → `caution`, L4–L5 → `avoid`; "C, 3rd D" becomes two
`pregnancy_categories` rows: `trimester NULL = C`, `trimester 3 = D`); renal /
hepatic `level`; adult max mg/day (→ `max_daily_doses` row `population = adult`);
pediatric mg/kg/day (→ row `population = pediatric` with `max_mg_per_kg_per_day`,
"ped max" → `max_mg_per_day`, `×` = `min_age_months` below which the drug is
contraindicated); brands as `Brand (Manufacturer)`. Manufacturer keys: Sq = Square, Bx = Beximco, In = Incepta,
Ac = Acme, ACI, Op = Opsonin, Es = Eskayef, Re = Renata, Ar = Aristopharma, Un = Unimed,
Ra = Radiant, Po = Popular, Hc = Healthcare, GSK, Sa = Sanofi, No = Novo Nordisk,
Ly = Lilly, Pf = Pfizer, AZ, Se = Servier, MSD, Me = Merck, SMC, Nu = Nuvista, Ja = Jayson, USV, Al = Alcon, Ba = Bayer, Gl = Glenmark, Ro = Roche.

| Generic (class) | Preg/Lact | Renal / Hepatic | Adult max | Ped mg/kg/d | Brands |
|---|---|---|---|---|---|
| Paracetamol (analgesic) | B / L1 | — / adjust_dose | 4000 | 60 | Napa (Bx), Ace (Sq), Fast (Ac), Xcel (ACI), Renova (Op) |
| Ibuprofen (NSAID) | C, 3rd D / L1 | avoid / caution | 2400 | 40 | Flamex (Sq), Profen (Bx), Inflam (ACI) |
| Diclofenac (NSAID) | C, 3rd D / L2 | avoid / caution | 150 | 3 | Clofenac (Bx), Voltalin (Sq), A-Fenac (Ac) |
| Naproxen (NSAID) | C / L3 | avoid / caution | 1250 | 20 | Naprosyn (Sq), Xenapro (Un), Napro-A (Ac) |
| Ketorolac (NSAID) | C, 3rd D / L2 | avoid / caution | 40 | × <16 y | Etorac (Sq), Ketorol (Bx), Rolac (In) |
| Aspirin (antiplatelet/NSAID) | C, 3rd D / L2 | caution / caution | 4000 | × <16 y | Ecosprin (USV), Carva (Sq), Ascard (Op) |
| Tramadol (opioid) | C / L3 | adjust_dose / adjust_dose | 400 | × <12 y | Anadol (Sq), Tramal (In), Lodol (Op) |
| Omeprazole (PPI) | C / L2 | — / caution | 40 | 1 | Seclo (Sq), Losectil (Es), PPI (Ar), Omenix (In) |
| Esomeprazole (PPI) | B / L2 | — / caution | 40 | 1 | Nexum (Sq), Esonix (In), Maxpro (Re) |
| Pantoprazole (PPI) | B / L2 | — / caution | 80 | 1 | Pantonix (In), Pantobex (Bx), Panto (ACI) |
| Ranitidine (H2 blocker) | B / L2 | adjust_dose / — | 300 | 10 | Neotack (Sq), Neoceptin-R (Bx), Ranitid (Op) — Ranitid `is_active=false` |
| Domperidone (prokinetic) | C / L1 | caution / avoid | 30 | 0.75 | Omidon (In), Motigut (Sq), Domperon (Op) |
| Ondansetron (antiemetic) | B / L2 | — / adjust_dose | 32 | 0.45 | Emistat (Ar), Anset (Bx), Ondron (Op) |
| Hyoscine butylbromide (antispasmodic) | C / L3 | — / — | 60 | 1.5 | Butapan (In), Hyosin (Sq), Buscopan (Sa) |
| Amoxicillin (penicillin) | B / L1 | adjust_dose / — | 3000 | 90 | Moxacil (Sq), Fimoxyl (Bx), Amoxin (Ar), Tycil (In) |
| Amoxicillin + Clavulanic acid (penicillin; components amoxicillin) | B / L1 | adjust_dose / caution | 3000 | 90 | Moxaclav (Sq), Fimoxyclav (Bx), Augmentin (GSK) |
| Cefixime (cephalosporin 3G) | B / L2 | adjust_dose / — | 400 | 8 | Cef-3 (Sq), Denvar (Bx), Rofixim (Op) |
| Cefuroxime (cephalosporin 2G) | B / L1 | adjust_dose / — | 1000 | 30 | Cefotil (Sq), Kilbac (Bx), Sefur (Op) |
| Ceftriaxone (cephalosporin 3G, inj) | B / L1 | caution / caution | 4000 | 100 | Ceftron (Sq), Traxon (Bx), Trizon (Op) |
| Azithromycin (macrolide) | B / L2 | — / caution | 500 | 10 | Zimax (Sq), Azithrocin (Bx), Tridosil (In), Azin (Ar) |
| Doxycycline (tetracycline) | D / L3 | — / caution | 200 | 4 (× <8 y) | Doxin (Sq), Doxicap (Bx), Dyna (Ac) |
| Ciprofloxacin (fluoroquinolone) | C / L3 | adjust_dose / — | 1500 | 30 | Ciprocin (Sq), Neofloxin (Bx), Ciprox (Op) |
| Levofloxacin (fluoroquinolone) | C / L3 | adjust_dose / — | 750 | 20 | Levoxin (Sq), Levox (Bx), Leflox (ACI) |
| Moxifloxacin ophthalmic (fluoroquinolone) | C / L3 | — / — | — | — | Vigamox (Al), Moxiflox (In), Optimox (Po) |
| Metronidazole (nitroimidazole) | B / L2 | — / adjust_dose | 2400 | 40 | Flagyl (Sa), Filmet (Sq), Amodis (Bx), Metryl (Op) |
| Cotrimoxazole (sulfonamide; components sulfamethoxazole, trimethoprim) | C, term D / L3 | adjust_dose / caution | 1920 | 40 | Cotrim (Sq), Septran (GSK), Bactrim (Ro) |
| Nitrofurantoin (urinary antiseptic) | B, term avoid / L2 | avoid / caution | 400 | 7 | Nitrofur (Op), Uritoin (In) |
| Fluconazole (azole) | C / L2 | adjust_dose / caution | 400 | 12 | Flugal (Sq), Lucon (In), Omastin (Bx) |
| Clotrimazole topical (azole) | B / L1 | — / — | — | — | Canesten (Ba), Candid (Gl), Fungidal (Sq) |
| Albendazole (anthelmintic) | C / L3 | — / caution | 800 | 15 | Almex (Sq), Alben (Op), Sintel (Bx) |
| Cetirizine (antihistamine) | B / L2 | adjust_dose / caution | 10 | 0.25 | Alatrol (Sq), Artizin (In), Cetizin (Bx) |
| Loratadine (antihistamine) | B / L1 | caution / caution | 10 | — (ped max 10) | Loratin (Sq), Pretin (Bx), Oradin (ACI) |
| Fexofenadine (antihistamine) | C / L2 | adjust_dose / — | 180 | — (ped max 60) | Fexo (Sq), Fenadin (Bx), Telfast (Sa) |
| Chlorpheniramine (antihistamine, sedating) | B / L3 | — / caution | 24 | 0.35 | Histacin (Ja), Piriton (GSK) |
| Montelukast (LTRA) | B / L3 | — / caution | 10 | — (ped max 5) | Montene (Sq), Monas (Ac), Provair (Un), Montair (In) |
| Salbutamol (SABA) | C / L1 | — / — | 16 (oral) | 0.3 (oral) | Ventolin (GSK), Sultolin (Sq), Azmasol (Bx) |
| Salmeterol + Fluticasone (ICS/LABA inhaler) | C / L2 | — / — | — | — | Seretide (GSK), Bexitrol-F (Bx), Ticamet (Sq) |
| Budesonide nebule (ICS) | B / L1 | — / — | 4 | — (ped max 2) | Pulmicort (AZ), Budecort (Bx), Nebozide (Sq) |
| Xylometazoline nasal (decongestant) | C / L3 | — / — | — | × <2 y | Otrivin (GSK), Rynex (Bx), Xylonet (Sq) |
| Prednisolone (corticosteroid) | C / L2 | — / — | 80 | 2 | Cortan (Sq), Precodil (Op), Deltasone (Bx) |
| Dexamethasone (corticosteroid) | C / L3 | — / — | 16 | 0.6 | Oradexon (Nu), Decason (Op), Dexo (Sq) |
| Betamethasone topical (corticosteroid) | C / L3 | — / — | — | — | Betnovate (GSK), Betaderm (Sq), Betasol (Op) |
| Metformin (biguanide) | B / L1 | avoid <30 eGFR, adjust_dose <45 / avoid | 2550 | — (ped max 2000, ≥10 y) | Comet (Sq), Metfor (Op), Informet (In), Bigmet (Ar) |
| Glimepiride (sulfonylurea) | C / L4 | caution / caution | 8 | × <18 y | Amaryl (Sa), Secrin (Sq), Glimex (Op) |
| Gliclazide (sulfonylurea) | C / L4 | caution / caution | 320 | × <18 y | Diamicron (Se), Glizid (Bx), Dimerol (Sq) |
| Sitagliptin (DPP-4) | B / L3 | adjust_dose / — | 100 | × <18 y | Januvia (MSD), Zita (Sq), Sitagen (In) |
| Insulin human 70/30 (insulin) | B / L1 | caution / caution | — | — | Mixtard 30 HM (No), Humulin 70/30 (Ly), Ansulin 30/70 (Sq) |
| Amlodipine (CCB) | C / L3 | — / caution | 10 | 0.3 | Amdocal (Bx), Camlodin (Sq), Amlopin (In) |
| Losartan (ARB) | D / L3 | caution / caution | 100 | 1.4 | Angilock (Sq), Osartil (In), Losardil (ACI) |
| Enalapril (ACEI) | D / L2 | caution / — | 40 | 0.6 | Envas (Bx), Enaril (Sq) |
| Bisoprolol (beta blocker) | C / L3 | caution / caution | 20 | — | Concor (Me), Bisocor (Sq), Bisopro (In) |
| Atenolol (beta blocker) | D / L3 | adjust_dose / — | 100 | 2 | Tenormin (AZ), Tenoloc (Op), Atenol (Sq) |
| Frusemide (loop diuretic) | C / L3 | caution / caution | 600 | 6 | Lasix (Sa), Fusid (Sq), Frusin (Op) |
| Spironolactone (K-sparing) | C / L2 | avoid / caution | 400 | 3.3 | Aldactone (Pf), Spirolac (Sq), Spirocard (In) |
| Atorvastatin (statin) | X / L3 | — / avoid | 80 | — (ped max 20, ≥10 y) | Atova (Sq), Anzitor (In), Tiginor (Ar) |
| Rosuvastatin (statin) | X / L3 | adjust_dose / avoid | 40 | — (ped max 20) | Rosuva (Sq), Rovasta (In), Rosutin (Bx) |
| Clopidogrel (antiplatelet) | B / L3 | — / caution | 300 | 1 | Plavix (Sa), Clopid (Sq), Clotin (In) |
| Warfarin (anticoagulant) | X / L2 | caution / caution | — | — | Warfin (Sq), Coumadin (BMS), Marevan (In) |
| Levothyroxine (thyroid) | A / L1 | — / — | 0.3 | 0.006 | Thyrox (Sq), Eltroxin (GSK), Thyrin (In) |
| Amitriptyline (TCA) | C / L2 | — / caution | 150 | 1 | Tryptin (Sq), Amilin (Bx), Amit (Op) |
| Sertraline (SSRI) | C / L2 | — / adjust_dose | 200 | — (ped max 200) | Zoloft (Pf), Serlift (Sq), Setra (In) |
| Clonazepam (benzodiazepine) | D / L3 | — / caution | 20 | 0.2 | Rivotril (Ro), Cloron (Sq), Clonium (Bx) |
| Diazepam (benzodiazepine) | D / L3 | — / adjust_dose | 40 | 0.8 | Sedil (Sq), Valium (Ro), Diapam (Op) |
| Allopurinol (xanthine oxidase inhibitor) | C / L2 | adjust_dose / caution | 900 | 20 | Zyloric (GSK), Loric (Sq), Alopurin (Op) |
| Oral rehydration salts (electrolyte) | A / L1 | — / — | — | — | Orsaline-N (SMC), Rice Saline (SMC), Glucolyte (Op) |
| Zinc sulfate (mineral) | A / L1 | — / — | 40 | — (ped max 20) | Zinc-B (Sq), Baby Zinc (Sq), Zif (Op) |
| Calcium carbonate + Vitamin D3 (supplement) | A / L1 | caution / — | 1500 | — | Calbo-D (Sq), Coralcal-D (Ra), Calcin-D (Op) |
| Ferrous sulfate (haematinic) | A / L1 | — / — | 600 | 6 | Feofol (Sq), Fefol (GSK), Zif-CI (Op) |

### 3.2 Presentations (`brands.csv` → `presentations` column)

Format `form:label[:pack]` separated by `;`. The importer parses labels
(`amount_value`, `amount_unit`, `per_value`, `per_unit`) and computes
`strength_mg` / `per_ml`. Examples (every brand of a generic gets its
generic's default set unless overridden):

| Generic | presentations |
|---|---|
| Paracetamol | `tab:500 mg; tab:665 mg XR; syr:120 mg/5 ml:100 ml; oral_drop:80 mg/ml:15 ml; supp:500 mg; inj:1 g/100 ml:100 ml` |
| Amoxicillin | `cap:250 mg; cap:500 mg; susp:125 mg/5 ml:100 ml; susp:250 mg/5 ml:100 ml; oral_drop:100 mg/ml:15 ml` |
| Azithromycin | `tab:250 mg; tab:500 mg; susp:200 mg/5 ml:15 ml; susp:200 mg/5 ml:30 ml` |
| Salbutamol | `tab:2 mg; tab:4 mg; syr:2 mg/5 ml:100 ml; inh_mdi:100 mcg/actuation:200; neb:2.5 mg/2.5 ml:20; neb:5 mg/2.5 ml:20` |
| Salmeterol + Fluticasone | `inh_mdi:25/125 mcg:120; inh_mdi:25/250 mcg:120; inh_dpi:50/250 mcg:60; inh_dpi:50/500 mcg:60` |
| Metformin | `tab:500 mg; tab:850 mg; tab:1000 mg; tab:500 mg XR; tab:1000 mg XR` |
| Insulin human 70/30 | `insulin:100 IU/ml:10 ml; insulin:100 IU/ml:3 ml pen` |
| Moxifloxacin ophthalmic | `eye_drop:0.5 %:5 ml` |
| Xylometazoline | `nasal_drop:0.05 %:10 ml; nasal_drop:0.1 %:10 ml; nasal_spray:0.1 %:10 ml` |
| Clotrimazole | `cream:1 %:20 g; sol:1 %:20 ml; pessary:100 mg; pessary:500 mg` |
| Ceftriaxone | `inj:250 mg; inj:500 mg; inj:1 g; inj:2 g` |
| ORS | `sachet:ORS 20.5 g` |
| Others | `tab:<usual strengths>` (e.g. Cetirizine `tab:10 mg; syr:5 mg/5 ml:60 ml; oral_drop:2.5 mg/ml:15 ml`; Omeprazole `cap:20 mg; cap:40 mg; inj:40 mg`) |

Forms/routes come from `forms.csv`/`routes.csv` with the code lists of §2.

### 3.3 ICD-10 codes with plain-language / Bangla aliases (`icd10.csv`, 68 rows)

| Code | Title | Aliases |
|---|---|---|
| A01.0 | Typhoid fever | typhoid, টাইফয়েড |
| A09 | Infectious gastroenteritis and colitis | diarrhoea, diarrhea, loose motion, ডায়রিয়া, পাতলা পায়খানা |
| A90 | Dengue fever | dengue, ডেঙ্গু |
| B01.9 | Varicella without complication | chicken pox, জলবসন্ত |
| B18.1 | Chronic viral hepatitis B | hep b, hbv, হেপাটাইটিস বি |
| B35.4 | Tinea corporis | ringworm, দাদ |
| B86 | Scabies | scabies, খোস পাঁচড়া, চুলকানি |
| D50.9 | Iron deficiency anaemia | anemia, anaemia, রক্তশূন্যতা |
| E03.9 | Hypothyroidism | hypothyroid, thyroid low, থাইরয়েড |
| E05.9 | Thyrotoxicosis | hyperthyroid |
| E11.9 | Type 2 diabetes mellitus without complications | sugar, diabetes, dm, t2dm, ডায়াবেটিস, সুগার |
| E11.2 | Type 2 DM with renal complications | diabetic nephropathy |
| E11.4 | Type 2 DM with neurological complications | diabetic neuropathy |
| E66.9 | Obesity | obese, overweight, মোটা |
| E78.5 | Hyperlipidaemia | cholesterol, lipid, লিপিড |
| F32.9 | Depressive episode | depression, বিষণ্ণতা |
| F41.1 | Generalised anxiety disorder | anxiety, tension, দুশ্চিন্তা |
| F51.0 | Insomnia | sleep problem, ঘুম হয় না |
| G43.9 | Migraine | migraine, মাইগ্রেন |
| G44.2 | Tension-type headache | tension headache |
| H10.9 | Conjunctivitis | red eye, eye infection, চোখ ওঠা |
| H66.9 | Otitis media | ear infection, কানে ব্যথা |
| I10 | Essential hypertension | bp, high pressure, htn, hypertension, প্রেসার, উচ্চ রক্তচাপ |
| I20.9 | Angina pectoris | angina, chest pain, বুকে ব্যথা |
| I25.9 | Chronic ischaemic heart disease | ihd, heart disease, হার্টের অসুখ |
| I50.9 | Heart failure | hf, chf |
| I63.9 | Cerebral infarction | stroke, স্ট্রোক |
| J00 | Acute nasopharyngitis | common cold, cold, সর্দি |
| J02.9 | Acute pharyngitis | sore throat, গলা ব্যথা |
| J03.9 | Acute tonsillitis | tonsil, টনসিল |
| J06.9 | Acute upper respiratory infection | urti, cold cough, সর্দি কাশি |
| J18.9 | Pneumonia | pneumonia, নিউমোনিয়া |
| J20.9 | Acute bronchitis | bronchitis |
| J30.4 | Allergic rhinitis | allergy, hay fever, sneezing, হাঁচি |
| J44.9 | COPD | copd |
| J45.9 | Asthma | asthma, হাঁপানি, শ্বাসকষ্ট |
| K02.9 | Dental caries | cavity, দাঁতের পোকা |
| K04.7 | Periapical abscess | tooth abscess, দাঁতে পুঁজ |
| K05.1 | Chronic gingivitis | gum disease, মাড়ি |
| K21.9 | Gastro-oesophageal reflux disease | gerd, acidity, reflux, heartburn, বুক জ্বালা |
| K25.9 | Gastric ulcer | ulcer, আলসার |
| K29.7 | Gastritis | gastric, gastritis, গ্যাস্ট্রিক |
| K30 | Functional dyspepsia | indigestion, বদহজম |
| K59.0 | Constipation | constipation, কোষ্ঠকাঠিন্য |
| K64.9 | Haemorrhoids | piles, পাইলস |
| K76.9 | Liver disease, unspecified | liver, লিভার |
| K80.2 | Calculus of gallbladder | gall stone, পিত্তথলির পাথর |
| L20.9 | Atopic dermatitis | eczema, একজিমা |
| L50.9 | Urticaria | hives, allergy rash, চাকা চাকা |
| L70.0 | Acne vulgaris | acne, pimple, ব্রণ |
| M10.9 | Gout | gout, uric acid, বাত |
| M17.9 | Gonarthrosis | knee oa, knee pain, হাঁটু ব্যথা |
| M54.5 | Low back pain | back pain, lbp, কোমর ব্যথা |
| M79.1 | Myalgia | body ache, গায়ে ব্যথা |
| N10 | Acute pyelonephritis | kidney infection |
| N18.9 | Chronic kidney disease | ckd, kidney disease, কিডনি |
| N20.0 | Calculus of kidney | kidney stone, পাথর |
| N39.0 | Urinary tract infection | uti, urine infection, প্রস্রাবে জ্বালা |
| N92.0 | Excessive menstruation | heavy period, মাসিক বেশি |
| N94.6 | Dysmenorrhoea | period pain, মাসিকে ব্যথা |
| O21.0 | Mild hyperemesis gravidarum | pregnancy vomiting |
| R05 | Cough | cough, কাশি |
| R10.4 | Abdominal pain | stomach pain, পেট ব্যথা |
| R11 | Nausea and vomiting | vomiting, বমি |
| R42 | Dizziness | vertigo, মাথা ঘোরা |
| R50.9 | Fever, unspecified | fever, জ্বর |
| R51 | Headache | headache, মাথা ব্যথা |
| R53 | Malaise and fatigue | weakness, দুর্বলতা |
| Z33.1 | Pregnant state, incidental | pregnant, গর্ভবতী |
| Z39.1 | Care of lactating mother | lactating, breastfeeding, বুকের দুধ |

### 3.4 Interactions (`interactions.csv`, 38 rows)

| A + B | Severity | Mechanism / management |
|---|---|---|
| Warfarin + Aspirin | major | additive bleeding; avoid, monitor INR |
| Warfarin + Ibuprofen / Diclofenac / Naproxen / Ketorolac (4 rows) | major | GI bleeding + INR ↑; avoid |
| Warfarin + Azithromycin | moderate | INR ↑; monitor |
| Warfarin + Ciprofloxacin | major | CYP1A2 inhibition, INR ↑ |
| Warfarin + Metronidazole | major | CYP2C9 inhibition; reduce dose, monitor |
| Warfarin + Fluconazole | major | CYP2C9 inhibition |
| Warfarin + Cotrimoxazole | major | CYP2C9 inhibition |
| Warfarin + Levothyroxine | moderate | ↑ anticoagulant effect |
| Warfarin + Omeprazole | minor | small INR ↑ |
| Clopidogrel + Omeprazole / Esomeprazole (2) | moderate | CYP2C19 inhibition reduces activation; prefer pantoprazole |
| Aspirin + Ibuprofen | moderate | ibuprofen blocks antiplatelet effect; separate doses |
| Ketorolac + Ibuprofen / Diclofenac / Naproxen / Aspirin (4) | contraindicated | NSAID combination; label contraindication |
| Ibuprofen + Diclofenac | major | duplicate NSAID; GI/renal risk |
| Losartan + Spironolactone | major | hyperkalaemia; monitor K+ |
| Enalapril + Spironolactone | major | hyperkalaemia |
| Cotrimoxazole + Spironolactone | major | hyperkalaemia |
| Losartan + Ibuprofen | moderate | renal impairment, ↓ effect |
| Enalapril + Ibuprofen | moderate | renal impairment |
| Frusemide + Ibuprofen | moderate | ↓ diuretic effect, renal risk |
| Metformin + Ciprofloxacin | moderate | dysglycaemia |
| Glimepiride + Ciprofloxacin | moderate | hypoglycaemia |
| Glimepiride + Fluconazole | moderate | CYP2C9 inhibition, hypoglycaemia |
| Metformin + Prednisolone | moderate | hyperglycaemia |
| Ciprofloxacin + Prednisolone | moderate | tendon rupture risk |
| Levofloxacin + Prednisolone | moderate | tendon rupture risk |
| Ciprofloxacin + Calcium carbonate | moderate | chelation; separate by 2 h |
| Doxycycline + Calcium carbonate | moderate | chelation |
| Doxycycline + Ferrous sulfate | moderate | chelation |
| Levothyroxine + Calcium carbonate | moderate | absorption ↓; separate 4 h |
| Levothyroxine + Ferrous sulfate | moderate | absorption ↓ |
| Levothyroxine + Omeprazole | minor | absorption ↓ |
| Tramadol + Sertraline | major | serotonin syndrome, seizures |
| Tramadol + Amitriptyline | major | serotonin syndrome, seizures |
| Sertraline + Amitriptyline | major | serotonin syndrome |
| Tramadol + Clonazepam / Diazepam (2) | major | respiratory depression |
| Azithromycin + Ondansetron | major | QT prolongation |
| Levofloxacin + Ondansetron | major | QT prolongation |
| Domperidone + Fluconazole | major | QT + CYP3A4; avoid |
| Domperidone + Azithromycin | major | QT prolongation |
| Atorvastatin + Fluconazole | moderate | CYP3A4 ↑ statin exposure |
| Bisoprolol + Salbutamol | moderate | antagonism |
| Atenolol + Salbutamol | moderate | antagonism |
| Allopurinol + Amoxicillin | moderate | rash incidence ↑ |
| Amlodipine + Atorvastatin | minor | ↑ statin exposure |

### 3.5 Allergy classes (`allergy_classes.csv`, 12) and members

`penicillins` (amoxicillin, amoxiclav; cross → cephalosporins), `cephalosporins`
(cefixime, cefuroxime, ceftriaxone; cross → penicillins), `sulfonamides`
(cotrimoxazole), `nsaids` (ibuprofen, diclofenac, naproxen, ketorolac, aspirin;
cross → none), `fluoroquinolones` (ciprofloxacin, levofloxacin, moxifloxacin),
`macrolides` (azithromycin), `tetracyclines` (doxycycline), `azoles`
(fluconazole, clotrimazole), `nitroimidazoles` (metronidazole),
`benzodiazepines` (clonazepam, diazepam), `opioids` (tramadol), `statins`
(atorvastatin, rosuvastatin), `ace_inhibitors` (enalapril; cross → `arbs`
angioedema), `arbs` (losartan).

### 3.6 Drug information (`drug_information.csv`)

One row per generic with `slug = generics.slug`, short bn/en `uses`,
`side_effects`, `warnings`, `interactions_summary` (2–4 sentences each;
placeholder text marked `[dev seed]` is acceptable for development).

---
## 4. Meilisearch indexes

Meilisearch 1.53 at `MEILISEARCH_HOST`, master key in `MEILISEARCH_KEY`
(dev: see memory notes). Catalog indexes are **not** Scout model indexes — a
search document is a join of brand × strength × generic, and catalog models
are read-only — so `App\Domain\Catalog\Search\CatalogSearchIndexer` talks to
`Meilisearch\Client` directly. The tenant custom-brand index *is* a Scout
model index (PRESCRIPTION.md §3.2). Settings live in
`config/catalog.php['search']` and are applied by the indexer (catalog) and by
`scout.meilisearch.index-settings` (tenant), so `scout:sync-index-settings`
and `catalog:index-search` produce identical behaviour.

Index uids always carry the Scout prefix: `CatalogSearchIndexer::uid('catalog_drugs')` returns
`config('scout.prefix').'catalog_drugs'` — empty prefix at runtime (`catalog_drugs`), `test{N}_catalog_drugs`
under the per-engineer test env (CONVENTIONS.md §6.1) — and every consumer (`DrugSearchService`, ICD search,
tests) asks the indexer for the uid instead of writing the literal. `t{tenant_id}_custom_brands` gets the
same prefix automatically through `TenantModel::searchableAs()`.

### 4.1 `catalog_drugs`

Documents: PRESCRIPTION.md §3.1 — `s{strength_id}` presentations (SCHEMA §5.6) plus
`g{generic_id}` generic documents (`doc_type = generic`, this document's
addition so "prescribe by generic" is one keystroke). Settings:

```php
'catalog_drugs' => [
  'searchableAttributes' => ['brand_name', 'generic_name', 'generic_aliases', 'brand_aliases', 'strength_label', 'form', 'manufacturer', 'label'],
  'filterableAttributes' => ['source', 'doc_type', 'generic_id', 'brand_id', 'dosage_form_id', 'route_id', 'form_code', 'route_code',
                             'strength_mg', 'is_active', 'is_controlled', 'therapeutic_class'],
  'sortableAttributes'   => ['popularity', 'brand_name'],
  'rankingRules'         => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'popularity:desc'],
  'typoTolerance'        => ['enabled' => true, 'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
                            'disableOnAttributes' => ['strength_label'], 'disableOnWords' => ['od', 'bd', 'tds', 'qds', 'sos']],
  'synonyms'             => ['acetaminophen' => ['paracetamol'], 'নাপা' => ['napa'], 'প্যারাসিটামল' => ['paracetamol'],
                            'সেকলো' => ['seclo'], 'ওমিপ্রাজল' => ['omeprazole'], 'অ্যামোক্সিসিলিন' => ['amoxicillin'],
                            'এজিথ্রোমাইসিন' => ['azithromycin'], 'মেটফরমিন' => ['metformin'], 'সালবিউটামল' => ['salbutamol'],
                            'সিরাপ' => ['syrup'], 'ট্যাবলেট' => ['tablet'], 'ক্যাপসুল' => ['capsule'], 'ইনজেকশন' => ['injection'],
                            'ড্রপ' => ['drops'], 'ইনহেলার' => ['inhaler'], 'xr' => ['extended release'], 'sr' => ['sustained release']],
  'localizedAttributes'  => [['attributePatterns' => ['generic_aliases', 'brand_aliases', 'generic_name', 'brand_name'], 'locales' => ['eng', 'ben']]],
  'separatorTokens'      => ['/', '+'],
  'stopWords'            => [],
  'distinctAttribute'    => null,
  'pagination'           => ['maxTotalHits' => 200],
],
```

`popularity` on presentations is the brand's `popularity`; for generic docs it
is `max(brand popularity) + 10` so the generic row is reachable but never
outranks an exact brand hit thanks to `exactness` preceding the sort.

### 4.2 `t{tenant_id}_custom_brands`

Same `searchableAttributes` (minus `label`/`brand_aliases`), `filterableAttributes`
`['source','doc_type','generic_id','dosage_form_id','route_id','form_code','route_code','strength_mg','is_active','review_status','promoted_to_master']`,
`sortableAttributes ['use_count']`, ranking rules ending in `use_count:desc`
instead of `popularity:desc`, same typo tolerance and synonyms.
Registered at boot by `App\Domain\Catalog\Search\CustomBrandIndexSettings::register()`:
`config(['scout.meilisearch.index-settings.'.(new CustomBrand)->searchableAs() => CustomBrandIndexSettings::array()])`
before `scout:sync-index-settings` runs inside `tenants:sync-search-settings {--tenant=*}`; provisioning
applies the same array through `App\Domain\Catalog\Search\MeilisearchIndexes::ensureTenantIndexes()` (ARCHITECTURE.md §4.4).

### 4.3 `catalog_icd10`

Document: `{ id: "E11.9", code, parent_code, chapter, block, title, title_bn, aliases[], is_billable, popularity }` (`popularity` = tenant-independent usage rank maintained by the indexer from `row_counts`-independent seed data; per-doctor ranking is applied in the app).

```php
'catalog_icd10' => [
  'searchableAttributes' => ['code', 'title', 'aliases', 'title_bn'],
  'filterableAttributes' => ['chapter', 'is_billable', 'parent_code'],
  'sortableAttributes'   => ['popularity'],
  'rankingRules'         => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'popularity:desc'],
  'typoTolerance'        => ['enabled' => true, 'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8], 'disableOnAttributes' => ['code']],
  'synonyms'             => ['sugar' => ['diabetes'], 'dm' => ['diabetes'], 'pressure' => ['hypertension'], 'bp' => ['hypertension'],
                            'gastric' => ['gastritis', 'reflux'], 'acidity' => ['reflux'], 'piles' => ['haemorrhoids'],
                            'cold' => ['nasopharyngitis', 'upper respiratory'], 'loose motion' => ['gastroenteritis'],
                            'সুগার' => ['diabetes'], 'প্রেসার' => ['hypertension'], 'গ্যাস্ট্রিক' => ['gastritis'], 'জ্বর' => ['fever'],
                            'কাশি' => ['cough'], 'সর্দি' => ['cold'], 'মাথাব্যথা' => ['headache'], 'পাইলস' => ['haemorrhoids']],
  'localizedAttributes'  => [['attributePatterns' => ['title_bn', 'aliases'], 'locales' => ['ben', 'eng']]],
  'pagination'           => ['maxTotalHits' => 100],
],
```

### 4.4 Indexer and zero-downtime rebuild

```php
namespace App\Domain\Catalog\Search;

final class CatalogSearchIndexer {
    public function __construct(private readonly \Meilisearch\Client $client) {}
    public function uid(string $index): string { return config('scout.prefix').$index; }   // 'catalog_drugs' | 'catalog_icd10'
    public function rebuild(string $index): void
    {
        $index = $this->uid($index);
        $next = $index.'_next';
        $this->client->createIndex($next, ['primaryKey' => 'id']);
        $this->client->index($next)->updateSettings(config("catalog.search.$index"));
        foreach ($this->documents($index)->chunk(2000) as $chunk) {              // lazy generator: strengths ⋈ brands ⋈ generics ⋈ forms ⋈ routes (+ one g{id} doc per generic)
            $task = $this->client->index($next)->addDocumentsInBatches($chunk->all(), 2000, 'id');
        }
        $this->client->waitForTask(end($task)['taskUid'], timeoutInMs: 600_000, intervalInMs: 500);
        $this->client->index($next)->swapIndexes([['indexes' => [$index, $next]]]);  // atomic swap
        $this->client->deleteIndex($next);
    }
    public function upsert(string $index, iterable $docs): void { $this->client->index($this->uid($index))->updateDocumentsInBatches([...$docs], 2000, 'id'); }
}
```

Commands (`App\Console\Commands\Catalog\*`): `catalog:index-search {--index=all} {--fresh}` (rebuild via swap),
`catalog:index-search --changed-since={version}` (incremental `upsert` of the
ids an import touched, plus `is_active=false` flips — documents are never
deleted, inactive ones are filtered by the query). Bulk import time for
≈ 60 k presentations: under 2 minutes on the dev box.

---

## 5. Import pipeline for DGDA updates

`php artisan catalog:import {path} {--source=dgda|seed|manual} {--version=} {--full} {--dry-run} {--force} {--reindex}`
(`App\Console\Commands\Catalog\ImportCommand`) → `App\Domain\Catalog\Import\CatalogImporter::run(ImportRequest $r): ImportReport`
(`ImportRequest` is an `App\Domain\Catalog\Data` DTO), always inside `CatalogWriteContext::run()`.

1. **Read & fingerprint.** Files (CSV/XLSX; DGDA's registered-product list is
   one sheet) are hashed; if a `catalog_versions` row with that `checksum_sha256` already exists
   the run exits "already imported" unless `--force`.
2. **Map rows** with a source-specific `RowMapper` (`DgdaRowMapper`, `SeedRowMapper`)
   into `ImportRow { manufacturer, brand, generic_text, strength_label, form_text, route_text, pack_size, dar_number, status }`.
3. **Normalise.** `GenericResolver::resolve(string $text): ?Generic` — lower-case, strip salts
   (`sodium`, `hydrochloride`… kept in `salt`), match `generics.slug` then `aliases`,
   then trigram ≥ 0.92. Unknown → generic created with `needs_review = true`
   **and** a `catalog_import_issues(kind: unknown_generic)` row (a brand must
   never be imported without a generic — C6 at catalog level). Combinations
   (`amoxicillin + clavulanic acid`) resolve to a combination generic whose
   `components` list the molecules. `StrengthLabelParser::parse('120 mg/5 ml')`
   → `{amount_value:120, amount_unit:'mg', per_value:5, per_unit:'ml'}`; failures → `unparsable_strength` issue, row skipped.
   `FormMapper` maps DGDA form text (`Tablet`, `Tab.`, `Film coated tablet`) to `dosage_forms.code`; unknown → `unknown_form` issue.
   `strength_mg` / `per_ml` / `pack_size_value` are computed here (`500 mg` tab → 500; `120 mg/5 ml` → per_ml 24; `100 IU/ml` → per_ml 100 in IU).
4. **Upsert by canonical key** (one `INSERT … ON CONFLICT DO UPDATE` per table, chunked 1000):
   generics `slug`; brands `(lower(name), generic_id)` with `slug = {brand}-{generic}` (suffix `-2` on collision);
   strengths `(brand_id, dosage_form_id, strength_label)`. Touched rows get the run's
   `catalog_version_id`. Updates only touch columns the source provides;
   `popularity`, `aliases`, `name_bn` are never overwritten by DGDA rows.
   Re-running the same file yields zero changes.
5. **Deactivate** (only with `--full`, meaning "this file is the complete DGDA
   list"): rows of the source not present in the file get `is_active = false`,
   `discontinued_at = now()`. Nothing is deleted, ever (C3). Partial files never deactivate.
6. **Version.** The run starts by inserting `catalog_versions` (`version`,
   `dgda_release_ref`, `status = draft`, `checksum_sha256`) and ends by setting
   `status = applied`, `applied_at`, `applied_by`, `row_counts` (previous
   `applied` → `superseded`). `--dry-run` prints the would-be counts and rolls back.
7. **After commit:** bump cache (`App\Domain\Catalog\Services\CatalogCache::bumpVersion()` — the key prefix
   changes, old keys expire), `catalog:index-search --changed-since`, and
   dispatch `App\Domain\Catalog\Events\CatalogVersionPublished` (consumed by SaaS: super-admin notification
   with stats and open issues — ARCHITECTURE.md §5.4). Interactions/cautions/max-dose/pregnancy/ICD files follow the
   same pipeline with their own mappers and canonical keys
   (`(generic_a, generic_b)`, `generic_id`, `code`).

Idempotency proof: `ImportIdempotencyTest` (§9) imports the seed twice and
asserts identical row counts, that no row's `updated_at` or `catalog_version_id` moved to the second version,
and a second version whose `row_counts` deltas are all zero.

---

## 6. Nightly `catalog:reconcile`

Scheduled 02:00 (ARCHITECTURE.md §4.7, registered by `App\Domain\Catalog\Schedule` — `tenants:backup` owns
02:30): the `catalog:reconcile` command runs in central context and fans out one
`App\Domain\Catalog\Jobs\ReconcileCatalogReferences` per active tenant
(`TenantAware`, queue `default` — there is no `maintenance` queue, ARCHITECTURE.md §4.6; `WithoutOverlapping`). Manual:
`catalog:reconcile {--tenant=} {--since=}`.

1. Load id sets from the catalog once per run (`generics`, `brands`, `strengths`,
   `allergy_classes`, `icd10_codes.code` with `is_active`) into memory (≈ 100 k ints).
2. For each tenant schema, for each `(table, column)` in the scan list of §2,
   stream `SELECT DISTINCT column, count(*), min(id), max(id) … GROUP BY column`
   and classify: **orphan** (id not in catalog), **inactive** (id exists,
   `is_active = false`), **renamed** (for `prescription_items`/`custom_brands`:
   snapshot `generic_name` ≠ live name — informational, proves snapshotting works),
   **triple_mismatch** (`strength_id` not under `brand_id` / `generic_id`).
3. Write one row per `(tenant, table, column)` to `public.catalog_reconciliation_reports`
   (SCHEMA §2.12: `run_id` ULID, `tenant_id`, `catalog_version_id`, `table_name`,
   `column_name`, `checked_count`, `orphan_count`, `sample_ids`, `status`,
   `resolved_at`, `resolved_by_super_admin_id`) — `status = clean` when nothing
   was found. `status` values `inactive_found`, `renamed_found` (SCHEMA §2.12)
   and a `details jsonb` column holding `{kind: {ref_id: row_count}}` so triage
   can see *which* ids are affected. Model `App\Models\Central\CatalogReconciliationReport`.
4. **Never** updates or deletes tenant clinical rows. The only side effects
   allowed: refresh the tenant's custom-brand search documents whose generic
   name changed (`CustomBrand::searchable()`), and set
   `custom_brands.is_active = false` with `review_note = 'generic missing in catalog {version}'`
   when the generic vanished (the brand disappears from autocomplete and
   `CustomBrandLinkCheck` blocks any draft still holding it).
5. Summary event `App\Domain\Catalog\Events\CatalogReconciliationCompleted(runId, tenants, orphans, inactive)`
   → SaaS module: super-admin dashboard tile + email when `orphans > 0` (ARCHITECTURE.md §5.4).

Runtime for 200 tenants × 1 M items: under 10 minutes (index scans only).

---

## 7. `CatalogIdExists` validation rule

```php
namespace App\Domain\Catalog\Rules;

final class CatalogIdExists implements \Illuminate\Contracts\Validation\ValidationRule
{
    /** @param 'generics'|'brands'|'strengths'|'allergy_classes'|'icd10_codes' $table */
    public function __construct(private readonly string $table, private readonly bool $requireActive = true) {}

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $row = app(\App\Domain\Catalog\Services\CatalogCache::class)->row($this->table, $value);   // Redis read-through, version-keyed (catalog:{ver}:…, PRESCRIPTION.md §5.6)
        if ($row === null) { $fail("The selected $attribute does not exist in the drug catalog."); return; }
        if ($this->requireActive && ! $row['is_active']) { $fail("The selected $attribute is discontinued."); }
    }
}
final class StrengthBelongsToBrand implements DataAwareRule, ValidationRule { /* strength.brand_id === brand_id && strength.generic_id === generic_id */ }
```

Used in every FormRequest that writes a catalog id: `DraftRequest` (items,
allergies, medications), `CustomBrandRequest` (`generic_id` — required, exists,
active), `TemplateRequest`, `PatientAllergyRequest`, `PatientMedicationRequest`.
`icd10_codes` is validated by `code` (string key). A macro
`Rule::catalog('generics')` returns the rule for brevity.

---

## 8. Custom-brand promotion to master

States on `custom_brands.review_status` (SCHEMA): `pending` (default on create —
creation *is* submission to the queue) → `approved` | `rejected` → `promoted`
once master rows exist. `promoted_to_master` becomes `true` only then.

| Step | Actor | Endpoint / action |
|---|---|---|
| Create | tenant doctor/admin | `POST /panel/custom-brands` (`routes/panel/catalog.php`, `panel.catalog.custom-brands.store`, `App\Domain\Catalog\Actions\CreateCustomBrand`) `{brand_name, manufacturer, generic_id (required, CatalogIdExists), strength, dosage_form_id, route_id}` → `generic_name`/`form`/`route` snapshotted from the catalog; usable immediately (linked to a generic); `App\Domain\Catalog\Events\CustomBrandCreated` → listener `QueueCustomBrandForPromotion` inserts `public.custom_brand_promotions` (SCHEMA.md §2.18: `tenant_id, custom_brand_id, snapshot jsonb, status, submitted_at, reviewed_by_super_admin_id, reviewed_at, decision jsonb`) so super-admin never scans tenant schemas |
| Review queue | super-admin | `GET /catalog/promotions?status=pending` on the **super** surface (`routes/super/catalog.php`, `super.catalog.promotions.index`, controller `App\Http\Controllers\Super\Catalog\PromotionController` — route file and controller owned by A per CONVENTIONS.md §2, calling this module's actions) — shows snapshot, similar master brands (trigram on `brands.name`), `use_count` in the tenant |
| Approve | super-admin | `POST /catalog/promotions/{promotion}/approve {mode: "map"\|"create", brand_id?, manufacturer?, presentations?}` (`super.catalog.promotions.approve`) → `App\Domain\Catalog\Actions\PromoteCustomBrand` inside `CatalogWriteContext::run()`: create or map `brands`/`strengths` under a `catalog_versions` row (`notes: promotion`), reindex touched docs; then tenant-side via `Tenancy::run($tenant, …)` (allowed for `App\Domain\SaaS`/`App\Tenancy` callers — the super controller wraps the call): `review_status = promoted`, `promoted_to_master = true`, `master_brand_id`, `master_strength_id`, `reviewed_at`, `review_note`, re-index the custom brand doc (its hits now carry the master `brand_id`/`strength_id`, so *new* prescriptions reference the master; existing rows are untouched — snapshot). Other tenants' identical pending brands are auto-resolved as `map`. |
| Reject | super-admin | `POST …/reject {reason}` (`super.catalog.promotions.reject`, `App\Domain\Catalog\Actions\RejectCustomBrandPromotion`) → `rejected`, `review_note`; the brand stays usable in that tenant (it still has a generic) but shows a "not in master" hint |

Audit: `create`/`update` on `CustomBrand` with `context.event` submitted|approved|rejected|promoted in the tenant log and `audit_logs_central` rows for the super-admin decision.

---

## 9. Test plan (`tests/Unit/Catalog`, `tests/Feature/Catalog` — `Tests\TestCase` + `WithTenants`, `CATALOG_DB_DATABASE=catalog_test_N`; group `catalog`, plus `search` for Meilisearch-backed tests)

| Test | Proves |
|---|---|
| `CatalogNamingTest` | no table named `drugs` on any connection (C1) |
| `CatalogModelReadOnlyTest` | `Generic::create()`, `save()`, `delete()`, `update()` outside `CatalogWriteContext` throw `CatalogIsReadOnly`; inside, they use `catalog_admin`; the singleton is recreated by Octane `flush` (asserted with `app()->forgetInstance()` + a fresh resolve) |
| `RuntimeRoleReadOnlyTest` (`@group pg-roles`) | with `catalog_reader` role, `DB::connection('catalog')->insert()` fails with a permission error |
| `CatalogMigrationsTest` | migrations run on `catalog_admin` only; `catalog:migrate` refuses the runtime connection |
| `DevSeedTest` | seed goes through the importer; counts ≥ thresholds (§3); every brand has an active generic; every strength label parses; `strength_mg`/`per_ml` computed; Ranitid inactive |
| `StrengthLabelParserTest` | table-driven: `500 mg`, `120 mg/5 ml`, `80 mg/ml`, `25/250 mcg`, `0.5 %`, `100 IU/ml`, `1 g/100 ml`, `2.5 mg/2.5 ml`, garbage |
| `GenericResolverTest` | salts stripped, aliases, trigram threshold, combinations → components, unknown → `needs_review` + issue |
| `ImportIdempotencyTest` | same file twice → zero changes, second version stats all zero; `--dry-run` rolls back |
| `ImportDeactivationTest` | `--full` deactivates missing rows without deleting; partial file deactivates nothing |
| `ImportInvalidatesCacheTest` | `CatalogCache` keys re-read after import (version prefix changed) |
| `ReconcileTest` | two tenant schemas, one orphan generic, one inactive brand, one renamed generic → correct report rows and `details`, no clinical row modified (checksum of tables before/after), custom brand deactivated with `review_note`, `clean` rows for untouched columns |
| `CatalogIdExistsTest` | missing / inactive / active ids; `StrengthBelongsToBrand` mismatch |
| `PromotionFlowTest` | create → pending queue row → approve (`map` and `create`) → master rows exist, tenant flags/`promoted` set, search doc carries master ids, historical `prescription_items` untouched; reject path |
| `SearchIndexTest` (`#[Group('search')]`) | settings applied; `nap` → Napa first; `napaa` typo hit; `নাপা` → Napa; `paracet` returns generic row; `nap 665` filters strength; `sugar`/`সুগার` → E11.9; inactive docs excluded by filter; rebuild swap leaves no `_next` index and never returns zero hits mid-rebuild; uids carry `SCOUT_PREFIX` |
| `CustomBrandIndexTest` (`#[Group('search')]`) | tenant index name from `searchableAs()`, `shouldBeSearchable` false when generic missing/rejected, merged list marks `source: custom` |
| `IsolationTest` | `assertTenantIsolated('custom_brands', …)` (CONVENTIONS.md §6.4) |

---

## 10. Schema additions requested (for docs/SCHEMA.md)

> Reconciled 2026-09-06: every item below is now in SCHEMA.md; the section is kept as the rationale record.

Catalog database (§2, marked ＋ there):

1. `generics.components jsonb NULL`, `generics.needs_review bool NOT NULL DEFAULT false`, `generics.name_bn`.
2. `brands.aliases jsonb NOT NULL DEFAULT '[]'`, `brands.discontinued_at timestamptz NULL`.
3. `strengths.strength_mg numeric(12,4) NULL`, `strengths.per_ml numeric(12,4) NULL`, `strengths.pack_size_value numeric(10,2) NULL`, `strengths.pack_unit varchar(16) NULL` — importer-computed; the safety maths and quantity calculator must not parse labels at runtime.
4. `dosage_forms.code varchar(16) UNIQUE NOT NULL`, `dosage_forms.is_liquid bool`, `dosage_forms.pack_unit varchar(16)`; `routes.code varchar(8) UNIQUE NOT NULL`, `routes.is_systemic bool` — the shorthand grammar's closed `form_code`/`route_code` vocabulary (PRESCRIPTION.md §2.4, §2.7).
5. New table `catalog_import_issues` (§2).

Public schema:

6. `catalog_reconciliation_reports`: `status` list extended with `inactive_found`, `renamed_found`; new `details jsonb NOT NULL DEFAULT '{}'` (`{kind: {ref_id: row_count}}`).
7. New table `custom_brand_promotions` (`tenant_id`, `custom_brand_id`, `snapshot jsonb`, `status pending|approved|rejected|promoted`, `submitted_at`, `reviewed_by_super_admin_id`, `reviewed_at`, `decision jsonb`; unique `(tenant_id, custom_brand_id)`) — the cross-tenant review queue.

Tenant schema:

8. Index `prescription_items (strength_id)` and `(brand_id)` for reconcile scans (also listed in PRESCRIPTION.md §10).

Naming already aligned with SCHEMA.md and used as-is here: `t{tenant_id}_custom_brands`
index name, `s{strength_id}`/`c{id}` document ids, `source` field, `renal_cautions.level = adjust_dose`,
`pregnancy_categories.lactation` values, `max_daily_doses.population` rows, `drug_information.public_slug`.
