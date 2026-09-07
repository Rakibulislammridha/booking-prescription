// Booking module XHR for the desk (CONVENTIONS §7.2): the one-click counter booking dialog → POST /panel/reception/bookings.
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { CounterBookingBody, CounterBookingResponse } from '@shared/types/models';

export async function storeCounterBooking(body: CounterBookingBody): Promise<CounterBookingResponse> {
  const { data } = await http.post<CounterBookingResponse>(route('panel.reception.bookings.store'), body);
  return data;
}
