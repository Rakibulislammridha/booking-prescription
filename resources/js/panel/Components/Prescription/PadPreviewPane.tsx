// The live pad preview (PRESCRIPTION.md §1.1 "[Preview]", §1.3 Ctrl+P, §7.1): the doctor's own prescription pad,
// filling up as he writes, next to the writer.
//
// It does NOT re-implement the sheet in React. It loads `panel.prescription.print` for the draft — the very route
// "Issue & Print" opens — which renders the transient DRAFT snapshot through PrescriptionRenderer and the Blade
// tree under resources/views/print/prescription/**. One renderer, one template, one set of pad geometry: an edit
// to a partial changes the preview and the printout in the same commit, because they are the same response.
//
// Three things make that safe to embed:
//   · the frame is sandboxed WITHOUT allow-scripts, so the sheet's own one-click `window.print()` (§7.6) cannot
//     fire inside the writer; `allow-same-origin` is kept so the linked woff2 faces still load and Bangla is laid
//     out with the same metrics it will print with;
//   · it is fetched on a trailing debounce and cached by the draft's saved version, so a burst of typing is one
//     request — and a version already on screen is never re-fetched;
//   · the sheet is rendered at its true paper width and scaled down, so what is on screen is the page, not a
//     reflowed approximation of it.
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import LinearProgress from '@mui/material/LinearProgress';
import Paper from '@mui/material/Paper';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import CloseIcon from '@mui/icons-material/Close';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import RefreshIcon from '@mui/icons-material/Refresh';
import { fetchPrintHtml, printUrl } from '@panel/api/prescription';

/** Trailing debounce after the draft's saved version changes. The autosave is 400 ms; this rides behind it. */
export const PREVIEW_DEBOUNCE_MS = 350;

/** CSS pixels of the paper at 96 dpi — the width the sheet is rendered at before it is scaled into the pane. */
const PAPER_PX: Record<string, { width: number; height: number }> = {
  A4: { width: 794, height: 1123 },
  A5: { width: 559, height: 794 },
};

export interface PadPreviewPaneProps {
  prescriptionId: string;
  /** Changes whenever the SAVED draft changes — the only thing that can change the sheet. */
  version: string;
  paper: string;
  /** The writer has an edit that has not reached the server yet: the sheet on screen is one save behind. */
  pending: boolean;
  onClose: () => void;
}

