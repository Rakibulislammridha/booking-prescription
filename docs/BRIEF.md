# Project Build Prompt — "Booking to Prescription" Clinic OPD SaaS

> Hand this document to the engineering team or coding agent as the authoritative
> build brief. Decisions marked **LOCKED** are settled and must not be
> re-litigated during implementation.

---

## 1. Mission

Build a multi-tenant SaaS product that covers the complete outpatient (OPD)
journey for hospitals and clinics in Bangladesh:

**patient books → serial issued → reception checks in and collects fee → vitals
recorded → doctor writes prescription → printed / PDF / SMS delivered →
follow-up auto-drafted.**

The product is sold as one continuous system, not a bundle of features. Two
things carry the sale:

- **The live queue** is the marketing hook. A patient watches their serial move
  on a cheap Android phone and stops sitting in a corridor for three hours.
- **The prescription writer** is the flagship. A doctor who can finish a routine
  prescription in under 60 seconds will never go back to paper.

Everything else exists to make those two work.

---

## 2. Locked technical decisions

| Area | Decision |
|---|---|
| Backend | Laravel 12, Octane, Horizon for queues |
| Realtime | **Laravel Reverb (WebSockets)**, with a designed polling fallback |
| Frontend | Inertia + React. Material UI for the admin/staff panel |
| Public booking site | Inertia + React, per-tenant theming, custom domain |
| Database | PostgreSQL |
| Tenancy | Schema-per-tenant inside the `booking` database |
| Drug/reference data | Separate `catalog` database, soft references only |
| Search | Meilisearch — drug, patient, and ICD-10 autocomplete |
| PDF | Gotenberg or Browsershot (headless Chrome). **Not DomPDF** — Bangla font rendering and real typography are required |
| Infra | VPS + Docker, S3-compatible storage, per-tenant daily DB dumps |
| Mobile | PWA for the reception desk (offline-capable). Flutter app optional later |

### Why DomPDF is banned

Bangla Unicode rendering and prescription typography are non-negotiable quality
signals. Prescriptions print on preprinted pads with fixed margins. Only a real
browser engine gets this right.

---

## 3. Databases and connections

Two PostgreSQL databases. Local development credentials:

```
Host:     localhost
User:     root
Password: password
```

| Connection name | Database | Purpose |
|---|---|---|
| `pgsql` | `booking` | Central/SaaS data + all tenant schemas |
| `catalog` | `catalog` | Shared clinical reference data |

`.env` shape:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=booking
DB_USERNAME=root
DB_PASSWORD=password

