{{--
  §7.4 public verification page — GET /rx/{code}, no login, throttled, noindex. It renders the SAME sheet markup
  as the paper and the PDF (partials.sheet-body) so a pharmacist comparing the two sees one document, wrapped in
  chrome that answers the only question the page exists to answer: is this prescription real, and is it current?
  Printing this page prints the sheet alone — the chrome is screen-only.

  @vars everything PrescriptionRenderer::data() supplies, plus $verification
--}}
@extends('print.prescription.layout')

@section('extra-style')
.verify-chrome { width: {{ $pad->paperWidthMm() }}mm; max-width: calc(100% - 16px); margin: 0 auto 6mm; font-family: 'Inter', 'Noto Sans Bengali', system-ui, sans-serif; }
.verify-banner { border-radius: 6px; padding: 10px 14px; display: flex; flex-wrap: wrap; gap: 4px 14px; align-items: baseline; }
.verify-banner b { font-size: 15px; }
.verify-valid { background: #ecfdf5; border: 1px solid #10b981; color: #065f46; }
.verify-superseded { background: #fffbeb; border: 1px solid #f59e0b; color: #92400e; }
.verify-voided { background: #fef2f2; border: 1px solid #ef4444; color: #991b1b; }
.verify-meta { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-top: 8px; font-size: 12px; color: #475569; }
.verify-meta .code { font-family: 'Inter', monospace; }
.verify-actions { margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; }
.verify-actions a { display: inline-block; padding: 7px 14px; border-radius: 6px; border: 1px solid #0f766e; color: #0f766e; text-decoration: none; font-size: 13px; }
.verify-actions a.primary { background: #0f766e; color: #fff; }
.verify-chain { margin-top: 8px; font-size: 12px; color: #475569; }
@media print { .verify-chrome { display: none !important; } }
@endsection

@section('sheet')
@php $status = (string) ($verification['status'] ?? 'valid'); $banner = (array) ($verification['banner'] ?? []); @endphp
<div class="verify-chrome">
  <div class="verify-banner verify-{{ $status }}">
    @switch($status)
      @case('voided')
        <b>Voided prescription · বাতিল করা প্রেসক্রিপশন</b>
        <span>This prescription was cancelled by the issuing doctor and must not be dispensed.</span>
        @break
      @case('superseded')
        <b>Superseded · সংশোধিত</b>
        <span>Replaced by version {{ $banner['by_version'] ?? '' }}@if (! empty($banner['by_date'])) on {{ \Illuminate\Support\Carbon::parse($banner['by_date'])->timezone(config('app.timezone'))->toFormattedDateString() }}@endif. Dispense the latest version.</span>
        @break
      @default
        <b>Valid prescription · বৈধ প্রেসক্রিপশন</b>
        <span>This is a verified copy of the prescription issued below.</span>
    @endswitch
  </div>
  <div class="verify-meta">
    <span>{{ $clinic['name'] ?? '' }}</span>
    <span>{{ $doctor['name'] ?? '' }}@if (! empty($doctor['bmdc_reg_no'])) · BMDC {{ $doctor['bmdc_reg_no'] }}@endif</span>
    @if (! empty($verification['issued_at']))<span>Issued {{ \Illuminate\Support\Carbon::parse($verification['issued_at'])->timezone(config('app.timezone'))->toDayDateTimeString() }}</span>@endif
    <span>Version {{ $verification['version'] ?? 1 }}</span>
    <span class="code">{{ $verification['verification_code'] ?? '' }}</span>
    @if (! empty($sha256))<span class="code" title="snapshot sha256">{{ substr((string) $sha256, 0, 16) }}</span>@endif
  </div>
  <div class="verify-actions">
    @if (! empty($verification['pdf_available']))
      <a class="primary" href="{{ route('site.prescription.verify.pdf', ['code' => $verification['verification_code']]) }}">Download PDF</a>
    @endif
    <a href="{{ route('site.prescription.verify', ['code' => $verification['verification_code']]) }}?layout=pharmacy">Pharmacy view</a>
  </div>
  @if (count((array) ($verification['versions'] ?? [])) > 1)
    <div class="verify-chain">
      Versions:
      @foreach ((array) $verification['versions'] as $version)
        <a href="{{ route('site.prescription.verify', ['code' => $version['verification_code']]) }}" style="color:#0f766e">v{{ $version['version'] }}</a>@if (! $loop->last) · @endif
      @endforeach
    </div>
  @endif
</div>

{{-- ?layout=pharmacy gives a counter the dispensing table without the clinical detail (§7.3). --}}
@include($o->isPharmacy() ? 'print.prescription.partials.pharmacy-body' : 'print.prescription.partials.sheet-body')
@endsection
