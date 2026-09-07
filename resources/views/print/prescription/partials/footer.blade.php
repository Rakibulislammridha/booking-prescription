{{-- §7.1 footer: signature · pad footer HTML · QR + verification code + snapshot hash + version chain. --}}
<div class="footer">
  <div class="pad-footer">
    @if ($pad->footerHtml() !== null && ! $o->preprinted)
      <div class="tiny muted">{!! $pad->footerHtml() !!}</div>
    @endif
    @if ($o->watermark !== 'DRAFT')
      <div class="tiny muted">
        <span class="code">{{ $labels->get('verification_code') }}: {{ $rx['verification_code'] ?? '' }}</span>
        @if (! empty($rx['verify_url']))<span class="code"> · {{ $rx['verify_url'] }}</span>@endif
      </div>
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
        <div style="height:12mm"></div>
      @endif
      <div class="sign-line">
        <div class="en" style="font-weight:600">{{ $doctor['name'] ?? '' }}</div>
        @if (! empty($doctor['degrees']))<div class="tiny muted en">{{ $doctor['degrees'] }}</div>@endif
        @if (! empty($doctor['bmdc_reg_no']))<div class="tiny muted code">BMDC {{ $doctor['bmdc_reg_no'] }}</div>@endif
      </div>
    </div>
  @endif

  @include('print.prescription.partials.qr')
</div>
