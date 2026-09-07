// The patient's outstanding balance, as a drop-in panel for the desk board and the patient record — both owned
// by other modules, so this reads Billing's own endpoint instead of re-deriving the number from appointments.
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Chip from '@mui/material/Chip';
import LinearProgress from '@mui/material/LinearProgress';
import Link from '@mui/material/Link';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { patientDues, type PatientDues } from '@panel/api/billing';
import { isApiError } from '@shared/http';
import { route } from '@shared/routes';
import { formatBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';

export interface PatientDuesPanelProps {
  /** Patient public id; null renders nothing (no patient selected yet). */
  patient: string | null;
}

export function PatientDuesPanel({ patient }: PatientDuesPanelProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [dues, setDues] = useState<PatientDues | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (patient === null) {
      setDues(null);

      return;
    }

    const controller = new AbortController();
    setBusy(true);
    setError(null);

    patientDues(patient, controller.signal)
      .then(setDues)
      .catch((e: unknown) => {
        if (controller.signal.aborted) return;
        setError(isApiError(e) ? e.message : e instanceof Error ? e.message : String(e));
      })
      .finally(() => { if (!controller.signal.aborted) setBusy(false); });

    return () => controller.abort();
  }, [patient]);

  if (patient === null) return null;
  if (busy && dues === null) return <LinearProgress />;
  if (error !== null) return <Alert severity="error">{error}</Alert>;
  if (dues === null || dues.due_paisa === 0) return null;

  return (
    <Stack spacing={1}>
      <Chip color="warning" label={t('billing.index.outstanding', { amount: formatBdt(dues.due_paisa, locale) })} />
      {dues.invoices.map((invoice) => (
        <Typography key={invoice.public_id} variant="body2">
          <Link component={RouterLink} href={route('panel.billing.invoices.show', { invoice: invoice.public_id })}>{invoice.number}</Link>
          {' · '}
          {formatBdt(invoice.due_paisa, locale)}
        </Typography>
      ))}
    </Stack>
  );
}
