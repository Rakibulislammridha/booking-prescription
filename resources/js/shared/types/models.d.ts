// Server-shaped types (JsonResource output, snake_case on the wire — docs/CONVENTIONS.md §13).
// Each module appends its own `export interface` block between its `// <module>:start` / `// <module>:end`
// markers (CONVENTIONS §2.1) and never edits another module's block. Keep blocks alphabetised inside.

// shared:start
export interface Money {
  paisa: number;
  formatted: string; // "৳500.00" via MoneyResource
}

export interface Paginated<T> {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: { current_page: number; from: number | null; last_page: number; per_page: number; to: number | null; total: number; path: string };
}

export interface CursorPaginated<T> {
  data: T[];
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
  meta: { path: string; per_page: number; next_cursor: string | null; prev_cursor: string | null };
}

/** 422 domain failure body (ARCHITECTURE §2 renderer): clients branch on `code`, never on message text. */
export interface DomainErrorBody {
  message: string;
  code: string; // dotted `<module>.<condition>`, e.g. serials.pool_exhausted
}

/** 422 validation failure body (Laravel default). */
export interface ValidationErrorBody {
  message: string;
  errors: Record<string, string[]>;
}
// shared:end

// tenancy:start
export interface PingResponse {
  t: number;        // unix seconds
  tenant: string;   // tenant public id
}
// tenancy:end

// clinic:start
export interface BranchSummary {
  public_id: string;
  name: string;
  code: string;
  slug: string;
}

/** BranchResource — the full branch row the setup screens edit. */
export interface ClinicBranch {
  id: number;
  public_id: string;
  name: string;
  code: string;
  slug: string;
  address: string | null;
  phone: string | null;
  email: string | null;
  is_main: boolean;
  is_active: boolean;
  geo: { lat?: number; lng?: number } | null;
  settings: Record<string, unknown>;
}

/** public.tenants name/locale + the `branding` jsonb that feeds the public site's --tenant-* variables. */
export interface ClinicBranding {
  name: string;
  name_bn: string | null;
  locale: 'bn' | 'en';
  primary_color: string | null;
  accent_color: string | null;
  on_primary_color: string | null;
  logo_url: string | null;
  timezone: string;
}

export interface ClinicDepartment {
  id: number;
  branch_id: number | null;
  branch_name?: string | null;
  name: string;
  name_bn: string | null;
  slug: string;
  sort_order: number;
  is_active: boolean;
}

export interface ClinicDoctor {
  id: number;
  public_id: string;
  name: string;
  name_bn: string | null;
  slug: string;
  code: string;
  gender: 'male' | 'female' | 'other' | null;
  mobile: string | null;
  email: string | null;
  department_id: number | null;
  department_name?: string | null;
  user_id: number | null;
  user_name?: string | null;
  sort_order: number;
  photo_path: string | null;
  photo_url: string | null;
  is_active: boolean;
  accepts_online_booking: boolean;
  accepts_telemedicine: boolean;
  room_label: string | null;
  profile?: ClinicDoctorProfile | null;
  specialty_ids?: number[];
  primary_specialty_id?: number | null;
  specialties?: ClinicSpecialty[];
}

export interface ClinicDoctorLeave {
  id: number;
  doctor_id: number;
  doctor_name?: string | null;
  branch_id: number | null;
  branch_name?: string | null;
  starts_on: string;
  ends_on: string;
  type: 'planned' | 'emergency';
  reason: string | null;
  notify_patients: boolean;
  notified_at: string | null;
  is_cancelled: boolean;
}

/** doctor_profiles — fees are paisa; `free_followup_within_days` is the window the billing engine honours. */
export interface ClinicDoctorProfile {
  degrees: string | null;
  degrees_bn: string | null;
  bmdc_reg_no: string | null;
  designation: string | null;
  new_fee_paisa: number;
  followup_fee_paisa: number;
  free_followup_within_days: number;
  followup_within_days: number;
  bio: string | null;
  bio_bn: string | null;
  experience_years: number | null;
  languages: string[];
  report_visit_free: boolean;
  telemedicine_fee_paisa: number | null;
  online_booking_fee_delta_paisa: number;
  advance_payment_required: boolean;
  chamber_notes: string | null;
}

export interface ClinicHoliday {
  id: number;
  branch_id: number | null;
  branch_name?: string | null;
  holiday_date: string;
  name: string;
  name_bn: string | null;
}

export interface ClinicSpecialty {
  id: number;
  name: string;
  name_bn: string | null;
  slug: string;
  icon: string | null;
  sort_order: number;
  is_active: boolean;
  doctors_count?: number;
}

export interface ClinicStaffUser {
  id: number;
  public_id: string;
  name: string;
  email: string;
  mobile: string | null;
  default_branch_id: number | null;
  branch_name?: string | null;
  locale: 'bn' | 'en';
  is_active: boolean;
  roles: string[];
  role: string | null;
  must_change_password: boolean;
  session_timeout_minutes: number | null;
  doctor_id?: number | null;
  last_login_at: string | null;
}

export interface DoctorSummary {
  public_id: string;
  slug: string;
  name: string;
  name_bn: string | null;
  room: string | null;
}

export type PadLanguage = 'bn' | 'en' | 'both';

/** The bounds App\Domain\Prescription\Render\PadGeometry clamps to; the designer refuses anything outside them. */
export interface PadLimits {
  margin_mm: { min: number; max: number };
  header_height_mm: { min: number; max: number };
  footer_height_mm: { min: number; max: number };
  font_size_pt: { min: number; max: number };
  rx_font_size_pt: { min: number; max: number };
}

export interface PadMargins {
  top: number;
  right: number;
  bottom: number;
  left: number;
}

export type PadSectionKey =
  | 'vitals' | 'complaints' | 'examination' | 'diagnosis' | 'rx'
  | 'investigations' | 'advice' | 'followup' | 'referral' | 'signature';

/** doctor_pad_settings (SCHEMA §3.1) — exactly the keys PadGeometry reads back at print time. */
export interface PadSettings {
  paper_size: 'A4' | 'A5';
  orientation: 'portrait' | 'landscape';
  letterhead_enabled: boolean;
  preprinted_mode: boolean;
  logo_path: string | null;
  header_html: string | null;
  footer_html: string | null;
  margins: PadMargins;
  header_height_mm: number;
  footer_height_mm: number;
  font_family: string;
  font_size_pt: number;
  show_qr: boolean;
  show_vitals: boolean;
  show_drug_info_url: boolean;
  layout: {
    sections: { key: PadSectionKey; visible: boolean }[];
    columns: 1 | 2;
    rx_font_size_pt: number | null;
    flags: { icd_codes: boolean; investigation_prices: boolean; generic_names: boolean };
  };
  token_slip_template: string;
  default_language: PadLanguage;
  signature_path: string | null;
}

/** The four value shapes SettingsRegistry types allow (`int`, `number`, `bool`, `string`). */
export type SettingValue = string | number | boolean | null;

/** One row of SettingsRegistry::all() as the settings page receives it. */
export interface SettingDefinition {
  type: 'int' | 'number' | 'bool' | 'string';
  default: unknown;
  options: string[] | null;
  min: number | null;
  max: number | null;
  /** A credential: encrypted at rest, and `values[key]` carries a mask (`••••1234`) rather than the value. */
  secret: boolean;
}

export interface SettingGroup {
  prefix: string;
  keys: string[];
}

export interface UserSummary {
  public_id: string;
  name: string;
  roles: string[];
}

/** One live staff session (BRIEF §5.N). `ref` is a hash of the session id — the id itself never leaves the server. */
export interface StaffSession {
  ref: string;
  ip: string | null;
  device: string;
  user_agent: string | null;
  login_at: string;
  last_seen_at: string;
  is_current: boolean;
}
// clinic:end

// audit:start
// audit:end

// patients:start
export type PatientGender = 'male' | 'female' | 'other';
export type BloodGroup = 'A+' | 'A-' | 'B+' | 'B-' | 'AB+' | 'AB-' | 'O+' | 'O-';
export type PatientRelationType = 'spouse' | 'child' | 'parent' | 'sibling' | 'guardian_of' | 'other';
export type PatientSource = 'online' | 'counter' | 'kiosk' | 'import' | 'walkin';
export type AllergenType = 'generic' | 'allergy_class' | 'food' | 'environmental' | 'other';
export type AllergySeverity = 'mild' | 'moderate' | 'severe' | 'unknown';
export type ConditionStatus = 'active' | 'chronic' | 'resolved';
export type MedicationSource = 'prescription' | 'reported';
export type PatientDocumentType = 'lab_report' | 'imaging' | 'external_prescription' | 'discharge_summary' | 'identity' | 'other';
export type OcrStatus = 'pending' | 'done' | 'failed' | 'skipped';
export type ConsentType = 'data_processing' | 'data_sharing' | 'sms' | 'whatsapp' | 'telemedicine' | 'research';
export type ConsentStatus = 'granted' | 'revoked';
export type ConsentChannel = 'counter' | 'online' | 'kiosk' | 'app' | 'phone';

/** PatientSummaryResource — search rows, lists, the portal (no ENC field, no address). */
export interface PatientSummary {
  public_id: string;
  patient_code: string;
  name: string;
  mobile: string;         // E.164
  mobile_local: string;   // 017…
  is_mobile_owner: boolean;
  gender: PatientGender | null;
  dob: string | null;     // YYYY-MM-DD
  dob_is_estimated: boolean;
  age_text: string | null;
  age_years: number | null;
  is_active: boolean;
  last_visit_at: string | null;
  visit_count: number;
}

