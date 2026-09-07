// The meter is the clinic's only warning that a Bangla template costs two or three SMS parts, so it must say
// "UCS-2" out loud and must prefer the server's count when one is available.
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SegmentMeter } from '../SegmentMeter';

describe('SegmentMeter', () => {
  it('reports GSM-7 and a single part for a short Latin body', () => {
    render(<SegmentMeter body="Serial A-012 with Dr. Rahman" locale="en" />);

    expect(screen.getByText(/GSM-7/)).toBeTruthy();
    expect(screen.getByTestId('segment-units').textContent).toBe('28');
  });

  it('reports UCS-2 and two parts for a real Bangla alert', () => {
    render(
      <SegmentMeter
        body="সেবা হাসপাতাল: আপনার আগে আর ৩ জন রোগী আছেন। এখনই চেম্বারের সামনে আসুন। সিরিয়াল A-012, ডা. রহমান। ক্রম পরিবর্তন হতে পারে।"
        locale="en"
      />
    );

    expect(screen.getByText(/UCS-2/)).toBeTruthy();
    expect(screen.getByTestId('segment-units').textContent).toBe('121');
  });

  it('prefers the server count so the editor can never disagree with what is billed', () => {
    render(
      <SegmentMeter
        body="anything"
        locale="en"
        server={{ encoding: 'UCS-2', units: 200, segments: 3, remaining: 1, per_segment: 67 }}
      />
    );

    expect(screen.getByTestId('segment-units').textContent).toBe('200');
    expect(screen.getByText(/UCS-2/)).toBeTruthy();
  });
});
