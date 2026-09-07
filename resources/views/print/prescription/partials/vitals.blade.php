{{-- §7.1 vitals: one line, omitted entirely when the compounder recorded nothing or the pad hides them. --}}
@php $v = (array) ($visit['vitals'] ?? []); @endphp
@if ($pad->showVitals() && $v !== [])
  @php
    $parts = [];
    if (($v['bp_systolic'] ?? null) !== null && ($v['bp_diastolic'] ?? null) !== null) { $parts[] = $labels->get('bp').' '.$v['bp_systolic'].'/'.$v['bp_diastolic'].' mmHg'; }
    if (($v['pulse_bpm'] ?? null) !== null) { $parts[] = $labels->get('pulse').' '.$v['pulse_bpm'].'/min'; }
    if (($v['temperature_c'] ?? null) !== null) { $parts[] = $labels->get('temp').' '.$v['temperature_c'].'°C'; }
    if (($v['spo2_percent'] ?? null) !== null) { $parts[] = $labels->get('spo2').' '.$v['spo2_percent'].'%'; }
    if (($v['weight_kg'] ?? null) !== null) { $parts[] = $labels->get('weight').' '.$v['weight_kg'].' kg'; }
    if (($v['height_cm'] ?? null) !== null) { $parts[] = $labels->get('height').' '.$v['height_cm'].' cm'; }
    if (($v['bmi'] ?? null) !== null) { $parts[] = $labels->get('bmi').' '.$v['bmi']; }
  @endphp
  @if ($parts !== [])
    <div class="section" data-section="vitals">
      <div class="section-title">{{ $labels->get('vitals') }}</div>
      <div class="kv small">@foreach ($parts as $part)<span class="num">{{ $part }}</span>@endforeach</div>
    </div>
  @endif
@endif