/** FamilyMemberResource — a household member with their relation to the mobile owner. */
export interface FamilyMember extends PatientSummary {
  relation: PatientRelationType | null;
}

export interface PatientAllergy {
  id: number;
  allergen_type: AllergenType;
  generic_id: number | null;
  allergy_class_id: number | null;
  allergen_name: string;
  reaction: string | null;
  severity: AllergySeverity;
  notes: string | null;
  is_active: boolean;
  recorded_by_user_id: number | null;
  verified_by_doctor_id: number | null;
  created_at: string;
  updated_at: string;
}

export interface PatientCondition {
  id: number;
  icd10_code: string | null;
  condition_name: string;
  status: ConditionStatus;
  onset_date: string | null;
  resolved_date: string | null;
  notes: string | null;
  recorded_by_user_id: number | null;
  created_at: string;
  updated_at: string;
}

export interface PatientMedication {
  id: number;
  generic_id: number | null;
  brand_id: number | null;
  custom_brand_id: number | null;
  generic_name: string;
  brand_name: string | null;
  dose_text: string | null;
  source: MedicationSource;
  prescription_item_id: number | null;
  started_on: string | null;
  ended_on: string | null;
  is_active: boolean;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface PatientDocument {
  id: number;
  visit_id: number | null;
  type: PatientDocumentType;
  title: string;
  document_date: string | null;
  original_filename: string;
  mime_type: string;
  size_bytes: number;
  is_image: boolean;
  ocr_status: OcrStatus;
  uploaded_by_type: 'User' | 'Patient';
  created_at: string;
}

export interface PatientConsent {
  id: number;
  type: ConsentType;
  status: ConsentStatus;
  policy_version: string;
  channel: ConsentChannel;
  captured_by_user_id: number | null;
  has_signature: boolean;
  evidence: { otp_verified?: boolean; text_shown?: string };
  occurred_at: string;
}

/** PatientResource — the full record on Patients/Show and Edit (staff only, audited view). */
export interface PatientRecord extends PatientSummary {
  blood_group: BloodGroup | null;
  email: string | null;
  address: string | null;
  district: string | null;
  national_id: string | null;
  guardian_name: string | null;
  photo_path: string | null;
  preferred_language: 'bn' | 'en';
  notes: string | null;
  tags: string[];
  registered_branch_id: number | null;
  source: PatientSource;
  created_at: string | null;
  updated_at: string | null;
  primary?: (PatientSummary & { relation: PatientRelationType }) | null;
  dependents?: FamilyMember[];
  allergies?: PatientAllergy[];
  conditions?: PatientCondition[];
  medications?: PatientMedication[];
  documents?: PatientDocument[];
  consents?: PatientConsent[];
}

/** One row of GET /panel/patients/{patient}/timeline (PRESCRIPTION.md §8); kinds grow as modules register sources. */
export interface TimelineEntry {
  kind: string;            // document | allergy | condition | medication | consent | visit | vital | prescription | …
  id: number;
  occurred_at: string;
  title: string;
  subtitle: string | null;
  ref: string | null;      // public_id when the row has one, else its id
  meta: Record<string, unknown>;
  cursor: string;
}

export interface TimelinePage {
  data: TimelineEntry[];
  meta: { next_cursor: string | null };
}

/** GET /panel/patients/{patient}/vitals-trend — oldest first; [] until the vitals table exists. */
export interface VitalsTrendPoint {
  id: number;
  visit_id: number | null;
  recorded_at: string;
  bp_systolic: number | null;
  bp_diastolic: number | null;
  pulse_bpm: number | null;
  /** stored unit (SCHEMA §3.4); the panel displays °F via `@shared/format/temperature` */
  temperature_c: number | null;
  temperature_f: number | null;
  spo2_percent: number | null;
  respiratory_rate: number | null;
  weight_kg: number | null;
  height_cm: number | null;
  bmi: number | null;
  blood_glucose_mgdl: number | null;
}

/** GET /api/patients/{patient}/summary — PRESCRIPTION.md §1.2 `PatientSummary` for the writer's left pane. */
export interface PatientClinicalSummary {
  public_id: string;
  patient_code: string;
  name: string;
  age_years: number | null;
  age_months: number | null;
  age_text: string | null;
  dob: string | null;
  dob_is_estimated: boolean;
  sex: PatientGender | null;
  phone: string;
  blood_group: BloodGroup | null;
  preferred_language: 'bn' | 'en';
  family_head: { public_id: string; name: string; relation: PatientRelationType } | null;
  allergies: PatientAllergy[];
  conditions: PatientCondition[];
  medications: PatientMedication[];
  flags: { pregnant: boolean; lactating: boolean; renal: boolean; hepatic: boolean };
  consents: { data_sharing: boolean; sms: boolean; whatsapp: boolean };
  last_visit_at: string | null;
  visit_count: number;
  recent_visits: unknown[];   // filled by the Prescription module
}
// patients:end

// scheduling:start
export type ScheduleMode = 'serial' | 'slot';
export type OverrideType = 'late_start' | 'cut_short' | 'cancelled' | 'capacity_change' | 'time_change' | 'extra_session';
export type SessionStatus = 'scheduled' | 'running' | 'paused' | 'closed' | 'cancelled';

export interface DoctorOption {
  id: number;
  public_id: string;
  name: string;
  name_bn: string | null;
  slug: string;
}

export interface SchedulingBranchOption {
  id: number;
  public_id: string;
  name: string;
  code: string;
}

export interface DoctorSchedule {
  id: number;
  doctor_id: number;
  branch_id: number;
  weekday: number; // 0 = Sunday … 6 = Saturday
  session_code: string;
  session_label: string | null;
  start_time: string; // "HH:mm"
  end_time: string;
  mode: ScheduleMode;
  slot_minutes: number | null;
  max_serials: number;
  online_quota: number;
  counter_quota: number;
  buffer_quota: number;
  avg_consult_minutes: number;
  fee_new_paisa: number | null;
  fee_followup_paisa: number | null;
  auto_noshow_after: number | null;
  works_on_holidays: boolean;
  effective_from: string;
  effective_to: string | null;
  is_active: boolean;
}

export interface ScheduleOverride {
  id: number;
  doctor_id: number;
  branch_id: number;
  override_date: string;
  session_code: string | null;
  type: OverrideType;
  delay_minutes: number | null;
  new_start_time: string | null;
  new_end_time: string | null;
  new_max_serials: number | null;
  new_online_quota: number | null;
  new_counter_quota: number | null;
  new_buffer_quota: number | null;
  reason: string | null;
  notify_patients: boolean;
  applied_at: string | null;
}

export interface SessionCounts {
  booked: number;
  checked_in: number;
  in_consultation: number;
  completed: number;
  no_show: number;
  cancelled: number;
  postponed: number;
}

export interface SessionRemaining {
  online: number;
  counter: number;
  buffer: number;
  counter_in_blocks: number;
  released: number;
}

export interface SessionInstance {
  public_id: string;
  session_code: string;
  session_date: string;
  status: SessionStatus;
  mode: ScheduleMode;
  slot_minutes: number | null;
  planned_start_at: string;
  planned_end_at: string;
  actual_start_at: string | null;
  actual_end_at: string | null;
  delay_minutes: number;
  pause_seconds: number;
  max_serials: number;
  online_quota: number;
  counter_quota: number;
  buffer_quota: number;
  avg_consult_seconds: number;
  consult_samples: number;
  auto_noshow_after: number;
  now_serving_serial_id: number | null;
  counts: SessionCounts;
  version: number;
  cancel_reason: string | null;
  doctor?: { public_id: string; slug: string; name: string; name_bn: string | null; room: string | null };
  branch?: { public_id: string; name: string; code: string; slug: string };
  remaining?: SessionRemaining;
  serials?: Serial[];
}

export interface AvailabilityDay {
  date: string;
  /** Why the day has no session — not in the weekly template, a holiday, or doctor leave; `null` when it has one. */
  closed: 'off' | 'holiday' | 'leave' | null;
  sessions: Array<{
    code: string;
    public_id: string;
    status: SessionStatus;
    mode: ScheduleMode;
    planned_start_at: string;
    planned_end_at: string;
    delay_minutes: number;
    online_remaining: number;
    online_quota: number;
    slot_minutes: number | null;
    /** Display code of the serial being served while the session is `running`; `null` otherwise. */
    now_serving: string | null;
    free_slots: string[] | null;
  }>;
}
// scheduling:end

// serials:start
export type SerialPool = 'online' | 'counter' | 'buffer';
export type SerialStatus = 'booked' | 'checked_in' | 'in_consultation' | 'completed' | 'no_show' | 'cancelled' | 'postponed';
export type SerialPriority = 'normal' | 'elderly' | 'emergency' | 'vip';
export type SerialSource = 'online' | 'counter' | 'walkin' | 'kiosk' | 'followup' | 'offline';
export type CancelReason = 'patient_request' | 'doctor_unavailable' | 'duplicate' | 'transferred' | 'no_payment' | 'session_cancelled' | 'other';

/** SerialResource (SERIAL_ENGINE §16). `patient` is filled by the Patients module's resource; `eta` by the Queue module. */
export interface Serial {
  public_id: string;
  display_code: string;
  number: number;
  position: number;
  status: SerialStatus;
  priority: SerialPriority;
  source: SerialSource;
  pool: SerialPool;
  patient_id: number | null;
  patient: { public_id: string; name: string; mobile_masked: string } | null;
  appointment_id: number | null;
  slot_start_at: string | null;
  booked_at: string;
  checked_in_at: string | null;
  called_at: string | null;
  completed_at: string | null;
  no_show_at: string | null;
  cancelled_at: string | null;
  cancel_reason_code: CancelReason | null;
  passed_count: number;
  skip_count: number;
  eta: string | null;
  session?: { public_id: string; code: string; date: string };
}

export interface SerialBlock {
  public_id: string;
  range_start: number;
  range_end: number;
  next_number: number;
  status: 'active' | 'released' | 'exhausted';
  reception_device_id: number | null;
  leased_at: string;
  expires_at: string | null;
  released_at: string | null;
  revoked_at: string | null;
  returned_count: number;
}

export interface PoolRanges {
  [pool: string]: { range_start: number; range_end: number; next_number: number; remaining: number };
}

export interface CallNextResponse {
  called: Serial | null;
  waiting_booked: number;
}
// serials:end

// booking:start
export type AppointmentType = 'new' | 'followup';
export type BookingChannel = 'online' | 'phone' | 'counter' | 'walkin' | 'kiosk' | 'followup' | 'telemedicine' | 'offline';
export type AppointmentStatus = 'draft' | 'pending' | 'confirmed' | 'checked_in' | 'in_consultation' | 'completed' | 'no_show' | 'cancelled' | 'postponed';
export type FeeRule = 'new' | 'followup_paid' | 'followup_free' | 'schedule_override' | 'telemedicine' | 'manual' | 'waived';
export type PaymentStatus = 'unpaid' | 'partial' | 'paid' | 'refunded';

/** AppointmentResource — the booking on the wire (site confirmation, desk dialogs, public API). */
export interface Appointment {
  public_id: string;
  type: AppointmentType;
  channel: BookingChannel;
  status: AppointmentStatus;
  scheduled_date: string | null;
  slot_start_at: string | null;
  fee: Money;
  list_fee: Money;
  fee_rule: FeeRule;
  fee_rule_reason: string | null;
  payment_status: PaymentStatus;
  notes: string | null;
  confirmed_at: string | null;
  cancelled_at: string | null;
  cancel_reason_code: CancelReason | null;
  patient?: { public_id: string; name: string; mobile_local: string };
  doctor?: { public_id: string; slug: string; name: string; name_bn: string | null; room: string | null };
  session?: { public_id: string; code: string; date: string; planned_start_at: string; status: SessionStatus } | null;
  serial?: { public_id: string; display_code: string; number: number; status: SerialStatus; position: number } | null;
}

/** POST /panel/reception/bookings body (StoreCounterBookingRequest). */
export interface CounterBookingBody {
  session: string;
  channel: 'counter' | 'phone' | 'walkin' | 'followup';
  patient?: string | null;
  mobile?: string | null;
  name?: string | null;
  sex?: 'm' | 'f' | 'o' | null;
  age_years?: number | null;
  priority?: SerialPriority;
  priority_reason?: string | null;
  type?: AppointmentType | null;
  previous_appointment?: string | null;
  notes?: string | null;
  client_event_id?: string;
}

export interface CounterBookingResponse {
  appointment: Appointment;
  serial: DeskSerial;
  patient_created: boolean;
  replayed: boolean;
}

/** Booking site: a doctor card on Booking/Index. */
export interface BookingDoctorCard {
  public_id: string;
  slug: string;
  name: string;
  name_bn: string | null;
  degrees: string | null;
  degrees_bn: string | null;
  designation: string | null;
  fee_paisa: number;
  specialties: Array<{ slug: string; name: string; name_bn: string | null }>;
  weekdays: number[];
}

export interface KioskSession {
  public_id: string;
  code: string;
  date: string;
  status: SessionStatus;
  planned_start_at: string;
  online_remaining: number;
  doctor: { public_id: string; slug: string; name: string; name_bn: string | null; fee_paisa: number };
}
// booking:end

// queue:start
/** Doctor identity as the queue pages receive it (REALTIME.md §4.1 `session.doctor`). */
export interface QueueDoctor {
  public_id: string;
  slug: string;
  name: string;
  name_bn: string | null;
  room: string | null;
}

/** One of today's session instances of a doctor — `GET /queue/{doctorSlug}/sessions` and the page props. */
export interface QueueSessionSummary {
  public_id: string;
  code: string;
  date: string;
  status: 'scheduled' | 'running' | 'paused' | 'closed' | 'cancelled';
  mode: 'serial' | 'slot';
  planned_start_at: string;
  expected_start_at: string;
  delay_minutes: number;
}

export interface QueueSessionsResponse {
  doctor: QueueDoctor;
  date: string;
  current: string | null;
  sessions: QueueSessionSummary[];
}

/** `GET /q/resolve/{localId}` (OFFLINE.md §10): a slip printed offline maps to its serial once the desk syncs. */
export interface ResolvedLocalSerial {
  resolved: boolean;
  local_id: string;
  serial?: { public_id: string; display_code: string; number: number; status: SerialStatus };
  session?: { public_id: string; code: string; date: string };
  doctor?: { slug: string; name: string; name_bn: string | null };
}

/** One tile of the waiting-room display (REALTIME.md §9.1). */
export interface DisplayTile {
  id: string;      // session instance public id
  code: string;
  doctor: QueueDoctor;
}

/** `board.updated` (REALTIME.md §3.1) — also the panel queue overview's props. */
export interface QueueBoardSession {
  id: string;
  doctor: string;
  code: string;
  status: 'scheduled' | 'running' | 'paused' | 'closed' | 'cancelled';
  now_serving: string | null;
  counts: { booked: number; checked_in: number; in_consultation: number; completed: number; no_show: number; cancelled: number; postponed: number };
  remaining: { online: number; counter: number; released: number; buffer: number };
  delay_minutes: number;
  version: number;
}

export interface QueueBoard {
  v: 1;
  branch: string;
  at: string;
  sessions: QueueBoardSession[];
}

/** `call.next` (REALTIME.md §3.1): pushed to the doctor screen and the waiting-room display together. */
export interface CallNextFrame {
  v: 1;
  session: string;
  doctor: string;
  serial: { id: string; code: string };
  patient: { first_name: string | null; age: number | null; sex: 'm' | 'f' | 'o' | null; vitals_taken: boolean };
  room: string | null;
  speak: { bn: string; en: string };
  version: number;
}

/** `session.delayed` (REALTIME.md §3.1/§10). */
export interface SessionDelayedFrame {
  v: 1;
  session: string;
  version: number;
  delay_minutes: number;
  expected_start_at: string;
  message: string | null;
  message_bn: string;
}

/** The staff-only patient card the doctor screen shows next to a serial code. */
export interface QueuePatientCard {
  name: string;
  age_text: string | null;
  sex: PatientGender | null;
  patient_code: string;
}
// queue:end

// reception:start
/** Prescription's VitalsStatus: `null` on a board row that cannot have a reading (not arrived, already finished). */
export interface DeskVitals {
  recorded: boolean;
  readings: number;
  recorded_at: string | null;
  reviewed: boolean;
}

/** SerialPresenter — SerialResource + patient + appointment: the board, the bootstrap and every accepted replay result. */
export interface DeskSerial extends Omit<Serial, 'patient'> {
  patient: { public_id: string; name: string; mobile_masked: string; age_text: string | null; sex: PatientGender | null; patient_code: string } | null;
  /** `hold_expires_at` is set only while an advance-payment hold is still sweepable (pending + unpaid). */
  appointment: { public_id: string; type: AppointmentType; channel: BookingChannel; status: AppointmentStatus; fee_paisa: number; list_fee_paisa: number; fee_rule: FeeRule; payment_status: PaymentStatus; hold_expires_at: string | null } | null;
  vitals: DeskVitals | null;
}

/** One session on today's board (BoardBuilder). */
export interface BoardSession {
  public_id: string;
  code: string;
  date: string;
  status: SessionStatus;
  mode: ScheduleMode;
  doctor: DoctorSummary;
  planned_start_at: string;
  planned_end_at: string;
  expected_start_at: string;
  delay_minutes: number;
  now_serving: { public_id: string; display_code: string } | null;
  counts: SessionCounts;
  remaining: SessionRemaining;
  fee_new_paisa: number;
  fee_followup_paisa: number;
  max_serials: number;
  version: number;
  serials: DeskSerial[];
}

export interface Board {
  date: string;
  branch: BranchSummary;
  sessions: BoardSession[];
  generated_at: string;
}

export interface ReceptionDevice {
  public_id: string;
  number: number;
  name: string;
  kind: 'reception' | 'display';
  status: 'active' | 'revoked';
  branch_id: string | null;
  block_size: number;
  app_version: string | null;
  last_seen_at: string | null;
  last_sync_at: string | null;
  revoked_at: string | null;
  receipt_prefix: string;
}

export interface RegisterDeviceResponse {
  device: ReceptionDevice;
  token: string;
  abilities: string[];
  expires_at: string | null;
  rotated: boolean;
}

/** GET /panel/reception/patients/lookup and /api/reception/patients/lookup rows: summary + last bookings. */
export interface DeskPatientLookup extends PatientSummary {
  history?: Array<{ public_id: string; date: string | null; doctor: string; doctor_public_id: string; status: AppointmentStatus; type: AppointmentType; fee_paisa: number; payment_status: PaymentStatus }>;
}

export interface ShiftSummary {
  date: string;
  branch: { public_id: string; name: string };
  sessions: number;
  serials: { issued: number; by_status: Record<string, number>; by_source: Record<string, number> };
  money: { expected_paisa: number; collected_paisa: number; offline_cash_paisa: number; paid_appointments: number; partial_appointments: number; unpaid_appointments: number; waived_paisa: number };
  per_user: Array<{ user: { public_id: string | null; name: string } | null; issued: number; by_source: Record<string, number>; checked_in: number; cancelled: number; collected_paisa: number }>;
  generated_at: string;
}
// reception:end

// prescription:start
// prescription:end

// catalog:start
/** One autocomplete hit of GET /api/catalog/drugs (PRESCRIPTION.md §3.3): a master presentation, a generic, or a tenant custom brand. */
export interface DrugSearchHit {
  id: string;                          // s{strength_id} | g{generic_id} | c{custom_brand_id}
  source: 'master' | 'custom';
  doc_type: 'presentation' | 'generic';
  label: string;
  generic_id: number;
  generic_name: string;
  generic_aliases: string[];
  brand_id: number | null;
  custom_brand_id: number | null;
  brand_name: string | null;
  manufacturer: string | null;
  strength_id: number | null;
  strength_label: string | null;
  strength_value: number | null;
  strength_unit: string | null;
  per_volume_ml: number | null;
  strength_mg: number | null;
  per_ml: number | null;
  dosage_form_id: number | null;
  form: string | null;
  form_code: string | null;
  default_unit: string | null;
  route_id: number | null;
  route: string | null;
  route_code: string | null;
  pack_size: string | null;
  pack_size_value: number | null;
  pack_unit: string | null;
  info_slug: string | null;
  therapeutic_class: string | null;
  is_controlled: boolean;
  popularity: number;
  is_active: boolean;
  review_status: 'pending' | 'approved' | 'rejected' | 'promoted' | null;
  promoted_to_master: boolean | null;
  usage: number;
  fav_for_dx: boolean;
  score: number;
  last_shorthand: string | null;
}

export interface DrugSearchResponse {
  q: string;
  took_ms: number;
  hits: DrugSearchHit[];
  engine: 'meilisearch' | 'database';
}

export interface Icd10SearchHit {
  id: string;
  code: string;
  parent_code: string | null;
  chapter: string | null;
  block: string | null;
  title: string;
  title_bn: string | null;
  aliases: string[];
  is_billable: boolean;
  popularity: number;
  usage: number;
  score: number;
}

export interface Icd10SearchResponse {
  q: string;
  took_ms: number;
  hits: Icd10SearchHit[];
  engine: 'meilisearch' | 'database' | 'none';
}

export interface GenericOption {
  id: number;
  name: string;
  name_bn: string | null;
  therapeutic_class: string | null;
  aliases: string[];
}

export interface DosageFormOption {
  id: number;
  code: string;
  name: string;
  name_bn: string | null;
}

export interface RouteOption {
  id: number;
  code: string;
  name: string;
  name_bn: string | null;
}

export type CustomBrandReviewStatus = 'pending' | 'approved' | 'rejected' | 'promoted';

export interface CustomBrand {
  id: number;
  brand_name: string;
  generic_id: number;
  generic_name: string;
  manufacturer: string | null;
  strength: string | null;
  dosage_form_id: number | null;
  form: string | null;
  route_id: number | null;
  route: string | null;
  review_status: CustomBrandReviewStatus;
  promoted_to_master: boolean;
  master_brand_id: number | null;
  master_strength_id: number | null;
  review_note: string | null;
  reviewed_at: string | null;
  use_count: number;
  is_active: boolean;
  created_at: string | null;
}
// catalog:end

// billing:start
/** `invoices.status` (SCHEMA §3.5). A void bill is never edited — corrections are new rows. */
export type InvoiceStatus = 'draft' | 'issued' | 'partially_paid' | 'paid' | 'void' | 'refunded';

/** `invoice_items.type` (SCHEMA §3.5). */
export type InvoiceItemType = 'consultation' | 'followup' | 'investigation' | 'telemedicine' | 'other';

/** `payments.method` / `refunds.method` (SCHEMA §3.5). */
export type BillingPaymentMethod = 'cash' | 'card' | 'bkash' | 'nagad' | 'sslcommerz' | 'other';

/** `payments.gateway` — the three online drivers of BRIEF §5.I. */
export type BillingGateway = 'bkash' | 'nagad' | 'sslcommerz';

/** `payments.status` (SCHEMA §3.5). */
export type PaymentTxnStatus = 'pending' | 'succeeded' | 'failed' | 'cancelled' | 'refunded' | 'partially_refunded';

/** `refunds.status` — money leaves the books only at `processed`. */
export type RefundStatus = 'pending' | 'approved' | 'processed' | 'rejected' | 'failed';

/** `refunds.reason_code` (BRIEF §5.F). */
export type RefundReason = 'doctor_absent' | 'patient_cancelled' | 'duplicate' | 'service_not_rendered' | 'goodwill' | 'other';

/** `discounts.type` / `coupons.type`. */
export type DiscountType = 'percentage' | 'fixed';

/** `discounts.reason_code` — every waiver carries one. */
export type DiscountReason = 'staff' | 'poor_fund' | 'followup' | 'doctor_waiver' | 'promo' | 'other';

/** `doctor_revenue_shares.item_type` / `.share_type`. */
export type RevenueShareItemType = InvoiceItemType | 'all';
export type RevenueShareType = 'percentage' | 'fixed';

export type CashShiftStatus = 'open' | 'closed';

export interface BillingDiscount {
  id: number;
  type: DiscountType;
  value: string;
  amount: Money;
  amount_paisa: number;
  reason_code: DiscountReason;
  note: string | null;
  approved: boolean;
}

export interface BillingInvoiceItem {
  id: number;
  sort_order: number;
  type: InvoiceItemType;
  description: string;
  quantity: number;
  unit_price: Money;
  line_total: Money;
  unit_price_paisa: number;
  line_total_paisa: number;
  /** Frozen at issue: a later rule change never rewrites these. */
  doctor_share_paisa: number;
  clinic_share_paisa: number;
}

export interface BillingPayment {
  public_id: string;
  receipt_number: string | null;
  method: BillingPaymentMethod;
  status: PaymentTxnStatus;
  amount: Money;
  refunded: Money;
  amount_paisa: number;
  refunded_paisa: number;
  refundable_paisa: number;
  gateway: BillingGateway | null;
  gateway_txn_id: string | null;
  paid_at: string | null;
  failed_reason: string | null;
  received_by?: { public_id: string; name: string } | null;
}

export interface BillingRefund {
  id: number;
  amount: Money;
  amount_paisa: number;
  method: BillingPaymentMethod;
  status: RefundStatus;
  reason_code: RefundReason;
  reason_note: string | null;
  processed_at: string | null;
  payment_public_id?: string;
}

export interface BillingInvoice {
  public_id: string;
  number: string;
  status: InvoiceStatus;
  subtotal: Money;
  discount: Money;
  coupon_discount: Money;
  vat: Money;
  total: Money;
  paid: Money;
  due: Money;
  subtotal_paisa: number;
  discount_paisa: number;
  coupon_discount_paisa: number;
  vat_paisa: number;
  total_paisa: number;
  paid_paisa: number;
  /** GENERATED total − paid; the server is the only writer. */
  due_paisa: number;
  issued_at: string | null;
  paid_at: string | null;
  voided_at: string | null;
  void_reason: string | null;
  notes: string | null;
  patient?: { public_id: string; name: string; patient_code: string; mobile_local: string };
  doctor?: { public_id: string; name: string; name_bn: string | null } | null;
  branch?: { public_id: string; name: string };
  appointment?: { public_id: string; type: AppointmentType; fee_rule: string; fee_rule_reason: string | null; scheduled_date: string | null } | null;
  items?: BillingInvoiceItem[];
  payments?: BillingPayment[];
  refunds?: BillingRefund[];
  discounts?: BillingDiscount[];
}

export interface BillingCoupon {
  id: number;
  code: string;
  name: string;
  type: DiscountType;
  value: string;
  max_discount_paisa: number | null;
  min_invoice_paisa: number;
  max_uses: number | null;
  max_uses_per_patient: number;
  uses_count: number;
  applies_to: Record<string, unknown>;
  valid_from: string | null;
  valid_until: string | null;
  is_active: boolean;
}

export interface BillingCashShift {
  id: number;
  status: CashShiftStatus;
  opened_at: string;
  closed_at: string | null;
  opening_float_paisa: number;
  expected_cash_paisa: number | null;
  counted_cash_paisa: number | null;
  /** counted − expected; a shortfall stays negative and visible. */
  variance_paisa: number | null;
  card_total_paisa: number | null;
  mobile_money_total_paisa: number | null;
  closing_note: string | null;
  user?: { public_id: string; name: string };
  branch?: { public_id: string; name: string };
}

export interface BillingShiftTotals {
  expected_cash_paisa: number;
  card_total_paisa: number;
  mobile_money_total_paisa: number;
  cash_in_paisa: number;
  cash_refunds_paisa: number;
  payment_count: number;
}

export interface BillingRevenueShareRule {
  id: number;
  item_type: RevenueShareItemType;
  share_type: RevenueShareType;
  share_value: string;
  effective_from: string;
  effective_to: string | null;
  is_active: boolean;
  doctor?: { public_id: string; name: string };
  branch?: { public_id: string; name: string } | null;
}

export interface BillingCollectionBucket {
  key: string | null;
  label?: string | null;
  method?: string | null;
  gross_paisa: number;
  refunds_paisa: number;
  net_paisa: number;
  count: number;
}

export interface BillingCollectionReport {
  from: string;
  to: string;
  totals: { gross_paisa: number; refunds_paisa: number; net_paisa: number; payment_count: number; invoice_count: number };
  by_method: BillingCollectionBucket[];
  by_day: Array<{ date: string; gross_paisa: number; refunds_paisa: number; net_paisa: number; count: number }>;
  by_doctor: BillingCollectionBucket[];
  by_branch: BillingCollectionBucket[];
  by_user: BillingCollectionBucket[];
}

export interface BillingCommissionRow {
  doctor_id: number | null;
  doctor_public_id: string | null;
  doctor_name: string | null;
  branch_id: number | null;
  branch_name: string | null;
  item_type: InvoiceItemType;
  billed_paisa: number;
  doctor_share_paisa: number;
  clinic_share_paisa: number;
  collected_paisa: number;
  item_count: number;
}

export interface BillingCommissionReport {
  from: string;
  to: string;
  rows: BillingCommissionRow[];
  totals: { billed_paisa: number; doctor_share_paisa: number; clinic_share_paisa: number; collected_paisa: number; item_count: number };
}

/** What a collect/refund call answers with (`duplicate = true` means a replay recorded nothing new). */
export interface BillingPaymentResult {
  public_id: string | null;
  receipt_no: string | null;
  amount_paisa: number;
  method: BillingPaymentMethod;
  status: PaymentTxnStatus;
  duplicate: boolean;
  invoice: { public_id: string; number: string; total_paisa: number; paid_paisa: number; due_paisa: number; status: InvoiceStatus };
}

export interface BillingRefundEligibility {
  eligible: boolean;
  reason_code?: RefundReason;
  minutes_before_start?: number | null;
  explanation: string;
  paid_paisa?: number;
}
// billing:end

// notifications:start
/** `notifications.channel` / `notification_templates.channel` (SCHEMA §3.6). */
export type NotificationChannel = 'sms' | 'whatsapp' | 'push' | 'email' | 'ivr';

/** `notifications.status` — `failed` is the dead-letter state the outbound log surfaces. */
export type NotificationStatus = 'queued' | 'scheduled' | 'sending' | 'sent' | 'delivered' | 'failed' | 'cancelled';

/** The closed event catalogue of BRIEF §5.J (plus otp / payment_receipt / serial_* raised by other modules). */
export type NotificationEventKey =
  | 'booking_confirmed'
  | 'reminder_day_before'
  | 'reminder_morning'
  | 'three_ahead'
  | 'doctor_delayed'
  | 'doctor_cancelled'
  | 'prescription_ready'
  | 'followup_due'
  | 'otp'
  | 'payment_receipt'
  | 'serial_transferred'
  | 'serial_postponed';

/** One outbound-log row. `body` is null when `body_redacted` (an OTP code is never shown back). */
export interface NotificationRow {
  id: number;
  event_key: NotificationEventKey;
  channel: NotificationChannel;
  status: NotificationStatus;
  recipient: string;
  locale: 'bn' | 'en';
  subject: string | null;
  body: string | null;
  body_redacted: boolean;
  patient?: { public_id: string; name: string; patient_code: string } | null;
  serial_id: number | null;
  attempts: number;
  segments: number | null;
  cost_paisa: number | null;
  last_error: string | null;
  scheduled_for: string | null;
  sent_at: string | null;
  delivered_at: string | null;
  created_at: string;
}

/** One delivery attempt with the provider exchange — the detail drawer. */
export interface NotificationAttempt {
  id: number;
  attempt_no: number;
  provider: string;
  provider_message_id: string | null;
  status: 'sent' | 'delivered' | 'failed' | 'rejected';
  request: Record<string, unknown> | null;
  response: Record<string, unknown> | null;
  error_code: string | null;
  latency_ms: number | null;
  created_at: string;
}

export interface NotificationDetail {
  data: NotificationRow;
  attempts: NotificationAttempt[];
}

/** A tenant template row; `is_default: true` rows are the built-in wording, not database rows. */
export interface NotificationTemplateRow {
  id: number | null;
  event_key: NotificationEventKey;
  channel: NotificationChannel;
  locale: 'bn' | 'en';
  subject: string | null;
  body: string;
  provider_template_id?: string | null;
  is_active?: boolean;
  is_default: boolean;
  preview?: string;
  segments?: SmsSegmentCount;
  updated_at?: string;
}

/** SegmentCounter's result: what the gateway bills for this body (Bangla = 70/67, Latin = 160/153). */
export interface SmsSegmentCount {
  encoding: 'GSM-7' | 'UCS-2';
  units: number;
  segments: number;
  remaining: number;
  per_segment: number;
}

export interface NotificationTemplatePreview {
  body: string;
  subject: string | null;
  variables: Record<string, string>;
  segments: SmsSegmentCount;
  unknown_placeholders: string[];
}

/** A gateway row WITHOUT credentials — only which credential keys are stored. */
export interface SmsGatewayRow {
  id: number;
  channel: NotificationChannel;
  provider: string;
  name: string;
  sender_id: string | null;
  credential_keys: string[];
  options: Record<string, unknown>;
  priority: number;
  is_default: boolean;
  is_active: boolean;
  balance_paisa: number | null;
  balance_checked_at: string | null;
  updated_at: string;
}

export interface PushSubscriptionRow {
  id: number;
  subscriber_type: string;
  subscriber_id: number;
  endpoint_host: string;
  endpoint_digest: string;
  content_encoding: string;
  user_agent: string | null;
  failed_count: number;
  last_used_at: string | null;
  created_at: string;
}
// notifications:end

// reports:start
/** Reports & analytics (BRIEF §5.L). Every shape here is exactly what `App\Domain\Reports\Queries\**` returns. */
export type ReportKind = 'dashboard' | 'appointments' | 'wait-times' | 'revenue' | 'patients' | 'clinical' | 'peak-hours';
export type ReportFormat = 'csv' | 'xlsx' | 'pdf';
export type ReportGranularity = 'day' | 'week' | 'month';
export type PeakMetric = 'arrivals' | 'bookings' | 'consultations';

export interface ReportFilterState {
  from: string;
  to: string;
  branch_id: number | null;
  doctor_id: number | null;
  specialty_id: number | null;
  method: string | null;
  metric: PeakMetric;
  limit: number;
  granularity: ReportGranularity;
  /** The raw query values, echoed back so the filter bar keeps showing what the user picked. */
  branch?: string | null;
  doctor?: string | null;
  specialty?: string | null;
}

export interface ReportScopeProps {
  financial: boolean;
  clinical: boolean;
  all_doctors: boolean;
  can_export: boolean;
  doctor_id: number | null;
  reports: ReportKind[];
}

export interface ReportOptionsProps {
  branches: { public_id: string; name: string }[];
  doctors: { public_id: string; name: string }[];
  specialties: { slug: string; name: string; name_bn: string | null }[];
}

/** Counts shared by every appointment-volume grouping. */
export interface ReportVolumeCounts {
  key?: string;
  booked: number;
  completed: number;
  no_show: number;
  cancelled: number;
  postponed: number;
  open: number;
  expected: number;
  no_show_rate: number | null;
  completion_rate: number | null;
}

export interface ReportVolumeByDoctor extends ReportVolumeCounts { doctor_id: number; doctor_name: string | null; doctor_public_id: string | null }
export interface ReportVolumeBySource extends ReportVolumeCounts { source: string; channel_group: string }
export interface ReportVolumeByGroup extends ReportVolumeCounts { channel_group: string }
export interface ReportVolumeByPeriod extends ReportVolumeCounts { period: string }

export interface AppointmentVolumeReport {
  totals: ReportVolumeCounts;
  by_period: ReportVolumeByPeriod[];
  by_doctor: ReportVolumeByDoctor[];
  by_source: ReportVolumeBySource[];
  by_channel_group: ReportVolumeByGroup[];
  granularity: ReportGranularity;
}

export interface ReportWaitStats {
  wait_samples: number;
  wait_avg_minutes: number | null;
  wait_p50_minutes: number | null;
  wait_p90_minutes: number | null;
  consult_samples: number;
  consult_avg_minutes: number | null;
  consult_p50_minutes: number | null;
  consult_p90_minutes: number | null;
  consult_excluded: number;
}

export interface ReportOverrunStats {
  sessions: number;
  overrun_avg_minutes: number | null;
  overrun_max_minutes: number | null;
  overran: number;
  overran_rate: number | null;
  late_start_avg_minutes: number | null;
  pause_avg_minutes: number | null;
}

export interface WaitTimeReport {
  totals: ReportWaitStats;
  by_doctor: (ReportWaitStats & { doctor_id: number; doctor_name: string | null; doctor_public_id: string | null })[];
  by_period: (ReportWaitStats & { period: string })[];
  overrun: { totals: ReportOverrunStats; by_doctor: (ReportOverrunStats & { doctor_id: number; doctor_name: string | null })[] };
  clamp: { min_seconds: number; max_seconds: number };
  granularity: ReportGranularity;
}

export interface RevenueReport {
  collection: BillingCollectionReport;
  commission: BillingCommissionReport;
}

export interface PatientMixTotals { patients: number; new: number; returning: number; new_rate: number | null }
export interface FollowUpCompliance {
  advised: number; due: number; booked: number; kept: number; booked_due: number; kept_due: number;
  booking_rate: number | null; compliance_rate: number | null; as_of: string;
}
export interface PatientMixReport {
  totals: PatientMixTotals;
  by_period: (PatientMixTotals & { period: string })[];
  by_doctor: (PatientMixTotals & { doctor_id: number; doctor_name: string | null; doctor_public_id: string | null })[];
  follow_up: FollowUpCompliance;
  granularity: ReportGranularity;
}

export interface TopDiagnosisRow { key: string; icd10_code: string | null; title: string; coded: boolean; visits: number; patients: number; final_visits: number }
export interface TopDrugRow { key: string; name: string; generic_id?: number | null; generic_name?: string | null; brand_id?: number | null; custom_brand_id?: number | null; is_custom?: boolean; items: number; prescriptions: number; patients: number }
export interface ReportTrendPoint { period: string; key: string; visits?: number; items?: number }

export interface ClinicalReport {
  diagnoses: { rows: TopDiagnosisRow[]; trend: ReportTrendPoint[]; trend_keys: string[]; total_visits: number; granularity: ReportGranularity };
  drugs: {
    by_generic: TopDrugRow[];
    by_brand: TopDrugRow[];
    trend: ReportTrendPoint[];
    trend_keys: string[];
    totals: { items: number; prescriptions: number; generic_only: number; custom_brand_items: number; generic_only_rate: number | null };
    granularity: ReportGranularity;
  };
}

export interface PeakHourReport {
  metric: PeakMetric;
  cells: { weekday: number; hour: number; count: number }[];
  grid: number[][];
  weekday_occurrences: number[];
  busiest: { weekday: number; hour: number; count: number } | null;
  daily_peak: { weekday: number; hour: number | null; count: number; total: number }[];
  total: number;
  max: number;
}

export interface DashboardSessionRow {
  public_id: string; session_code: string; status: string; doctor_name: string | null; branch_name: string | null;
  planned_start_at: string | null; planned_end_at: string | null;
  /** Serials issued for the session; `booked` is the subset that has not arrived yet. */
  issued: number;
  booked: number; checked_in: number; in_consultation: number; completed: number; no_show: number;
  capacity: number; avg_consult_seconds: number; delay_minutes: number;
}

export interface ReportDashboard {
  date: string;
  timezone: string;
  appointments: ReportVolumeCounts;
  waits: ReportWaitStats;
  patients: PatientMixTotals;
  money: { gross_paisa: number; refunds_paisa: number; net_paisa: number; payment_count: number; invoice_count: number } | null;
  live: { waiting: number; in_consultation: number; not_arrived: number };
  sessions: DashboardSessionRow[];
}

export interface ReportExportRow {
  public_id: string; report: string; format: ReportFormat; status: 'pending' | 'processing' | 'ready' | 'failed';
  title: string; row_count: number | null; file_size: number | null; error: string | null;
  created_at: string | null; completed_at: string | null; expires_at: string | null;
  downloadable: boolean; filters: Record<string, unknown>;
}
// reports:end

// saas:start
// The SaaS control plane's wire shapes deliberately do NOT live here, and this block records why so the next
// person does not add a second copy:
//
//   resources/js/panel/Components/Super/types.ts    the super console + the two tenant-facing SaaS screens
//   resources/js/site/Components/Central/types.ts   the central host's marketing, sign-up and invoice pages
//
// This file is the shared TENANT model contract, read by both bundles. Nothing in Module M is: the console
// shapes (`TenantDetail`, `PlatformTotals`, `SuperPlan`, `CentralAuditRow`) are read only by
// `panel/Pages/Super/**`, and the central shapes (`CentralLinks`, `PricingPlan`, `DocSection`) only by
// `site/Pages/Central/**` — the two surfaces never share one. Duplicating them here would give a wire contract
// two definitions and one of them would rot, so the module files are the single source and the server-side
// shapes they mirror are `App\Domain\SaaS\Queries\{TenantOverview,PlanCatalog}` and
// `App\Domain\SaaS\Data\LimitStatus`.
// saas:end

// telemedicine:start
// telemedicine:end

// prescription:start
// Wire shapes of the Prescription module (PRESCRIPTION.md §1.2, §1.5, §2.11, §4.2, §4.13, §5.1, §6). Ids of visits /
// prescriptions on the wire are public_id ULIDs; template / snippet / favourite / vital / catalog rows use bigint ids.
export type VisitType = 'opd' | 'followup' | 'telemedicine';
export type VisitStatus = 'open' | 'closed' | 'cancelled';
export type PrescriptionStatus = 'draft' | 'issued' | 'amended' | 'voided';
export type PrescriptionLanguage = 'bn' | 'en' | 'both';
export type DoseTiming = 'before' | 'after' | 'with' | 'any';
export type ReferralType = 'doctor' | 'hospital' | 'diagnostic_centre';
export type InvestigationCategory = 'lab' | 'imaging' | 'procedure' | 'other';
export type AdviceCategory = 'diet' | 'lifestyle' | 'warning' | 'followup' | 'general' | 'finding' | 'complaint';
export type SafetySeverity = 'info' | 'warning' | 'critical';
export type DoseUnit = 'tab' | 'cap' | 'ml' | 'tsp' | 'tbsp' | 'drop' | 'puff' | 'spray' | 'sachet' | 'amp' | 'vial' | 'unit' | 'app' | 'supp' | 'neb' | 'pessary';

/** Shorthand parser output = prescription_items.dose_json (v1). PHP and TS parsers agree byte-for-byte (tests/Fixtures/shorthand_cases.json). */
export interface ParseIssue {
  code: 'unknown_token' | 'duplicate_schedule' | 'duplicate_duration' | 'duplicate_timing' | 'duplicate_route' | 'missing_schedule' | 'missing_duration'
    | 'slot_count' | 'amount_zero' | 'unit_mismatch' | 'interval_invalid' | 'route_incompatible' | 'quantity_unknown' | 'continuous_assumed'
    | 'unit_inferred' | 'max_without_sos' | 'drug_missing' | 'instruction_too_long';
  severity: 'error' | 'warning' | 'info';
  token: string | null;
  span: [number, number] | null;
  message: string;
  message_bn: string;
  suggestion: string | null;
}
export type ParsedSchedule =
  | { type: 'slots'; slots: number[] }
  | { type: 'frequency'; code: 'od' | 'bd' | 'tds' | 'qds'; per_day: 1 | 2 | 3 | 4; amount: number }
  | { type: 'interval'; every_hours: number; amount: number }
  | { type: 'stat'; amount: number }
  | { type: 'sos'; amount: number; max_per_day: number | null };
export type ParsedDuration = { type: 'days'; days: number } | { type: 'continuous'; assumed_days: number } | { type: 'till_finish' };
export interface ParsedLine {
  v: 1;
  raw: string;
  normalized: string;
  unit: DoseUnit;
  unit_inferred: boolean;
  schedule: ParsedSchedule | null;
  daily_total: number | null;
  duration: ParsedDuration | null;
  timing: DoseTiming;
  timing_code: 'af' | 'bf' | 'wf' | 'em' | 'hs' | null;
  route_code: string | null;
  quantity: { value: number | null; unit: string; source: 'auto' | 'override' | 'none'; basis: string | null };
  instruction: string | null;
  issues: ParseIssue[];
}
/** ParseContext built from the picked presentation (fixture `contexts`). per_ml = mg per ml. */
export interface ParseContext {
  form_code: string | null;
  default_unit: DoseUnit;
  pack_size: number | null;
  pack_unit: string | null;
  strength_mg: number | null;
  per_ml: number | null;
  is_liquid: boolean;
  cont_days: number;
  locale: 'bn' | 'en';
  route_code: string | null;
  strength_label: string | null;
  form_label: string | null;
}

export interface DrugRef {
  kind: 'presentation' | 'generic' | 'custom';
  generic_id: number;
  brand_id: number | null;
  custom_brand_id: number | null;
  strength_id: number | null;
  generic_name: string;
  brand_name: string | null;
  strength: string | null;
  form: string | null;
  form_code?: string | null;
  route: string | null;
  route_code?: string | null;
  pack_size?: number | null;
  pack_unit?: string | null;
  strength_mg?: number | null;
  per_ml?: number | null;
  info_slug?: string | null;
  label?: string;
}
/** What the writer POSTs for a line's drug (only the ids are read; the server resolves the rest). */
export interface DrugRefInput { generic_id?: number | null; brand_id?: number | null; custom_brand_id?: number | null; strength_id?: number | null }

export interface SafetyOverride { fingerprint: string; kind: string; severity: SafetySeverity; reason: string; overridden_by_user_id: number | null; overridden_at: string }
export interface SafetyAlert {
  key: string;                       // interaction | allergy | duplicate | pediatric | max_dose | pregnancy | renal | hepatic | custom_brand | catalog | parse
  code: string;                      // e.g. interaction.contraindicated, allergy.direct, custom_brand.unlinked, catalog.ref_missing, parse.error
  severity: SafetySeverity;
  fingerprint: string;               // stable: "{key}:{code}:{sorted generic ids}[:bucket]"
  overridable: boolean;
  title: string;
  message: string;
  message_bn: string;
  item_keys: string[];
  generic_ids: number[];
  evidence: Record<string, unknown>;
  overridden: { reason: string; by: number | null; at: string | null } | null;
}
export interface SafetyComputed { daily_mg?: number | null; per_dose_mg?: number | null; mg_per_kg_day?: number | null; adult_max_mg_day?: number | null; pediatric_max_mg_per_kg_day?: number | null }
/** POST …/check response (§5.5). */
export interface SafetyCheckResponse { alerts: SafetyAlert[]; issue_blocked_by: string[]; computed: { items: Record<string, SafetyComputed> }; catalog_version: string }

export interface ItemDisplay { dose: string; duration: string; timing: string; quantity: string; route: string; interpretation: string }
export interface PrescriptionItemRow {
  key: string;                       // server: "i{id}"; after a save the client's key is echoed
  id: number;
  sort_order: number;
  drug: DrugRef | null;
  shorthand: string;                 // dose_json.raw
  parsed: ParsedLine | null;
  snapshot: { generic_name: string; brand_name: string | null; strength: string | null; form: string | null; route: string | null };
  display: { bn: ItemDisplay; en: ItemDisplay } | null;
  quantity: number | null;
  quantity_unit: string | null;
  duration_days: number | null;
  duration_text: string | null;
  timing: DoseTiming;
  instruction: string | null;
  instruction_bn: string | null;
  is_continued: boolean;
  safety_overrides: SafetyOverride[];
}
export interface InvestigationLineRow { key: string; id: number; sort_order: number; investigation_catalog_id: number | null; name: string; name_bn: string | null; price_paisa: number | null; external_diagnostic_centre_id: number | null; referral_note: string | null; is_urgent: boolean }
export interface AdviceLineRow { key: string; id: number; sort_order: number; advice_snippet_id: number | null; text: string; text_bn: string | null }
export interface ReferralRow { key: string; id: number; type: ReferralType; referred_to_doctor_id: number | null; external_diagnostic_centre_id: number | null; referred_to_name: string; referred_to_specialty: string | null; note: string | null; is_urgent: boolean }
export interface DrawingJson {
  canvas: { w: number; h: number; template: 'blank' | 'dental_adult' | 'dental_child' | 'eye_pair' | 'skeleton_front' | 'body_front_back' | 'spine' | 'abdomen' };
  strokes: { tool: 'pen' | 'marker' | 'eraser'; color: string; width: number; points: [number, number, number][] }[];
  texts?: { x: number; y: number; text: string; size: number }[];
}
/** The draft as the server returns it (writer props `prescription`, PATCH …/draft response, apply-template response). */
export interface PrescriptionDraft {
  id: string;
  version: number;
  status: PrescriptionStatus;
  language: PrescriptionLanguage;
  root_id: string;
  supersedes_id: string | null;
  amend_reason: string | null;
  updated_at: string | null;         // the optimistic-concurrency token → expected_updated_at
  mode: 'structured' | 'handwriting';
  handwriting_image_path: string | null;
  drawing_json: DrawingJson | null;
  drawing_image_path: string | null;
  items: PrescriptionItemRow[];
  investigations: InvestigationLineRow[];
  advice: AdviceLineRow[];
  referrals: ReferralRow[];
  alerts: SafetyAlert[];
  issue_blocked_by: string[];
  template_skipped?: string[];
}
export interface DraftSaveResponse { prescription: PrescriptionDraft; alerts: SafetyAlert[]; issue_blocked_by: string[] }
/** 422 body of PATCH …/draft and POST …/issue when a server parse has `error` issues. */
export interface DraftParseErrorResponse { message: string; code: 'prescriptions.parse_error'; errors: Record<string, ParseIssue[]> }
/** 422 body of POST …/issue when a critical alert blocks. */
export interface IssueBlockedResponse { message: string; code: 'prescriptions.issue_blocked'; issue_blocked_by: string[]; alerts: SafetyAlert[] }
/** 409 body when expected_updated_at is stale: the server draft to reload. */
export interface DraftConflictResponse { message: string; code: 'prescriptions.draft_conflict'; prescription: PrescriptionDraft }

/** PATCH …/draft request (§4.13). Lists are full replacements; rows with `id` update, others insert, absent ids delete. */
export interface DraftSaveRequest {
  language?: PrescriptionLanguage;
  visit?: { chief_complaints?: Complaint[]; examination_findings?: string | null; diagnoses?: Diagnosis[]; follow_up_on?: string | null; follow_up_note?: string | null; private_notes?: string | null };
  follow_up_days?: number | null;
  create_booking?: boolean;
  vitals_reviewed?: boolean;
  items?: { key: string; id?: number | null; sort_order?: number; drug: DrugRefInput | null; shorthand: string; instruction_bn?: string | null; safety_overrides?: { fingerprint: string; reason: string }[] }[];
  investigations?: { key: string; id?: number | null; investigation_catalog_id?: number | null; name?: string; name_bn?: string | null; external_diagnostic_centre_id?: number | null; referral_note?: string | null; is_urgent?: boolean }[];
  advice?: { key: string; id?: number | null; advice_snippet_id?: number | null; text?: string; text_bn?: string | null }[];
  referrals?: { key: string; id?: number | null; type: ReferralType; referred_to_doctor_id?: number | null; external_diagnostic_centre_id?: number | null; referred_to_name?: string; referred_to_specialty?: string | null; note?: string | null; is_urgent?: boolean }[];
  expected_updated_at?: string | null;
}
export interface Complaint { key?: string; text: string; text_bn: string | null; duration: string | null; sort: number }
export interface Diagnosis { key?: string; icd10_code: string | null; title: string; kind: 'provisional' | 'final'; sort: number }

export interface VisitRow {
  id: string;
  status: VisitStatus;
  type: VisitType;
  serial_display: string | null;
  session_code: string | null;
  started_at: string | null;
  ended_at: string | null;
  appointment_id: number | null;
  chief_complaints: Complaint[];
  examination_findings: string | null;
  diagnoses: Diagnosis[];
  follow_up_on: string | null;
  follow_up_note: string | null;
  current_prescription_id: string | null;
}
export interface VitalsRow {
  id: number;
  visit_id: number;
  bp_systolic: number | null; bp_diastolic: number | null; pulse_bpm: number | null; temperature_c: number | null; temperature_f: number | null; spo2_percent: number | null;
  respiratory_rate: number | null; weight_kg: number | null; height_cm: number | null; bmi: number | null; blood_glucose_mgdl: number | null; notes: string | null;
  recorded_by: { id: number; name: string } | null;
  recorded_at: string | null;
  edited_by_doctor: boolean;
  reviewed_by_doctor_at: string | null;
}
/** POST /panel/visits/{visit}/vitals · PATCH /panel/vitals/{vital} body (`reviewed: true` = the Reviewed tick). Temperature is sent in °F; the server stores °C. */
export interface VitalsInput { bp_systolic?: number | null; bp_diastolic?: number | null; pulse_bpm?: number | null; temperature_f?: number | null; spo2_percent?: number | null; respiratory_rate?: number | null; weight_kg?: number | null; height_cm?: number | null; blood_glucose_mgdl?: number | null; notes?: string | null; reviewed?: boolean }
export interface VisitBrief { id: string; date: string; doctor: string | null; dx: string[]; rx_item_count: number; follow_up_on: string | null; prescription_id: string | null; prescription_status: PrescriptionStatus | null }
/** The writer's left-pane patient card (§8.1). */
export interface PatientClinicalCard {
  public_id: string; patient_code: string; name: string; age_text: string | null; age_years: number | null; age_months: number | null;
  sex: PatientGender | null; phone: string; mobile: string; blood_group: BloodGroup | null; dob: string | null; family_head: string | null;
  allergies: { id: number; allergen_type: AllergenType; allergen_name: string; generic_id: number | null; allergy_class_id: number | null; reaction: string | null; severity: AllergySeverity | null }[];
  conditions: { id: number; icd10_code: string | null; condition_name: string; status: ConditionStatus; onset_date: string | null }[];
  medications: { id: number; generic_id: number | null; generic_name: string; brand_name: string | null; dose_text: string | null; source: MedicationSource }[];
  flags: { pregnant: boolean; lactating: boolean; renal: boolean; hepatic: boolean };
}
export interface TopDrug { id: number; icd10_code: string | null; drug: DrugRefInput & { kind: 'presentation' | 'generic' | 'custom'; presentation_key: string }; label: string; default_dose: { dose_schedule?: string | null; duration_days?: number | null; timing?: string; instruction?: string | null; shorthand?: string }; use_count: number; is_pinned: boolean; rank: number; last_used_at: string | null }
export interface TemplateBrief { id: number; name: string; shorthand: string | null; icd10_code: string | null; diagnosis_title: string | null; item_count: number; follow_up_days: number | null; is_shared: boolean; doctor_id: number | null; use_count: number }
export interface TemplateItemRow { id: number; sort_order: number; drug: DrugRefInput & { generic_name: string; brand_name: string | null; strength: string | null; form: string | null; route: string | null }; shorthand: string; dose_json: ParsedLine | Record<string, never>; dose_schedule: string | null; duration_days: number | null; quantity: number | null; quantity_unit: string | null; timing: DoseTiming; instruction: string | null; instruction_bn: string | null; is_continued: boolean }
export interface TemplateFull extends TemplateBrief { body: { chief_complaints: Complaint[]; examination_findings: string | null; advice: { snippet_id: number | null; text: string; text_bn: string | null }[]; investigations: { investigation_catalog_id: number | null; name: string }[]; follow_up_days: number | null }; items: TemplateItemRow[] }
export interface AdviceSnippet { id: number; doctor_id: number | null; shorthand: string | null; category: AdviceCategory | null; text: string; text_bn: string | null; is_shared: boolean; use_count: number; is_active: boolean }
export interface InvestigationCatalogRow { id: number; branch_id: number | null; code: string | null; name: string; name_bn: string | null; category: InvestigationCategory; price_paisa: number; prep_instructions: string | null; prep_instructions_bn: string | null; is_active: boolean; sort_order: number }
export interface ExternalCentreBrief { id: number; name: string; address: string | null; phone: string | null }
export interface PadSettingsBrief { paper_size: 'A4' | 'A5'; letterhead_enabled: boolean; preprinted_mode: boolean; default_language: PrescriptionLanguage; show_qr: boolean; show_vitals: boolean; layout: Record<string, unknown> }

/** GET /panel/visits/{visit}/prescribe — Inertia `Prescription/Writer` props (one request, §1.2). */
export interface WriterPageProps {
  visit: VisitRow;
  patient: PatientClinicalCard;
  vitals: VitalsRow | null;
  recent_visits: VisitBrief[];
  prescription: PrescriptionDraft;
  doctor: { id: number; public_id: string; name: string; pad: PadSettingsBrief; prefs: { default_duration_days: number; cont_days: number; dictation_lang: string; cheatsheet_seen_count: number } };
  quick_pick: { top_drugs: TopDrug[]; templates: TemplateBrief[]; snippets: AdviceSnippet[]; investigations: InvestigationCatalogRow[]; external_centres: ExternalCentreBrief[] };
  features: { ai: boolean; voice: boolean; handwriting: boolean; drawing_backgrounds: string[] };
  cheat_sheet_version: string;
}

export interface PrescriptionBrief { id: string; version: number; status: PrescriptionStatus; language: PrescriptionLanguage; verification_code: string | null; issued_at: string | null; amend_reason: string | null; root_id: string; supersedes_id: string | null; visit_id: string | null }
/** GET /panel/prescriptions/{prescription} for an issued / amended / voided row: the frozen snapshot is the only render source. */
export interface IssuedPrescription extends PrescriptionBrief {
  snapshot: PrescriptionSnapshot;
  snapshot_sha256: string | null;
  pad_snapshot: Record<string, unknown> | null;
  is_latest: boolean;
  superseded_by: { id: string; version: number; issued_at: string | null } | null;
  versions: PrescriptionBrief[];
  pdf_status: 'ready' | 'pending';
  printed_count: number;
  last_printed_at: string | null;
  delivered_channels: string[];
  voided: { at: string; reason: string | null } | null;
}
/** One row of GET /panel/prescriptions (PrescriptionRowResource): a finder row — labels and status, never the snapshot. */
export interface PrescriptionIndexRow {
  id: string;
  version: number;
  status: PrescriptionStatus;
  language: PrescriptionLanguage;
  issued_at: string | null;
  created_at: string | null;
  verification_code: string | null;
  pdf_status: 'ready' | 'pending';
  items_count: number;
  diagnosis: string | null;
  visit_id: string;
  patient: { public_id: string; patient_code: string; name: string; mobile_local: string; age_text: string | null; gender: string | null } | null;
  doctor: { public_id: string; name: string; name_bn: string | null } | null;
}
/** prescriptions.snapshot (§6.2) — base keys of SCHEMA §5.3.2 plus the additive ones. */
export interface PrescriptionSnapshot {
  schema: 1;
  prescription: { public_id: string; version: number; verification_code: string; issued_at: string; language: PrescriptionLanguage; verify_url: string; id: number; root_id: number; root_public_id: string; supersedes_id: number | null; supersedes_public_id: string | null; status_at_issue: string; mode: 'structured' | 'handwriting'; catalog_version: string; tenant_id: number; visit_id: number; amend_reason: string | null };
  clinic: { name: string; name_bn: string | null; branch: { name: string; address: string | null; phone: string | null }; logo_data_uri: string | null };
  doctor: { name: string; name_bn: string | null; degrees: string | null; degrees_bn: string | null; bmdc_reg_no: string | null; designation: string | null; specialties: string[]; signature_path: string | null; signature_data_uri: string | null; id: number; public_id: string };
  patient: { public_id: string; patient_code: string; name: string; age_text: string | null; gender: string | null; mobile_masked: string; weight_kg: number | null; age_months: number | null; id: number; dob: string | null };
  visit: { public_id: string; date: string; serial: string | null; type: VisitType; chief_complaints: (Complaint & { duration_label: { bn: string; en: string } })[]; examination_findings: string | null; diagnoses: Diagnosis[]; vitals: Record<string, unknown> | null };
  items: { sort: number; generic_name: string; brand_name: string | null; strength: string | null; form: string | null; route: string | null; dose_schedule: string | null; dose_json: ParsedLine | Record<string, never>; duration_text: string | null; quantity: number | null; quantity_unit: string | null; timing: DoseTiming; instruction: string | null; instruction_bn: string | null; info_url: string | null; generic_id: number | null; brand_id: number | null; strength_id: number | null; custom_brand_id: number | null; is_continued: boolean; display: { bn: ItemDisplay | null; en: ItemDisplay | null } }[];
  investigations: { name: string; name_bn: string | null; price_paisa: number | null; external_centre: string | null; referral_note: string | null; is_urgent: boolean }[];
  investigations_total_paisa: number;
  advice: { text: string; text_bn: string | null }[];
  referrals: { type: ReferralType; to: string; specialty: string | null; note: string | null; is_urgent: boolean }[];
  follow_up: { on: string | null; note: string | null; days: number | null; label: { bn: string; en: string } };
  handwriting_image_path: string | null;
  handwriting_pages: string[];
  drawing_image_path: string | null;
  drawing_json: DrawingJson | null;
  pad: Record<string, unknown> & { header_html_inlined: string | null };
  allergies: string[];
  safety: { alerts: SafetyAlert[]; overrides: SafetyOverride[] };
  qr: { url: string; svg_data_uri: string };
  rendered_by: { app_version: string; template_version: string; keywords_version: string };
}
/** POST …/issue 200 body (§6.1). */
export interface IssueResult { prescription: PrescriptionBrief; print_url: string | null; pdf_status: 'pending' | 'ready'; follow_up_draft_appointment_id: string | null }
/** GET /rx/{code} JSON (§7.4). */
export interface VerificationDocument { status: 'valid' | 'superseded' | 'voided'; banner: { key: 'valid' } | { key: 'superseded'; by_version: number | null; by_date: string | null; by_code: string | null } | { key: 'voided'; voided_at: string | null }; version: number; verification_code: string | null; issued_at: string | null; snapshot_sha256: string | null; snapshot: PrescriptionSnapshot; pdf_available: boolean; purpose: 'verify'; watermark: 'COPY' | 'VOID' }
/** GET /panel/search/drugs hits (§3.3). */
export interface DrugSearchHit extends DrugRef { id: string; source: 'master' | 'custom'; doc_type: 'presentation' | 'generic'; manufacturer: string | null; strength_label: string | null; usage: number; fav_for_dx: boolean; score: number; last_shorthand: string | null; review_status?: string | null }
export interface AiSummaryResponse { available: boolean; suggestion_id?: number; type?: 'history_summary'; lines?: string[]; model?: string; generated_at?: string | null }
export interface AiDifferentialsResponse { available: boolean; suggestion_id?: number; type?: 'differential'; items?: { label: string; icd10_code: string | null; rationale: string }[]; model?: string; generated_at?: string | null }
// prescription:end

// telemedicine:start
/** The call document (`App\Domain\Telemedicine\Services\RoomStateBuilder`): the Inertia prop AND the state poll body. */
export interface TelemedicineRoomState {
  room: string;
  status: 'scheduled' | 'open' | 'ended' | 'cancelled';
  provider: 'agora' | 'livekit' | 'jitsi';
  scheduled_at: string;
  opened_at: string | null;
  ended_at: string | null;
  max_minutes: number;
  recording: { allowed: boolean; active: boolean };
  can_join: boolean;
  presence: { doctor: boolean; patient: boolean };
  call: { started_at: string; ended_at: string | null; duration_seconds: number; end_reason: string | null } | null;
  serial: { public_id: string; code: string; status: string; is_being_seen: boolean } | null;
  doctor: { public_id: string; slug: string; name: string; name_bn: string | null };
  /** How the waiting room reaches the Queue module's existing live state — no second realtime system. */
  queue: { tenant_public_id: string | null; session_public_id: string; doctor_slug: string } | null;
  viewer: 'doctor' | 'patient' | null;
  server_time: string;
}
/** One row of the doctor's telemedicine board. */
export interface TelemedicineRoomSummary {
  room: string;
  status: TelemedicineRoomState['status'];
  scheduled_at: string;
  opened_at: string | null;
  patient: { public_id: string; name: string; code: string };
  doctor: { public_id: string; name: string };
  serial: { code: string; status: string } | null;
  fee_paisa: number;
  payment_status: string | null;
}
/** A doctor bookable over video (site.telemedicine.book). */
export interface TelemedicineDoctor {
  public_id: string;
  slug: string;
  name: string;
  name_bn: string | null;
  degrees: string | null;
  designation: string | null;
  fee_paisa: number;
  weekdays: number[];
}
// telemedicine:end
