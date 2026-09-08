# Prescription Module — Build Specification

Scope: BRIEF §5.G (G.1–G.4), §5.H, §3.3, §3.4. Companion documents:
`docs/SCHEMA.md` (tenant tables — owned by the data architect; table and column
names used below are theirs) and `docs/CATALOG.md` (catalog database, search
indexes, import, reconcile — owned by this document's author).

This is an implementation spec. Where it names a class, endpoint, JSON key or
Blade file, build exactly that. Where it is silent, choose the simplest thing
that keeps the invariants in §0.

---

## 0. Invariants (LOCKED — restated from the brief, not negotiable)

| # | Invariant | Enforced by |
|---|---|---|
| I1 | PDF is rendered by headless Chrome via `spatie/browsershot`. DomPDF is never installed. | `composer.json`, `PdfRenderer` |
| I2 | Every safety check reasons over `generic_id`, never `brand_id`. A brand is only a lookup key to a generic. | `SafetyContext` is built from generic ids only |
| I3 | Snapshot on write. `prescription_items` stores `generic_name`, `brand_name`, `strength`, `form`, `route` as text; `prescriptions.snapshot` is the only render source once issued. | `SnapshotBuilder`, `PrescriptionRenderer` |
| I4 | A custom brand that cannot be resolved to a live catalog generic is unusable in a prescription. Hard block, not overridable. | `CustomBrandLinkCheck`, `CatalogIdExists` |
| I5 | Prescriptions are immutable once issued. An edit creates version+1. | `Prescription` model guard, DB trigger (requested), `AmendPrescription` |
| I6 | No rendering path joins live to `catalog`. Rendering runs zero queries on the `catalog` connection. | `CatalogJoinFreeRenderingTest` |
| I7 | Every clinical write and view produces an `audit_logs` row. | `PrescriptionAuditor` |
| I8 | Autocomplete hits Meilisearch, never the catalog database. | `DrugSearchService` |

### 0.1 Conventions

- Namespaces: `App\Domain\Prescription\{Actions,Services,Events,Data,Enums,Rules,Exceptions,Jobs,Listeners,Safety,Shorthand,Render,AI}`
  (`Safety`, `Shorthand`, `Render`, `AI` are the module-private sub-namespaces allowed by ARCHITECTURE.md §5.3;
  enums still live in `Enums`), `App\Domain\Catalog\*`, `App\Domain\Patients\*`. HTTP layer in
  `App\Http\Controllers\Panel\Prescription\*` (panel surface) and `App\Http\Controllers\Site\Prescription\*`
  (the two public pages); FormRequests in `App\Http\Requests\Panel\Prescription\*`.
- Eloquent models: tenant models in `App\Models\Tenant\*` (default `pgsql`
  connection; the search path is exactly `"tenant_<id>"`, set by `Tenancy::initialize()` — ARCHITECTURE.md §4), control-plane
  models in `App\Models\Central\*`, catalog models in `App\Models\Catalog\*`
  on the `catalog` connection (see CATALOG.md §1). Column names below are
  SCHEMA.md's; the visit row (`visits`) is the mutable clinical working record
  (complaints, findings, diagnoses, follow-up) and the prescription row holds
  Rx items, investigations, advice, referrals and the frozen `snapshot`.
- Routes: staff endpoints live in `routes/panel/prescription.php` inside the panel group of
  `bootstrap/app.php` (`web`, `tenant`, `auth:web`, `SetActiveBranch` — ARCHITECTURE.md §2; names `panel.prescription.*`),
  plus `role:doctor|hospital_admin` or the `prescriptions.*` permissions per route (ARCHITECTURE.md §6.2)
  unless stated. The writer's XHR endpoints (§3.8, §4.13, §5.5) return JSON from the panel surface — the
  documented exception in CONVENTIONS.md §5. Public routes `GET /rx/{code}` (`site.prescription.verify`) and
  `GET /drug/{slug}` (`site.prescription.drug`) live in `routes/site/prescription.php` and have no auth.
- Identifiers: route parameters `{visit}`, `{prescription}` bind by `public_id` (`{visit:public_id}`,
  ULIDs — CONVENTIONS.md §5) and the wire ids of visits/prescriptions/patients are `public_id` strings;
  template, snippet, favourite, vital and investigation-catalog rows have no `public_id` in SCHEMA.md and use
  their bigint id in panel URLs only. Where a JSON sample below shows a numeric `id` for a visit or
  prescription, read it as that row's `public_id`; catalog ids (`generic_id`, …) and `prescription_items.id`
  stay integers. The writer's item `key` is a client-generated ULID (`@shared/ulid`).
- Every JSON body and response uses `snake_case`. Timestamps are ISO-8601 with
  offset (`2026-09-06T10:15:00+06:00`), tenant timezone `Asia/Dhaka`.
- Money is integer paisa. Weights kg (1 dp), heights cm (integer), temperature °C (1 dp).
- Client code (React 19, TS, MUI 9, zustand 5 — CONVENTIONS.md §7.1): page `resources/js/panel/Pages/Prescription/Writer.tsx`,
  components `resources/js/panel/Components/Prescription/**`, hooks `resources/js/panel/hooks/prescription/**`,
  and the pure-TS library (parser, store, types) in `resources/js/panel/lib/prescription/{shorthand,store,types}/**`.
- Redis keys are tenant-scoped with the bigint tenant id: `t:{tenantId}:doctor:{doctorId}:{top50|usage|icd}`
  and `t:{tenantId}:doctor:{doctorId}:fav:{icd}` (CONVENTIONS.md §15).
- The doctor is `auth()->user()->doctor` (`doctors` row); `doctor_id` below means that id.

---

## 1. The 60-second flow

### 1.1 Screen: three panes, one page

Route: `GET /panel/visits/{visit:public_id}/prescribe` (`panel.prescription.writer`) → Inertia page `Prescription/Writer`
(`resources/js/panel/Pages/Prescription/Writer.tsx`).
Controller: `App\Http\Controllers\Panel\Prescription\WriterController::show(Visit $visit)` (calls `AuditLog::view($visit)`, CONVENTIONS.md §5).
If the visit has no `draft` prescription for this doctor, `CreateDraftPrescription`
runs inside the request and the draft id is part of the props (so the first
autosave already has a target — no "create" round trip).

```
┌───────────────────────┬──────────────────────────────────────────────┬──────────────────────────┐
│ LEFT · History (280px)│ CENTRE · Prescription (fluid, min 640px)     │ RIGHT · Quick-pick (300px)│
│                       │                                              │                          │
│ Patient card          │ [Alerts strip — sticky, collapsible]         │ Tabs: For Dx · Top 50 ·  │
│  name/age/sex/ID      │                                              │ Templates · Tests ·      │
│  phone, family        │ 1 Chief complaints  [+ duration]             │ Advice                   │
│  ⚠ allergies (red)    │ 2 Vitals (compounder)  ☐ reviewed   BMI 24.1 │                          │
│  conditions, meds     │ 3 On examination                             │ Search box (Ctrl+K)      │
│                       │ 4 Diagnosis  [ICD chips]  prov/final         │                          │
│ Timeline (last 5)     │ 5 Rx ───────────────────────────────────────  │ List — each row is ONE   │
│  06 Aug · Fever · Rx  │   1. Napa 500mg Tab  1+0+1 ×10d  AF   [20]   │ click to insert          │
│  02 Jul · HTN f/u     │   2. ▌nap                                    │                          │
│  …                    │      ┌ Napa 500mg Tablet  Paracetamol ─────┐ │ Cheat-sheet (F2)         │
│ [Load older]          │      │ Napa Extra 500/65 … (custom ●)      │ │                          │
│                       │      └────────────────────────────────────┘ │ AI (advisory) panel      │
│ Vitals trend (spark)  │ 6 Investigations [+ price]   7 Advice        │  · 3-line summary        │
│ Documents (lazy)      │ 8 Follow-up [ 7d ▾ ]   Referral              │  · differentials         │
│                       │ [Draw] [Handwrite] [🎤]                       │                          │
│                       │ ──────────────────────────────────────────── │                          │
│                       │ [Issue & Print  Ctrl+Enter] [Issue] [Preview] │                          │
└───────────────────────┴──────────────────────────────────────────────┴──────────────────────────┘
```

Tablet (< 1100px): right pane becomes a bottom drawer, left pane a top sheet, centre never
narrower than 640px. Phone: read-only (view/print/send) — writing needs ≥ 768px.

### 1.2 What loads in the single Inertia request

`WriterController::show` returns these props in one response. Everything here
is needed before the doctor's first keystroke; nothing else is.

```ts
// resources/js/panel/lib/prescription/types/page.ts
export interface WriterPageProps {
  visit: {
    id: number; serial_display: string; session_code: string;
    started_at: string | null; type: 'opd' | 'followup' | 'telemedicine'; appointment_id: number | null;
    chief_complaints: Complaint[]; examination_findings: string | null; diagnoses: Diagnosis[];
    follow_up_on: string | null; follow_up_note: string | null;      // visits.* (SCHEMA §3.4)
  };
  patient: PatientSummary;              // §8.1 — name, age_years, age_months, sex, phone, patient_code,
                                        // family_head, allergies[], conditions[], medications[],
                                        // flags {pregnant, lactating, renal, hepatic} derived from conditions
  vitals: VitalsRow | null;             // latest `vitals` row of this visit (compounder); null if none
  recent_visits: VisitBrief[];          // last 5 visits (excluding this one): date, doctor, dx labels,
                                        // rx item count, follow_up_date, prescription_id (latest issued version)
  prescription: PrescriptionDraft;      // §1.5 — the draft (created if absent) incl. items, alerts, version
  doctor: {
    id: number; name: string;
    pad: PadSettingsBrief;              // paper, letterhead on/off, preprinted mode, language default
    prefs: { default_duration_days: number; cont_days: number };   // doctor_profiles.prefs; the print-language default is pad.default_language (doctor_pad_settings)
  };
  quick_pick: {
    top_drugs: TopDrug[];               // ≤ 50 = doctor_favourites with icd10_code IS NULL ordered by rank (Redis-cached)
    templates: TemplateBrief[];         // id, name, shorthand (/cold), icd10_code, item_count — bodies fetched on apply
    snippets: AdviceSnippet[];          // doctor's + clinic-shared advice_snippets (shorthand, category, text, text_bn)
    investigations: InvestigationCatalogRow[]; // clinic catalog: id, name, code, price_paisa, category, is_active
    external_centres: ExternalCentreBrief[];
  };
  features: { ai: boolean; voice: boolean; handwriting: boolean; drawing_backgrounds: string[] };
  cheat_sheet_version: string;          // cache-buster for the in-app grammar sheet
}
```

Deferred (Inertia `defer()`, loaded after first paint in a second request the
doctor never waits for): `timeline_older` (visits 6–25), `documents` (last 10
uploaded reports), `vitals_trend` (last 12 vitals rows).

Fetched lazily on demand (XHR, §3.8 endpoints): drug search, ICD search,
favourites for a diagnosis, template body, older timeline pages, AI calls,
safety re-checks, previous prescription bodies ("copy from last visit").

Budget: the show response must stay under 120 KB gzipped and one query per
prop group (no N+1: eager-load `patient.allergies`, `patient.conditions`,
`patient.medications`; the top-50 list comes from Redis key
`t:{tenantId}:doctor:{doctorId}:top50`, warmed on issue).

### 1.3 Keyboard model

Focus zones, in Tab order: `complaints → findings → diagnosis → rx[0..n] → investigations → advice → follow_up → referral → issue_button`.
Vitals is *not* in the Tab order (compounder data; doctor jumps in with `Alt+2`
or a click). The right pane is never in the Tab order; it is reached with
`Ctrl+K` and left with `Esc`.

Initial focus: `complaints` if empty, otherwise the first empty Rx line.

| Key | Where | Effect |
|---|---|---|
| `Tab` / `Shift+Tab` | anywhere | Next / previous zone. In an Rx line: commits the line if parse is clean, then moves to the next line. |
| `Enter` | Rx line, drug phase, popup open | Selects highlighted candidate → drug chip; caret moves to shorthand phase |
| `Enter` | Rx line, shorthand phase | Commits line (if clean) and creates + focuses a new empty line below |
| `Shift+Enter` | Rx line | Commits and stays on the line |
| `Enter` | complaints/findings/advice textareas | New bullet (these are chip-lists, not paragraphs) |
| `↑` / `↓` | any popup | Move highlight (wraps) |
| `↑` / `↓` | Rx line, no popup | Move to previous / next Rx line (same caret column) |
| `Ctrl+↑` / `Ctrl+↓` | Rx line | Reorder line up / down |
| `Esc` | popup open | Close popup, keep text |
| `Esc` | popup closed, line dirty | Revert line to last committed state |
| `Esc` | right pane / dialog | Return focus to the last Rx line |
| `Backspace` | shorthand phase, caret at column 0 | Re-open the drug chip for editing (text restored) |
| `Ctrl+D` | Rx line | Duplicate line |
| `Ctrl+Backspace` | Rx line | Delete line (undo toast for 8 s) |
| `Ctrl+K` | anywhere | Focus right-pane search (searches templates, snippets, tests, drugs) |
| `Alt+1..8` | anywhere | Jump to zone 1..8 (Complaints, Vitals, Findings, Dx, Rx, Ix, Advice, Follow-up) |
| `Ctrl+Enter` | anywhere | Open Issue dialog with "Issue & Print" focused; `Enter` again issues |
| `Ctrl+P` | anywhere | Print preview of the current draft (watermarked DRAFT) |
| `F2` or `Ctrl+/` | anywhere | Toggle the shorthand cheat-sheet |
| `Ctrl+Shift+V` | anywhere | Voice dictation toggle for the focused text field |
| `Ctrl+.` | Rx line with alert | Focus the alert for this line (override / details) |

Actions that must be ≤ 2 clicks (or their keyboard equivalent), measured from
a focused writer: apply template (1), insert top-50 drug (1), insert favourite
for current diagnosis (1), add investigation (1), add advice snippet (1), set
follow-up 7/15/30 days (1 — preset chips), issue & print (2: button + confirm),
override a warning (2: "Override" + reason submit), amend an issued
prescription (2), send by SMS (2), switch to handwriting (1), copy last visit's
Rx (2: "Copy from 06 Aug" + confirm), mark vitals reviewed (1).

Mouse-only doctors: every keyboard action above also has a visible control;
the Rx line shows `[+]` for new line and a `⋮` menu (duplicate, delete, move).

### 1.4 The 60-second walk-through (routine URTI, adult)

Complaints focused on open: `fever 3d` ⏎ `cough 2d` ⏎ (5 s) → `Tab` findings
`throat congested` (8 s) → `Tab` Dx `urti` ⏎ picks J06.9 (11 s) → `Tab` Rx:
`nap` ⏎ `1+1+1 5d af` ⏎ · `cet` ⏎ `0+0+1 5d` ⏎ · `fexo 120` ⏎ `0+0+1 7d` ⏎
(31 s) → right pane "For Dx J06.9" advice snippet, 1 click (33 s) → follow-up
chip `7d` (35 s) → `Ctrl+Enter` ⏎ (38 s) → issued, snapshot frozen, PDF queued,
print tab opens with the same HTML (42 s). Safety alerts arrive inline as each
line commits (≈ 60–150 ms on LAN) and never steal focus; a `critical` alert
turns the Issue button into "Review 1 critical alert" until overridden or the
line changes.

### 1.5 Client state (zustand)

```ts
// resources/js/panel/lib/prescription/store/writerStore.ts
export type ItemKey = string;               // client-generated ULID (@shared/ulid); stable across autosaves; server echoes it back

export interface RxItemDraft {
  key: ItemKey;
  id: number | null;                        // prescription_items.id once persisted
  sort_order: number;
  drug: DrugRef | null;                     // committed chip
  shorthand: string;                        // raw text after the chip, e.g. "1+0+1 10d af //with water"
  parsed: ParsedLine | null;                // client parse (§2.11); server's parse wins on echo
  quantity_auto: number | null;             // §2.12
  quantity_unit: string | null;             // "tab" | "bottle" | "ml" | "inhaler" | ...
  status: 'editing' | 'committed' | 'saving' | 'error';
  errors: ParseIssue[];                     // §2.13
}

export interface DrugRef {
  kind: 'presentation' | 'generic' | 'custom';
  generic_id: number;                       // ALWAYS present (I2, I4)
  brand_id: number | null;
  custom_brand_id: number | null;
  strength_id: number | null;
  generic_name: string; brand_name: string | null;
  strength: string | null; form: string | null; form_code: string | null; route: string | null;
  pack_size: number | null; pack_unit: string | null;
  strength_mg: number | null; per_ml: number | null;   // for max-dose maths, from the search doc
}

export interface Diagnosis { key: string; icd10_code: string | null; title: string; kind: 'provisional' | 'final'; sort: number }   // visits.diagnoses[]
export interface Complaint { key: string; text: string; text_bn: string | null; duration: string | null; sort: number }   // visits.chief_complaints[]
export interface InvestigationLine { key: string; investigation_catalog_id: number | null; name: string; name_bn: string | null; price_paisa: number | null; external_diagnostic_centre_id: number | null; referral_note: string | null; is_urgent: boolean }
export interface AdviceLine { key: string; advice_snippet_id: number | null; text: string; text_bn: string | null }
export interface FollowUp { on: string | null; days: number | null; note: string | null; create_booking: boolean }   // visits.follow_up_on / follow_up_note
export interface Referral { key: string; type: 'doctor' | 'hospital' | 'diagnostic_centre'; referred_to_doctor_id: number | null; external_diagnostic_centre_id: number | null; referred_to_name: string; referred_to_specialty: string | null; note: string | null; is_urgent: boolean }

export interface WriterState {
  prescription_id: number; version: number; status: 'draft';
  language: 'bn' | 'en' | 'both';          // prescriptions.language, default 'both' (Bangla instructions, English drug names)
  complaints: Complaint[]; findings: string[];   // findings chips are joined with '; ' into visits.examination_findings
  vitals: VitalsRow | null; vitals_reviewed: boolean;
  diagnoses: Diagnosis[];
  items: RxItemDraft[];
  investigations: InvestigationLine[]; advice: AdviceLine[];
  follow_up: FollowUp; referrals: Referral[];
  drawing: DrawingJson | null; handwriting_image_path: string | null; mode: 'structured' | 'handwriting';
  alerts: SafetyAlert[]; overrides: Record<string, { reason: string }>;   // keyed by alert.fingerprint; persisted per line in prescription_items.safety_overrides
  dirty: boolean; last_saved_at: string | null; server_updated_at: string | null; save_error: string | null;   // server_updated_at is the optimistic-concurrency token
  focus: { zone: FocusZone; item_key?: ItemKey };
  // actions
  setDrug(key: ItemKey, drug: DrugRef | null): void;
  setShorthand(key: ItemKey, text: string): void;      // runs parseLine() synchronously
  commitItem(key: ItemKey): Promise<void>;             // → debounced PATCH draft (§4.13)
  applyTemplate(id: number): Promise<void>;
  override(fingerprint: string, reason: string): void;
  issue(opts: { print: boolean }): Promise<IssueResult>;
}
export type FocusZone = 'complaints' | 'vitals' | 'findings' | 'diagnosis' | 'rx' | 'investigations' | 'advice' | 'follow_up' | 'referral' | 'issue';
```

Autosave: any state change sets `dirty`; a 400 ms trailing debounce sends
`PATCH /panel/prescriptions/{prescription}/draft` (§4.13) with the full draft body. The
response contains the canonical parse of each item and the current alert set,
which replaces local `parsed`/`alerts` (server is authoritative; the client
parser exists for instant feedback only). While offline the store keeps
retrying with backoff (1 s, 2 s, 5 s, 10 s…) and shows "Not saved" in red; the
Issue button is disabled until the last save succeeded.

---
## 2. Shorthand grammar (formal spec)

The Rx line is a single text input with two phases. Phase A (drug): the text
typed so far is a drug query; an autocomplete popup shows candidates (§3).
Selecting one turns it into a chip. Phase B (shorthand): everything after the
chip is parsed by this grammar, live, on every keystroke, on the client; the
server re-parses authoritatively on save and on issue with the same keyword
table. Two implementations, one fixture (§9.1) — they must agree byte-for-byte
on `dose_json`.

### 2.1 Normalisation (before tokenising)

1. Map Bangla digits `০১২৩৪৫৬৭৮৯` → `0123456789`. Map `×` → `x`, `½` → `1/2`, `¼` → `1/4`, `¾` → `3/4`, `—`/`–` → `-`.
2. Split off the instruction: the first occurrence of `//` or `"` (straight or curly `“`) starts the instruction; everything after it (trimmed) is `instruction`, verbatim, case preserved, Bangla allowed.
3. Lower-case the remainder. Collapse whitespace. Insert a space between a digit run and a following letter run *except* inside the closed unit set (`5ml`, `500mg`, `10d`, `2w`, `1m`, `q6h`, `8hrly`, `x20` stay attached).

### 2.2 Grammar (EBNF)

```ebnf
line         = body ;                                    (* drug is the chip, not text *)
body         = { token , ws } , [ instruction ] ;
token        = schedule | duration | timing | route | qty | maxclause ;

schedule     = slots | frequency | interval | stat | sos | hsalone ;
slots        = amount , "+" , amount , [ "+" , amount , [ "+" , amount ] ] ;   (* 2..4 slots *)
frequency    = [ amount , ws ] , freqword ;
freqword     = "od" | "daily" | "bd" | "bid" | "tds" | "tid" | "qds" | "qid" ;
interval     = [ amount , ws ] , ( "q" , int , hourword | int , "hrly" ) ;
hourword     = "h" | "hr" | "hrs" | "hourly" ;
stat         = [ amount , ws ] , "stat" ;
sos          = [ amount , ws ] , "sos" ;                (* may follow a frequency: see 2.4 *)
hsalone      = "hs" ;                                   (* when no other schedule token *)
maxclause    = "max" , ws , int ;                       (* only valid after sos *)

amount       = number , [ ws ] , [ unit ] ;
number       = mixed | fraction | decimal | int ;
mixed        = int , ws , fraction ;                    (* "1 1/2" *)
fraction     = int , "/" , int ;                        (* 1/2 1/4 3/4 *)
decimal      = int , "." , int ;
unit         = "tab" | "tabs" | "tablet" | "cap" | "caps" | "capsule" | "ml" | "tsp" | "tbsp"
             | "drop" | "drops" | "puff" | "puffs" | "sachet" | "amp" | "vial" | "unit" | "units" | "iu"
             | "mg" | "g" | "mcg" | "app" | "apply" | "supp" | "spray" | "sprays" ;

duration     = int , durunit | "cont" | "continue" | "tf" | "till" , ws , "finish" ;
durunit      = "d" | "day" | "days" | "w" | "wk" | "wks" | "week" | "weeks" | "m" | "mo" | "month" | "months" ;

timing       = "af" | "pc" | "bf" | "ac" | "wf" | "em" | "hs" ;   (* hs after a schedule = timing *)
route        = "po" | "sl" | "pr" | "pv" | "top" | "iv" | "im" | "sc" | "id" | "inh" | "neb" | "ng"
             | "le" | "re" | "be" | "lear" | "rear" | "bear" | "nasal" | "buccal" ;
qty          = "x" , int , [ ws , packword ] ;
packword     = "bot" | "bottle" | "bottles" | "tube" | "tubes" | "pack" | "packs" | "inhaler" | "vial" | "vials" | "amp" | "amps" ;

instruction  = ( "//" | '"' ) , { any } ;
ws           = " " , { " " } ;
int          = digit , { digit } ;
```

Keyword table (the closed vocabulary above) lives in
`resources/shorthand/keywords.json` and is imported by both parsers. Adding a
synonym is a JSON edit plus a fixture row, never code.

### 2.3 Drug lookup token (phase A)

- Query = trimmed text, case-insensitive, Bangla allowed. Sent to `GET /panel/search/drugs?q=` after 1 character, debounced 80 ms; for the first 2 characters the client first filters the doctor's preloaded top-50 locally (instant) and merges network results when they arrive.
- Matching: prefix on `brand_name`, `generic_name`, `aliases`; typo tolerance 1 edit from 4 chars, 2 from 8 (index settings in CATALOG.md §4). `nap` → Napa; `napa 665` → Napa 665 mg XR; `paracet` → Paracetamol (generic doc) and all its brands' presentations.
- Ranking (server, §3.3): brand presentations first, generic doc as a row below the brands ("Paracetamol — any brand"); doctor's usage and diagnosis favourites boost; custom brands merged and rendered with a `●` marker and the label "clinic brand".
- A number in the query (`nap 500`, `napa500`) filters the strength (`strength_mg = 500` or label prefix).
- A query starting with `/` is not a drug: `/cold` matches `prescription_templates.shorthand` (Enter applies the template, §3.6), `/rest` matches `advice_snippets.shorthand` (Enter inserts the advice). Zero clicks for the doctor's own protocols.
- Popup rows show: brand, strength, form (mono-spaced), generic in grey, manufacturer, and a right-aligned `★ 42` usage count. `Enter`/`Tab` picks; `Alt+Enter` picks the *generic* row instead (prescribe by generic).

### 2.4 Schedule forms

| Form | Tokens | Meaning | `schedule` |
|---|---|---|---|
| Slots (2) | `1+1` | morning, night | `{type:"slots", slots:[1,1]}` |
| Slots (3) | `1+0+1` | morning, noon, night | `{type:"slots", slots:[1,0,1]}` |
| Slots (4) | `1+1+1+1` | morning, noon, evening, night | `{type:"slots", slots:[1,1,1,1]}` |
| Fractions / decimals | `1/2+0+1/2`, `0.5+0+0.5`, `1 1/2+0+1` | as written | slots `[0.5,0,0.5]` |
| With unit | `2 tsp+0+2 tsp`, `5ml+0+5ml`, `1 tab+0+1 tab` | unit taken from the first slot; all slots must agree | `unit` set, `unit_inferred:false` |
| Frequency | `od` `bd` `tds` `qds` (`daily` `bid` `tid` `qid` synonyms), optional leading amount `1 tds`, `2 puff bd` | 1/2/3/4 times daily | `{type:"frequency", code:"tds", per_day:3, amount:1}` |
| Interval | `q6h`, `q8h`, `q12h`, `1 q6h`, `8hrly` | every N hours; `per_day = 24/N` (must divide 24, else error `interval_invalid`) | `{type:"interval", every_hours:6, amount:1}` |
| Stat | `stat`, `2 stat` | single dose now | `{type:"stat", amount:1}` |
| SOS | `sos`, `1 sos`, `1 sos max 3`, `1 puff qds sos` | as needed; `max` clause or a preceding frequency sets `max_per_day` | `{type:"sos", amount:1, max_per_day:3}` |
| Bedtime alone | `hs` (no other schedule) | once daily at bedtime | `{type:"frequency", code:"od", per_day:1, amount:1}` + `timing_code:"hs"` |

Exactly one schedule per line (exception: `frequency` followed by `sos`
collapses into `sos` with `max_per_day`). A second schedule token is error
`duplicate_schedule`. Amount omitted → `1` of the unit. Amounts must be > 0 and
at least one slot must be > 0 (`amount_zero`). Slot count 5+ → `slot_count`.

Amount units and inference:

- Explicit unit wins. Slots must all use the same unit family (`unit_mismatch` otherwise).
- No unit → inferred from the drug's `form_code` (table below), `unit_inferred:true`, and the inline interpretation says so ("1 tsp (5 ml)"). Inference is displayed, never silent.
- `mg`/`g`/`mcg` amounts on a solid form are converted to units of the presentation: `500mg` on a 500 mg tablet → `1 tab`; `250mg` → `0.5 tab`. Allowed results: multiples of 0.25; anything else is error `unit_mismatch` with the message "500 mg tablet cannot give 300 mg". On liquids `mg` converts via `strength_mg / per_ml` to ml (`120mg/5ml`: `240mg` → `10 ml`).

| `form_code` | default unit | pack unit | notes |
|---|---|---|---|
| `tab`, `cap`, `supp`, `sachet`, `pessary` | `tab`/`cap`/`supp`/`sachet`/`pessary` | same | counted |
| `syr`, `susp`, `sol`, `oral_drop` | `tsp` (5 ml) | `bottle` (pack_size ml) | `tbsp` = 15 ml; `ml` explicit |
| `eye_drop`, `ear_drop`, `nasal_drop`, `nasal_spray` | `drop` / `spray` | `bottle` | quantity is packs |
| `inh_mdi`, `inh_dpi` | `puff` | `inhaler` (pack_size actuations) | |
| `neb` | `neb` (respule) | `respule` | counted |
| `inj` | `amp` or `vial` (from presentation) | same | counted per dose |
| `insulin` | `unit` | `vial`/`pen` (pack_size units) | |
| `cream`, `oint`, `gel`, `lotion`, `powder`, `shampoo`, `mouthwash`, `paint` | `app` | `tube`/`pack` | quantity is packs |

### 2.5 Duration

`10d` → 10 days; `2w` → 14; `1m` → 30 (calendar months are never used; a month
is 30 days for quantity); `cont`/`continue` → `{type:"continuous", assumed_days: doctor.prefs.cont_days}`
(default 30, printed as "Continue" / "চলবে"); `tf`/`till finish` → `{type:"till_finish"}`
(printed "Till finish" / "শেষ পর্যন্ত"). One duration per line (`duplicate_duration`).
Missing duration on a counted form is warning `missing_duration` (quantity
unknown; line may still commit; the item shows a `qty?` badge and prints no
quantity). Missing duration on pack forms (drops, inhaler, cream, spray) is
normal: no issue, quantity = 1 pack.

### 2.6 Timing

| token | `timing` column | `timing_code` | printed (bn / en) |
|---|---|---|---|
| `af`, `pc` | `after` | `af` | খাবারের পরে / after meal |
| `bf`, `ac` | `before` | `bf` | খাবারের আগে / before meal |
| `wf` | `with` | `wf` | খাবারের সাথে / with meal |
| `em` | `before` | `em` | খালি পেটে / empty stomach |
| `hs` (after a schedule) | `any` | `hs` | শোবার সময় / at bedtime |
| none | `any` | `null` | — |

One timing per line (`duplicate_timing`).

### 2.7 Route override

The presentation's route (from the search document) is the default. A route
token overrides it and is stored in `dose_json.route_code` and the item's
`route` snapshot text. `od` is **never** a route (it is once daily); eyes are
`le`/`re`/`be`, ears `lear`/`rear`/`bear`. A route token that is impossible for
the form (`iv` on a tablet) is error `route_incompatible`.

### 2.8 Quantity override

`x20` → `quantity.value = 20`, `source:"override"`, unit = the item's counting
unit; `x2 bottle` → 2 packs. Override always wins over auto-calculation and is
printed as typed. `x0` is error `amount_zero`.

### 2.9 Instruction

Everything after `//` or `"` — free text, any script, case preserved, max 200
chars, stored in `prescription_items.instruction` and `dose_json.instruction`.
Printed on its own line under the item in the pad language. A line that is
*only* an instruction (no schedule) is allowed for pack forms and is warning
`missing_schedule` for counted forms.

### 2.10 Case and script

Everything except the instruction is case-insensitive (`AF` = `af`). Bangla digits are
accepted wherever a digit is; Bangla words only in the instruction and the drug query (§3 synonyms).

### 2.11 Parse result — `dose_json` (v1)

`prescription_items.dose_json` stores the complete parse result. The scalar
columns `dose_schedule`, `duration_days`, `quantity`, `timing`, `instruction`
and `route` are projections written by the server from this object; the server
never trusts client projections.

```ts
// resources/js/panel/lib/prescription/shorthand/types.ts  (mirrored by App\Domain\Prescription\Data\DoseJson)
export interface ParsedLine {
  v: 1;
  raw: string;                       // exactly as typed (after Bangla-digit mapping only)
  normalized: string;                // canonical spelling, e.g. "1+0+1 10d af // with water"
  unit: DoseUnit;                    // "tab" | "cap" | "ml" | "tsp" | "tbsp" | "drop" | "puff" | "spray" | "sachet" | "amp" | "vial" | "unit" | "app" | "supp" | "neb" | "pessary"
  unit_inferred: boolean;
  schedule:
    | { type: 'slots'; slots: number[] }                                    // 2..4
    | { type: 'frequency'; code: 'od' | 'bd' | 'tds' | 'qds'; per_day: 1 | 2 | 3 | 4; amount: number }
    | { type: 'interval'; every_hours: number; amount: number }
    | { type: 'stat'; amount: number }
    | { type: 'sos'; amount: number; max_per_day: number | null }
    | null;
  daily_total: number | null;        // in `unit`; null for stat/sos-without-max
  duration:
    | { type: 'days'; days: number }
    | { type: 'continuous'; assumed_days: number }
    | { type: 'till_finish' }
    | null;
  timing: 'before' | 'after' | 'with' | 'any';
  timing_code: 'af' | 'bf' | 'wf' | 'em' | 'hs' | null;
  route_code: string | null;         // override only; null = presentation default
  quantity: { value: number | null; unit: string; source: 'auto' | 'override' | 'none'; basis: string | null };
  instruction: string | null;
  issues: ParseIssue[];
}
export interface ParseIssue {
  code: 'unknown_token' | 'duplicate_schedule' | 'duplicate_duration' | 'duplicate_timing' | 'duplicate_route'
      | 'missing_schedule' | 'missing_duration' | 'slot_count' | 'amount_zero' | 'unit_mismatch'
      | 'interval_invalid' | 'route_incompatible' | 'quantity_unknown' | 'continuous_assumed' | 'unit_inferred'
      | 'max_without_sos' | 'drug_missing' | 'instruction_too_long';
  severity: 'error' | 'warning' | 'info';
  token: string | null; span: [number, number] | null;   // char offsets in `raw`
  message: string; message_bn: string; suggestion: string | null;   // e.g. "aff" → "af"
}
```

`dose_schedule` text projection: slots → `"1+0+1"` (fractions as `1/2`),
frequency → `"1 tds"`, interval → `"1 q6h"`, stat → `"stat"`/`"2 stat"`, sos →
`"1 sos"`/`"1 sos max 3"`. `duration_days`: days, `assumed_days` for
continuous, `null` for till-finish/none; `duration_text`: `"10 days"` /
`"Continue"` / `"Till finish"` in the pad language. `quantity` / `quantity_unit`
columns: `quantity.value` / `quantity.unit`. `instruction` holds the typed
instruction; `instruction_bn` is filled only when the doctor typed Bangla (script
detected) or picked a Bangla snippet. `dose_json` is this `ParsedLine` object —
it supersedes the placeholder shape sketched in SCHEMA.md's `prescription_items`
JSON note (see §10).

### 2.12 Quantity auto-calculation

`QuantityCalculator::compute(ParsedLine $line, DrugRef $drug): Quantity` runs
after every successful parse. `basis` is a human string shown in the popover
("2/day × 10 d = 20 tab").

1. `daily_total` = sum(slots) | `per_day × amount` | `(24 / every_hours) × amount` | `max_per_day × amount` (sos with max) | `null`.
2. `days` = `duration.days` | `assumed_days` | `null`.
3. By counting family:
   - **Counted** (`tab cap supp sachet pessary neb amp vial`): `ceil(daily_total × days)`; stat → `amount`; sos without max or no days → `none` + `quantity_unknown`.
   - **Liquid** (`ml/tsp/tbsp`): `total_ml = daily_ml × days` (tsp = 5, tbsp = 15). If `pack_size` (ml) known → `ceil(total_ml / pack_size)` bottles, basis "20 ml/day × 5 d = 100 ml → 1 × 100 ml"; if unknown → value = `total_ml`, unit `ml`, plus info `quantity_unknown`("pack size unknown").
   - **Insulin** (`unit`): `total_units = daily × days`; packs = `ceil(total_units / pack_size)` (vial 1000 U, pen 300 U); unit `vial`/`pen`.
   - **Inhaler** (`puff`): `total = daily × days`; packs = `ceil(total / pack_size)` (actuations, default 200); no days → 1.
   - **Packs** (`drop spray app`): `1` pack per started 28 days (`ceil(days/28)`), no days → 1.
4. Override (`x`) replaces the value; unit follows the family.
5. Rounding: counted values round *up* to a whole; liquids to whole bottles.
   Half-tablet totals round up (`0.5 × 3 × 5 = 7.5 → 8`).

### 2.13 Error and ambiguity policy — never guess silently

- The interpretation line under every Rx line always shows what the parser
  understood, in the pad language, e.g. `১টা সকালে + ১টা রাতে · ১০ দিন · খাবারের পরে · ২০টি`.
  If the doctor's intent differs from what is shown, they see it before commit.
- `error` issues block commit of *that line* only (`Enter` keeps focus, the
  offending span is underlined, the message appears inline with the suggestion
  as a clickable fix: "Did you mean `af`?"). `Tab` away leaves the line in
  `error` state; the Issue button is disabled while any line is in error.
- `warning` issues commit but show a yellow badge (`qty?`, `no duration`);
  they are listed in the Issue dialog for a last look.
- `info` issues (inferred unit, assumed continuous days) are shown once in the
  interpretation line and are not listed elsewhere.
- Unknown tokens are never dropped and never auto-converted to instruction.
  The inline fix offers "Move to instruction" which rewrites the text as
  `… // abc` visibly.
- The server re-parses on save/issue; if the server parse has an `error`
  issue the save responds 422 with the item key and issues, and the client
  shows them exactly like local errors. Issue refuses with the same shape.

### 2.14 Discoverable in-app cheat-sheet

`F2` / `Ctrl+/` / the `?` on the Rx header opens a right-pane sheet generated
from `keywords.json` (never stale): three columns — schedule, duration/timing,
route/quantity/instruction — each example tappable (inserts into the focused Rx
line), plus a live "try it" input showing the parse tree. For a doctor's first
three sessions a dismissible toast "Type `1+0+1 10d af` — see the cheat sheet
(F2)" appears once per session (`doctor_profiles.prefs.cheatsheet_seen_count`).
`/panel/help/shorthand` prints it on A4 for the chamber wall.

### 2.15 Parser architecture

- TS: `resources/js/panel/lib/prescription/shorthand/{normalize,tokenize,classify,assemble,quantity,parse}.ts`, entry `parseLine(text: string, ctx: ParseContext): ParsedLine`.
- PHP: `App\Domain\Prescription\Shorthand\ShorthandParser::parse(string $text, ParseContext $ctx): ParsedLine`
  with `Normalizer`, `Tokenizer`, `Classifier`, `Assembler`, `QuantityCalculator`, `Keywords` (reads the same JSON).
- `ParseContext { form_code, default_unit, pack_size, pack_unit, strength_mg, per_ml, is_liquid, cont_days, locale }`
  is built from the `DrugRef` (client) / from the search document or catalog
  strength row (server, cached §5.6).
- Pure functions, no I/O, deterministic; the same fixture drives both test suites (§9.1).

### 2.16 Test table (fixture excerpt — the fixture is the contract)

Context: `tab` = 500 mg tablet, pack n/a; `syr` = 120 mg/5 ml syrup, pack 100 ml;
`eye` = eye drops 5 ml; `inh` = MDI 200 actuations; `inj` = 1 ml ampoule; `ins` = insulin vial 1000 U;
`cream` = 20 g tube; `sachet`; `cont_days` = 30. `qty` shows `value unit (source)`.

| # | Input | Ctx | schedule | unit | daily | duration | timing/code | route | qty | instruction | issues |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `1+0+1 10d af` | tab | slots [1,0,1] | tab (inferred) | 2 | 10 d | after/af | — | 20 tab (auto) | — | info unit_inferred |
| 2 | `0+0+1 7d hs` | tab | slots [0,0,1] | tab | 1 | 7 d | any/hs | — | 7 tab | — | info |
| 3 | `1/2+0+1/2 10d` | tab | slots [0.5,0,0.5] | tab | 1 | 10 d | any | — | 10 tab | — | info |
| 4 | `1+0+1 10D AF` | tab | slots [1,0,1] | tab | 2 | 10 d | after/af | — | 20 tab | — | info |
| 5 | `১+০+১ ১০d` | tab | slots [1,0,1] | tab | 2 | 10 d | any | — | 20 tab | — | info |
| 6 | `500mg+0+500mg 5d` | tab | slots [1,0,1] | tab (from mg) | 2 | 5 d | any | — | 10 tab | — | [] |
| 7 | `250mg tds 5d` | tab | freq tds ×0.5 | tab | 1.5 | 5 d | any | — | 8 tab (7.5↑) | — | [] |
| 8 | `300mg+0+300mg 5d` | tab | — | — | — | — | — | — | — | — | error unit_mismatch |
| 9 | `1 tds 5d` | tab | freq tds ×1 | tab | 3 | 5 d | any | — | 15 tab | — | info |
| 10 | `1 q6h 5d` | tab | interval 6 h ×1 | tab | 4 | 5 d | any | — | 20 tab | — | info |
| 11 | `1 q5h 3d` | tab | — | — | — | — | — | — | — | — | error interval_invalid |
| 12 | `stat` | tab | stat ×1 | tab | null | null | any | — | 1 tab | — | info |
| 13 | `1 sos` | tab | sos ×1 max null | tab | null | null | any | — | none | — | info, warning quantity_unknown |
| 14 | `1 sos x10` | tab | sos ×1 max null | tab | null | null | any | — | 10 tab (override) | — | info |
| 15 | `1 sos max 3 5d` | tab | sos ×1 max 3 | tab | 3 | 5 d | any | — | 15 tab | — | info |
| 16 | `1+0+1 cont` | tab | slots [1,0,1] | tab | 2 | continuous (30) | any | — | 60 tab | — | info continuous_assumed |
| 17 | `1+0+1 tf` | tab | slots [1,0,1] | tab | 2 | till_finish | any | — | none | — | warning quantity_unknown |
| 18 | `1+0+1` | tab | slots [1,0,1] | tab | 2 | null | any | — | none | — | warning missing_duration |
| 19 | `1+0+1 10d bf` | tab | slots [1,0,1] | tab | 2 | 10 d | before/bf | — | 20 tab | — | info |
| 20 | `hs 10d` | tab | freq od ×1 | tab | 1 | 10 d | any/hs | — | 10 tab | — | info |
| 21 | `1+0+1 5d sl` | tab | slots [1,0,1] | tab | 2 | 5 d | any | sl | 10 tab | — | info |
| 22 | `1+0+1 5d iv` | tab | — | — | — | — | — | — | — | — | error route_incompatible |
| 23 | `1+0+1 10d af x30` | tab | slots [1,0,1] | tab | 2 | 10 d | after/af | — | 30 tab (override) | — | info |
| 24 | `1+0+1 10d //after breakfast` | tab | slots [1,0,1] | tab | 2 | 10 d | any | — | 20 tab | `after breakfast` | info |
| 25 | `1+0+1 10d "খাবারের পরে"` | tab | slots [1,0,1] | tab | 2 | 10 d | any | — | 20 tab | `খাবারের পরে` | info |
| 26 | `1+0+1 10d 5d` | tab | — | — | — | — | — | — | — | — | error duplicate_duration |
| 27 | `1+0+1 tds 5d` | tab | — | — | — | — | — | — | — | — | error duplicate_schedule |
| 28 | `1+0+1 10d aff` | tab | — | — | — | — | — | — | — | — | error unknown_token `aff` → suggest `af` |
| 29 | `0+0+0 5d` | tab | — | — | — | — | — | — | — | — | error amount_zero |
| 30 | `1+0+1+1+1 5d` | tab | — | — | — | — | — | — | — | — | error slot_count |
| 31 | `max 3 5d` | tab | — | — | — | — | — | — | — | — | error max_without_sos |
| 32 | `2 tsp+0+2 tsp 5d` | syr | slots [2,0,2] | tsp | 4 (20 ml) | 5 d | any | — | 1 bottle (100 ml) | — | [] |
| 33 | `5ml+0+5ml 7d` | syr | slots [5,0,5] | ml | 10 | 7 d | any | — | 1 bottle (70 ml) | — | [] |
| 34 | `1+1+1 7d` | syr | slots [1,1,1] | tsp (inferred) | 3 (15 ml) | 7 d | any | — | 2 bottles (105 ml) | — | info unit_inferred |
| 35 | `1 tsp tds 5d` | syr | freq tds ×1 | tsp | 3 (15 ml) | 5 d | any | — | 1 bottle (75 ml) | — | [] |
| 36 | `1 drop be tds 5d` | eye | freq tds ×1 | drop | 3 | 5 d | any | be | 1 bottle | — | [] |
| 37 | `1 drop be bd` | eye | freq bd ×1 | drop | 2 | null | any | be | 1 bottle | — | [] |
| 38 | `2 puff bd` | inh | freq bd ×2 | puff | 4 | null | any | — | 1 inhaler | — | [] |
| 39 | `1 puff qds sos` | inh | sos ×1 max 4 | puff | 4 | null | any | — | 1 inhaler | — | [] |
| 40 | `1 amp im stat` | inj | stat ×1 | amp | null | null | any | im | 1 amp | — | [] |
| 41 | `1 iv bd 3d` | inj | freq bd ×1 | amp (inferred) | 2 | 3 d | any | iv | 6 amp | — | info unit_inferred |
| 42 | `10 unit sc bd cont` | ins | freq bd ×10 | unit | 20 | continuous (30) | any | sc | 1 vial (600 U) | — | info continuous_assumed |
| 43 | `apply bd 7d` | cream | freq bd ×1 | app | 2 | 7 d | any | — | 1 tube | — | [] |
| 44 | `//apply thin layer at night` | cream | null | app | null | null | any | — | 1 tube | `apply thin layer at night` | [] |
| 45 | `//with water` | tab | null | tab | null | null | any | — | none | `with water` | warning missing_schedule |
| 46 | `1 tds 5d` | (no drug) | — | — | — | — | — | — | — | — | error drug_missing |
| 62 | `1+0+1  10d   af ` (extra spaces) | tab | slots [1,0,1] | tab | 2 | 10 d | after/af | — | 20 tab | — | info |

`normalized` for row 1 is `1+0+1 10d af`; for the `//after breakfast` row `1+0+1 10d // after breakfast`;
for `250mg tds 5d` it is `1/2 tds 5d`. The full fixture (`tests/Fixtures/shorthand_cases.json`)
contains every row above plus the complete expected `dose_json`.

---
## 3. Autocomplete and quick-pick

### 3.1 `catalog_drugs` index (shared, read-only for tenants)

Index settings, synonyms and the import that fills it are specified in
CATALOG.md §4. Document shape (one document per *presentation* = brand ×
strength × form, plus one per generic):

```json
{ "id": "s1234", "source": "master", "doc_type": "presentation",
  "label": "Napa 500 mg Tab",
  "generic_id": 17, "generic_name": "Paracetamol", "generic_aliases": ["Acetaminophen", "প্যারাসিটামল"],
  "brand_id": 88, "brand_name": "Napa", "brand_aliases": ["নাপা"], "manufacturer": "Beximco Pharmaceuticals",
  "strength_id": 1234, "strength_label": "500 mg", "strength_value": 500, "strength_unit": "mg", "per_volume_ml": null,
  "strength_mg": 500, "per_ml": null,
  "dosage_form_id": 3, "form": "Tablet", "form_code": "tab", "default_unit": "tab",
  "route_id": 1, "route": "Oral", "route_code": "po",
  "pack_size": "10x10", "pack_size_value": null, "pack_unit": null,
  "info_slug": "paracetamol", "therapeutic_class": "Analgesic / antipyretic", "is_controlled": false,
  "popularity": 980, "is_active": true }

{ "id": "g17", "source": "master", "doc_type": "generic", "label": "Paracetamol (any brand)",
  "generic_id": 17, "generic_name": "Paracetamol", "generic_aliases": ["Acetaminophen", "প্যারাসিটামল"],
  "info_slug": "paracetamol", "therapeutic_class": "Analgesic / antipyretic", "is_controlled": false,
  "popularity": 990, "is_active": true }
```

Ids and the `source` field follow SCHEMA.md §5.6 (`s{strength_id}`, `c{custom_brand_id}`);
`doc_type`, `strength_mg`, `per_ml`, `form_code`, `route_code`, `default_unit` and the
`g{generic_id}` documents are this document's additions (CATALOG.md §4.1).

### 3.2 `t{tenant_id}_custom_brands` index

Model `App\Models\Tenant\CustomBrand` (a `TenantModel`) uses `Laravel\Scout\Searchable`. It does **not**
override `searchableAs()`: `TenantModel::searchableAs()` (ARCHITECTURE.md §5.1) already yields
`config('scout.prefix').'t'.Tenancy::id().'_custom_brands'` = `t{tenant_id}_custom_brands` at runtime
(`test{N}_t9001_custom_brands` under the test prefix), which is why every consumer obtains the uid from
`(new CustomBrand)->searchableAs()` rather than a literal:

```php
public function shouldBeSearchable(): bool
{
    return $this->is_active && $this->review_status !== 'rejected' && $this->deleted_at === null;   // generic_id is NOT NULL by schema
}
public function toSearchableArray(): array
{
    $form  = $this->dosage_form_id ? CatalogCache::dosageForm($this->dosage_form_id) : null;   // code, default_unit, is_liquid
    $route = $this->route_id ? CatalogCache::route($this->route_id) : null;
    $str   = StrengthLabelParser::tryParse($this->strength);                                    // strength_mg / per_ml or nulls
    return ['id' => 'c'.$this->id, 'source' => 'custom', 'doc_type' => 'presentation', 'custom_brand_id' => $this->id,
        'label' => trim("{$this->brand_name} {$this->strength} ".($form['abbreviation'] ?? $this->form)),
        'generic_id' => $this->generic_id, 'generic_name' => $this->generic_name,
        'brand_id' => $this->master_brand_id, 'strength_id' => $this->master_strength_id,        // set after promotion
        'brand_name' => $this->brand_name, 'manufacturer' => $this->manufacturer,
        'strength_label' => $this->strength, 'strength_mg' => $str?->strengthMg, 'per_ml' => $str?->perMl,
        'dosage_form_id' => $this->dosage_form_id, 'form' => $this->form, 'form_code' => $form['code'] ?? null, 'default_unit' => $form['default_unit'] ?? 'tab',
        'route_id' => $this->route_id, 'route' => $this->route, 'route_code' => $route['code'] ?? null,
        'review_status' => $this->review_status, 'promoted_to_master' => $this->promoted_to_master,
        'use_count' => $this->use_count, 'is_active' => $this->is_active];
}
```

Index settings are registered under `scout.meilisearch.index-settings` with a
wildcard-free name resolved at boot by `App\Domain\Catalog\Search\CustomBrandIndexSettings::register()`
(the same array as `catalog_drugs` minus `therapeutic_class`/`popularity`, sorted by
`use_count:desc`; CATALOG.md §4.2); `scout:sync-index-settings` runs per tenant from
`tenants:sync-search-settings`, and provisioning creates the index through
`MeilisearchIndexes::ensureTenantIndexes()` (ARCHITECTURE.md §4.4). `SCOUT_PREFIX` is empty at runtime
and `test{N}_` in tests (CONVENTIONS.md §6.1); tenant isolation is by the explicit index name. Catalog
values are read once at index time through `CatalogCache` (never joined at render time).

### 3.3 Merged, ranked list — `DrugSearchService`

`App\Domain\Prescription\Services\DrugSearchService::search(DrugSearchQuery $q): DrugSearchResult`

```php
final class DrugSearchQuery { public function __construct(
    public readonly string $q, public readonly int $doctorId, public readonly array $dxCodes = [],
    public readonly ?int $strengthMg = null, public readonly int $limit = 12) {} }
```

1. Extract a trailing number from `q` (`nap 500` → `strengthMg = 500`, `q = "nap"`).
2. One federated `Meilisearch\Client::multiSearch([...], $federation)` call (SCHEMA §5.6) with two
   `Meilisearch\Contracts\SearchQuery` objects, each `->setIndexUid(...)->setQuery($q)->setFilter(['is_active = true', ...strength filter])->setShowRankingScore(true)->setAttributesToSearchOn(['brand_name','generic_name','generic_aliases','brand_aliases'])->setFederationOptions((new FederationOptions)->setWeight(1.0))`
   for `CatalogSearchIndexer::uid('catalog_drugs')` and `(new CustomBrand)->searchableAs()` (both carry the Scout prefix), and `$federation = (new MultiSearchFederation)->setLimit(40)`.
   Hits carry `_federation.indexUid` and `_rankingScore`; the app re-ranks (step 4).
3. Doctor boosts from Redis hash `t:{tenantId}:doctor:{doctorId}:usage` (`presentation_key → use_count`, warmed from `doctor_drug_usage`) and set `…:fav:{icd}` for each code in `dxCodes`.
4. Score: `score = _rankingScore × 1000 + min(use_count, 200) × 2 + (fav_for_dx ? 150 : 0) + (doc_type === 'presentation' ? 20 : 0)`; sort desc, stable on `popularity`; take `limit`. Generic docs always keep at least one slot if they scored (so "prescribe by generic" is reachable). Net effect matches SCHEMA §5.6: favourites for the current diagnosis first, then master, then custom.
5. Response (`GET /panel/search/drugs?q=nap&dx=J06.9&limit=12`):

```json
{ "q": "nap", "took_ms": 9,
  "hits": [
    { "id": "s1234", "source": "master", "doc_type": "presentation", "label": "Napa 500 mg Tab",
      "generic_id": 17, "generic_name": "Paracetamol", "brand_id": 88, "custom_brand_id": null, "brand_name": "Napa",
      "manufacturer": "Beximco", "strength_id": 1234, "strength_label": "500 mg", "strength_mg": 500, "per_ml": null,
      "dosage_form_id": 3, "form": "Tablet", "form_code": "tab", "default_unit": "tab", "route_id": 1, "route": "Oral", "route_code": "po",
      "pack_size_value": null, "pack_unit": null, "info_slug": "paracetamol",
      "usage": 42, "fav_for_dx": true, "score": 1187.5, "last_shorthand": "1+0+1 5d af" },
    { "id": "c55", "source": "custom", "doc_type": "presentation", "custom_brand_id": 55, "brand_id": null, "generic_id": 17, "...": "…",
      "review_status": "pending", "score": 812.0 },
    { "id": "g17", "source": "master", "doc_type": "generic", "generic_id": 17, "generic_name": "Paracetamol", "score": 790.0 }
  ] }
```

`last_shorthand` (from `doctor_favourites.default_dose.shorthand`) is offered as a
ghost suggestion after the chip: pressing `→` accepts it — a routine line then
costs three keys.

### 3.4 ICD-10 search — `GET /panel/search/icd?q=sugar`

Index `catalog_icd10` (CATALOG.md §4.3). Aliases are plain language in both
scripts (`sugar`, `diabetes`, `dm`, `ডায়াবেটিস`, `সুগার` → `E11`), synonyms map
colloquial → clinical. The doctor's own recent codes (Redis zset
`t:{tenantId}:doctor:{doctorId}:icd`, incremented on issue) rank first when they
match. Response: `{hits:[{code:"E11.9", title:"Type 2 diabetes mellitus without complications", title_bn:"…", chapter:"IV", is_billable:true, usage:31}]}`.
Free text with no code is allowed (`icd10_code:null`) and prints as typed.