CATALOG_DB_HOST=127.0.0.1
CATALOG_DB_PORT=5432
CATALOG_DB_DATABASE=catalog
CATALOG_DB_USERNAME=root
CATALOG_DB_PASSWORD=password
```

> These are local development credentials only. Production must use a
> restricted role, and the `catalog` connection must be granted `SELECT` only
> for the application runtime user. Writes to `catalog` happen through a
> separate admin-only role.

### 3.1 `booking` database — schema layout

- **`public` schema** — SaaS control plane. Tenants, plans, subscriptions,
  invoices, super-admin users, feature flags, custom domains.
- **`tenant_<id>` schema, one per clinic** — patients, doctors, schedules,
  appointments, serials, prescriptions, payments, tenant-added custom brands,
  audit logs.

Foreign keys between tenant schemas and `public` work normally and should be
used.

### 3.2 `catalog` database — shared clinical reference

Read-only to every tenant at runtime. Updated centrally when DGDA publishes
changes, so every clinic gets the same data with no drift.

Tables:

| Table | Contents |
|---|---|
| `generics` | Molecule / salt. **All safety logic runs against this table** |
| `brands` | Bangladesh brand names, each FK'd to a generic |
| `strengths` | Brand + strength + form combinations |
| `dosage_forms` | Tablet, capsule, syrup, injection, drop, ointment… |
| `routes` | Oral, IV, IM, topical, ophthalmic… |
| `icd10_codes` | With plain-language search aliases |
| `drug_interactions` | Generic ↔ generic pairs, severity graded. Top ~500 molecules to start |
| `allergy_classes` | Cross-reactivity groups (penicillin allergy must flag amoxicillin) |
| `pregnancy_categories` | Per generic |
| `renal_cautions` / `hepatic_cautions` | Per generic |
| `max_daily_doses` | Per generic, with pediatric mg/kg where applicable |

**Never name a table `drugs`.** In BD prescribing, "drug" is ambiguous between
molecule and brand. The doctor types a brand; the safety layer needs the
molecule. Keeping `generics` and `brands` explicitly separate prevents a class
of bug where the wrong join silently disables interaction checking.

Catalog data will be populated **after** the application is built. Build against
the schema; seed with a small representative sample for development.

### 3.3 Cross-database integrity rules

Postgres cannot enforce FKs across databases. Therefore:

1. **Snapshot on write.** `prescription_items` stores `generic_name`,
   `brand_name`, `strength`, and `form` as plain text at the moment of issue,
   alongside soft `generic_id` / `brand_id` references. A prescription issued
   today must print identically in five years regardless of catalog edits.
   **Never render a historical prescription by joining live to `catalog`.**
2. **Validate in application code.** Every write referencing a catalog id must
   verify existence before commit.
3. **Reconcile on a schedule.** A nightly job checks all soft references and
   reports orphans to super-admin. Orphans are reported, never auto-deleted —
   clinical records are immutable.
4. **Meilisearch is the hot path.** Autocomplete queries the search index, not
   the catalog database directly.

### 3.4 Tenant-added custom brands

Clinics will encounter brands missing from the master catalog.

- Custom brands live in the **tenant schema**, not `catalog`.
- **A generic/molecule link is mandatory.** A brand with no molecule mapping is
  invisible to interaction, duplicate-therapy, and allergy checking. This is the
  single most dangerous failure mode in the product. A custom brand without a
  molecule link must be **unusable in a prescription** — block at validation.
- Each carries a `promoted_to_master` flag and review status for the path back
  into the shared catalog via super-admin approval.
- Autocomplete presents master catalog and tenant custom brands as **one ranked
  list**, visually distinguished.

---

## 4. Core domain model

Build these first. Everything else hangs off them.

```
public schema:
  tenants, plans, subscriptions, invoices, domains,
  super_admins, feature_flags, usage_counters

tenant schema:
  branches, departments, specialties
  users (roles: Hospital Admin, Doctor, Compounder/Receptionist,
         Accountant, Patient)
  doctors, doctor_profiles, doctor_schedules, doctor_sessions,
  schedule_overrides, holidays, doctor_leaves
  patients, patient_relations (family grouping)
  appointments, serials, serial_events
  visits, vitals, prescriptions, prescription_items,
  prescription_investigations, prescription_advice
  custom_brands
  payments, invoices, refunds, discounts
  notifications, notification_logs
  audit_logs
