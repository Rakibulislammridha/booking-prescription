// jsdom has no IndexedDB. `fake-indexeddb` (a devDependency request to the foundation) provides one; when it is not
// installed the Dexie-backed suites are skipped rather than failing the whole run. It schedules with setImmediate,
// so fake timers in these suites must leave setImmediate real (see fakeTimers()).
import { vi } from 'vitest';
import Dexie from 'dexie';

export async function loadIndexedDb(): Promise<boolean> {
  if (typeof globalThis.indexedDB === 'undefined') {
    try {
      const name = 'fake-indexeddb/auto';
      await import(/* @vite-ignore */ name);
    } catch {
      return false;
    }
  }
  if (typeof globalThis.indexedDB === 'undefined') return false;
  // Dexie captured `indexedDB` when it was imported (before the shim): point it at the shim explicitly.
  Dexie.dependencies.indexedDB = globalThis.indexedDB;
  Dexie.dependencies.IDBKeyRange = globalThis.IDBKeyRange;
  return true;
}

let counter = 0;

/** A unique device id per test so every ReceptionDB starts empty without deleting databases between tests. */
export function uniqueDevice(): string {
  counter += 1;
  return `dev-${Date.now().toString(36)}-${counter}`;
}

/** Fake the timers the sync engine uses, never setImmediate (fake-indexeddb's scheduler). */
export function fakeTimers(): void {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
}
