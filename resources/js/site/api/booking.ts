// Booking site XHR (CONVENTIONS §7.2): the availability calendar (api.scheduling.availability) and the booking OTP —
// the latter only requested by a page whose `otp_required` prop is true (tenant setting `kiosk.otp_required`, default off).
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { AvailabilityDay } from '@shared/types/models';

export interface AvailabilityResponse {
  doctor: { public_id: string; slug: string; name: string; name_bn: string | null };
  branch: { public_id: string; slug: string; name: string };
  days: AvailabilityDay[];
}

export async function fetchAvailability(slug: string, from: string, to: string, branch?: string | null): Promise<AvailabilityResponse> {
  const { data } = await http.get<AvailabilityResponse>(route('api.scheduling.availability', { slug }), { params: { from, to, ...(branch ? { branch } : {}) } });
  return data;
}

export async function requestBookingOtp(mobile: string): Promise<{ sent: boolean; resend_in: number; ttl: number }> {
  const { data } = await http.post<{ sent: boolean; resend_in: number; ttl: number }>(route('site.booking.otp'), { mobile });
  return data;
}