### 3.5 Per-doctor learning

`App\Domain\Prescription\Listeners\RecordDoctorUsage` on `PrescriptionIssued` (queued, queue `default`, `TenantAware`):

- `doctor_drug_usage` (monthly learning rows): upsert on `(doctor_id, icd10_code, generic_id, brand_id, custom_brand_id, strength_id, period_month)` → `use_count + 1`, `last_used_at`, `last_dose = {dose_schedule, duration_days, timing, instruction, shorthand: dose_json.normalized}`; `icd10_code` = the visit's primary final diagnosis (first `kind:'final'`, else first provisional, else null).
- `doctor_favourites` (ranked quick-pick): upsert the global row (`icd10_code NULL`) and one row per diagnosis code on the visit, identity `(doctor_id, icd10_code, generic_id, brand_id, custom_brand_id)` → `use_count + 1`, `label`, `default_dose` (same shape as `last_dose`, incl. the `shorthand` key — a JSON-shape extension of SCHEMA's `default_dose`). `is_pinned` rows are never overwritten by learning. The nightly `prescriptions:recompute-favourites` command (registered by `App\Domain\Prescription\Schedule` via `tenants:run`, ARCHITECTURE.md §4.7; work runs on queue `default`) rewrites `rank` from the trailing 6 months of `doctor_drug_usage` (pinned first).
- Also records `(doctor_id, icd10_code)` usage for ICD ranking, `advice_snippets.use_count`, `prescription_templates.use_count` and `investigation_catalog` usage per doctor (Redis only).
- Cache refresh: rewrite `…:top50` (global favourites ordered by rank, TTL 7 d), `…:usage` hash, `…:fav:{icd}` sets.
- Top-50 surfacing: right pane tab "Top 50" (from props), "For Dx" tab shows
  protocols (§3.7) then `GET /panel/doctors/me/favourites?icd=J06.9` → up to 15
  `{drug: DrugRef, label, default_dose, use_count, is_pinned}`; one click inserts drug
  *and* shorthand as a committed line.

### 3.6 Templates

Tables (SCHEMA §3.4): `prescription_templates` (`doctor_id` nullable = clinic-shared,
`name`, `shorthand` e.g. `/cold`, `icd10_code`, `diagnosis_title`, `is_shared`,
`body` jsonb `{chief_complaints, examination_findings, advice[], investigations[], follow_up_days}`,
`use_count`) and `prescription_template_items` (the `prescription_items` columns
incl. `dose_schedule`, `dose_json` = `ParsedLine`, whose `normalized` string is
the re-parseable shorthand).

| Endpoint | Body / result |
|---|---|
| `GET /panel/prescription-templates` | `[{id,name,shorthand,icd10_code,diagnosis_title,item_count,follow_up_days,is_shared,use_count}]` |
| `GET /panel/prescription-templates/{id}` | full body incl. items |
| `POST /panel/prescription-templates` | `{name, shorthand?, from_prescription_id?, is_shared?, icd10_code?}` — with `from_prescription_id` the current draft/issued body is captured (items with their `dose_json`, investigations, advice, follow-up interval, complaints/findings optional) |
| `PUT /panel/prescription-templates/{id}` | same body, full replace |
| `DELETE /panel/prescription-templates/{id}` | soft delete |
| `POST /panel/prescriptions/{prescription}/apply-template/{template}` | `{mode:"append"|"replace"}` → returns the updated `PrescriptionDraft` + `alerts`. Items are re-parsed from `dose_json.normalized` against today's presentation (catalog refs re-validated; a template item whose catalog id vanished is inserted as a *drug-less* line with `drug_missing` so the doctor re-picks). Duplicate generic already on the pad → skipped with a notice. Follow-up = today + `body.follow_up_days`; `icd10_code`/`diagnosis_title` added as a provisional diagnosis if absent. Audit `update {event: template_applied}`. Also triggered by typing `/cold` in an Rx line (§2.3). |

Applying then editing is the normal flow: the template is a starting point,
never a lock.

### 3.7 Favourites and protocols

- A **protocol** is a template with an `icd10_code`. It surfaces at the top of
  the "For Dx" tab whenever that code is on the pad.
- A **favourite** is a learned `(dx, drug, default_dose)` row (`doctor_favourites`).
  `is_pinned = true` keeps it at the top regardless of `use_count`
  (`PATCH /panel/doctors/me/favourites/{id} {is_pinned:true}`); pinned rows are
  never rewritten by the nightly recompute. `DELETE` removes a learned row (it
  can be re-learned); pinned rows must be unpinned first.
- A doctor may seed favourites without prescribing: right-click a search hit →
  "Pin for J06.9".

### 3.8 Search / quick-pick endpoint summary

| Method & path | Purpose | Latency budget |
|---|---|---|
| `GET /panel/search/drugs?q&dx[]&limit` | §3.3 | ≤ 40 ms server |
| `GET /panel/search/icd?q&limit` | §3.4 | ≤ 30 ms |
| `GET /panel/doctors/me/favourites?icd` | §3.5 | ≤ 30 ms |
| `GET /panel/search/investigations?q`, `GET /panel/doctors/me/top-drugs`, `GET /panel/advice-snippets?q&category` | clinic catalog (Postgres `ILIKE`, no Meilisearch), top-50 refresh, `Ctrl+K` palette | ≤ 20 ms |

All search endpoints are `GET`, cacheable per user for 30 s
(`Cache-Control: private, max-age=30`), and rate-limited at 20 req/s per user.

---

## 4. Clinical structure and data flow

### 4.1 Chief complaints (with duration)

Chip list. Typing `fever 3d` splits on the trailing duration token using §2.5's
`duration` rule (`3d`, `2w`, `1m`; anything else stays in the text). Stored on
the visit: `visits.chief_complaints` `[{text:"Fever", text_bn:null, duration:"3d", sort:0}]`
(SCHEMA shape); the Bangla duration label is derived at render. Printed `C/C: Fever — 3 days; Cough — 2 days`.
Quick-pick: doctor's own recent complaints (Redis zset, last 20).

### 4.2 Vitals (compounder enters, doctor reviews)

- The compounder reaches it from the reception board: the vitals button on a **checked-in** row `POST`s
  `panel.reception.vitals.open` (`VitalsDeskController`), which opens the visit through the same idempotent
  `StartVisit` the doctor screen uses and redirects to `GET /panel/reception/visits/{visit}/vitals`
  (`Reception/Vitals`). That screen is a form over the endpoint below — no second write path — and is online only
  (OFFLINE.md §6.2, §11: clinical bodies are never cached on the device).
- Compounder screen `POST /panel/visits/{visit}/vitals` (`VitalsController::store`, permission `prescriptions.vitals.record` — held by the `receptionist` (compounder) and `doctor` roles):
  `{bp_systolic, bp_diastolic, pulse_bpm, temperature_c, spo2_percent, respiratory_rate, weight_kg, height_cm, blood_glucose_mgdl, notes}`;
  server sets `recorded_by_user_id`, `recorded_at`, computes `bmi`. Several rows per visit are allowed (re-check); the writer shows the latest.
- Writer shows the row read-only with a `☐ Reviewed` tick and an Edit pencil.
  Doctor edits go to `PATCH /panel/vitals/{vital}` (same row, `edited_by_doctor = true`, diff audited as `update` on `Vital`);
  ticking Reviewed (or the first doctor save) sets `reviewed_by_doctor_at` (SCHEMA.md §3.4).
- BMI = `weight_kg / (height_cm/100)^2` (1 dp), computed by the app on write; the client computes it too for instant
  display (`panel/lib/prescription/vitals.ts`, shared by the desk screen and the writer card). Measurements are held
  as **text** while they are typed and parsed once on save — `Number('37.')` is `37`, so parsing per keystroke turns
  37.6 °C into 376.
- Age-based rules: age < 12 y and `weight_kg` null → banner "Weight needed for pediatric dosing" (PediatricDoseCheck emits `warning`, §5.3).
- `VitalsRow`: `{id, bp_systolic, bp_diastolic, pulse_bpm, temperature_c, spo2_percent, respiratory_rate, weight_kg, height_cm, bmi, blood_glucose_mgdl, notes, recorded_by:{id,name}, recorded_at, edited_by_doctor, reviewed_by_doctor_at}`.

### 4.3 On-examination findings

Chip list, free text, voice-dictation enabled, quick-pick from
`advice_snippets` rows with `category = 'finding'` (SCHEMA.md §3.4).
Stored as `visits.examination_findings` (text; chips joined with `; `, split back
on load). Printed `O/E: …`.

### 4.4 Diagnosis

Chips from §3.4; each chip toggles `provisional`/`final` (click the badge or
`Ctrl+F` on the chip). Stored in `visits.diagnoses` `[{icd10_code, title, kind, sort}]`
(`title` is a snapshot; the GIN index serves top-diagnosis reports).
Printed `Dx: Acute URTI (J06.9)` — codes print unless `pad.layout.flags.icd_codes = false`.

### 4.5 Investigations

From `investigation_catalog` (clinic's own tests: `name, name_bn, code, category, price_paisa, prep_instructions, is_active`).
Each pick creates a `prescription_investigations` row with snapshot columns
`name`, `name_bn`, `price_paisa`, plus `investigation_catalog_id`,
`external_diagnostic_centre_id` (nullable), `referral_note`, `is_urgent`, `sort_order`.
Printing: a numbered list (prep instructions in small type); prices and total
print if `pad.layout.flags.investigation_prices`. External referral: select a
row from `external_diagnostic_centres` for one or more investigations and add a
note → printed as "Referred to {centre}: {tests} — {note}". Ad-hoc tests (not
in the catalog) are allowed with `investigation_catalog_id = null` and no price.

### 4.6 Rx items

§2. Each `prescription_items` row is written at draft time with the snapshot
text (`generic_name, brand_name, strength, form, route`) copied from the
search document the doctor picked, the soft ids (`generic_id, brand_id,
strength_id, custom_brand_id`), `info_url_slug`, `dose_json` and the
projections (`dose_schedule, duration_days, duration_text, quantity,
quantity_unit, timing, instruction, instruction_bn`). `is_continued` is set by
the `cont` duration (feeds `patient_medications` at issue). Issue re-validates ids
against the catalog (`CatalogIdExists`) and re-copies snapshot text from the
live catalog row *at that moment* (this is the "snapshot on write" moment;
after issue nothing is ever re-read). Order is `sort_order`; reordering is a
draft save.

### 4.7 Advice

`advice_snippets` (`doctor_id` nullable = clinic library, `shorthand` e.g. `/rest`,
`category` diet|lifestyle|warning|followup|general|finding|complaint (SCHEMA.md §3.4),
`text`, `text_bn`, `is_shared`, `use_count`, `is_active`). Picks and free text
(voice-enabled) create `prescription_advice` rows `{advice_snippet_id nullable, text, text_bn, sort_order}`.
Printed per `language`: `both` prints `text_bn` when present else `text`; a
snippet with only one language prints that one.
CRUD: `GET/POST/PUT/DELETE /panel/advice-snippets[/{id}]`; "Save as snippet" on any typed advice line (1 click).

### 4.8 Follow-up → draft booking

Chips `3d 7d 15d 1m` + date picker + note; `create_booking` default `true`.
Stored on the visit: `visits.follow_up_on` (date) and `visits.follow_up_note`;
the chosen interval in days is kept in the snapshot for the printed label. On
issue, `IssuePrescription` dispatches:

```php
namespace App\Domain\Prescription\Events;
final class FollowUpScheduled {   // ShouldDispatchAfterCommit
    public function __construct(
        public readonly int $tenantId, public readonly int $prescriptionId, public readonly int $visitId,
        public readonly int $patientId, public readonly int $doctorId, public readonly ?int $branchId,
        public readonly string $followUpDate,           // Y-m-d, tenant tz
        public readonly ?string $note, public readonly bool $createBooking) {}
}
```

The Booking module owns the listener (ARCHITECTURE.md §5.4):
`App\Domain\Booking\Listeners\CreateDraftFollowUpAppointment::handle(FollowUpScheduled $e): void`
(queued, `TenantAware`) — creates an appointment with `status = draft`, `type = followup`, `channel = followup`,
`follow_up_of_visit_id = $e->visitId` and `scheduled_date` = the preferred date (there is no `prescription_id` column —
the prescription is reached through `visits.current_prescription_id`); **no serial is allocated** until reception
or the patient confirms through `RebookFollowUp` (SERIAL_ENGINE.md §11.3). Notifications listens to the
same event for the "follow-up due" reminder. This document does not implement either. The writer
shows the draft appointment on the issued view ("Follow-up draft created") from the `visit.follow_up_on`
value; the appointment's public id arrives later through the reception board, not through this event.

### 4.9 Referral

`prescription_referrals` `{type: doctor|hospital|diagnostic_centre, referred_to_doctor_id, external_diagnostic_centre_id, referred_to_name, referred_to_specialty, note, is_urgent}`
(several allowed). Picker searches the tenant's doctors (`/panel/search/doctors?q`),
the external centres, or free text. Printed block "Referred to Dr … (Cardiology) — note".

### 4.10 Voice dictation

`<DictationButton for={fieldRef} />` wraps every free-text field (complaints,
findings, advice, instruction, notes): `window.SpeechRecognition ?? window.webkitSpeechRecognition`,
`lang` from the doctor's preference (`bn-BD` | `en-US`, toggled by long-press on
the mic, persisted in `doctor_profiles.prefs.dictation_lang`), `continuous`,
`interimResults` (interim text greyed at the caret, final segments inserted).
API absent (Firefox, some WebViews) → button hidden, nothing else changes. Audio
never touches our servers (browser engine only — stated on the settings page).
`Ctrl+Shift+V` toggles dictation for the focused field.

### 4.11 Draw / annotate

`<DrawingCanvas>` (pointer events, pressure-aware, pen prioritised over touch
when a pen has been seen in the session). Backgrounds are SVGs in
`resources/svg/drawing-backgrounds/{blank,dental_adult,dental_child,eye_pair,skeleton_front,body_front_back,spine,abdomen}.svg`,
listed in `features.drawing_backgrounds`.

```ts
export interface DrawingJson {                                   // = prescriptions.drawing_json (SCHEMA shape + `texts`)
  canvas: { w: number; h: number; template: 'blank' | 'dental_adult' | 'dental_child' | 'eye_pair' | 'skeleton_front' | 'body_front_back' | 'spine' | 'abdomen' };
  strokes: { tool: 'pen' | 'marker' | 'eraser'; color: string; width: number; points: [number, number, number][] }[];   // x, y, pressure
  texts?: { x: number; y: number; text: string; size: number }[];
}
```

Save: `POST /panel/prescriptions/{prescription}/drawing` multipart `{json, png}` →
`drawing_json` (jsonb) and `drawing_image_path` on the `uploads` disk (ARCHITECTURE.md §8.7 — S3 in
production, falls back to `local` in dev) at
`TenantPath::for("patients/{patient_public_id}/prescriptions/{root_public_id}/v{n}/drawing.png")`
= `tenants/{tenant_id}/patients/…/drawing.png` (every tenant object key goes through `App\Support\Storage\TenantPath`).
Printing uses `App\Domain\Prescription\Render\DrawingSvgRenderer::render(array $json): string`
(inline SVG from the JSON — vector, no file access); the PNG is for the timeline
thumbnail and the public verification page.

### 4.12 Handwriting mode (adoption bridge — first-class)

Toggle `Handwrite` (1 click / `Ctrl+H`). The centre pane becomes a stylus
canvas whose aspect ratio equals the pad body area for the doctor's paper and
margins (§7.2), so what is written lands exactly where it prints. Up to 3
pages (`+ page`). Undo/redo in-memory; save on every stroke pause (2 s) as PNG
(`devicePixelRatio` × up to 2480 px wide) to
`POST /panel/prescriptions/{prescription}/handwriting` multipart `{page, png}` →
`uploads` disk, `TenantPath::for("patients/{patient_public_id}/prescriptions/{root_public_id}/v{n}/handwriting-{page}.png")`;
`handwriting_image_path` stores page 1's path, further pages follow the naming
convention and the snapshot lists all pages. the snapshot key `snapshot.prescription.mode`
is `handwriting` when any page exists, else `structured` (a snapshot key, not a column).

Print: when handwriting pages exist they *are* the body, one page each;
structured Rx lines, if any, print after them under "Rx (typed)". Safety checks
run on structured lines only; the Issue dialog carries the fixed warning
"Handwritten content is not safety-checked". Structured header (patient,
vitals, dx chips) still prints above page 1 unless `preprinted` mode.

### 4.13 Draft persistence — `PATCH /panel/prescriptions/{prescription}/draft`

Controller `DraftController::update` → `App\Domain\Prescription\Actions\SaveDraft::handle(Prescription $rx, DraftPayload $payload): DraftResult`.
Precondition: `status = draft` and the doctor owns it (403 otherwise). Request:

```json
{ "language": "both",
  "visit": { "chief_complaints": [{"text":"Fever","text_bn":null,"duration":"3d","sort":0}],
             "examination_findings": "Throat congested",
             "diagnoses": [{"icd10_code":"J06.9","title":"Acute upper respiratory infection","kind":"provisional","sort":0}],
             "follow_up_on": "2026-09-13", "follow_up_note": null },
  "follow_up_days": 7, "create_booking": true, "vitals_reviewed": true,
  "items": [{"key":"i1","sort_order":0,
             "drug":{"kind":"presentation","generic_id":17,"brand_id":88,"custom_brand_id":null,"strength_id":1234},
             "shorthand":"1+0+1 5d af",
             "safety_overrides":[{"fingerprint":"interaction:major:17:203","reason":"Short course, monitoring INR"}]}],
  "investigations": [{"key":"x1","investigation_catalog_id":12,"external_diagnostic_centre_id":null,"referral_note":null,"is_urgent":false,"sort_order":0}],
  "advice": [{"key":"a1","advice_snippet_id":7,"text":"Drink plenty of water","text_bn":"প্রচুর পানি পান করুন","sort_order":0}],
  "referrals": [],
  "expected_updated_at": "2026-09-06T10:15:00+06:00" }
```

Server: validates ids (`CatalogIdExists` for generic/brand/strength, tenant
existence for custom brand/snippet/investigation/centre), re-parses each item,
writes the `visit.*` keys to the `visits` row, writes child rows by `key`
(upsert; missing keys deleted), stores overrides in
`prescription_items.safety_overrides` (`[{fingerprint, kind, severity, reason, overridden_by_user_id, overridden_at}]` — extends SCHEMA's shape),
runs `SafetyPipeline::forDraft`, audits `update` on `Prescription` and `Visit`
with `before/after` diffs. Response `200`:

```json
{ "prescription": { "id": 501, "version": 1, "status": "draft", "updated_at": "2026-09-06T10:15:03+06:00",
    "items": [{"key":"i1","id":9001,"parsed":{...ParsedLine...},"snapshot":{"generic_name":"Paracetamol","brand_name":"Napa","strength":"500 mg","form":"Tablet","route":"Oral"}}] },
  "alerts": [ ...SafetyAlert... ],
  "issue_blocked_by": ["interaction:contraindicated:17:203"] }
```

`422` when a server parse has `error` issues: `{errors:{"items.i1":[ParseIssue…]}}`.
`409` when `expected_updated_at` ≠ the row's `updated_at` (another tab saved):
body carries the server draft; the client shows "Updated elsewhere — reload".

---
## 5. Safety intelligence

Runs server-side on every draft save (§4.13), on every explicit check
(§5.5) and again inside the issue transaction (§6.1). Every check reasons over
`generic_id` (I2). The client only displays.

### 5.1 Contract

```php
namespace App\Domain\Prescription\Enums;                 // enums live in Enums (CONVENTIONS.md §3.2), not in Safety

enum SafetyStage: string { case Draft = 'draft'; case Issue = 'issue'; }
enum SafetySeverity: string { case Info = 'info'; case Warning = 'warning'; case Critical = 'critical'; }   // referred to as `Severity` below

namespace App\Domain\Prescription\Safety;

use App\Domain\Prescription\Enums\{SafetyStage, SafetySeverity as Severity};

interface SafetyCheck
{
    public function key(): string;                       // 'interaction', 'allergy', …
    public function run(SafetyContext $ctx): array;      // list<SafetyAlert>; pure w.r.t. tenant data, reads catalog via CatalogCache only
}

final class SafetyContext {
    public function __construct(
        public readonly int $prescriptionId,
        public readonly SafetyStage $stage,
        public readonly PatientSafetyProfile $patient,   // age_months, weight_kg, sex, is_pregnant, is_lactating,
                                                         // renal_impairment, hepatic_impairment (bools derived §5.3),
                                                         // allergy_generic_ids[], allergy_class_ids[], allergy_texts[],
                                                         // current_medication_generic_ids[]
        public readonly array $items,                    // list<SafetyItem>: key, generic_id, brand_id, custom_brand_id, strength_id,
                                                         // strength_mg, per_ml, form_code, route_code, dose_json (ParsedLine|null),
                                                         // daily_mg (float|null, computed by DailyDoseCalculator), per_dose_mg
        public readonly array $overrides,                // fingerprint => ['reason' => string, 'by' => int, 'at' => string]
    ) {}
}

final class SafetyAlert implements \JsonSerializable {
    public function __construct(
        public readonly string $key, public readonly string $code, public readonly Severity $severity,
        public readonly string $fingerprint,             // stable id: "{key}:{code}:{sorted generic ids}[:bucket]"
        public readonly bool $overridable,
        public readonly string $title, public readonly string $message, public readonly string $messageBn,
        public readonly array $itemKeys, public readonly array $genericIds, public readonly array $evidence,
        public ?array $overridden = null,
    ) {}
    public function blocksIssue(): bool { return $this->severity === Severity::Critical && ($this->overridden === null || !$this->overridable); }
}

final class SafetyPipeline {
    /** @param list<SafetyCheck> $checks  (order from config('prescription.safety.checks')) */
    public function __construct(private readonly array $checks, private readonly CatalogCache $catalog) {}
    public function run(SafetyContext $ctx): SafetyReport;   // applies overrides, sorts critical→info
}
final class SafetyReport { /** @var list<SafetyAlert> */ public array $alerts; /** @var list<string> */ public array $issueBlockedBy; public array $computed; }
```

Alert JSON (as returned in every draft/check/issue response):

```json
{ "key": "interaction", "code": "interaction.contraindicated", "severity": "critical",
  "fingerprint": "interaction:contraindicated:17:203", "overridable": true,
  "title": "Contraindicated combination", "message": "Warfarin + Aspirin: major bleeding risk. Management: avoid; if unavoidable monitor INR.",
  "message_bn": "ওয়ারফারিন + অ্যাসপিরিন: রক্তক্ষরণের ঝুঁকি…",
  "item_keys": ["i2", "i3"], "generic_ids": [17, 203],
  "evidence": { "pair": ["Warfarin", "Aspirin"], "severity_source": "contraindicated", "mechanism": "…", "source": "catalog v2026.09" },
  "overridden": null }
```

### 5.2 Grading and blocking rules

| Severity | UI | Issue |
|---|---|---|
| `info` | grey line under the item | never blocks, not listed in the Issue dialog |
| `warning` | amber badge on the item + alerts strip | never blocks; listed in the Issue dialog for acknowledgement (one click) |
| `critical` | red, alerts strip expanded, Issue button becomes "Review N critical" | blocks until overridden with reason; non-overridable codes block absolutely |

Non-overridable codes: `custom_brand.unlinked`, `catalog.ref_missing`, `parse.error`.

Override flow: alert → "Override" → reason (≥ 10 chars, free text) → stored on
every item the alert names (`prescription_items.safety_overrides[]`, §4.13) →
audit `update {event: safety_override, alert, reason}` → alert shows
"Overridden by Dr X: reason". Overrides are keyed by fingerprint: if the item
changes such that the fingerprint changes (different generic, dose bucket
crosses a threshold) the override is dropped and the alert returns. On issue,
overrides are copied into `snapshot.safety.overrides` and printed nowhere.

### 5.3 The checks (`App\Domain\Prescription\Safety\Checks\*`)

| Class | Logic | Severity |
|---|---|---|
| `InteractionCheck` | All unordered pairs over `items[].generic_id ∪ patient.current_medication_generic_ids` (pairs with at least one prescribed item). Lookup `drug_interactions` by `(least(a,b), greatest(a,b))`. | contraindicated → critical; major → warning; moderate → warning (collapsed); minor → info. Evidence carries mechanism/management. Pairs involving a patient medication say "with current medication X". |
| `AllergyCheck` | `patient_allergies` (`is_active`): `allergen_type = generic` → item generic ∈ `allergy_generic_ids` → hit. `allergen_type = allergy_class` (or the class of an allergen generic) → item generic ∈ `allergy_class_generics` → class hit. `allergy_classes.cross_reacts_with[{allergy_class_id, probability_pct}]` (e.g. penicillins → cephalosporins) → cross hit. `food/environmental/other` rows: `allergen_name` fuzzy-matched (trigram ≥ 0.6) against generic and class names. | direct/class → critical; cross → warning (critical when `probability_pct ≥ 10` and patient `severity = severe`); free-text match → warning; unstructured text with no match → info "verify allergy: 'sulfa'". |
| `DuplicateTherapyCheck` | Two items with the same generic → duplicate (combination generics expand through `generics.components`). Two items with the same `generics.therapeutic_class` (e.g. two NSAIDs, two PPIs) → class duplicate. Item generic ∈ active `patient_medications` → already-on. | duplicate → warning (critical if both systemic and same route); class → info; already-on → info. |
| `PediatricDoseCheck` | Applies when `age_months < 144` or (`weight_kg < 40` and age < 18 y). Needs `weight_kg`; missing → one `warning pediatric.weight_missing`. Picks the `max_daily_doses` row with `population = pediatric` whose `[min_age_months, max_age_months]` contains the age (route-scoped row preferred); age below `min_age_months` of every row → critical `pediatric.contraindicated_age`. `mg_per_kg_day = daily_mg / weight_kg` vs `max_mg_per_kg_per_day`; daily vs `max_mg_per_day`; per-dose vs `max_mg_per_dose`. `computed.items[key].mg_per_kg_day` feeds the interpretation line when `generics.is_pediatric_weight_based`. | > 1.5 × limit → critical; > limit → warning; ≥ 0.8 × → info. |
| `PregnancyLactationCheck` | `is_pregnant` = `patient_conditions` (`status` active/chronic) has `icd10_code` `Z33.1` or any `O` chapter code (the writer's quick toggle writes `Z33.1`; trimester from `onset_date` when known). Row selection: `pregnancy_categories` with matching `trimester`, else the `trimester NULL` row. `category` D → warning, X → critical. `is_lactating` (`Z39.1`): `lactation` `caution` → warning, `avoid` → critical, `unknown` → info. Female 10–55 y, status unknown, item category D/X → one info `pregnancy.status_unknown` with a "Set status" action. | as listed |
| `RenalHepaticCheck` | `renal_impairment` = any active condition code in N17–N19 (toggle writes `N18.9`); `hepatic_impairment` = K70–K77 or B18 (toggle writes `K76.9`). `renal_cautions` rows (most specific `egfr_below` first; the patient's eGFR is unknown to v1, so the `egfr_below NULL` row or the highest threshold applies) / `hepatic_cautions` (`child_pugh_class`): `level` `avoid` → critical, `adjust_dose` → warning, `caution` → info; `advice` text in evidence. | as listed |
| `MaxDailyDoseCheck` | Adults (else Pediatric handles). `daily_mg` summed per generic across items (two paracetamol brands add up; combination components counted). Row: `population = elderly` when age ≥ 65 and such a row exists, else `adult`; `route_id` row preferred over the any-route row. vs `max_mg_per_day`; per-dose vs `max_mg_per_dose`. | > max → critical; ≥ 80 % → info; per-dose > max → warning. |
| `CustomBrandLinkCheck` | For items with `custom_brand_id`: row exists in the tenant, not soft-deleted, `is_active`, `review_status ≠ rejected`, `CatalogCache::generic(custom_brand.generic_id)` exists and is active, and `item.generic_id === custom_brand.generic_id`. (`generic_id` is NOT NULL by schema, so "unlinked" in practice means the generic vanished or was deactivated — reconcile flips such brands to `is_active = false`.) | fail → critical `custom_brand.unlinked`, non-overridable (I4). |
| `CatalogReferenceCheck` | `generic_id`, `brand_id`, `strength_id` resolve via `CatalogCache` and are active; strength belongs to brand belongs to generic. | fail → critical `catalog.ref_missing`, non-overridable. Inactive (discontinued) → warning `catalog.discontinued`. |

`DailyDoseCalculator::dailyMg(ParsedLine, strength_mg, per_ml, form_code): ?float`
— counted units × `strength_mg`; liquids `daily_ml × strength_mg / per_ml`
(tsp/tbsp converted); `puff`/`spray`/`drop` → `strength_mg` per actuation when
known else null; sos with max → max-based; stat → per-dose only.

Drug-level checks (interaction, allergy, duplicate, pregnancy, renal/hepatic,
custom brand, catalog ref) run as soon as a chip is committed with no
shorthand yet; dose-level checks (pediatric, max dose) wait for a parse.

### 5.4 Check registration

`config/prescription.php`:

```php
'safety' => ['checks' => [
    Checks\CatalogReferenceCheck::class, Checks\CustomBrandLinkCheck::class, Checks\AllergyCheck::class,
    Checks\InteractionCheck::class, Checks\DuplicateTherapyCheck::class, Checks\PediatricDoseCheck::class,
    Checks\MaxDailyDoseCheck::class, Checks\PregnancyLactationCheck::class, Checks\RenalHepaticCheck::class,
]],
```

### 5.5 Endpoint — `POST /panel/prescriptions/{prescription}/check`

Runs the pipeline on the *posted* body without persisting (used the instant a
chip is committed, before the 400 ms autosave). Request: the `items` and
`overrides` arrays of §4.13 (other sections optional). Response:

```json
{ "alerts": [ …SafetyAlert… ], "issue_blocked_by": ["custom_brand:unlinked:c55"],
  "computed": { "items": { "i1": { "daily_mg": 1000, "per_dose_mg": 500, "mg_per_kg_day": 41.7, "adult_max_mg_day": 4000 } } },
  "catalog_version": "2026.09.1" }          // catalog_versions.version of the current `applied` row (CATALOG.md §2); the same string is frozen into snapshot.prescription.catalog_version (§6.2)
```

Latency budget 80 ms at 10 items (all catalog reads from Redis).

### 5.6 Caching of catalog lookups — `App\Domain\Catalog\Services\CatalogCache`

Read-through Redis cache in front of the `catalog` connection, keyed by catalog
version so an import invalidates everything at once: `catalog:{ver}:generic:{id}`,
`…:brand:{id}`, `…:strength:{id}`, `…:dosage_form:{id}`, `…:route:{id}` (row
arrays), `…:interaction:{a}:{b}` (row or `"none"` — negatives cached too),
`…:allergy_class:{id}:generics`, `…:generic:{id}:allergy_classes`,
`…:maxdose:{generic}`, `…:pregnancy:{generic}`, `…:renal:{generic}`,
`…:hepatic:{generic}` (all TTL 24 h); `catalog:current_version` (TTL 60 s) →
latest `catalog_versions` row with `status = applied`. Batch API
(`generics(array $ids)`, `interactions(array $pairs)`) uses `MGET` and fills
misses with one `WHERE … IN` query; per-request memoisation via `once()`;
`CatalogCache::fake()` seeds from arrays in tests.

### 5.7 AI assist (advisory only)

```php
namespace App\Domain\Prescription\AI;
interface AiAssistant {
    public function isAvailable(): bool;
    public function summariseVisits(VisitSummaryRequest $r): AiResult;        // exactly 3 lines, ≤ 140 chars each
    public function suggestDifferentials(DifferentialRequest $r): AiResult;   // ≤ 5 {label, icd_code|null, rationale}
}
final class NullAiAssistant implements AiAssistant { /* isAvailable(): false; both methods return AiResult::unavailable() */ }
final class HttpChatAssistant implements AiAssistant { /* provider-agnostic chat-completion over HTTP; base_url, key, model,
    timeout (8 s) from config('services.ai'); prompts in resources/ai/prompts/{summary,differentials}.v1.md */ }
```

`PrescriptionServiceProvider` (the module's single provider, CONVENTIONS.md §12) binds `NullAiAssistant` unless `services.ai.key` is set
and the Pennant feature `ai-assist` (`App\Domain\SaaS\Features\AiAssist`) is on for the tenant — the product works
with no AI key. Input is de-identified (age, sex, dates relative to today,
diagnoses, items, vitals — never name, phone, patient id).

| Endpoint | Body → result |
|---|---|
| `POST /panel/visits/{visit}/ai/summary` | `{}` → `{suggestion_id, type:"history_summary", lines:[…3], model, generated_at}` |
| `POST /panel/visits/{visit}/ai/differentials` | `{chief_complaints, examination_findings, vitals}` (current draft values) → `{suggestion_id, type:"differential", items:[{label, icd10_code, rationale}]}` |
| `PATCH /panel/ai-suggestions/{id}` | `{accepted: true|false, accepted_fragment?: string}` |

Every call writes `ai_suggestions` (`visit_id, doctor_id, patient_id, type` history_summary|differential, `provider, model, prompt` (ENC; first line carries the prompt template version), `response` (ENC), `accepted` (nullable), `accepted_at`, `accepted_fragment` (ENC), `input_tokens, output_tokens, latency_ms, request_id`).
Nothing is inserted into the prescription by the AI: the doctor clicks
"Insert as provisional Dx" on a differential (which marks `accepted = true`) or
dismisses (`false`). The panel carries the fixed line "Advisory only — verify
clinically". Unavailable → panel hidden, no error.

---

## 6. Issue, immutability, amendment

### 6.1 `IssuePrescription`

`App\Domain\Prescription\Actions\IssuePrescription::handle(Prescription $rx, IssueRequest $req): Prescription`
(`IssueRequest { language, print: bool, acknowledged_warnings: string[] }`). Inside one transaction with `SELECT … FOR UPDATE` on the row:

1. Assert `status = draft`, owner doctor, last draft save succeeded (`rev` matches).
2. Re-parse every item server-side; any `error` issue → 422.
3. Validate every catalog id (`CatalogIdExists`, CATALOG.md §7) and copy snapshot text (`generic_name, brand_name, strength, form, route`) from the live catalog row **now**; custom brands copy from the tenant row.
4. `SafetyPipeline::run(stage: Issue)`. Any `blocksIssue()` alert → 422 `{issue_blocked_by:[…], alerts:[…]}`.
5. Build `snapshot` via `SnapshotBuilder::build($rx, $doctorPad, $catalogVersion): array` (§6.2).
6. Set `status = issued`, `issued_at = now()`, `issued_by_user_id`, `root_prescription_id` (= own id for v1), `verification_code = VerificationCode::generate()` (12 chars, Crockford base32, unique), `language`, `snapshot`, `snapshot_sha256` (sha256 of the canonical JSON — sorted keys, no whitespace), `pad_snapshot` (copy of `doctor_pad_settings`); set `visits.current_prescription_id`; insert `patient_medications` (`source = prescription`) for items with `is_continued`.
7. If `supersedes_prescription_id` is set: the superseded row → `status = amended` (the only permitted transition on an issued row, §6.4).
8. Audit `issue` on the new row (and `amend` on the old row).
9. After commit: `App\Domain\Prescription\Events\PrescriptionIssued` (`ShouldDispatchAfterCommit`) → `GeneratePrescriptionPdf` job, `RecordDoctorUsage`, Notifications ("prescription ready"), the Serials listener that completes the consultation, SaaS usage (ARCHITECTURE.md §5.4); `FollowUpScheduled` if `visits.follow_up_on` is set. The writer tab is told about the PDF later by `PdfReady` on the private channel `tenant.{tenantPublicId}.prescription.{prescriptionPublicId}` (REALTIME.md §2; `TenantChannel::prescription($rx)`).

Response `200 {prescription:{id, version, status:"issued", verification_code, issued_at}, print_url, pdf_status:"pending", follow_up_draft_appointment_id:null}`.

### 6.2 Snapshot shape (`prescriptions.snapshot`, immutable render source)

SCHEMA.md §5.3.2 defines the base document; this module extends it with the
keys marked `＋` (additive, so SCHEMA's renderer contract still holds).
Everything the templates print is inside this object: header HTML, logo and QR
as data URIs, both language renderings of every item, the drug-info URL.

```json
{ "schema": 1,
  "prescription": { "public_id": "rx_01J…", "version": 2, "verification_code": "7Q3K9M2VXH4B", "issued_at": "2026-09-06T10:41:12+06:00",
    "language": "both", "verify_url": "https://clinic.example.com/rx/7Q3K9M2VXH4B",
    "＋id": 501, "＋root_id": 480, "＋supersedes_id": 480, "＋status_at_issue": "issued", "＋mode": "structured",
    "＋catalog_version": "2026.09.1", "＋tenant_id": 12, "＋visit_id": 9001 },
  "clinic": { "name": "…", "＋name_bn": "…", "branch": { "name": "…", "address": "…", "phone": "…" }, "＋logo_data_uri": "data:image/png;base64,…" },
  "doctor": { "name": "Dr. …", "name_bn": "…", "degrees": "MBBS, FCPS (Medicine)", "bmdc_reg_no": "A-12345", "designation": "…",
    "specialties": ["Medicine"], "signature_path": null, "＋signature_data_uri": null, "＋id": 7 },
  "patient": { "public_id": "…", "patient_code": "P-000331", "name": "…", "age_text": "34 y", "gender": "F", "mobile_masked": "017*****12",
    "weight_kg": 58.0, "＋age_months": 412, "＋id": 3301 },
  "visit": { "date": "2026-09-06", "serial": "A-042", "type": "opd",
    "chief_complaints": [ { "text": "Fever", "text_bn": null, "duration": "3d", "sort": 0, "＋duration_label": { "bn": "৩ দিন", "en": "3 days" } } ],
    "examination_findings": "Throat congested",
    "diagnoses": [ { "icd10_code": "J06.9", "title": "Acute upper respiratory infection", "kind": "provisional", "sort": 0 } ],
    "vitals": { "bp_systolic": 120, "bp_diastolic": 80, "pulse_bpm": 78, "temperature_c": 98.6, "spo2_percent": 98, "weight_kg": 58.0,
      "height_cm": 160, "bmi": 22.7, "recorded_at": "…", "＋recorded_by": "…", "＋reviewed_by_doctor_at": "…" } },
  "items": [ { "sort": 0, "generic_name": "Paracetamol", "brand_name": "Napa", "strength": "500 mg", "form": "Tablet", "route": "Oral",
      "dose_schedule": "1+0+1", "dose_json": { "…": "ParsedLine (§2.11)" }, "duration_text": "5 days", "quantity": 10, "quantity_unit": "tab",
      "timing": "after", "instruction": null, "instruction_bn": null, "info_url": "https://app.example.com/drug/paracetamol",
      "＋generic_id": 17, "＋brand_id": 88, "＋strength_id": 1234, "＋custom_brand_id": null, "＋is_continued": false,
      "＋display": { "bn": { "dose": "১ + ০ + ১", "duration": "৫ দিন", "timing": "খাবারের পরে", "quantity": "১০টি" },
                     "en": { "dose": "1 + 0 + 1", "duration": "5 days", "timing": "after meal", "quantity": "10 tab" } } } ],
  "investigations": [ { "name": "CBC", "name_bn": null, "price_paisa": 40000, "external_centre": null, "referral_note": null, "is_urgent": false } ],
  "＋investigations_total_paisa": 40000,
  "advice": [ { "text": "Drink plenty of water", "text_bn": "প্রচুর পানি পান করুন" } ],
  "referrals": [ { "type": "doctor", "to": "Dr …", "specialty": "Cardiology", "note": "…" } ],
  "follow_up": { "on": "2026-09-13", "note": null, "＋days": 7, "＋label": { "bn": "৭ দিন পর", "en": "after 7 days" } },
  "handwriting_image_path": null, "＋handwriting_pages": [],
  "drawing_image_path": "tenants/12/patients/…/drawing.png", "＋drawing_json": { "…": "DrawingJson (§4.11)" },
  "pad": { "…": "pad_snapshot (doctor_pad_settings row at issue, §7.2)", "＋header_html_inlined": "<…images as data URIs…>" },
  "allergies": ["Penicillin"],
  "＋safety": { "alerts": [ "…final alert set…" ], "overrides": [ { "fingerprint": "interaction:major:17:203", "reason": "…", "overridden_by_user_id": 7, "overridden_at": "…" } ] },
  "＋qr": { "url": "https://clinic.example.com/rx/7Q3K9M2VXH4B", "svg_data_uri": "data:image/svg+xml;base64,…" },
  "＋rendered_by": { "app_version": "1.4.0", "template_version": "print.prescription.v1" } }
```

Rendering therefore needs the row and nothing else (I6). `snapshot_sha256` is
computed over this document and printed in the footer next to the QR.

### 6.3 Amendment — `AmendPrescription`

`AmendPrescription::handle(Prescription $issued, string $reason): Prescription`
— allowed only on the *latest* issued version of a chain (409 otherwise; the
partial unique index on `supersedes_prescription_id` enforces "amended once")
by the issuing doctor (or hospital admin with permission), and only while the
visit has no other draft (one draft per visit). Creates a new row:
`version = old.version + 1`, `supersedes_prescription_id = old.id`,
`root_prescription_id = old.root_prescription_id`, `status = draft`,
`amend_reason = $reason`, copies items/investigations/advice/referrals and
drawing/handwriting files (copied, not moved) with fresh keys; the visit row
(complaints, findings, diagnoses, follow-up) is edited in place — the old
snapshot keeps the old values. The old row is untouched. Audit `amend` on the
old row (`context.to_version`) and `create` on the new.
The doctor edits and issues (§6.1 step 7 flips the old row to `amended`).
Abandoning the draft (`DELETE /panel/prescriptions/{prescription}` on a draft with
`supersedes_prescription_id`) leaves the old row `issued`.

Chain queries: `Prescription::versions($rootId)` ordered by version; every
version keeps its own `snapshot`, `verification_code`, `pdf_path` and remains
printable forever with the banner "Superseded by version N on date".

### 6.4 Void — `VoidPrescription::handle(Prescription $issued, string $reason)`

`status = voided`, `voided_at`, `voided_by_user_id`, `void_reason`. Snapshot
untouched; printing adds a VOID watermark; `/rx/{code}` shows "Voided".

### 6.5 Immutability enforcement

- `App\Models\Tenant\Prescription::booted()`: `updating` → if `getOriginal('status') !== 'draft'`, the dirty set must be ⊆ `{status, pdf_path, pdf_generated_at, printed_count, last_printed_at, delivered_channels, drawing_image_path, voided_at, voided_by_user_id, void_reason, updated_at}` (SCHEMA §5.3.3's list) and `status` may only move `issued → amended|voided`; otherwise throw `App\Domain\Prescription\Exceptions\ImmutablePrescriptionException`. `deleting` → throw unless draft.
- Child models (`PrescriptionItem`, `PrescriptionInvestigation`, `PrescriptionAdvice`, `PrescriptionReferral` — diagnoses are `visits.diagnoses` jsonb; there is no diagnosis child table or model): `saving`/`deleting` → throw unless parent is draft (one cached parent read).
- The database enforces the same rule: `prescriptions_immutable_trg` and `prescription_children_immutable_trg` (`public.fn_prescription_guard`, SCHEMA §5.3.3), so raw SQL cannot bypass it; the model guard exists to give a typed exception before the round trip.
- `snapshot` is `null` while draft and set exactly once; the model casts it to `PrescriptionSnapshot` (read-only DTO).

### 6.6 Audit log entries — `App\Domain\Prescription\Services\PrescriptionAuditor`

`audit_logs` (SCHEMA §3.7) has a closed `action` list; the module's finer
event names go into `context.event`. Columns written: `actor_type`, `actor_id`,
`action`, `auditable_type/id`, `patient_id`, `before`, `after` (changed
attributes only; ENC columns as `"[encrypted]"`), `context`, `ip`, `user_agent`,
`request_id`, `occurred_at`.

| Event | `action` | auditable | `context` |
|---|---|---|---|
| draft created | `create` | Prescription | `{event:"created", visit_id, version, supersedes_id}` |
| writer / read view opened (once per session per prescription) | `view` | Prescription | `{event:"viewed", version}` |
| every successful `PATCH draft` | `update` | Prescription (+ Visit when visit keys changed) | `{event:"draft_saved"}` + `before/after` |
| doctor edits / reviews vitals | `update` | Vital | `{event:"vitals_updated"|"vitals_reviewed"}` |
| template applied | `update` | Prescription | `{event:"template_applied", template_id, mode}` |
| safety override | `update` | PrescriptionItem | `{event:"safety_override", alert, reason}` |
| issued | `issue` | Prescription | `{event:"issued", version, verification_code, alerts_summary}` |
| amendment started (new draft) / old row superseded | `create` (new) / `amend` (old) | Prescription | `{event:"amend_started", from_version, to_version, reason}` |
| voided | `void` | Prescription | `{event:"voided", reason}` |
| print view served | `print` | Prescription | `{event:"printed", layout, paper, letterhead}` |
| PDF generated (actor `system`) / file served | `update` / `download` | Prescription | `{event:"pdf_generated", path, bytes}` / `{event:"downloaded"}` |
| delivery requested | `share` | Prescription | `{event:"sent", channel, to_masked}` |
| public `/rx/{code}` hit (actor `system`, `actor_id` null) | `view` | Prescription | `{event:"verified_view"}` |
| AI suggested / accepted / rejected | `create` / `update` | AiSuggestion | `{event:"ai_suggested"|"ai_accepted"|"ai_rejected", type}` |
| handwriting uploaded / drawing saved | `update` | Prescription | `{event:"handwriting_uploaded", page}` / `{event:"drawing_saved"}` |

---
## 7. Output: print, PDF, QR, delivery

### 7.1 Renderer and Blade templates

`App\Domain\Prescription\Render\PrescriptionRenderer::render(PrescriptionSnapshot $s, RenderOptions $o): string`
with `RenderOptions { layout: 'full'|'pharmacy', paper: 'A4'|'A5', letterhead: bool, preprinted: bool, watermark: null|'DRAFT'|'VOID'|'COPY', language: 'bn'|'en', purpose: 'print'|'pdf'|'verify' }`.
Defaults come from `snapshot.pad`; query parameters override per print. The
renderer receives only the DTO — it has no repository, no model, no catalog
access (I6). Draft preview builds a *transient* snapshot with
`SnapshotBuilder::preview()` and `watermark: DRAFT`.

```
resources/views/print/prescription/
  layout.blade.php              <html lang> · <style> (@page from pad, fonts, .bn/.en) · @yield('sheet') · print script for purpose=print
  sheet.blade.php               full layout: header → patient-bar → vitals → clinical → body → footer
  pharmacy.blade.php            drug + strength + form + quantity table only, patient name/age, code, QR
  partials/header.blade.php     letterhead (pad.header_html / clinic+doctor block) OR blank spacer of pad.header_height_mm when preprinted
  partials/patient-bar.blade.php name · age/sex · code · date · serial · version tag
  partials/vitals.blade.php     one line, omitted when null
  partials/clinical.blade.php   C/C · O/E · Dx (codes per pad flag)
  partials/rx-items.blade.php   numbered items: brand (en, bold) · generic (grey) · strength/form · dose line (pad language) · instruction · info link
  partials/investigations.blade.php  list + prices/total per pad flag · external referral lines
  partials/advice.blade.php     bullets in pad language
  partials/follow-up.blade.php  date + label + referral block
  partials/handwriting.blade.php one <img> per page, page-break-after
  partials/drawing.blade.php    inline SVG from DrawingSvgRenderer
  partials/footer.blade.php     signature line · QR (svg data uri) · verification code · first 8 hex of snapshot_sha256 · "v2 · supersedes v1" · pad.footer_html · page x/y (CSS counters)
resources/views/site/rx/show.blade.php        verification page (site surface, Blade — not Inertia): status banner + @include sheet (purpose=verify, watermark COPY)
resources/views/site/drug/show.blade.php      /drug/{slug} (site surface, Blade)
```

The whole `resources/views/print/**` tree is the one print/PDF template location (CONVENTIONS.md §1; token
slips, invoices and receipts of other modules sit beside `prescription/`).

### 7.2 Pad settings → CSS

`doctor_pad_settings` (SCHEMA §3.1): `paper_size (A4|A5)`, `orientation`,
`letterhead_enabled`, `preprinted_mode`, `logo_path`, `header_html`,
`footer_html` (sanitised on save, images inlined as data URIs at issue),
`margins {top,right,bottom,left}` mm, `header_height_mm`, `footer_height_mm`,
`font_family`, `font_size_pt`, `show_qr`, `show_vitals`, `show_drug_info_url`,
`layout {sections[], columns, rx_font_size_pt, ＋flags:{icd_codes, investigation_prices, generic_names}}`
(the `flags` key is a JSON-shape extension), plus `signature_path` and
`default_language` (SCHEMA.md §3.1). The copy frozen in
`pad_snapshot` is what renders. Rendered as:

```css
@page { size: A4 portrait; margin: 20mm 15mm 20mm 15mm; }           /* paper_size + orientation + margins */
body { font-family: 'Noto Sans Bengali', 'Inter', system-ui, sans-serif; font-size: 10.5pt; }   /* font_family, font_size_pt */
.en, .drug { font-family: 'Inter', 'Noto Sans Bengali', system-ui, sans-serif; }
.bn { font-family: 'Noto Sans Bengali', 'Inter', sans-serif; }
.header-spacer { height: 35mm; }                                     /* preprinted_mode: header_height_mm */
.footer { position: fixed; bottom: 0; height: 20mm; }
.watermark { position: fixed; inset: 0; opacity: .08; font-size: 96pt; transform: rotate(-30deg); }
```

Inter is served from `public/fonts/inter/*.woff2` via `@font-face`; Noto Sans
Bengali resolves to the system-installed font in Chrome (also declared with
`local()` and a bundled woff2 fallback). Numbers in Bangla renderings use
Bangla digits (`NumberFormatter` helper `bn_digits()`); drug names always
print in English (`.drug`).

### 7.3 Pharmacy-friendly view

`layout=pharmacy`: one table — `#`, brand + strength + form, quantity, "see instruction"
marker — plus patient name/age, date, doctor, QR. No diagnosis, vitals or advice
(privacy). One click from the issued view; A5 by default.

### 7.4 QR code and verification page

`App\Domain\Prescription\Render\QrCodeRenderer::svgDataUri(string $url): string`:

```php
$result = (new \Endroid\QrCode\Builder\Builder(
    writer: new \Endroid\QrCode\Writer\SvgWriter(), data: $url,
    encoding: new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
    errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Medium,
    size: 220, margin: 0, roundBlockSizeMode: \Endroid\QrCode\RoundBlockSizeMode::Margin,
))->build();
return $result->getDataUri();
```

Called once at issue; the data URI is stored in `snapshot.qr.svg_data_uri` so
rendering needs no library call; `pad.show_qr = false` hides it on paper (the
verification code and hash still print). URL: `https://{tenant primary domain}/rx/{verification_code}`.

`GET /rx/{code}` (`routes/site/prescription.php`, `site.prescription.verify`) → `App\Http\Controllers\Site\Prescription\VerificationController::show` (no auth, `throttle:rx-verify`
30/min/IP registered by `PrescriptionServiceProvider`, `X-Robots-Tag: noindex`): the tenant is already resolved by the global `ResolveTenant` middleware, finds the
row by `verification_code` (any status), renders from the snapshot with
`purpose: verify`, and shows a banner: **Valid** (latest issued), **Superseded
by version N (date)**, or **Voided**. Phone is masked, no other patient
identifiers beyond name/age/sex. Audit `view {event: verified_view}`.
"Download PDF" serves the stored PDF for valid versions.

### 7.5 PDF via Browsershot (Chrome), queued

`App\Domain\Prescription\Jobs\GeneratePrescriptionPdf` (`ShouldQueue`, `TenantAware`, queue
`pdf`, `$tries = 3`, `$backoff = [10, 60, 300]`, `$timeout = 120`, `ShouldBeUnique` by prescription id):

```php
$html = $renderer->render($snapshot, RenderOptions::fromPad($snapshot->pad, purpose: 'pdf'));
$pdf = \Spatie\Browsershot\Browsershot::html($html)
    ->setChromePath(config('prescription.pdf.chrome_path'))          // /usr/bin/google-chrome
    ->noSandbox()
    ->addChromiumArguments(['disable-gpu', 'disable-dev-shm-usage', 'no-zygote', 'font-render-hinting' => 'none'])
    ->format($paper)                                                   // 'A4' | 'A5'
    ->margins($m['top'], $m['right'], $m['bottom'], $m['left'], 'mm')
    ->showBackground()->emulateMedia('print')
    ->timeout(60)->protocolTimeout(60000)
    ->pdf();
Storage::disk('pdfs')->put($path, $pdf);                                // ARCHITECTURE.md §8.7; $path = TenantPath::for("patients/{patient_public_id}/prescriptions/{root_public_id}/v{n}/{code}.pdf")
$rx->forceFill(['pdf_path' => $path, 'pdf_generated_at' => now()])->save();   // permitted post-issue columns §6.5
event(new PdfReady($rx->id, $path));                                   // ShouldBroadcast on TenantChannel::prescription($rx) → tenant.{tenantPublicId}.prescription.{prescriptionPublicId}
```

Prerequisites: `npm i puppeteer` (with `PUPPETEER_SKIP_DOWNLOAD=1`, we use
system Chrome) — it is not yet in `package.json` (foundation request); the Horizon
`supervisor-pdf` for queue `pdf` with 2 processes (ARCHITECTURE.md §4.6); `config('prescription.pdf.chrome_path')`
from `CHROME_PATH`. `GET /panel/prescriptions/{prescription}/pdf` streams the file with
audit `download`, or `202 {status:"pending"}` if not ready
(client listens for `PdfReady`). Regeneration (`POST …/pdf/regenerate`) is
allowed — it re-renders the *same snapshot*.

### 7.6 One-click print

`GET /panel/prescriptions/{prescription}/print?layout=full&paper=A4&letterhead=1&preprinted=0`
returns the same HTML as the PDF with a script that calls `window.print()` on
`load` and closes on `afterprint`. "Issue & Print" opens it in a new tab in the
same click handler (popup-blocker safe). Draft `Ctrl+P` uses
`…/print?draft=1` (transient snapshot, DRAFT watermark). Issued prints bump
`printed_count` / `last_printed_at` (permitted post-issue columns) and audit `print`.

### 7.7 Delivery

`POST /panel/prescriptions/{prescription}/send {channel: 'sms'|'whatsapp'|'email', to?: string}`
→ `App\Domain\Prescription\Events\PrescriptionDeliveryRequested(tenantId, prescriptionId, patientId, channel, to, verificationUrl, pdfPath|null, language)`.
The Notifications module owns templates and gateways (listener `App\Domain\Notifications\Listeners\DeliverPrescription`, ARCHITECTURE.md §5.4); if `pdfPath` is null it
chains after `PdfReady`; on success it appends the channel to
`prescriptions.delivered_channels` (permitted post-issue column). Audit `share`.
Default `to` = patient mobile/email.

### 7.8 Drug information page

`GET /drug/{slug}` (`site.prescription.drug`) → `App\Http\Controllers\Site\Prescription\DrugInfoController::show` reads `drug_information`
by `public_slug` (catalog read — this is not a prescription render), cached
1 h, renders `indications`, `side_effects`, `contraindications`, `precautions`
(en) and `indications_bn`, `side_effects_bn`, `patient_advice_bn` with a
language toggle; an unpublished row (`published_at` null) shows a generic
placeholder. The slug is snapshotted into `prescription_items.info_url_slug`
and the full URL into `snapshot.items[].info_url` at issue; it prints under
each item when `pad.show_drug_info_url`.

---

## 8. Patient record pieces the writer depends on

- **Timeline** `GET /panel/patients/{patient}/timeline?cursor=&limit=25` — `App\Domain\Patients\Queries\PatientTimelineQuery` unions `visits` (with `current_prescription_id`, dx titles from `visits.diagnoses`), `vitals`, `patient_documents`, ordered by `occurred_at desc`, keyset cursor `(occurred_at, kind, id)`. The writer's left pane is `limit=5`.
- **Vitals trend** `GET /panel/patients/{patient}/vitals-trend?limit=12` — rows for sparkline (BP, weight, BMI, SpO2); deferred prop on open.
- **Allergies / conditions / medications** — `patient_allergies` (`allergen_type` generic|allergy_class|food|environmental|other, `generic_id?`, `allergy_class_id?`, `allergen_name`, `reaction`, `severity`, `is_active`, `verified_by_doctor_id`), `patient_conditions` (`icd10_code?`, `condition_name`, `status` active|chronic|resolved, `onset_date`), `patient_medications` (`generic_id?`, `brand_id?`, `custom_brand_id?`, `generic_name`, `brand_name`, `dose_text`, `source` prescription|reported, `is_active`). Endpoints `GET/POST/PATCH/DELETE /panel/patients/{patient}/{allergies|conditions|medications}[/{rowId}]`; the writer edits them inline from the patient card (allergy add is 2 clicks) and re-runs `check` afterwards. Quick toggles Pregnant / Lactating / Renal / Hepatic write the coded conditions of §5.3. "Add current Rx items to medication list" on issue (1 click, default off).
- **Uploaded reports** — `patient_documents` (`type` lab_report|imaging|…, `title` (OCR-suggested, editable), `document_date`, `original_filename`, `storage_disk`, `storage_path`, `mime_type`, `size_bytes`, `ocr_status` pending|done|failed|skipped, `ocr_text` ENC). Upload `POST /panel/patients/{patient}/documents` → job `NameUploadedDocument` calls `App\Domain\Patients\Contracts\DocumentNamer::suggest(PatientDocument $d): DocumentNaming` (`{title, document_date, ocr_text}`) — `NullDocumentNamer` returns `"{type} – {upload date}"` and `ocr_status = skipped`; a real OCR driver later fills `ocr_text`, sets `ocr_status = done` and a title like "CBC – 06 Sep 2026". Writer shows thumbnails in the left pane; click opens a viewer.
- **Consent log** is out of this document's scope but the writer reads the patient's latest `patient_consents` rows (`type` ∈ `data_sharing`, `sms`, `whatsapp` with `status = granted`; SCHEMA.md §3.2 — there is no `share_prescription` consent type) to default the send channel.

---

## 9. Test plan

### 9.1 PHPUnit (`tests/Unit/Prescription` extend `PHPUnit\Framework\TestCase`; `tests/Feature/Prescription` extend `Tests\TestCase` with `WithTenants`/`actingAsDoctor()` — CONVENTIONS.md §6; groups `search` (real Meilisearch, `SCOUT_PREFIX=test{N}_`) and `browsershot` (needs `CHROME_PATH`; skipped when absent))

| Test class | What it proves |
|---|---|
| `Unit\Prescription\Shorthand\ShorthandParserTest` | table-driven from `tests/Fixtures/shorthand_cases.json` (every §2.16 row + full `dose_json`); Bangla digits; case; normalisation idempotence |
| `Unit\Prescription\Shorthand\QuantityCalculatorTest` | each counting family, rounding, pack sizes, overrides, cont/tf |
| `Unit\Prescription\Shorthand\KeywordsTest` | `keywords.json` has no duplicate tokens across categories; every token appears in a fixture row |
| `Unit\Prescription\Safety\{Interaction,Allergy,DuplicateTherapy,PediatricDose,PregnancyLactation,RenalHepatic,MaxDailyDose,CustomBrandLink,CatalogReference}CheckTest` | one class each with `CatalogCache::fake()`; severity thresholds; fingerprint stability; patient-medication pairs; cross-reactivity; two brands of one generic summing daily mg |
| `Unit\Prescription\Safety\SafetyPipelineTest` | override application, `issueBlockedBy`, non-overridable codes, dropped override on fingerprint change |
| `Feature\Prescription\WriterPageTest` | show props shape, draft auto-created, query count budget, tenant isolation (other tenant's visit → 404) |
| `Feature\Prescription\DraftSaveTest` | upsert by key, visit keys written to `visits`, overrides stored per item, 422 on parse error, 409 on stale `expected_updated_at`, alerts in response, audit `update {event: draft_saved}` |
| `Feature\Prescription\CheckEndpointTest` | drug-level checks without shorthand, computed mg/kg |
| `Feature\Prescription\IssuePrescriptionTest` | snapshot built, `verification_code` unique, catalog text copied at issue, events dispatched (`PrescriptionIssued`, `FollowUpScheduled` with payload), blocked by critical, allowed after override |
| `Feature\Prescription\SnapshotImmutabilityTest` | updating any clinical column on an issued row throws `ImmutablePrescriptionException`; child rows cannot be saved/deleted; permitted dirty set works; DB trigger rejects raw `UPDATE` |
| `Feature\Prescription\AmendmentChainTest` | v2 draft references `supersedes`/`root`, issuing v2 flips v1 to `amended`, v1 still renders with banner, amending non-latest → 409, abandoning v2 draft leaves v1 issued |
| `Feature\Prescription\CatalogJoinFreeRenderingTest` | `DB::connection('catalog')->beforeExecuting(fn () => throw new RuntimeException('catalog query during render'))` then render full, pharmacy, verify page and PDF HTML for an issued and an amended prescription; also assert `count(DB::connection('catalog')->getQueryLog()) === 0`; and rendering still succeeds after the catalog row is renamed/deleted |
| `Feature\Prescription\CustomBrandBlockTest` | custom brand whose generic is null/inactive/mismatched → `custom_brand.unlinked`, issue 422, override impossible; linked custom brand passes and is snapshotted |
| `Feature\Prescription\AuditTrailTest` | every event in §6.6 produces exactly one row with the documented `action`/`context.event` and `before/after` (`assertAudited()`); view logged once per session; ENC columns masked |
| `Feature\Prescription\IsolationTest` | `assertTenantIsolated()` for `visits`, `vitals`, `prescriptions`, `prescription_items`, `prescription_investigations`, `prescription_advice`, `prescription_referrals`, `prescription_templates`, `advice_snippets`, `doctor_favourites`, `doctor_drug_usage`, `ai_suggestions` (CONVENTIONS.md §6.4) |
| `Feature\Prescription\TemplateTest` | save from draft, apply append/replace, vanished catalog id → `drug_missing` line, follow-up computed |
| `Feature\Prescription\DoctorLearningTest` | usage/favourite upserts on issue, top-50 cache refresh, favourites endpoint ordering (pinned first) |
| `Feature\Prescription\VerificationPageTest` | valid / superseded / voided banners, throttle, noindex, no phone leak |
| `Feature\Prescription\PdfGenerationTest` (`@group browsershot`) | job writes a PDF > 10 KB containing Bangla glyphs (pdftotext contains "খাবারের"), `pdf_path` set, `PdfReady` broadcast |
| `Feature\Prescription\DrugSearchTest` (`#[Group('search')]`) | merged list, custom brand marked, strength filter, usage boost ordering, generic row present |
| `Feature\Prescription\AiAssistTest` | null driver hides feature, http driver logs `ai_suggestions`, accept/reject flags, de-identified payload |
| `Feature\Prescription\HandwritingTest` | upload stores PNG under patient-private path, snapshot lists pages, print uses image body |

### 9.2 Vitest (`resources/js/panel/lib/prescription/**/__tests__` for the library, `resources/js/panel/Components/Prescription/__tests__` for components)

| File | Coverage |
|---|---|
| `shorthand/parse.test.ts` | the same `tests/Fixtures/shorthand_cases.json` — assert deep-equal `dose_json` (parity with PHP) |
| `shorthand/normalize.test.ts` | Bangla digits, unicode fractions, instruction split, spacing |
| `shorthand/quantity.test.ts` | families, rounding, overrides |
| `store/writerStore.test.ts` | debounce, rev handling, server parse replacing local, offline retry, overrides keyed by fingerprint |
| `components/RxLine.test.tsx` | keyboard model of §1.3: Enter/Tab/Esc/arrows/Backspace-to-chip/Ctrl+↑↓/Ctrl+D; error blocks Enter; ghost `last_shorthand` accept with `→` |
| `components/Writer.keyboard.test.tsx` | zone order, `Alt+n`, `Ctrl+K`, `Ctrl+Enter` opens dialog, `F2` sheet |
| `components/Cheatsheet.test.tsx` | generated from keywords.json, inserts example |

---

## 10. Schema additions requested (for docs/SCHEMA.md)

> Reconciled 2026-09-06: every item below is now in SCHEMA.md; the section is kept as the rationale record.

Checked against the current SCHEMA.md; everything else this document uses
already exists there. Additions, smallest first:

1. `vitals.reviewed_by_doctor_at timestamptz NULL` — the explicit "reviewed" tick (§4.2); `edited_by_doctor` alone cannot express "seen and accepted unchanged".
2. `doctor_pad_settings.signature_path varchar(255) NULL` and `default_language varchar(5) NOT NULL DEFAULT 'both'` (`bn|en|both`) — seeds `prescriptions.language` for new drafts.
3. `advice_snippets.category` list extended with `finding` and `complaint` — the same library powers the O/E and C/C quick-picks (§4.1, §4.3).
4. JSON-shape extensions (no DDL): `prescription_items.dose_json` = `ParsedLine` (§2.11) in place of the placeholder shape in the `prescription_items` note; `prescription_items.safety_overrides[]` = `{fingerprint, kind, severity, reason, overridden_by_user_id, overridden_at}`; `doctor_favourites.default_dose` / `doctor_drug_usage.last_dose` gain a `shorthand` key; `doctor_pad_settings.layout` gains `flags {icd_codes, investigation_prices, generic_names}`; `prescriptions.drawing_json` gains an optional `texts[]` and the `canvas.template` list of §4.11; `prescriptions.snapshot` gains the `＋` keys of §6.2.
5. `prescriptions` uniqueness note: the writer relies on the existing partial unique indexes (`(visit_id) WHERE status='draft'`, `(supersedes_prescription_id)`) — keep them.
6. `patient_documents.ocr_status` gains no values; `NullDocumentNamer` uses the existing `skipped`.
7. Index `prescription_items (strength_id)` and `(brand_id)` for `catalog:reconcile` scans (CATALOG.md §6); `(generic_id)` and `(custom_brand_id)` already exist.

Divergences from the build prompt's fixed context that SCHEMA.md resolved and
this document follows: complaints/findings/diagnoses/follow-up live on `visits`
(not `prescriptions`); vitals columns are `pulse_bpm`, `spo2_percent`,
`recorded_by_user_id`; audit rows use the closed `action` list plus
`context.event`; `ai_suggestions` is keyed by visit; `language` includes `both`.
