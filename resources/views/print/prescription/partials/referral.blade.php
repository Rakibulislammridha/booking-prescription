{{-- §7.1 referral block (doctor / hospital / diagnostic centre) with the referral note. --}}
@if ($referrals !== [])
  <div class="section" data-section="referral">
    <div class="section-title">{{ $labels->get('referral') }}</div>
    @foreach ($referrals as $referral)
      <div>
        <span class="en"><b>{{ $referral['to'] ?? '' }}</b></span>
        @if (! empty($referral['specialty']))<span class="muted en"> · {{ $referral['specialty'] }}</span>@endif
        @if (! empty($referral['is_urgent']))<span class="tiny" style="color:#b91c1c"> · {{ $labels->get('urgent') }}</span>@endif
        @if (! empty($referral['note']))<div class="small">{{ $referral['note'] }}</div>@endif
      </div>
    @endforeach
  </div>
@endif
