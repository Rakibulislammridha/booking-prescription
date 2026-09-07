import { describe, expect, it } from 'vitest';
import { fillTemplate, queueUrl, renderSlip, type SlipLabels } from '../TokenSlip';
import type { PrintTemplate } from '@shared/offline';

const labels: SlipLabels = { ahead: 'Ahead', eta: 'Approx', fee: 'Fee', paid: 'paid', due: 'due', receipt: 'Receipt', offline: 'issued offline', footer: 'Get well soon' };
const template = (id: PrintTemplate['id']): PrintTemplate => ({ id, version: 1, css: `@page { size: ${id}mm auto }`, html: '<div class="slip" lang="{{lang}}"><div class="doctor">{{doctor}}</div><div class="code">{{code}}</div><div class="patient">{{patient}}</div><div class="meta">{{ahead_label}} {{eta_label}}</div><div class="fee">{{fee_label}}</div><div class="receipt">{{receipt_label}}</div><div class="qr">{{qr}}</div><div class="url">{{queue_url}}</div><div class="offline">{{offline_marker}}</div><div class="foot">{{footer}}</div></div>' });

describe('token slip (OFFLINE §10)', () => {
  it.each(['58', '80', 'a5'] as const)('renders the %s format with a Bangla name and a local id', (id) => {
    const html = renderSlip(template(id), {
      serialPublicId: 'local:01J8ZK4V2Q3W5X6Y7Z8A9B0C1D', displayCode: 'A-042', doctorName: 'Dr. Rahman', doctorNameBn: 'ডা. রহমান', sessionCode: 'A', sessionLabel: 'Morning (A)',
      date: '2026-09-06', plannedStartAt: '2026-09-06T03:00:00Z', patientName: 'রহিমা বেগম', ahead: 12, eta: null, feePaisa: 50000, paymentStatus: 'paid', receiptNo: 'D2-000123', doctorSlug: 'dr-rahman',
      origin: 'https://clinic.example', offline: true,
    }, 'bn', labels);
    expect(html).toContain(`@page { size: ${id}mm auto }`);
    expect(html).toContain('ডা. রহমান');
    expect(html).toContain('রহিমা বেগম');
    expect(html).toContain('<div class="code">A-০৪২</div>');
    expect(html).toContain('Ahead: ১২');
    expect(html).toContain('Fee: ৳৫০০.০০ · paid');
    expect(html).toContain('Receipt: D২-০০০১২৩');
    expect(html).toContain('issued offline');
    expect(html).toContain('<svg');
    expect(html).toContain('https://clinic.example/q/dr-rahman/today?s=local%3A01J8ZK4V2Q3W5X6Y7Z8A9B0C1D');
    expect(html).toContain('lang="bn"');
    expect(html).toMatch(/^<!doctype html>/);
  });

  it('builds the queue URL and fills placeholders safely', () => {
    expect(queueUrl('https://x.test/', 'dr-a', 'SER1')).toBe('https://x.test/q/dr-a/today?s=SER1');
    expect(fillTemplate('<b>{{a}}</b>{{missing}}', { a: '1' })).toBe('<b>1</b>');
    const html = renderSlip(template('58'), { serialPublicId: 's', displayCode: 'A-001', doctorName: '<script>', doctorNameBn: null, sessionCode: 'A', sessionLabel: 'A', date: '2026-01-01', patientName: 'X & Y', doctorSlug: 'd', origin: 'http://o', offline: false }, 'en', labels);
    expect(html).toContain('&lt;script&gt;');
    expect(html).toContain('X &amp; Y');
    expect(html).not.toContain('issued offline');
    expect(html).toContain('Get well soon');
  });
});
