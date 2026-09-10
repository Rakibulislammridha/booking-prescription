// Wire shapes of the console's tenant management screens (Super\TenantController, Super\Tenants\StaffController,
// App\Domain\SaaS\Queries\{TenantOverview,TenantStaffDirectory}). They extend the console-wide shapes in
// `../types.ts` rather than editing them: snake_case, ISO-8601 UTC timestamps, paisa integers.
import type { DomainRow, TenantDetail, TenantRow } from '@panel/Components/Super/types';

/** TenantStaffDirectory::row() — a clinic user as the console reads it (never the password hash, never patients). */
export interface StaffRow {
  public_id: string;
  name: string;
  email: string;
  mobile: string | null;
  role: string | null;
  roles: string[];
  is_active: boolean;
  must_change_password: boolean;
  branch_name: string | null;
  last_login_at: string | null;
  last_login_ip: string | null;
  created_at: string | null;
}

export type CredentialKind = 'password' | 'link';

/** App\Domain\SaaS\Data\CredentialReveal::toArray() — pulled from the session once, shown once. */
export interface RevealPayload {
  kind: CredentialKind;
  value: string;
  user_name: string;
  email: string;
  expires_at: string | null;
}

export interface DeletionExport {
  id: number;
  completed_at: string | null;
  size_bytes: number | null;
}

/** The state of the two deletion guards, as TenantController::show() sends it. */
export interface DeletionState {
  unsettled_invoices: number;
  export_max_age_hours: number;
  export: DeletionExport | null;
}

export interface TenantBranding {
  name_bn: string | null;
  primary_color: string | null;
  accent_color: string | null;
  logo_path: string | null;
  logo_url: string | null;
}

/** TenantOverview::detail() with the fields the create/edit screens added. */
export interface ConsoleTenantDetail extends TenantDetail {
  platform_notes: string | null;
  deleted_at: string | null;
  branding: TenantBranding;
}

export type SslStatusValue = 'none' | 'pending' | 'issued' | 'failed';

export interface ConsoleDomainRow extends DomainRow {
  ssl_status: SslStatusValue;
  ssl_expires_at: string | null;
}

/** A list row: `deleted_at` and `last_export_id` are only ever non-null under the `deleted` filter. */
export interface TenantListRow extends TenantRow {
  deleted_at: string | null;
  last_export_id: number | null;
}

export interface TenantListFilters {
  q: string;
  status: string;
  plan: string;
  attention: string;
  sort: string;
  dir: string;
}

export interface PlanOption {
  code: string;
  name: string;
}

export type SlugCheckReason = 'invalid' | 'reserved' | 'taken';

/** Super\Tenants\SlugCheckController. */
export interface SlugCheck {
  slug: string;
  host: string | null;
  available: boolean;
  reason: SlugCheckReason | null;
}

export type SlugAvailability = 'idle' | 'checking' | 'available' | SlugCheckReason;
