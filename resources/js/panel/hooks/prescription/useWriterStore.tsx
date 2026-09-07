// React binding for the writer store: one store per open writer, provided by the page and read by every component.
import { createContext, useContext, useEffect, useMemo, type ReactNode } from 'react';
import { useStore } from 'zustand';
import type { WriterPageProps } from '@shared/types/models';
import { createWriterStore, type WriterState, type WriterStore, type WriterStoreOptions } from '@panel/lib/prescription/store/writerStore';

const WriterStoreContext = createContext<WriterStore | null>(null);

export function WriterStoreProvider({ props, options, children }: { props: WriterPageProps; options?: WriterStoreOptions; children: ReactNode }) {
  const store = useMemo(() => createWriterStore(props, options), [props.prescription.id]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => () => store.getState().destroy(), [store]);

  return <WriterStoreContext.Provider value={store}>{children}</WriterStoreContext.Provider>;
}

export function useWriterStoreApi(): WriterStore {
  const store = useContext(WriterStoreContext);
  if (store === null) throw new Error('useWriterStore must be used inside <WriterStoreProvider>');
  return store;
}

export function useWriter<T>(selector: (state: WriterState) => T): T {
  return useStore(useWriterStoreApi(), selector);
}

/** The action bag is stable — pulling it out of the store avoids re-rendering on every keystroke. */
export function useWriterActions(): WriterState {
  return useWriterStoreApi().getState();
}
