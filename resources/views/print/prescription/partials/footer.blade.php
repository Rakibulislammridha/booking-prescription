{{--
  §7.1 foot of the sheet, in the order a real pad reads: first the row that closes the consultation — verification
  code, the doctor's signature, the QR — then the pad's own footer band, a rule and one to three columns (the
  hospital logo and address, the chamber hours, the number you ring for a serial).

  Like the header this comes from `pad.letterhead` frozen at issue, or from the snapshot-built fallback when the
  doctor never designed one (which has no columns, so nothing but the signature row prints — exactly what a pad
  that has never been designed printed before). `pad.footer_html` is no longer rendered; see header.blade.php.
--}}
@php
  $lh = $pad->letterhead($clinic, $doctor);
  // A preprinted pad already carries the clinic's own band on the paper; printing ours on top of it is the one
  // thing this mode exists to prevent.
  $columns = $o->preprinted ? [] : $lh->columns();
@endphp
<div class="footer">
  <div class="pad-footer">
    @if ($o->watermark !== 'DRAFT')
      {{-- The code, the snapshot hash and WHERE to check them — but not the 50-character URL itself. It wrapped to
           four lines of tiny text in a 30 mm column, nobody has ever typed one, and the QR beside it is the way
           there. A pharmacy without a scanner needs the host and the code, and that is what this prints. --}}
      <div class="tiny muted">
        <span class="code">{{ $labels->get('verification_code') }}: {{ $rx['verification_code'] ?? '' }}</span>
        @if ($sha256 !== '')<span class="code"> · {{ substr($sha256, 0, 8) }}</span>@endif
      </div>
      @php $verifyHost = empty($rx['verify_url']) ? null : parse_url((string) $rx['verify_url'], PHP_URL_HOST); @endphp
      @if (is_string($verifyHost) && $verifyHost !== '')
        <div class="tiny muted code">{{ $verifyHost }}/rx</div>
      @endif
    @endif
    @php $version = (int) ($rx['version'] ?? 1); @endphp
    @if ($version > 1)
      <div class="tiny muted code">v{{ $version }} · supersedes v{{ $version - 1 }}</div>
    @endif
  </div>

  @if ($pad->section('signature'))
    <div class="sign-block">
      @if (! empty($doctor['signature_data_uri']))
        <img class="sign-img" src="{{ $doctor['signature_data_uri'] }}" alt="">
      @else
        <div class="sign-space"></div>
      @endif
      {{-- Degrees and registration number share one line: they are one credential to whoever checks the sheet,
           and two stacked captions here cost the prescription four millimetres of body. --}}
      <div class="sign-line">
        <div class="en" style="font-weight:600">{{ $doctor['name'] ?? '' }}</div>
        @if (! empty($doctor['degrees']) || ! empty($doctor['bmdc_reg_no']))
          <div class="tiny muted">@if (! empty($doctor['degrees']))<span class="en">{{ $doctor['degrees'] }}</span>@endif @if (! empty($doctor['degrees']) && ! empty($doctor['bmdc_reg_no']))·@endif @if (! empty($doctor['bmdc_reg_no']))<span class="code">BMDC {{ $doctor['bmdc_reg_no'] }}</span>@endif</div>
        @endif
      </div>
    </div>
  @endif

  @include('print.prescription.partials.qr')
</div>

@if ($columns !== [])
  @if ($lh->footerRule)
    <hr class="rule" style="border-top-color:{{ $lh->textColor }}">
  @endif
  <div class="pad-band" data-footer-columns="{{ count($columns) }}">
    @foreach ($columns as $column)
      <div class="pad-col" style="text-align:{{ $column->align }}">
        @if ($column->logo && ! empty($clinic['logo_data_uri']))
          <img class="pad-col-logo" src="{{ $clinic['logo_data_uri'] }}" alt="">
        @endif
        @foreach ($column->lines as $line)
          @include('print.prescription.partials.letterhead-line', ['line' => $line, 'lh' => $lh])
        @endforeach
      </div>
    @endforeach
  </div>
@endif
