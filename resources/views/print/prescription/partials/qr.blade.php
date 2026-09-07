{{-- §7.4 QR block. The SVG data URI was built once at issue and frozen into snapshot.qr — printing calls no
     library and reaches no service. pad.show_qr=false hides the image; the code and hash still print, because
     they are what a pharmacy phones the clinic about. --}}
@php
  // A draft preview has no verification code yet, so its QR would resolve to a 404. Print the word instead of a
  // code that looks scannable — a preview that can be mistaken for the real sheet is the failure to avoid.
  $isDraft = $o->watermark === 'DRAFT';
@endphp
<div class="qr-block">
  @if (! $isDraft && $pad->showQr() && ! empty($qr['svg_data_uri']))
    <img src="{{ $qr['svg_data_uri'] }}" alt="{{ $labels->in('en', 'verify_hint') }}">
  @endif
  <div class="tiny muted code" style="margin-top:.6mm">{{ $isDraft ? 'DRAFT' : ($rx['verification_code'] ?? '') }}</div>
  @if (! $isDraft && $sha256 !== '')
    <div class="tiny muted code">{{ substr($sha256, 0, 8) }}</div>
  @endif
</div>
