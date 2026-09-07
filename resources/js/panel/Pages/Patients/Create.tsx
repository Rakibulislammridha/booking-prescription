// Patients/Create: registration form (POST panel.patients.store). `?primary=<public_id>` pre-links the new
// person into an existing household (same mobile, relation to the owner).
import type { ReactNode } from 'react';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { PatientForm, type BranchOption } from '@panel/Components/Patients/PatientForm';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { PatientSummary } from '@shared/types/models';

type Props = PageProps<{ branches: BranchOption[]; primary: PatientSummary | null }>;

export default function Create({ branches, primary }: Props) {

  return (
    <Card sx={{ maxWidth: 960 }}>
      <CardContent>
        <PatientForm mode="create" url={route('panel.patients.store')} branches={branches} primary={primary} onCancel={() => window.history.back()} />
      </CardContent>
    </Card>
  );
}

Create.layout = (page: ReactNode) => <PanelLayout title="patients.create.title">{page}</PanelLayout>;
