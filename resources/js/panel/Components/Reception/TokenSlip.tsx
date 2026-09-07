// Token slip printing (docs/OFFLINE.md §10): the cached template (58 / 80 mm thermal or A5, HTML + CSS with
// `{{placeholder}}` slots) is filled locally — offline too — rendered into a hidden iframe (srcdoc), fonts awaited,
// then printed. Bangla renders through the precached Noto Sans Bengali. Reprint is always allowed and logged by
// the caller (`print_token`). `renderSlip()` is the pure part, unit-tested.
import { useEffect, useRef } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { QRCodeSVG } from 'qrcode.react';
import { formatBn } from '@shared/format/number';
import { formatBdt } from '@shared/format/money';
import { formatDateDhaka, formatTimeDhaka } from '@shared/format/date';
import type { PrintTemplate } from '@shared/offline';
import type { Locale } from '@shared/types/shared-props';

export interface SlipData {
  serialPublicId: string;            // server id or local:<clientEventId>
  displayCode: string;
  doctorName: string;
  doctorNameBn: string | null;
  sessionCode: string;
  sessionLabel: string;
  date: string;                      // YYYY-MM-DD
  plannedStartAt?: string | null;
  patientName: string;
  ahead?: number | null;
  eta?: string | null;
  feePaisa?: number | null;
  paymentStatus?: string | null;
  receiptNo?: string | null;
  doctorSlug: string;
  origin: string;                    // window.location.origin
  offline: boolean;
  footer?: string;
}

export interface SlipLabels { ahead: string; eta: string; fee: string; paid: string; due: string; receipt: string; offline: string; footer: string }

function escape(text: string): string {
  return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c);
}

/** The public queue URL the QR encodes (REALTIME §5.3): `/q/{doctor-slug}/today?s={serial id or local id}`. */
export function queueUrl(origin: string, doctorSlug: string, serialPublicId: string): string {
  return `${origin.replace(/\/$/, '')}/q/${doctorSlug}/today?s=${encodeURIComponent(serialPublicId)}`;
}

export function fillTemplate(html: string, values: Record<string, string>): string {
  return html.replace(/\{\{(\w+)\}\}/g, (_, key: string) => values[key] ?? '');
}

/** Pure: template + data → a complete printable HTML document. */
export function renderSlip(template: PrintTemplate, data: SlipData, locale: Locale, labels: SlipLabels): string {
  const url = queueUrl(data.origin, data.doctorSlug, data.serialPublicId);
  const qr = renderToStaticMarkup(<QRCodeSVG value={url} size={106} level="M" />);
  const fee = data.feePaisa === null || data.feePaisa === undefined ? '' : `${labels.fee}: ${formatBdt(data.feePaisa, locale)} · ${data.paymentStatus === 'paid' ? labels.paid : labels.due}`;
  const values: Record<string, string> = {
    lang: locale,
    doctor: escape(locale === 'bn' && data.doctorNameBn ? data.doctorNameBn : data.doctorName),
    session_label: escape(data.sessionLabel),
    date: escape(formatDateDhaka(data.date, locale) + (data.plannedStartAt ? ` · ${formatTimeDhaka(data.plannedStartAt, locale)}` : '')),
    code: escape(formatBn(data.displayCode, locale)),
    patient: escape(data.patientName),
    ahead_label: data.ahead === null || data.ahead === undefined ? '' : escape(`${labels.ahead}: ${formatBn(data.ahead, locale)}`),
    eta_label: data.eta ? escape(`${labels.eta}: ${formatTimeDhaka(data.eta, locale)}`) : '',
    fee_label: escape(fee),
    receipt_label: data.receiptNo ? escape(`${labels.receipt}: ${formatBn(data.receiptNo, locale)}`) : '',
    qr,
    queue_url: escape(url),
    offline_marker: data.offline ? escape(labels.offline) : '',
    footer: escape(data.footer ?? labels.footer),
  };
  const body = fillTemplate(template.html, values);
  return `<!doctype html><html lang="${locale}"><head><meta charset="utf-8"><title>${escape(data.displayCode)}</title><style>${template.css}</style></head><body>${body}</body></html>`;
}

export interface TokenSlipProps {
  template: PrintTemplate | null;
  data: SlipData | null;
  locale: Locale;
  labels: SlipLabels;
  /** Increment to print again (reprint). */
  printKey: number;
  onPrinted?: () => void;
}

/** Mounts a hidden iframe, fills it, waits for fonts, prints. */
export function TokenSlip({ template, data, locale, labels, printKey, onPrinted }: TokenSlipProps) {
  const frame = useRef<HTMLIFrameElement | null>(null);

  useEffect(() => {
    if (!template || !data || printKey === 0) return undefined;
    const el = frame.current;
    if (!el) return undefined;
    const html = renderSlip(template, data, locale, labels);
    let cancelled = false;
    const onLoad = (): void => {
      const win = el.contentWindow;
      if (!win || cancelled) return;
      const ready = (win.document as Document & { fonts?: { ready: Promise<unknown> } }).fonts?.ready ?? Promise.resolve();
      void ready.then(() => { if (!cancelled) { win.focus(); win.print(); onPrinted?.(); } });
    };
    el.addEventListener('load', onLoad);
    el.srcdoc = html;
    return () => { cancelled = true; el.removeEventListener('load', onLoad); };
  }, [template, data, locale, labels, printKey, onPrinted]);

  return <iframe ref={frame} title="token-slip" aria-hidden="true" tabIndex={-1} style={{ position: 'fixed', width: 0, height: 0, border: 0, opacity: 0, pointerEvents: 'none' }} />;
}
