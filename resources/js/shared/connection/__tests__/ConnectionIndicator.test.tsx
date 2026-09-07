import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import { ConnectionIndicator, OFFLINE_TITLE_PREFIX, viewFor } from '../ConnectionIndicator';
import { resetConnectionForTests, useConnection } from '../store';
import { stopHeartbeat } from '../heartbeat';
import { initI18n } from '../../i18n';

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-09-06T04:42:00Z'));
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => { /* heartbeat never answers in these tests */ })));
  resetConnectionForTests();
  document.title = 'Reception';
  initI18n('en');
});
afterEach(() => {
  stopHeartbeat();
  vi.useRealTimers();
  vi.unstubAllGlobals();
  delete document.documentElement.dataset.connection;
});

describe('viewFor', () => {
  it('treats the boot state as connecting, not offline', () => {
    expect(viewFor({ mode: 'offline', browserOnline: true, lastHeartbeatOkAt: null, consecutiveHeartbeatFailures: 0, pendingEvents: 0, syncPhase: 'idle' })).toBe('connecting');
    expect(viewFor({ mode: 'offline', browserOnline: false, lastHeartbeatOkAt: null, consecutiveHeartbeatFailures: 0, pendingEvents: 0, syncPhase: 'idle' })).toBe('offline');
    expect(viewFor({ mode: 'offline', browserOnline: true, lastHeartbeatOkAt: null, consecutiveHeartbeatFailures: 2, pendingEvents: 0, syncPhase: 'idle' })).toBe('offline');
    expect(viewFor({ mode: 'online', browserOnline: true, lastHeartbeatOkAt: 1, consecutiveHeartbeatFailures: 0, pendingEvents: 3, syncPhase: 'syncing' })).toBe('syncing');
  });
});

describe('ConnectionIndicator', () => {
  it('online: quiet green dot + "Online"', () => {
    resetConnectionForTests({ mode: 'online', lastHeartbeatOkAt: Date.now() });
    render(<ConnectionIndicator boot={false} />);
    const el = screen.getByTestId('connection-indicator');
    expect(el).toHaveAttribute('data-view', 'online');
    expect(screen.getByRole('status')).toHaveTextContent('Online');
    expect(el.querySelector('.ci__strip')).not.toBeNull();
  });

  it('quiet variant has no strip', () => {
    resetConnectionForTests({ mode: 'online', lastHeartbeatOkAt: Date.now() });
    render(<ConnectionIndicator boot={false} variant="quiet" />);
    expect(screen.getByTestId('connection-indicator').querySelector('.ci__strip')).toBeNull();
  });

  it('degraded: amber bar with the paused text, spinner and since HH:MM', () => {
    resetConnectionForTests({ mode: 'degraded', since: Date.now(), lastHeartbeatOkAt: Date.now() });
    render(<ConnectionIndicator boot={false} />);
    const el = screen.getByTestId('connection-indicator');
    expect(el).toHaveAttribute('data-view', 'degraded');
    expect(screen.getByRole('status')).toHaveTextContent('Live updates paused — refreshing every 5 s');
    expect(screen.getByRole('status')).toHaveTextContent('since 10:42');
    expect(el.querySelector('.ci__spinner')).not.toBeNull();
  });

  it('offline: assertive red bar with since, block and queued count; title prefix and viewport outline', () => {
    resetConnectionForTests({ mode: 'offline', since: Date.now(), browserOnline: false, pendingEvents: 4, activeBlock: { displayFrom: 'A-021', displayTo: 'A-030', remaining: 7 } });
    render(<ConnectionIndicator boot={false} />);
    const alert = screen.getByRole('alert');
    expect(alert).toHaveAttribute('aria-live', 'assertive');
    expect(alert).toHaveTextContent('OFFLINE since 10:42');
    expect(alert).toHaveTextContent('issuing from block A-021–A-030 (7 left)');
    expect(alert).toHaveTextContent('4 actions queued');
    expect(document.title).toBe(OFFLINE_TITLE_PREFIX + 'Reception');
    expect(document.documentElement.dataset.connection).toBe('offline');
    expect(screen.getByTestId('ci-mute')).toBeInTheDocument();
  });

  it('offline in Bangla uses Bangla digits', () => {
    initI18n('bn');
    resetConnectionForTests({ mode: 'offline', since: Date.now(), browserOnline: false, pendingEvents: 4, activeBlock: { displayFrom: 'A-021', displayTo: 'A-030', remaining: 7 } });
    render(<ConnectionIndicator boot={false} />);
    expect(screen.getByRole('alert')).toHaveTextContent('অফলাইন ১০:৪২ থেকে');
    expect(screen.getByRole('alert')).toHaveTextContent('ব্লক A-021–A-030 (৭ বাকি)');
    expect(screen.getByRole('alert')).toHaveTextContent('৪টি কাজ অপেক্ষমাণ');
  });

  it('restores the title and outline when the desk recovers, and shows the blue syncing bar while events drain', () => {
    resetConnectionForTests({ mode: 'offline', since: Date.now(), browserOnline: false, pendingEvents: 4 });
    render(<ConnectionIndicator boot={false} />);
    expect(document.title.startsWith(OFFLINE_TITLE_PREFIX)).toBe(true);
    act(() => { useConnection.setState({ mode: 'degraded', browserOnline: true, lastHeartbeatOkAt: Date.now(), syncPhase: 'syncing' }); });
    expect(document.title).toBe('Reception');
    expect(document.documentElement.dataset.connection).toBe('degraded');
    expect(screen.getByTestId('connection-indicator')).toHaveAttribute('data-view', 'syncing');
    expect(screen.getByRole('status')).toHaveTextContent('Back online — syncing 4 actions…');
    act(() => { useConnection.setState({ pendingEvents: 1 }); });
    expect(screen.getByRole('status')).toHaveTextContent('3/4');
    act(() => { useConnection.setState({ pendingEvents: 0, syncPhase: 'idle' }); });
    expect(screen.getByTestId('connection-indicator')).toHaveAttribute('data-view', 'degraded');
  });

  it('conflicts badge persists across views and calls onConflictsClick', () => {
    const onClick = vi.fn();
    resetConnectionForTests({ mode: 'online', lastHeartbeatOkAt: Date.now(), conflicts: 3 });
    render(<ConnectionIndicator boot={false} onConflictsClick={onClick} />);
    const badge = screen.getByTestId('ci-conflicts');
    expect(badge).toHaveTextContent('3 need your decision');
    badge.click();
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('boot state renders as connecting (grey), never as offline', () => {
    render(<ConnectionIndicator boot={false} />);
    expect(screen.getByTestId('connection-indicator')).toHaveAttribute('data-view', 'connecting');
    expect(screen.queryByRole('alert')).toBeNull();
    expect(document.title).toBe('Reception');
  });
});
