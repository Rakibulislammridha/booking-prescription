// One debounced, abortable, keyboard-navigable search for every autocomplete in the writer (drugs, ICD-10,
// investigations, doctors, snippets). PRESCRIPTION.md §3.8: the endpoints are cheap and cached, so the debounce is
// short (140 ms) — the doctor must never wait for a list.
import { useCallback, useEffect, useRef, useState } from 'react';

export const SEARCH_DEBOUNCE_MS = 140;
export const MIN_QUERY_LENGTH = 2;

export interface SearchState<T> {
  query: string;
  results: T[];
  loading: boolean;
  error: string | null;
  highlight: number;
}

export interface SearchApi<T> extends SearchState<T> {
  setQuery(next: string): void;
  setHighlight(next: number): void;
  moveHighlight(delta: number): void;
  selected(): T | null;
  reset(): void;
  refresh(): void;
}

export function useDebouncedSearch<T>(
  run: (query: string, signal: AbortSignal) => Promise<T[]>,
  options: { debounceMs?: number; minLength?: number; enabled?: boolean; deps?: unknown[] } = {},
): SearchApi<T> {
  const { debounceMs = SEARCH_DEBOUNCE_MS, minLength = MIN_QUERY_LENGTH, enabled = true } = options;
  const [state, setState] = useState<SearchState<T>>({ query: '', results: [], loading: false, error: null, highlight: 0 });
  const timer = useRef<number | null>(null);
  const abort = useRef<AbortController | null>(null);
  const runRef = useRef(run);
  runRef.current = run;
  const deps = options.deps ?? [];

  const fire = useCallback(
    (query: string) => {
      abort.current?.abort();
      if (!enabled || query.trim().length < minLength) {
        setState((s) => ({ ...s, results: [], loading: false, error: null, highlight: 0 }));
        return;
      }
      const controller = new AbortController();
      abort.current = controller;
      setState((s) => ({ ...s, loading: true, error: null }));
      runRef.current(query.trim(), controller.signal)
        .then((results) => {
          if (controller.signal.aborted) return;
          setState((s) => ({ ...s, results, loading: false, highlight: 0 }));
        })
        .catch((error: unknown) => {
          if (controller.signal.aborted) return;
          setState((s) => ({ ...s, results: [], loading: false, error: error instanceof Error ? error.message : String(error) }));
        });
    },
    [enabled, minLength],
  );

  const setQuery = useCallback(
    (next: string) => {
      setState((s) => ({ ...s, query: next }));
      if (timer.current !== null) window.clearTimeout(timer.current);
      timer.current = window.setTimeout(() => {
        timer.current = null;
        fire(next);
      }, debounceMs);
    },
    [debounceMs, fire],
  );

  useEffect(
    () => () => {
      if (timer.current !== null) window.clearTimeout(timer.current);
      abort.current?.abort();
    },
    [],
  );

  // A changed dependency (the diagnosis codes that boost drug ranking) re-runs the current query.
  useEffect(() => {
    if (state.query.trim().length >= minLength) fire(state.query);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return {
    ...state,
    setQuery,
    setHighlight: (highlight) => setState((s) => ({ ...s, highlight })),
    moveHighlight: (delta) =>
      setState((s) => {
        if (s.results.length === 0) return s;
        const next = (s.highlight + delta + s.results.length) % s.results.length;
        return { ...s, highlight: next };
      }),
    selected: () => state.results[state.highlight] ?? null,
    reset: () => {
      abort.current?.abort();
      if (timer.current !== null) window.clearTimeout(timer.current);
      setState({ query: '', results: [], loading: false, error: null, highlight: 0 });
    },
    refresh: () => fire(state.query),
  };
}
