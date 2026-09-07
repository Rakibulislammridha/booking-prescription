{{-- §4.12 — when the doctor wrote by hand, the sheets ARE the prescription. One page each, after the structured
     sheet, at the pad's own aspect ratio. Sources are data URIs (PrintImageInliner) so Browsershot needs no
     session and no network to render them. --}}
@if ($handwritingPages !== [])
  @foreach ($handwritingPages as $page)
    <div class="attachment-page">
      <div class="tiny muted en" style="margin-bottom:1mm">{{ $labels->get('handwritten') }} · {{ $patient['name'] ?? '' }} · <span class="code">{{ $rx['verification_code'] ?? '' }}</span> · {{ $loop->iteration }}/{{ count($handwritingPages) }}</div>
      <img class="attachment-img" src="{{ $page }}" alt="">
    </div>
  @endforeach
@endif
