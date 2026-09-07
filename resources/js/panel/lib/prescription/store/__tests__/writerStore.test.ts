// The writer's state machine: debounce, the server parse winning over the local one, offline retry, overrides keyed
// by fingerprint, template apply, and the exact conditions under which Issue is allowed (PRESCRIPTION.md §1.5, §5.2).
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@shared/http';
import type { PrescriptionItemRow, SafetyAlert } from '@shared/types/models';
import { emptyDraft, manualTimers, NAPA, SYRUP, writerProps } from '../../__tests__/fixtures';
import { buildDraftBody, createWriterStore, issueReadiness, itemErrors, OVERRIDE_MIN_REASON, type WriterApi } from '../writerStore';

function api(overrides: Partial<WriterApi> = {}): WriterApi {
  return {
    saveDraft: vi.fn(async () => ({ prescription: emptyDraft(), alerts: [], issue_blocked_by: [] })),
    checkSafety: vi.fn(async () => ({ alerts: [], issue_blocked_by: [], computed: { items: {} }, catalog_version: '2026.09.1' })),
    issuePrescription: vi.fn(async () => ({ prescription: { id: 'rx', version: 1, status: 'issued' as const, language: 'both' as const, verification_code: 'ABC', issued_at: null, amend_reason: null, root_id: 'rx', supersedes_id: null, visit_id: 'v' }, print_url: null, pdf_status: 'pending' as const, follow_up_draft_appointment_id: null })),
    applyTemplate: vi.fn(async () => ({ prescription: emptyDraft(), alerts: [], issue_blocked_by: [] })),
    ...overrides,
  };
}

function serverItem(key: string, overrides: Partial<PrescriptionItemRow> = {}): PrescriptionItemRow {
  return {
    key,
    id: 9001,
    sort_order: 0,
    drug: NAPA,
    shorthand: '1+0+1 5d af',
    parsed: null,
    snapshot: { generic_name: 'Paracetamol', brand_name: 'Napa', strength: '500 mg', form: 'Tablet', route: 'Oral' },
    display: null,
    quantity: 10,
    quantity_unit: 'tab',
    duration_days: 5,
    duration_text: '5 days',
    timing: 'after',
    instruction: null,
    instruction_bn: null,
    is_continued: false,
    safety_overrides: [],
    ...overrides,
  };
}

const CRITICAL: SafetyAlert = {
  key: 'interaction',
  code: 'interaction.contraindicated',
  severity: 'critical',
  fingerprint: 'interaction:contraindicated:17:203',
  overridable: true,
  title: 'Contraindicated combination',
  message: 'Warfarin + Aspirin',
  message_bn: 'ওয়ারফারিন + অ্যাসপিরিন',
  item_keys: [],
  generic_ids: [17, 203],
  evidence: {},
  overridden: null,
};

const TERMINAL: SafetyAlert = { ...CRITICAL, key: 'custom_brand', code: 'custom_brand.unlinked', fingerprint: 'custom_brand:unlinked:c55', overridable: false, title: 'Unlinked custom brand' };

