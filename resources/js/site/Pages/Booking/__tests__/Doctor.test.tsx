// The public booking form has no OTP step unless the clinic turned `kiosk.otp_required` on (BRIEF §5.C: mobile +
// name → session → serial → confirmation). With the prop off the page must show no send-code button and no code
// field, must never call the OTP endpoint, and must post a payload with no `otp` key at all — the server ignores
// the field, but a form that still carried one would be dead UI waiting to confuse the next reader. With the prop
// on the verified flow is unchanged: the button, the field and the code in the payload.
import { useRef, useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import { i18n } from '@shared/i18n';
import type { KioskSession } from '@shared/types/models';
import Doctor from '../Doctor';

type Data = Record<string, string>;
type Transform = (d: Data) => Record<string, unknown>;

const post = vi.fn<(url: string, data: Record<string, unknown>) => void>();
const requestBookingOtp = vi.fn<(mobile: string) => Promise<{ sent: boolean; resend_in: number; ttl: number }>>();
const fetchAvailability = vi.fn();

// A faithful stand-in for Inertia's useForm: state, per-key setData, a transform applied at post time. What the
// page hands to `post` after its own transform is exactly what the assertions read.
vi.mock('@inertiajs/react', () => ({
  useForm: (initial: Data) => {
    const [data, setDataState] = useState<Data>(initial);
    const transform = useRef<Transform>((d) => d);
    return {
      data,
      errors: {},
      processing: false,
      setData: (key: string, value: string) => setDataState((d) => ({ ...d, [key]: value })),
      transform: (fn: Transform) => { transform.current = fn; },
      post: (url: string) => post(url, transform.current(data)),
    };
  },
}));
vi.mock('@site/Layouts/SiteLayout', () => ({ SiteLayout: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('@shared/routes', () => ({ route: (name: string) => `/${name}` }));
vi.mock('@site/api/booking', () => ({ fetchAvailability: (...a: unknown[]) => fetchAvailability(...a), requestBookingOtp: (m: string) => requestBookingOtp(m) }));

const doctor = { public_id: 'doc_1', slug: 'dr-rahman', name: 'Dr Rahman', name_bn: null, degrees: 'MBBS', degrees_bn: null, room: null, fee_paisa: 50000, followup_fee_paisa: 30000, free_followup_within_days: 7 };
const branch = { public_id: 'br_1', slug: 'main', name: 'Main branch', phone: '01700000000' };
const session = { public_id: '01J8ZK4V2Q3W5X6Y7Z8A9B0C2A', code: 'A', status: 'scheduled' as const, mode: 'serial' as const, planned_start_at: '2026-09-10T03:00:00Z', planned_end_at: '2026-09-10T07:00:00Z', online_remaining: 5 };
const shared = {
  surface: 'site' as const,
  auth: { guard: null, user: null, impersonating: false },
  tenant: null, branch: null, branches: [], today_session: null, locale: 'en' as const,
  flash: { success: null, error: null, warning: null, info: null },
  features: {}, ziggy: { url: 'http://demo.test', port: null, defaults: {}, routes: {} }, csrf_token: 'x',
  app: { name: 'bp', env: 'testing', version: '1', reverb: { key: 'k', host: 'localhost', port: 8080, scheme: 'http' as const } },
  errors: {},
};

function show(props: { otp_required: boolean; channel?: 'online' | 'kiosk'; kiosk?: { branch: string; sessions: KioskSession[] } | null }) {
  return render(
    <I18nextProvider i18n={i18n}>
      <Doctor
        {...shared}
        doctor={doctor} branch={branch} from="2026-09-10" to="2026-09-23"
        otp_required={props.otp_required} otp_resend_seconds={60}
        online_payment_enabled={false} advance_payment_required={false}
        channel={props.channel ?? 'online'} kiosk={props.kiosk ?? null}
      />
    </I18nextProvider>,
  );
}

async function pickSessionAndFill(): Promise<void> {
  fireEvent.click(await screen.findByRole('button', { name: /Session A/ }));
  fireEvent.change(screen.getByLabelText('Mobile number'), { target: { value: '01712345678' } });
  fireEvent.change(screen.getByLabelText('Patient name'), { target: { value: 'Rahima' } });
}

describe('Booking/Doctor without OTP (the default)', () => {
  beforeEach(() => {
    fetchAvailability.mockResolvedValue({ doctor, branch, days: [{ date: '2026-09-10', sessions: [session] }] });
  });

  it('shows mobile + name, no send-code button and no code field, and posts a payload with no otp key', async () => {
    show({ otp_required: false });
    await pickSessionAndFill();

    expect(screen.queryByRole('button', { name: 'Send code' })).toBeNull();
    expect(screen.queryByLabelText('6-digit code')).toBeNull();
    expect(screen.queryByText(/verify/i)).toBeNull();

    const submit = screen.getByRole('button', { name: /Confirm serial — session A/ });
    expect(submit).toBeEnabled();
    fireEvent.click(submit);

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    const [url, payload] = post.mock.calls[0]!;
    expect(url).toBe('/site.booking.store');
    expect(payload).toMatchObject({ session: session.public_id, channel: 'online', mobile: '01712345678', name: 'Rahima', sex: null, age_years: null });
    expect(payload).not.toHaveProperty('otp');
    expect(requestBookingOtp).not.toHaveBeenCalled();
  });

  it('the kiosk / QR page follows the same setting', () => {
    const kioskSession: KioskSession = { public_id: session.public_id, code: 'A', date: '2026-09-10', status: 'scheduled', planned_start_at: session.planned_start_at, online_remaining: 5, doctor: { public_id: doctor.public_id, slug: doctor.slug, name: doctor.name, name_bn: null, fee_paisa: 50000 } };
    show({ otp_required: false, channel: 'kiosk', kiosk: { branch: branch.public_id, sessions: [kioskSession] } });

    expect(screen.getByRole('button', { name: /Session A/ })).toBeInTheDocument();
    expect(screen.getByLabelText('Mobile number')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Send code' })).toBeNull();
    expect(screen.queryByLabelText('6-digit code')).toBeNull();
    expect(fetchAvailability).not.toHaveBeenCalled();
  });
});

describe('Booking/Doctor with OTP on (the clinic opted in)', () => {
  beforeEach(() => {
    fetchAvailability.mockResolvedValue({ doctor, branch, days: [{ date: '2026-09-10', sessions: [session] }] });
    requestBookingOtp.mockResolvedValue({ sent: true, resend_in: 60, ttl: 300 });
  });

  it('keeps the verified flow: send-code button, code field, submit gated on the code, otp in the payload', async () => {
    show({ otp_required: true });
    await pickSessionAndFill();

    const send = screen.getByRole('button', { name: 'Send code' });
    expect(send).toBeEnabled();
    fireEvent.click(send);
    await waitFor(() => expect(requestBookingOtp).toHaveBeenCalledWith('01712345678'));

    const submit = screen.getByRole('button', { name: /Confirm serial — session A/ });
    expect(submit).toBeDisabled();
    fireEvent.change(screen.getByLabelText('6-digit code'), { target: { value: '০০০০০০' } });   // Bangla digits are accepted
    expect(submit).toBeEnabled();
    fireEvent.click(submit);

    await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
    expect(post.mock.calls[0]![1]).toMatchObject({ mobile: '01712345678', otp: '000000' });
  });
});
