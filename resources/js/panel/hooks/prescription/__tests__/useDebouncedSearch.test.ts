// Autocomplete behaviour (§3.3, §3.8): one request per pause, the previous one aborted, and the SERVER's ranking
// preserved verbatim — the boost maths lives in DrugSearchService and the client must never re-sort it.
import { describe, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import { MIN_QUERY_LENGTH, useDebouncedSearch } from '../useDebouncedSearch';

interface Hit {
  id: string;
  score: number;
}

describe('useDebouncedSearch', () => {
  it('collapses a burst of keystrokes into a single request', async () => {
    const run = vi.fn(async (query: string, _signal: AbortSignal): Promise<Hit[]> => [{ id: query, score: 1 }]);
    const { result } = renderHook(() => useDebouncedSearch<Hit>(run, { debounceMs: 20 }));

    act(() => {
      result.current.setQuery('n');
      result.current.setQuery('na');
      result.current.setQuery('nap');
    });

    await waitFor(() => expect(result.current.results).toHaveLength(1));
    expect(run).toHaveBeenCalledTimes(1);
    expect(run.mock.calls[0]?.[0]).toBe('nap');
  });

  it('does not search below the minimum query length', async () => {
    const run = vi.fn(async (_query: string, _signal: AbortSignal): Promise<Hit[]> => []);
    const { result } = renderHook(() => useDebouncedSearch<Hit>(run, { debounceMs: 5 }));
    act(() => result.current.setQuery('n'));
    await new Promise((resolve) => setTimeout(resolve, 25));
    expect(run).not.toHaveBeenCalled();
    expect(MIN_QUERY_LENGTH).toBe(2);
  });

  it('aborts the in-flight request when the query moves on', async () => {
    const signals: AbortSignal[] = [];
    const run = vi.fn(async (q: string, signal: AbortSignal) => {
      signals.push(signal);
      await new Promise((resolve) => setTimeout(resolve, 30));
      return [{ id: q, score: 1 }];
    });
    const { result } = renderHook(() => useDebouncedSearch<Hit>(run, { debounceMs: 5 }));

    act(() => result.current.setQuery('nap'));
    await waitFor(() => expect(run).toHaveBeenCalledTimes(1));
    act(() => result.current.setQuery('cet'));
    await waitFor(() => expect(run).toHaveBeenCalledTimes(2));

    expect(signals[0]?.aborted).toBe(true);
    await waitFor(() => expect(result.current.results.map((h) => h.id)).toEqual(['cet']));
  });

  it('keeps the server ranking exactly as it arrived', async () => {
    const ranked: Hit[] = [
      { id: 's1234', score: 1187.5 }, // favourite for the diagnosis
      { id: 'c55', score: 812 }, // tenant custom brand
      { id: 'g17', score: 790 }, // prescribe-by-generic row keeps its slot
    ];
    const { result } = renderHook(() => useDebouncedSearch<Hit>(async () => ranked, { debounceMs: 1 }));
    act(() => result.current.setQuery('para'));
    await waitFor(() => expect(result.current.results).toHaveLength(3));
    expect(result.current.results.map((h) => h.id)).toEqual(['s1234', 'c55', 'g17']);
  });

  it('wraps the highlight in both directions', async () => {
    const { result } = renderHook(() => useDebouncedSearch<Hit>(async () => [{ id: 'a', score: 1 }, { id: 'b', score: 1 }, { id: 'c', score: 1 }], { debounceMs: 1 }));
    act(() => result.current.setQuery('abc'));
    await waitFor(() => expect(result.current.results).toHaveLength(3));

    act(() => result.current.moveHighlight(1));
    expect(result.current.highlight).toBe(1);
    act(() => result.current.moveHighlight(-2));
    expect(result.current.highlight).toBe(2);
    act(() => result.current.moveHighlight(1));
    expect(result.current.highlight).toBe(0);
  });

  it('re-runs the current query when a dependency (the diagnosis boost) changes', async () => {
    const run = vi.fn(async (_query: string, _signal: AbortSignal): Promise<Hit[]> => [{ id: 'a', score: 1 }]);
    const { result, rerender } = renderHook(({ dx }: { dx: string }) => useDebouncedSearch<Hit>(run, { debounceMs: 1, deps: [dx] }), { initialProps: { dx: '' } });

    act(() => result.current.setQuery('nap'));
    await waitFor(() => expect(run).toHaveBeenCalledTimes(1));
    rerender({ dx: 'J06.9' });
    await waitFor(() => expect(run).toHaveBeenCalledTimes(2));
  });

  it('surfaces an error without wiping the input', async () => {
    const { result } = renderHook(() =>
      useDebouncedSearch<Hit>(async () => {
        throw new Error('boom');
      }, { debounceMs: 1 }),
    );
    act(() => result.current.setQuery('nap'));
    await waitFor(() => expect(result.current.error).toBe('boom'));
    expect(result.current.query).toBe('nap');
    expect(result.current.results).toEqual([]);
  });
});
