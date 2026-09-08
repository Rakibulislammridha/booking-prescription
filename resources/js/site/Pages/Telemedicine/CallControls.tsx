// Mute / camera / leave, plus the connection-quality dot. Presentational: every decision is in callMachine.
import { useTranslation } from 'react-i18next';
import type { CallQuality, CallState } from './core/callMachine';

const QUALITY_CLASS: Record<CallQuality, string> = {
  unknown: 'bg-slate-400',
  good: 'bg-emerald-500',
  fair: 'bg-amber-500',
  poor: 'bg-red-500',
};

export interface CallControlsProps {
  state: CallState;
  hasCamera: boolean;
  onToggleMic(): void;
  onToggleCam(): void;
  onLeave(): void;
  leaveLabel: string;
  compact?: boolean;
}

export function CallControls({ state, hasCamera, onToggleMic, onToggleCam, onLeave, leaveLabel, compact = false }: CallControlsProps) {
  const { t } = useTranslation();
  const button = `rounded-full border px-3 ${compact ? 'py-1 text-xs' : 'py-2 text-sm'} font-medium`;

  return (
    <div className="flex flex-wrap items-center gap-2" data-testid="call-controls">
      <button
        type="button"
        onClick={onToggleMic}
        aria-pressed={!state.micEnabled}
        data-testid="toggle-mic"
        className={`${button} ${state.micEnabled ? 'border-slate-300 bg-white text-slate-700' : 'border-red-300 bg-red-50 text-red-700'}`}
      >
        {state.micEnabled ? t('telemedicine.call.mute') : t('telemedicine.call.unmute')}
      </button>
      {hasCamera ? (
        <button
          type="button"
          onClick={onToggleCam}
          aria-pressed={!state.camEnabled}
          data-testid="toggle-cam"
          className={`${button} ${state.camEnabled ? 'border-slate-300 bg-white text-slate-700' : 'border-red-300 bg-red-50 text-red-700'}`}
        >
          {state.camEnabled ? t('telemedicine.call.camera_off_action') : t('telemedicine.call.camera_on_action')}
        </button>
      ) : null}
      <button type="button" onClick={onLeave} data-testid="leave-call" className={`${button} border-red-600 bg-red-600 text-white`}>
        {leaveLabel}
      </button>
      <span className="ml-auto flex items-center gap-2 text-xs text-slate-600" data-testid="call-quality">
        <span className={`inline-block h-2.5 w-2.5 rounded-full ${QUALITY_CLASS[state.quality]}`} aria-hidden="true" />
        {t(`telemedicine.call.quality.${state.quality}`)}
        {state.phase === 'reconnecting' ? <span className="text-amber-700">· {t('telemedicine.call.reconnecting')}</span> : null}
      </span>
    </div>
  );
}
