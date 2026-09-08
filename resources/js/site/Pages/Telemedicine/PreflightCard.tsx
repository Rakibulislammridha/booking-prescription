// The device check a patient does BEFORE they are in front of their doctor. Every outcome is spelled out,
// because "camera error" thirty seconds before a consultation is not something a patient can act on.
import { useTranslation } from 'react-i18next';
import type { PreflightReport } from './core/preflight';

export interface PreflightCardProps {
  report: PreflightReport;
  busy: boolean;
  onRun(): void;
  onRunAudioOnly(): void;
}

const TONE: Record<string, string> = {
  ready: 'border-emerald-300 bg-emerald-50 text-emerald-900',
  'no-camera': 'border-amber-300 bg-amber-50 text-amber-900',
  idle: 'border-slate-200 bg-white text-slate-700',
  checking: 'border-slate-200 bg-white text-slate-700',
};

export function PreflightCard({ report, busy, onRun, onRunAudioOnly }: PreflightCardProps) {
  const { t } = useTranslation();
  const tone = TONE[report.status] ?? 'border-red-300 bg-red-50 text-red-900';
  const blocked = report.status !== 'idle' && report.status !== 'checking' && !report.canJoin;

  return (
    <section className={`rounded-lg border p-4 ${tone}`} data-testid="preflight" data-status={report.status}>
      <h2 className="text-sm font-semibold">{t('telemedicine.preflight.title')}</h2>
      <p className="mt-1 text-sm" data-testid="preflight-message">{t(report.messageKey)}</p>
      {report.status === 'ready' || report.status === 'no-camera' ? (
        <ul className="mt-2 space-y-0.5 text-xs">
          <li>{t('telemedicine.preflight.microphone')}: {report.hasMicrophone ? report.microphoneLabel || t('telemedicine.preflight.ok') : t('telemedicine.preflight.missing')}</li>
          <li>{t('telemedicine.preflight.camera')}: {report.hasCamera ? report.cameraLabel || t('telemedicine.preflight.ok') : t('telemedicine.preflight.missing')}</li>
        </ul>
      ) : null}
      <div className="mt-3 flex flex-wrap gap-2">
        <button
          type="button"
          onClick={onRun}
          disabled={busy}
          data-testid="preflight-run"
          className="rounded bg-primary px-3 py-2 text-sm font-medium text-on-primary disabled:opacity-60"
        >
          {busy ? t('telemedicine.preflight.checking_action') : report.status === 'idle' ? t('telemedicine.preflight.run') : t('telemedicine.preflight.retry')}
        </button>
        {blocked ? (
          <button type="button" onClick={onRunAudioOnly} disabled={busy} className="rounded border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700">
            {t('telemedicine.preflight.audio_only')}
          </button>
        ) : null}
      </div>
    </section>
  );
}
