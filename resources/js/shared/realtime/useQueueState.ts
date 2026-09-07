// React wrapper over subscribeQueue(): one handle per mounted component (CONVENTIONS §7.2).
import { useEffect, useRef, useState } from 'react';
import { useConnection, type ConnectionMode } from '../connection/store';
import { subscribeQueue, type LiveQueueHandle } from './liveQueue';
import type { QueueState } from './types';

export interface UseQueueStateOptions {
  tenantId: string;
  doctorSlug: string;
  sessionId?: string | null;
  initial?: QueueState | null;
  onEvent?: (name: string, payload: unknown) => void;
  enabled?: boolean;
}

export interface UseQueueStateResult {
  state: QueueState | null;
  mode: ConnectionMode;
  /** epoch ms of the last applied state (for "showing the queue as of 10:42") */
  updatedAt: number | null;
}

export function useQueueState({ tenantId, doctorSlug, sessionId = null, initial = null, onEvent, enabled = true }: UseQueueStateOptions): UseQueueStateResult {
  const [state, setState] = useState<QueueState | null>(initial);
  const [updatedAt, setUpdatedAt] = useState<number | null>(initial ? Date.now() : null);
  const mode = useConnection((s) => s.mode);
  const onEventRef = useRef(onEvent);
  onEventRef.current = onEvent;
  const initialRef = useRef(initial);

  useEffect(() => {
    if (!enabled) return undefined;
    const handle: LiveQueueHandle = subscribeQueue({
      tenantId,
      doctorSlug,
      sessionId,
      initial: initialRef.current,
      onState: (s) => { setState(s); setUpdatedAt(Date.now()); },
      onEvent: (name, payload) => onEventRef.current?.(name, payload),
    });
    return () => handle.stop();
  }, [tenantId, doctorSlug, sessionId, enabled]);

  return { state, mode, updatedAt };
}