describe('writerStore', () => {
  let timers: ReturnType<typeof manualTimers>;

  beforeEach(() => {
    timers = manualTimers();
  });

  it('parses a line locally the moment it is typed and only then schedules a save', () => {
    const calls = api();
    const store = createWriterStore(writerProps(), { api: calls, timers: timers.api });
    const key = store.getState().addItem();
    store.getState().setDrug(key, NAPA);
    store.getState().setShorthand(key, '1+0+1 10d af');

    const item = store.getState().items.find((i) => i.key === key);
    expect(item?.parsed?.quantity.value).toBe(20);
    expect(item?.display?.en.interpretation).toContain('20 tab');
    expect(calls.saveDraft).not.toHaveBeenCalled();
    expect(store.getState().dirty).toBe(true);

    timers.run();
    expect(calls.saveDraft).toHaveBeenCalledTimes(1);
  });

  it('re-parses against the new presentation when the drug changes', () => {
    const store = createWriterStore(writerProps(), { api: api(), timers: timers.api });
    const key = store.getState().addItem();
    store.getState().setDrug(key, NAPA);
    store.getState().setShorthand(key, '1 tds 5d');
    expect(store.getState().items[0]?.parsed?.quantity).toMatchObject({ value: 15, unit: 'tab' });

    store.getState().setDrug(key, SYRUP);
    expect(store.getState().items[0]?.parsed?.quantity).toMatchObject({ value: 1, unit: 'bottle' });
  });

  it('lets the server parse replace the local one — but not while the doctor keeps typing', async () => {
    const gate = Promise.withResolvers<void>();
    const serverParsed = { v: 1 as const, raw: '1+0+1 5d af', normalized: '1+0+1 5d af', unit: 'tab' as const, unit_inferred: true, schedule: { type: 'slots' as const, slots: [1, 0, 1] }, daily_total: 2, duration: { type: 'days' as const, days: 5 }, timing: 'after' as const, timing_code: 'af' as const, route_code: null, quantity: { value: 10, unit: 'tab', source: 'auto' as const, basis: 'server' }, instruction: null, issues: [] };
    const calls = api({
      saveDraft: vi.fn(async () => {
        await gate.promise;
        return { prescription: emptyDraft({ items: [serverItem('K1', { parsed: serverParsed })] }), alerts: [], issue_blocked_by: [] };
      }),
    });
    const store = createWriterStore(writerProps(), { api: calls, timers: timers.api });
    const key = store.getState().addItem(undefined, { key: 'K1' });
    store.getState().setDrug(key, NAPA);
    store.getState().setShorthand(key, '1+0+1 5d af');
    timers.run();

    // The doctor types on before the response lands: the local parse must survive.
    store.getState().setShorthand(key, '1+0+1 5d af bf');
    gate.resolve();
    await vi.waitFor(() => expect(store.getState().saving).toBe(false));
    expect(store.getState().items[0]?.parsed?.quantity.basis).not.toBe('server');
    expect(store.getState().items[0]?.id).toBe(9001);

    // A save whose text is unchanged does adopt the server parse.
    store.getState().setShorthand(key, '1+0+1 5d af');
    timers.run();
    await vi.waitFor(() => expect(store.getState().items[0]?.parsed?.quantity.basis).toBe('server'));
  });

  it('maps a 422 parse error onto the offending line and keeps the draft dirty', async () => {
    const calls = api({
      saveDraft: vi.fn(async () => {
        throw new ApiError('parse', { status: 422, code: 'prescriptions.parse_error', errors: { 'items.K1': [{ code: 'unknown_token', severity: 'error', token: 'aff', span: null, message: 'Unknown token "aff"', message_bn: 'x', suggestion: 'af' }] } as never });
      }),
    });
    const store = createWriterStore(writerProps(), { api: calls, timers: timers.api });
    store.getState().addItem(undefined, { key: 'K1' });
    store.getState().setDrug('K1', NAPA);
    store.getState().setShorthand('K1', '1+0+1 5d aff');
    timers.run();

    await vi.waitFor(() => expect(store.getState().saveError).not.toBeNull());
    const item = store.getState().items[0];
    expect(item?.status).toBe('error');
    expect(itemErrors(item!).map((i) => i.suggestion)).toContain('af');
    expect(store.getState().dirty).toBe(true);
  });

  it('surfaces a 409 conflict with the server draft and stops saving until it is dismissed', async () => {
    const server = emptyDraft({ version: 1, items: [serverItem('OTHER')] });
    const calls = api({
      saveDraft: vi.fn(async () => {
        throw new ApiError('conflict', { status: 409, code: 'prescriptions.draft_conflict', cause: { response: { data: { prescription: server } } } });
      }),
    });
    const store = createWriterStore(writerProps(), { api: calls, timers: timers.api });
    store.getState().addItem(undefined, { key: 'K1' });
    store.getState().setShorthand('K1', '1+0+1 5d');
    timers.run();

    await vi.waitFor(() => expect(store.getState().conflict).not.toBeNull());
    expect(store.getState().conflict?.items[0]?.key).toBe('OTHER');

    await store.getState().save(true);
    expect(calls.saveDraft).toHaveBeenCalledTimes(1); // no further writes while the conflict stands
  });

  it('retries a network failure with backoff and clears the error once it lands', async () => {
    let attempts = 0;
    const calls = api({
      saveDraft: vi.fn(async () => {
        attempts++;
        if (attempts === 1) throw new ApiError('offline', { network: true, code: 'network' });
        return { prescription: emptyDraft(), alerts: [], issue_blocked_by: [] };
      }),
    });
    const store = createWriterStore(writerProps(), { api: calls, timers: timers.api });
    store.getState().addItem(undefined, { key: 'K1' });
    store.getState().setShorthand('K1', '1+0+1 5d');
    timers.run();

    await vi.waitFor(() => expect(store.getState().saveError).not.toBeNull());
    expect(store.getState().retries).toBe(1);
    timers.run(); // the backoff timer
    await vi.waitFor(() => expect(store.getState().saveError).toBeNull());
    expect(attempts).toBe(2);
  });

  it('keys overrides by fingerprint, enforces the reason length and refuses non-overridable alerts', () => {
    const store = createWriterStore(writerProps({ prescription: emptyDraft({ alerts: [CRITICAL, TERMINAL] }) }), { api: api(), timers: timers.api });

    expect(store.getState().override(CRITICAL.fingerprint, 'too short')).toBe(false);
    expect(store.getState().override(TERMINAL.fingerprint, 'a'.repeat(OVERRIDE_MIN_REASON))).toBe(false);
    expect(store.getState().override(CRITICAL.fingerprint, 'Short course, INR monitored')).toBe(true);
    expect(store.getState().overrides[CRITICAL.fingerprint]).toBe('Short course, INR monitored');
  });

  it('sends an override on every item the alert names, and drops it when the alert is gone', () => {
    const alert = { ...CRITICAL, item_keys: ['K1'] };
    const store = createWriterStore(writerProps({ prescription: emptyDraft({ alerts: [alert] }) }), { api: api(), timers: timers.api });
    store.getState().addItem(undefined, { key: 'K1' });
    store.getState().setDrug('K1', NAPA);
    store.getState().setShorthand('K1', '1+0+1 5d');
    store.getState().override(alert.fingerprint, 'Short course, INR monitored');

    expect(buildDraftBody(store.getState()).items?.[0]?.safety_overrides).toEqual([{ fingerprint: alert.fingerprint, reason: 'Short course, INR monitored' }]);

    // The item changed enough that the server no longer reports the alert: the override is not resent.
    store.setState({ alerts: [] });
    expect(buildDraftBody(store.getState()).items?.[0]?.safety_overrides).toEqual([]);
  });

  it('blocks Issue on critical alerts, unsaved work and parse errors, and allows it otherwise', () => {
    const store = createWriterStore(writerProps(), { api: api(), timers: timers.api });
    expect(issueReadiness(store.getState())).toMatchObject({ ready: false, reason: 'empty' });

    store.getState().addItem(undefined, { key: 'K1' });
    store.getState().setDrug('K1', NAPA);
    store.getState().setShorthand('K1', '1+0+1 5d af');
    expect(issueReadiness(store.getState())).toMatchObject({ ready: false, reason: 'unsaved' });

    store.setState({ dirty: false });
    expect(issueReadiness(store.getState())).toMatchObject({ ready: true, reason: null });

    store.setState({ alerts: [CRITICAL] });
    expect(issueReadiness(store.getState())).toMatchObject({ ready: false, reason: 'blocked' });

    store.setState({ alerts: [{ ...CRITICAL, overridden: { reason: 'ok reason here', by: 7, at: null } }] });
    expect(issueReadiness(store.getState())).toMatchObject({ ready: true });

    // A non-overridable critical stays terminal even when "overridden".
    store.setState({ alerts: [{ ...TERMINAL, overridden: { reason: 'ok reason here', by: 7, at: null } }] });
    expect(issueReadiness(store.getState())).toMatchObject({ ready: false, reason: 'blocked' });

    store.setState({ alerts: [] });
    store.getState().setShorthand('K1', '1+0+1 5d aff');
    store.setState({ dirty: false });
    expect(issueReadiness(store.getState())).toMatchObject({ ready: false, reason: 'parse_error' });
  });

  it('adopts the server alert set when issue is refused', async () => {
    const calls = api({
      issuePrescription: vi.fn(async () => {
        throw new ApiError('blocked', { status: 422, code: 'prescriptions.issue_blocked', cause: { response: { data: { issue_blocked_by: [CRITICAL.fingerprint], alerts: [CRITICAL] } } } });
      }),
    });
    const store = createWriterStore(writerProps(), { api: calls, timers: timers.api });
    await expect(store.getState().issue({ print: true })).rejects.toBeInstanceOf(ApiError);
    expect(store.getState().alerts).toEqual([CRITICAL]);
    expect(store.getState().issueBlockedBy).toEqual([CRITICAL.fingerprint]);
  });

  it('applies a template in one call and reports what was skipped', async () => {
    const calls = api({
      applyTemplate: vi.fn(async () => ({ prescription: emptyDraft({ items: [serverItem('T1')], template_skipped: ['Paracetamol'] }), alerts: [], issue_blocked_by: [] })),
    });
    const store = createWriterStore(writerProps(), { api: calls, timers: timers.api });
    await store.getState().applyTemplate(12, 'append');

    expect(calls.applyTemplate).toHaveBeenCalledWith(expect.any(String), 12, 'append');
    expect(store.getState().items).toHaveLength(1);
    expect(store.getState().items[0]?.status).toBe('committed');
    expect(store.getState().templateSkipped).toEqual(['Paracetamol']);
    expect(store.getState().dirty).toBe(false);
  });

  it('builds the §4.13 body: empty lines dropped, sort_order renumbered, expected_updated_at echoed', () => {
    const store = createWriterStore(writerProps(), { api: api(), timers: timers.api });
    store.getState().addItem(undefined, { key: 'A' });
    store.getState().setDrug('A', NAPA);
    store.getState().setShorthand('A', '1+0+1 5d');
    store.getState().addItem(undefined, { key: 'B' }); // untouched, must not be sent
    store.getState().addItem(undefined, { key: 'C' });
    store.getState().setDrug('C', SYRUP);
    store.getState().setShorthand('C', '1 tsp bd 5d');
    store.getState().moveItem('C', -2);

    const body = buildDraftBody(store.getState());
    expect(body.items?.map((i) => i.key)).toEqual(['C', 'A']);
    expect(body.items?.map((i) => i.sort_order)).toEqual([0, 1]); // contiguous over the sent list
    expect(body.items?.[0]?.drug).toEqual({ generic_id: 17, brand_id: 88, custom_brand_id: null, strength_id: 4321 });
    expect(body.expected_updated_at).toBe('2026-09-07T10:00:00+06:00');
    expect(body.visit?.chief_complaints).toEqual([]);
  });

  it('debounces the safety check separately from the save so alerts arrive as a line commits', () => {
    const calls = api();
    const store = createWriterStore(writerProps(), { api: calls, timers: timers.api });
    store.getState().addItem(undefined, { key: 'K1' });
    store.getState().setDrug('K1', NAPA);
    expect(calls.checkSafety).not.toHaveBeenCalled();
    timers.run();
    expect(calls.checkSafety).toHaveBeenCalledTimes(1);
    expect((calls.checkSafety as ReturnType<typeof vi.fn>).mock.calls[0]?.[1]).toMatchObject({ items: [expect.objectContaining({ key: 'K1' })] });
  });
});
