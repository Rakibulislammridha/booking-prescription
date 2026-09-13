// Queue/Today — today's chambers at the branch, one row each. The row's way into the doctor screen was rendered
// unconditionally while the gate for it sat unread in the props: `can.call_next`. The screen's own door is
// `queue.call-next` for anyone who is not that doctor (DoctorScreenController::isOperator), and a COMPOUNDER
// holds it for nobody — so the one button on their queue overview was a guaranteed 403, and the board itself is
// already narrowed to their doctors, which is what made it look like a screen meant for them.
import { describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { ThemeProvider } from '@mui/material/styles';
import { I18nextProvider } from 'react-i18next';
import { theme } from '@panel/theme';
import { i18n } from '@shared/i18n';
import { setZiggy } from '@shared/routes';
import type { QueueBoard, QueueBoardSession } from '@shared/types/models';
import type { SharedProps } from '@shared/types/inertia';
import Today from '../Today';

// The live feed and the degraded-mode poll are REALTIME's subject; this page gets its state from the props.
vi.mock('@panel/api/queue', () => ({ fetchQueueToday: vi.fn() }));
vi.mock('@shared/realtime/useChannel', () => ({ useChannel: () => undefined }));
vi.mock('@shared/connection/store', () => ({ useConnection: () => 'online' }));
vi.mock('@panel/Layouts/PanelLayout', () => ({ PanelLayout: ({ children }: { children: React.ReactNode }) => <>{children}</> }));

setZiggy({
  url: 'http://demo.test', port: null, defaults: {},
  routes: { 'panel.queue.doctor': { uri: 'panel/queue/doctor', methods: ['GET'] } },
});

const shared: SharedProps = {
  surface: 'panel',
  auth: { guard: 'web', user: { id: 1, name: 'Staff', roles: [], permissions: [], doctor_id: null }, impersonating: false },
  tenant: null, branch: null, branches: [], today_session: null, locale: 'en',
  flash: { success: null, error: null, warning: null, info: null },
  features: {}, ziggy: { url: 'http://demo.test', port: null, defaults: {}, routes: {} }, csrf_token: 'x',
  app: { name: 'bp', env: 'testing', version: '1', reverb: { key: 'k', host: 'localhost', port: 8080, scheme: 'http' } },
  errors: {},
};

const session: QueueBoardSession = {
  id: 'ses_1', doctor: 'doc_1', code: 'A', status: 'running', now_serving: 'A-002',
  counts: { booked: 2, checked_in: 3, in_consultation: 1, completed: 4, no_show: 0, cancelled: 0, postponed: 0 },
  remaining: { online: 0, counter: 0, released: 0, buffer: 0 },
  delay_minutes: 0, version: 3,
};

const board: QueueBoard = { v: 1, branch: 'br_1', at: '2026-09-09T04:00:00Z', sessions: [session] };
const doctors = { doc_1: { slug: 'dr-rahman', name: 'Dr Rahman', name_bn: null, room: 'Room 3' } };

function show(callNext: boolean) {
  return render(
    <I18nextProvider i18n={i18n}><ThemeProvider theme={theme}>
      <Today
        {...shared}
        tenant_public_id={null}
        channel={null}
        branch={{ public_id: 'br_1', slug: 'main', name: 'Main' }}
        date="2026-09-09"
        board={board}
        doctors={doctors}
        can={{ call_next: callNext }}
      />
    </ThemeProvider></I18nextProvider>,
  );
}

const row = () => within(screen.getByRole('table')).getAllByRole('row')[1] as HTMLElement;

describe('Queue/Today — the way into the doctor screen', () => {
  it('offers it to an operator, aimed at that row\'s doctor and session', () => {
    show(true);

    expect(within(row()).getByRole('link', { name: 'Open queue' }))
      .toHaveAttribute('href', '/panel/queue/doctor?doctor=dr-rahman&session=ses_1');
  });

  // The row itself stays — the overview is a read, and the board has already been narrowed to the doctors this
  // user may see. What goes is the one control behind a door they do not hold.
  it('withholds it from a user the screen would refuse, without hiding the chamber', () => {
    show(false);

    expect(within(row()).queryByRole('link', { name: 'Open queue' })).toBeNull();
    expect(row()).toHaveTextContent('Dr Rahman');
    expect(row()).toHaveTextContent('A-002');
  });
});
