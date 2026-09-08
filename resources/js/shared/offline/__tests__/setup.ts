// jsdom has no IndexedDB. `fake-indexeddb` (a devDependency) provides one, and it is imported **statically and
// unconditionally**: these suites hold the LOCKED §5.F.1 client guarantees, so a missing shim must break the run
// rather than skip it — the previous dynamic import silently turned ten tests into no-ops. It schedules with
// setImmediate, so fake timers in these suites must leave setImmediate real (see fakeTimers()).
import 'fake-indexeddb/auto';
import { vi } from 'vitest';
import Dexie from 'dexie';

// Dexie captures `indexedDB` when its own module is evaluated; point it at the shim explicitly so that import
// order between this file and `dexie` can never decide whether the suite runs against a real store.
Dexie.dependencies.indexedDB = globalThis.indexedDB;
Dexie.dependencies.IDBKeyRange = globalThis.IDBKeyRange;

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
