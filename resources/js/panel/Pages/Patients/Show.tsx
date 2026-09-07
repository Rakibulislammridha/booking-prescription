// Patients/Show: header, household, and the mini-EMR tabs — timeline, allergies, conditions, medications,
// documents (upload), consents. The server records the audit `view` before rendering (ARCHITECTURE §8.1).
import { useState, type ReactNode, type SyntheticEvent } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Tabs from '@mui/material/Tabs';
import Tab from '@mui/material/Tab';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { PatientHeader } from '@panel/Components/Patients/PatientHeader';
import { FamilyPanel } from '@panel/Components/Patients/FamilyPanel';
import { TimelineTab } from '@panel/Components/Patients/TimelineTab';
import { AllergiesTab } from '@panel/Components/Patients/AllergiesTab';
import { ConditionsTab } from '@panel/Components/Patients/ConditionsTab';
import { MedicationsTab } from '@panel/Components/Patients/MedicationsTab';
import { DocumentsTab } from '@panel/Components/Patients/DocumentsTab';
import { ConsentsTab } from '@panel/Components/Patients/ConsentsTab';
import { PatientDuesPanel } from '@panel/Components/Billing/PatientDuesPanel';
import { useSharedProps } from '@shared/inertia';
import { formatBn } from '@shared/format/number';
import { formatDateDhaka } from '@shared/format/date';
import type { PageProps } from '@shared/types/inertia';
import type { FamilyMember, PatientRecord, TimelinePage, VitalsTrendPoint } from '@shared/types/models';
import type { Locale } from '@shared/types/shared-props';

type Props = PageProps<{
  patient: PatientRecord;
  family: FamilyMember[];
  timeline: TimelinePage;
  timeline_kinds: string[];
  vitals_trend: VitalsTrendPoint[];
  vitals_available: boolean;
  can: { update: boolean; manage_clinical: boolean; upload_document: boolean; record_consent: boolean; merge: boolean; create: boolean };
  policy_version: string;
}>;

const TABS = ['timeline', 'allergies', 'conditions', 'medications', 'documents', 'consents'] as const;
type TabKey = (typeof TABS)[number];

export default function Show({ patient, family, timeline, timeline_kinds, vitals_trend, vitals_available, can, policy_version }: Props) {
  const { t, i18n } = useTranslation();
  const shared = useSharedProps();
  const locale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const [tab, setTab] = useState<TabKey>('timeline');
  // Billing's panel, mounted on the record it describes. It renders nothing when the patient owes nothing, so
  // the card only appears when there is something to act on.
  const canSeeDues = shared.auth.user?.permissions.includes('billing.invoices.view') ?? false;
  const id = patient.public_id;
  const activeAllergies = (patient.allergies ?? []).filter((a) => a.is_active).length;
  const currentConditions = (patient.conditions ?? []).filter((c) => c.status !== 'resolved').length;
  const activeMedications = (patient.medications ?? []).filter((m) => m.is_active).length;

  const counts: Record<TabKey, number | null> = {
    timeline: null,
    allergies: activeAllergies,
    conditions: currentConditions,
    medications: activeMedications,
    documents: (patient.documents ?? []).length,
    consents: (patient.consents ?? []).length,
  };

  return (
    <Stack spacing={2}>
      <PatientHeader patient={patient} canUpdate={can.update} canMerge={can.merge} />

      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', lg: '2fr 1fr' }, alignItems: 'start' }}>
        <Card>
          <Tabs value={tab} onChange={(_e: SyntheticEvent, v: TabKey) => setTab(v)} variant="scrollable" allowScrollButtonsMobile sx={{ borderBottom: 1, borderColor: 'divider' }}>
            {TABS.map((key) => (
              <Tab key={key} value={key} label={counts[key] === null ? t(`patients.show.tabs.${key}`) : `${t(`patients.show.tabs.${key}`)} (${formatBn(counts[key] ?? 0, locale)})`} />
            ))}
          </Tabs>
          <CardContent>
            {tab === 'timeline' ? <TimelineTab patient={id} initial={timeline} kinds={timeline_kinds} /> : null}
            {tab === 'allergies' ? <AllergiesTab patient={id} allergies={patient.allergies ?? []} canManage={can.manage_clinical} /> : null}
            {tab === 'conditions' ? <ConditionsTab patient={id} conditions={patient.conditions ?? []} canManage={can.manage_clinical} /> : null}
            {tab === 'medications' ? <MedicationsTab patient={id} medications={patient.medications ?? []} canManage={can.manage_clinical} /> : null}
            {tab === 'documents' ? <DocumentsTab patient={id} documents={patient.documents ?? []} canUpload={can.upload_document} /> : null}
            {tab === 'consents' ? <ConsentsTab patient={id} consents={patient.consents ?? []} canRecord={can.record_consent} policyVersion={policy_version} /> : null}
          </CardContent>
        </Card>

        <Stack spacing={2}>
          {canSeeDues ? <PatientDuesPanel patient={patient.public_id} /> : null}
          <FamilyPanel patient={patient} family={family} canCreate={can.create} />
          <Card>
            <CardContent>
              <Typography variant="subtitle1" gutterBottom sx={{ fontWeight: 600 }}>{t('patients.show.vitals_trend')}</Typography>
              {!vitals_available || vitals_trend.length === 0 ? (
                <Typography variant="body2" color="text.secondary">{t('patients.show.vitals_unavailable')}</Typography>
              ) : (
                <Stack spacing={0.5}>
                  {vitals_trend.slice(-6).map((v) => (
                    <Typography key={v.id} variant="body2">
                      {formatDateDhaka(v.recorded_at, locale)} · {v.bp_systolic !== null && v.bp_diastolic !== null ? `BP ${formatBn(`${v.bp_systolic}/${v.bp_diastolic}`, locale)} ` : ''}
                      {v.weight_kg !== null ? `${formatBn(v.weight_kg, locale)} kg ` : ''}
                      {v.spo2_percent !== null ? `SpO₂ ${formatBn(v.spo2_percent, locale)}%` : ''}
                    </Typography>
                  ))}
                </Stack>
              )}
            </CardContent>
          </Card>
        </Stack>
      </Box>
    </Stack>
  );
}

Show.layout = (page: ReactNode) => <PanelLayout title="patients.index.title">{page}</PanelLayout>;