```

### Serial identity — LOCKED

A serial is scoped to **(branch, doctor, session, date)**. It is never global.

Display format: `A-042`, where `A` is the session code (A = morning,
B = evening, etc.) and `042` is the sequence.

---

## 5. Modules

### A. Tenant & hospital setup

- Branch management, departments, specialties.
- Roles and permissions: Super Admin, Hospital Admin, Doctor,
  Compounder/Receptionist, Accountant, Patient.
- Doctor profiles: degrees, BMDC registration number, specialties, chamber
  timings, consultation fee, follow-up fee, and a **free follow-up window**
  (e.g. free revisit within 15 days) that the billing engine must honour
  automatically.
- **Prescription pad designer, per doctor**: letterhead on/off, logo,
  header/footer, margins, paper size (A4/A5), and a
  **print-on-preprinted-pad mode** that leaves the header area blank.
- Holiday calendar, doctor leave, emergency cancellation with automatic
  notification to every booked patient.

### B. Schedule & slot engine

- Weekly recurring schedules per doctor per branch.
- Sessions (Morning / Evening) with start–end time, max serials, average
  minutes per patient.
- **Two operating modes:**
  - *Serial mode* — token number, no fixed time. This is how BD clinics
    actually work and is the default.
  - *Slot mode* — fixed-time appointments, for premium and diagnostic clinics.
- Capacity rules: max serials, plus **4–5 buffer serials reserved** for
  walk-ins, VIPs, and emergencies.
- Per-date override: doctor arrives late, session cut short, session cancelled.

### C. Booking channels

All five channels feed the same serial engine:

1. **Online** — public per-tenant booking site on a custom domain. Doctor search
   by specialty, name, or day. Calendar shows serials remaining.
2. **Phone/offline** — receptionist books on the patient's behalf; patient
   identified by mobile number.
3. **Walk-in** — instant serial drawn from the reserved buffer.
4. **Kiosk/QR** — patient scans a QR at reception and self-books from their
   phone.
5. **Repeat/follow-up** — one-tap rebooking from a previous visit.

**Booking flow:** mobile number → patient auto-matched or created → doctor /
date / session → serial assigned atomically → payment (optional / advance /
full) → confirmation.

### D. Serial engine — get this exactly right

This is the highest-risk component in the system. Two patients holding the same
serial number destroys trust in a single morning.

- **Atomic allocation.** Database transaction with row-level lock. Duplicate
  numbers must be impossible under concurrent booking. Write a concurrency test
  that hammers this with parallel requests before considering it done.
- **Daily reset** per doctor per session.
- **Reserved ranges: online pool vs counter pool.** Online booking must never
  consume the counter's serials. Only Super Admin and Doctor may adjust the
  split.
- **Reordering** — drag a serial up or down; priority insert for elderly,
  emergency, or VIP. Every reorder writes an audit log entry.
- **Statuses:** `Booked → Arrived/Checked-in → In Consultation → Completed`,
  plus `No-show`, `Cancelled`, `Postponed to next session`.
- **Auto no-show** after N serials pass without check-in, with a reinstate
  option.
- **Transfer serial to another doctor** when a doctor becomes unavailable.

### E. Live queue

- Public link plus QR, **no login required**:
  `queue.hospital.com/dr-rahman/today`
- Shows: now serving, your serial, patients ahead, and estimated call time
  computed from a **running average of actual consultation times**, not static
  arithmetic.
- Auto-updating over Reverb WebSocket. Must work on a low-end Android phone
  over poor wifi.
- **Notify at T-3 serials:** "3 patients ahead, please reach the chamber."
- **Waiting-room display mode** — TV/monitor layout, large fonts, multi-doctor
  grid, optional voice call-out in Bangla and English.
- **Delay broadcast** — doctor running 40 minutes late, one tap notifies
  everyone waiting.

**Fallback requirement:** clinic wifi in Bangladesh is unreliable. Implement a
polling fallback (`GET /queue/{doctor}/state` with ETag, 5-second interval) that
engages automatically when the WebSocket cannot connect or drops. The frontend
holds a single connection-state model shared with the offline reception logic in
module F — do not build two independent network-state systems.

### F. Reception / compounder desk

- **Today's board** — all doctors, all sessions, live counts
  (booked / arrived / done / remaining).
- One-click: create serial, check in, mark arrived, collect fee, print token
  slip.
- Patient quick-search by mobile, name, or ID with instant history.
- Token slip printing — 58mm/80mm thermal or A5.
- Cash drawer / shift-close report — collected vs expected, per receptionist.
- **Call-next button** pushing simultaneously to the doctor's screen and the
  waiting-room display.
- Refund and cancellation with reason codes.

#### F.1 Offline resilience — LOCKED design

Reception must keep working during internet loss. Abandonment of clinic software
traces to this more than any other cause.

**Approach: PWA with a pre-allocated serial block per device.**

- Each reception device is registered and receives a **pre-allocated block of
  serial numbers** for the current session while online.
- Going offline, the device issues serials **only from its own block**. Two
  receptionists therefore cannot collide, because their blocks are disjoint.
- Offline capabilities: issue serial from block, check in, mark arrived, print
  token slip, view cached patient history.
- Offline restrictions: no refunds, no online-pool serials, no prescription
  access.
- Local storage via IndexedDB. All actions queue as an ordered event log.
- On reconnect, the queue replays to the server. Conflicts are surfaced to the
  receptionist for resolution — **never silently auto-merged**.
- Unused block serials are returned to the pool at session close.
- The UI must show connection state unmistakably. A receptionist should never be
  unsure whether they are online.

### G. Prescription module — the flagship

Target: **a routine prescription completed in under 60 seconds, with nothing
more than two clicks deep.** Design every interaction against that number.

#### G.1 Writing experience

- **Single-screen, three-pane layout:** left = patient history timeline,
  centre = prescription, right = quick-pick panel.
- Everything autocompletes from the catalog plus the doctor's own history. **The
  doctor's top 50 drugs surface first, learned per diagnosis.**
- **Keyboard-first shorthand parsing.** Typing `nap` → Napa 500mg; then
  `1+0+1 10d AF` parses to dose schedule, duration, and after-food timing.
  Document the full shorthand grammar and make it discoverable in-app.
- **Templates** — save an entire prescription as a template
  ("Common cold – adult"), apply in one click, then edit.
- **Favourites / protocols** per doctor per diagnosis.
- **Voice dictation** for advice and notes, Bangla and English.
- **Draw/annotate area** for diagrams — dental chart, ortho, eye —
  stylus-friendly on tablet.
- **Handwriting mode** — doctor writes on a tablet; the system stores the image
  alongside whatever structured data exists. This is the adoption bridge for
  older doctors and is genuinely important in the BD market. Do not treat it as
  a nice-to-have.

#### G.2 Clinical structure

- Chief complaints with duration.
- **Vitals** — BP, pulse, temperature, SpO2, weight, height, auto-calculated
  BMI. **Entered by the compounder before the doctor sees the patient.** The
  doctor can review and edit every vital.
- On-examination findings.
- Provisional and final diagnosis — ICD-10 coded, searchable in plain language.
- **Investigations** — lab tests and imaging selected from the clinic's own test
  catalog with prices, printed on the prescription. The doctor can also refer a
  patient to an external diagnostic centre for specific investigations, with a
  referral note.
- **Rx line items** — generic name, brand name, strength, form, dose schedule,
  duration, quantity to dispense, timing (before/after meal), route, and a
  custom instruction line.
- Each drug prints with a **"click here for more information" URL** resolving to
  side effects and drug information.
- **Advice / lifestyle** from a reusable snippet library.
- **Follow-up date** → automatically creates a draft booking and a reminder.
- **Referral** to another doctor or hospital with a referral note.

#### G.3 Safety intelligence — the differentiator

All checks run against **generic/molecule**, never brand.

- Drug–drug interaction alerts, severity graded.
- Allergy alerts from the patient profile, including cross-reactivity via
  `allergy_classes`.
- Duplicate therapy warning — same molecule reached by two different brands.
- **Pediatric weight-based dose calculator**, auto mg/kg.
- Pregnancy and lactation category flags.
- Renal and hepatic dose caution flags.
- Maximum daily dose validation.
- **Optional AI assist** — summarise the patient's last five visits into three
  lines; suggest differentials from complaints and findings. **Always advisory,
  never auto-inserted, always doctor-confirmed.** Every AI suggestion is logged
  with whether the doctor accepted it.

#### G.4 Output

- Print A4/A5, with or without letterhead, one click.
- PDF carrying a **QR code that opens a verifiable digital copy**.
- Delivery by SMS, WhatsApp, or email.
- **Multi-language print** — Bangla instructions for the patient, English drug
  names.
- **Pharmacy-friendly view** — drug and quantity only.
- **Amendment flow** — prescriptions are **immutable once issued**. An edit
  creates a new version with a full audit trail. The original remains
  retrievable and printable forever.

### H. Patient record / mini-EMR

- Unique patient ID with mobile number as identity.
- **Family/dependent grouping under one mobile number** — one phone often serves
  a whole household.
- Timeline: visits, prescriptions, vitals trend charts, uploaded reports,
  documents.
- Allergies, chronic conditions, current long-term medication list.
- Uploaded reports (photo/PDF) with OCR-based naming.
- Patient consent and data-sharing log.

### I. Payments & billing

- Consultation fee, free/discounted follow-up logic, discount with reason,
  coupons.
- Online payment: **bKash, Nagad, SSLCommerz**.
- Counter cash and card, partial payment, due tracking.
- Invoice and money receipt printing.
- **Doctor revenue share / commission split reports** — a major selling point
  for multi-doctor clinics.
- Daily and monthly collection reports by doctor, branch, and user.

### J. Notifications

- **Channels:** SMS, WhatsApp Cloud API, push, email, and **IVR call for
  feature-phone patients**.
- **Events:** booking confirmed, day-before reminder, morning-of reminder,
  "3 ahead" call alert, doctor delayed, doctor cancelled, prescription ready,
  follow-up due.
- Per-tenant SMS gateway credentials and template management, **Bangla Unicode
  support**.
- Delivery logs with retry.

### K. Telemedicine (add-on tier)

- Video consultation via Agora, LiveKit, or Jitsi.
- Ends in the **same prescription flow** as an in-person visit — no separate
  prescription path.

### L. Reports & analytics

- Appointments booked / completed / no-show, by doctor, day, and source
  (online vs counter).
- Average wait time, average consultation time, session overrun analysis.
- Revenue by doctor, branch, and payment method.
- New vs returning patients, follow-up compliance rate.
- **Top diagnoses and top prescribed drugs** — this data is genuinely valuable.
- Peak-hour heatmap for staffing decisions.
- Export to CSV, Excel, PDF.

### M. SaaS control plane

- Plan tiers with enforced limits: branches, doctors, monthly appointments, SMS
  credits, storage, module toggles.
- Free trial, subscription billing, invoices, dunning, auto-suspend.
- Custom domain with DNS verification.
- Tenant onboarding wizard with demo data seeding.
- Super-admin: impersonate tenant, usage dashboards, per-tenant feature flags.
- Marketing site, pricing page, documentation, in-app changelog.

### N. Security & compliance

- **Full audit log on every clinical record view and edit** — who, what, when,
  IP address.
- Encryption at rest for clinical fields; TLS everywhere.
- Role-scoped data access — a doctor sees only his own patients unless
  explicitly permitted.
- Session timeout and device management.
- Automated per-tenant backup and restore; full data export on churn.
- Handle data to HIPAA/GDPR-style standards even though Bangladesh does not
  mandate it — it is a selling point for export markets.

---

## 6. Explicitly out of scope

Do not build these. They were considered and removed:

- **Lab module** — no sample collection, result entry, or report upload
  workflow. Investigations appear on the prescription and print; that is all.
- **Pharmacy module** — no dispensing workflow, no stock management.
- **Pharmacist and Lab roles** — removed from the role list.
- **"Report ready" notification event** — removed with the lab module.

Patient-uploaded reports in the mini-EMR remain in scope, since those arrive
from outside diagnostic centres.

---

## 7. Build order

Do not build modules in alphabetical order. Build in dependency order.

**Phase 1 — Foundation**
Tenancy, both database connections, schema layout, roles and permissions,
branches, doctors, patients. Nothing user-facing yet.

**Phase 2 — The spine**
Schedules and sessions → serial engine (with concurrency tests) → counter
booking → reception desk today's board. At the end of this phase a clinic could
theoretically run its front desk.

**Phase 3 — The hook**
Live queue, Reverb integration, polling fallback, waiting-room display, public
booking site. This is what gets demoed.

**Phase 4 — The flagship**
Prescription writer: structure first, then autocomplete and shorthand parsing,
then safety intelligence, then output and printing.

**Phase 5 — Money and retention**
Payments, billing, notifications, mini-EMR timeline.

**Phase 6 — Sell it**
Reports, SaaS control plane, subscription billing, super-admin tooling.

**Phase 7 — Upsell**
Telemedicine.

Offline reception (F.1) is built alongside Phase 2, not retrofitted. Retrofitting
offline support onto a synchronous UI is a rewrite.

---

## 8. Definition of done

A module is not complete until:

- Concurrency-sensitive paths have tests proving correctness under parallel load
  (serial allocation especially).
- Every clinical write produces an audit log entry.
- The screen is usable on a low-end Android device over a poor connection.
- Bangla text renders correctly in the UI, in printed output, and in SMS.
- Tenant isolation is verified by test — no query can reach another tenant's
  schema.
- No prescription rendering path joins live to the `catalog` database.
