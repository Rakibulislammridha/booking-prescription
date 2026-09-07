// The hook for the private reception/doctor/display/prescription channels (CONVENTIONS §7.2). Components pass
// handlers keyed by wire event name (without the leading dot); the hook subscribes when Echo is available and
// leaves on unmount. Reconnection is Echo's job; the connection store decides what "live" means.
import { useEffect, useRef } from 'react';
import { loadEcho, type ReverbEcho } from './echo';

export type ChannelKind = 'public' | 'private' | 'presence';
export type ChannelHandlers = Record<string, (payload: unknown) => void>;

export interface UseChannelOptions {
  kind?: ChannelKind;                                   // default 'private'
  enabled?: boolean;                                    // default true
  echo?: () => Promise<ReverbEcho | null>;              // test seam
}

export function useChannel(name: string | null | undefined, handlers: ChannelHandlers, options: UseChannelOptions = {}): void {
  const { kind = 'private', enabled = true } = options;
  const handlersRef = useRef(handlers);
  handlersRef.current = handlers;
  const eventNames = Object.keys(handlers).sort().join('|');
  const getEcho = options.echo ?? loadEcho;

  useEffect(() => {
    if (!name || !enabled) return undefined;
    let cancelled = false;
    let echoRef: ReverbEcho | null = null;

    void getEcho().then((echo) => {
      if (!echo || cancelled) return;
      echoRef = echo;
      const channel = kind === 'private' ? echo.private(name) : kind === 'presence' ? echo.join(name) : echo.channel(name);
      for (const event of eventNames.split('|').filter(Boolean)) {
        channel.listen(`.${event}`, (payload: unknown) => handlersRef.current[event]?.(payload));
      }
    });

    return () => {
      cancelled = true;
      echoRef?.leave(name);
    };
  }, [name, kind, enabled, eventNames, getEcho]);
}
