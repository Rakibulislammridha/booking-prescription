// One chip for a catalogue job's state, colour AND text (StatusChip's rule). A running job shows its step and
// percent so an operator watching an import knows it is moving, not hung.
import Chip from '@mui/material/Chip';
import type { ChipProps } from '@mui/material/Chip';
import { useTranslation } from 'react-i18next';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { CatalogJobRow } from '@panel/Components/Super/types';

const TONE: Record<CatalogJobRow['status'], ChipProps['color']> = {
  uploaded: 'default',
  queued: 'info',
  running: 'warning',
  succeeded: 'success',
  failed: 'error',
};

export function JobStatusChip({ job, size = 'small' }: { job: Pick<CatalogJobRow, 'status' | 'progress'>; size?: ChipProps['size'] }) {
  const { t } = useTranslation();
  const locale = getLocale();
  let label = t(`super.catalog.jobs.status.${job.status}`);

  if (job.status === 'running') {
    const step = job.progress.step ? t(`super.catalog.imports.steps.${job.progress.step}`, { defaultValue: job.progress.step }) : '';
    const percent = job.progress.percent === undefined ? '' : ` · ${formatNumber(job.progress.percent, locale)}%`;
    label = `${label}${step ? ` · ${step}` : ''}${percent}`;
  }

  return <Chip size={size} color={TONE[job.status]} variant={job.status === 'running' ? 'filled' : 'outlined'} label={label} />;
}
