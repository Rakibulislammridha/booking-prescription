// Enum option lists for the Patients forms (values mirror App\Domain\Patients\Enums / SCHEMA Appendix A) and the
// i18n key builders the components share. Bangla digits come from formatBn at display time.
import type { TFunction } from 'i18next';
import { formatBn } from '@shared/format/number';
import type { Locale } from '@shared/types/shared-props';
import type {
  AllergenType,
  AllergySeverity,
  BloodGroup,
  ConditionStatus,
  ConsentChannel,
  ConsentStatus,
  ConsentType,
  MedicationSource,
  PatientDocumentType,
  PatientGender,
  PatientRelationType,
  PatientSummary,
} from '@shared/types/models';

export const GENDERS: readonly PatientGender[] = ['male', 'female', 'other'];
export const BLOOD_GROUPS: readonly BloodGroup[] = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
export const RELATIONS: readonly PatientRelationType[] = ['spouse', 'child', 'parent', 'sibling', 'guardian_of', 'other'];
export const ALLERGEN_TYPES: readonly AllergenType[] = ['generic', 'allergy_class', 'food', 'environmental', 'other'];
export const SEVERITIES: readonly AllergySeverity[] = ['mild', 'moderate', 'severe', 'unknown'];
export const CONDITION_STATUSES: readonly ConditionStatus[] = ['active', 'chronic', 'resolved'];
export const MEDICATION_SOURCES: readonly MedicationSource[] = ['reported', 'prescription'];
export const DOCUMENT_TYPES: readonly PatientDocumentType[] = ['lab_report', 'imaging', 'external_prescription', 'discharge_summary', 'identity', 'other'];
export const CONSENT_TYPES: readonly ConsentType[] = ['data_processing', 'data_sharing', 'sms', 'whatsapp', 'telemedicine', 'research'];
export const CONSENT_STATUSES: readonly ConsentStatus[] = ['granted', 'revoked'];
export const CONSENT_CHANNELS: readonly ConsentChannel[] = ['counter', 'online', 'kiosk', 'app', 'phone'];

/** "34y · Male" / "৩৪ব · পুরুষ" — age with the estimated marker, then sex. */
export function ageSexLabel(t: TFunction, patient: Pick<PatientSummary, 'age_text' | 'dob_is_estimated' | 'gender'>, locale: Locale): string {
  const parts: string[] = [];
  if (patient.age_text) parts.push(formatBn(patient.age_text, locale) + (patient.dob_is_estimated ? ` (${t('patients.show.estimated')})` : ''));
  if (patient.gender) parts.push(t(`patients.gender.${patient.gender}`));
  return parts.join(' · ');
}

export function fileSize(bytes: number, locale: Locale): string {
  const kb = bytes / 1024;
  return formatBn(kb >= 1024 ? `${(kb / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(kb))} KB`, locale);
}
