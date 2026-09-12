// The post-issue bar (BRIEF §5.G: issue → next patient, never stranded): Print, PDF, and — when the visit belongs
// to one of today's open sessions the user may drive — Call next patient and Back to today's session. "Call next"
// is `panel.queue.call-next-visit`: call-next plus the called serial's visit in one request; someone called →
// straight into their writer (or the session page for an operator who cannot write); no one waiting → the session
// page, which says so; a serial still in consultation → the engine's refusal shown here, never swallowed.
import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import CircularProgress from '@mui/material/CircularProgress';
import Stack from '@mui/material/Stack';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import NextIcon from '@mui/icons-material/SkipNext';
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdf';
import PrintIcon from '@mui/icons-material/Print';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { callNextVisit } from '@panel/api/queue';
import { isApiError } from '@shared/apiError';
import type { PrescriptionQueueLink } from '@shared/types/models';

export interface PostIssueBarProps {
  queue: PrescriptionQueueLink | null;
  onPrint: () => void;
  onPdf: () => void;
}

export function PostIssueBar({ queue, onPrint, onPdf }: PostIssueBarProps) {
  const { t } = useTranslation();
  const [phase, setPhase] = useState<'idle' | 'calling' | 'leaving'>('idle');
  const [error, setError] = useState<string | null>(null);

  const callNext = (): void => {
    if (queue === null || !queue.can_call_next || phase !== 'idle') return;
    setPhase('calling');
    setError(null);
    void callNextVisit(queue.session_id)
      .then((result) => {
        setPhase('leaving');
        if (result.called === null) router.visit(queue.session_url, { data: { notice: 'no_one_waiting' } });
        else router.visit(result.writer_url ?? queue.session_url);
      })
      .catch((e: unknown) => {
        setPhase('idle');
        setError(isApiError(e) && e.code === 'queue.chamber_occupied' ? t('prescriptions.show.chamber_occupied') : e instanceof Error ? e.message : String(e));
      });
  };

  const busy = phase !== 'idle';

  return (
    <Stack spacing={1} data-testid="post-issue-bar">
      <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', alignItems: 'center', gap: 1 }}>
        <Button size="small" variant="contained" startIcon={<PrintIcon />} onClick={onPrint} data-testid="post-issue-print">
          {t('common.actions.print')}
        </Button>
        <Button size="small" startIcon={<PictureAsPdfIcon />} onClick={onPdf} data-testid="post-issue-pdf">
          {t('prescriptions.show.download_pdf')}
        </Button>
        {queue !== null && queue.can_call_next ? (
          <Button
            size="small"
            variant="contained"
            color="success"
            startIcon={phase === 'idle' ? <NextIcon /> : <CircularProgress size={14} color="inherit" />}
            disabled={busy}
            onClick={callNext}
            data-testid="post-issue-call-next"
          >
            {phase === 'idle' ? t('prescriptions.show.call_next') : t('prescriptions.show.calling_next')}
          </Button>
        ) : null}
        {queue !== null ? (
          <Button size="small" startIcon={<ArrowBackIcon />} component={RouterLink} href={queue.session_url} disabled={busy} data-testid="post-issue-back">
            {t('prescriptions.show.back_to_session')}
          </Button>
        ) : null}
      </Stack>
      {error !== null ? (
        <Alert severity="warning" onClose={() => setError(null)} data-testid="post-issue-error">{error}</Alert>
      ) : null}
    </Stack>
  );
}

export default PostIssueBar;
