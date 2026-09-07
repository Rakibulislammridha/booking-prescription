// Patients/Edit: demographics form (PUT panel.patients.update). Viewing the edit form is an audited read.
import type { ReactNode } from 'react';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { PatientForm, type BranchOption } from '@panel/Components/Patients/PatientForm';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { PatientRecord } from '@shared/types/models';

type Props = PageProps<{ patient: PatientRecord; branches: BranchOption[] }>;

export default function Edit({ patient, branches }: Props) {
  return (
    <Card sx={{ maxWidth: 960 }}>
      <CardContent>
        <Typography variant="h6" component="h2" gutterBottom lang="bn">
          {patient.name} · <Typography component="span" variant="body2" color="text.secondary" sx={{ fontFamily: 'monospace' }}>{patient.patient_code}</Typography>
        </Typography>
        <PatientForm mode="edit" url={route('panel.patients.update', { patient: patient.public_id })} patient={patient} branches={branches} onCancel={() => window.history.back()} />
      </CardContent>
    </Card>
  );
}

Edit.layout = (page: ReactNode) => <PanelLayout title="patients.edit.title">{page}</PanelLayout>;