export function PadPreviewPane({ prescriptionId, version, paper, pending, onClose }: PadPreviewPaneProps) {
  const { t } = useTranslation();
  const page = PAPER_PX[paper] ?? { width: 794, height: 1123 };

  const [html, setHtml] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const [scale, setScale] = useState(1);
  const [height, setHeight] = useState(page.height);
  const [nonce, setNonce] = useState(0);
  const loaded = useRef<string | null>(null);
  const box = useRef<HTMLDivElement | null>(null);

  const load = useCallback(
    (signal: AbortSignal) => {
      setLoading(true);
      fetchPrintHtml(prescriptionId, signal)
        .then((sheet) => {
          if (signal.aborted) return;
          loaded.current = version;
          setHtml(sheet);
          setFailed(false);
        })
        .catch(() => {
          if (!signal.aborted) setFailed(true);
        })
        .finally(() => {
          if (!signal.aborted) setLoading(false);
        });
    },
    [prescriptionId, version],
  );

  // One fetch per saved version: a version already on screen is not re-fetched, and a burst of typing collapses
  // into the single request that follows the last autosave.
  useEffect(() => {
    if (loaded.current === version && nonce === 0) return;
    const controller = new AbortController();
    const timer = window.setTimeout(() => load(controller.signal), PREVIEW_DEBOUNCE_MS);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [version, nonce, load]);

  // The sheet is laid out at paper width and scaled to the pane, so the preview is the page — same line breaks,
  // same margins, same font sizes in proportion — rather than a narrow reflow of it.
  useLayoutEffect(() => {
    const element = box.current;
    if (element === null) return;
    const measure = (): void => setScale(Math.min(1, Math.max(0.2, (element.clientWidth - 8) / page.width)));
    measure();
    if (typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(measure);
    observer.observe(element);

    return () => observer.disconnect();
  }, [page.width]);

  const onFrameLoad = (event: React.SyntheticEvent<HTMLIFrameElement>): void => {
    try {
      const doc = event.currentTarget.contentDocument;
      const measured = doc?.documentElement.scrollHeight ?? 0;
      if (measured > 0) setHeight(Math.max(page.height, measured));
    } catch {
      /* a frame we cannot measure keeps the paper height */
    }
  };

  return (
    <Paper variant="outlined" sx={{ height: '100%', display: 'flex', flexDirection: 'column', minHeight: 0 }} data-testid="pad-preview">
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, px: 1, py: 0.25, borderBottom: 1, borderColor: 'divider' }}>
        <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
          {t('prescriptions.preview.title')}
        </Typography>
        <Chip size="small" variant="outlined" sx={{ height: 18, fontSize: 10 }} label={paper} />
        {pending || loading ? (
          <Typography variant="caption" color="text.secondary" data-testid="pad-preview-pending">
            {t('prescriptions.preview.updating')}
          </Typography>
        ) : null}
        <Box sx={{ flexGrow: 1 }} />
        <Tooltip title={t('prescriptions.preview.refresh')}>
          <IconButton tabIndex={-1} size="small" onClick={() => setNonce((n) => n + 1)} aria-label={t('prescriptions.preview.refresh')}>
            <RefreshIcon fontSize="inherit" />
          </IconButton>
        </Tooltip>
        <Tooltip title={t('prescriptions.preview.open')}>
          <IconButton tabIndex={-1} size="small" onClick={() => window.open(printUrl(prescriptionId), '_blank')} aria-label={t('prescriptions.preview.open')}>
            <OpenInNewIcon fontSize="inherit" />
          </IconButton>
        </Tooltip>
        <Tooltip title={t('prescriptions.preview.hide')}>
          <IconButton tabIndex={-1} size="small" onClick={onClose} aria-label={t('prescriptions.preview.hide')}>
            <CloseIcon fontSize="inherit" />
          </IconButton>
        </Tooltip>
      </Box>

      <Box sx={{ height: 2 }}>{loading ? <LinearProgress sx={{ height: 2 }} /> : null}</Box>

      <Box ref={box} sx={{ flexGrow: 1, minHeight: 0, overflow: 'auto', bgcolor: '#eef1f5', p: 0.5 }}>
        {failed ? (
          <Box sx={{ p: 1 }}>
            <Typography variant="caption" color="error" sx={{ display: 'block', mb: 0.5 }}>
              {t('prescriptions.preview.failed')}
            </Typography>
            <Button size="small" onClick={() => setNonce((n) => n + 1)}>
              {t('common.actions.retry')}
            </Button>
          </Box>
        ) : null}

        {html === null ? (
          failed ? null : (
            <Typography variant="caption" color="text.secondary" sx={{ p: 1, display: 'block' }}>
              {t('prescriptions.preview.empty')}
            </Typography>
          )
        ) : (
          <Box sx={{ width: page.width * scale, height: height * scale, mx: 'auto', position: 'relative', overflow: 'hidden' }}>
            <iframe
              title={t('prescriptions.preview.frame')}
              data-testid="pad-preview-frame"
              // No allow-scripts: the sheet carries the print route's own window.print() call (§7.6) and it must
              // never run in the writer. allow-same-origin keeps the linked font files loadable.
              sandbox="allow-same-origin"
              srcDoc={html}
              onLoad={onFrameLoad}
              style={{ width: page.width, height, border: 0, background: '#fff', transform: `scale(${scale})`, transformOrigin: 'top left', position: 'absolute', top: 0, left: 0 }}
            />
          </Box>
        )}
      </Box>
    </Paper>
  );
}

export default PadPreviewPane;
