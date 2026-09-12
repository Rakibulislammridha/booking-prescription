{{-- §7.1 vitals, omitted entirely when the compounder recorded nothing or the pad hides them.

     Laid out as a labelled grid rather than the grey run-on sentence this used to be. A doctor reading a sheet is
     not reading the vitals — they are looking for ONE of them, and "BP 145/92 mmHg Pulse 76/min Temp 98.2°F SpO₂
     97% …" makes that a search. Four fixed cells per row, caption above value, so the eye lands on the number it
     came for; seven measurements therefore always fit in exactly two rows.

     The snapshot stores temperature in °C (SCHEMA §3.4, the clinical canonical unit); the sheet prints °F,
     converted HERE at render time, so a prescription issued before the °F change prints correctly too. --}}
@php $v = (array) ($visit['vitals'] ?? []); @endphp
@if ($pad->showVitals() && $v !== [])
  @php
    $cells = [];
    $cell = function (string $key, ?string $value, string $unit = '') use (&$cells, $labels): void {
        if ($value !== null && trim($value) !== '') {
            $cells[] = ['label' => $labels->in($labels->language() === 'bn' ? 'bn' : 'en', $key), 'value' => $value, 'unit' => $unit];
        }
    };

    $bp = ($v['bp_systolic'] ?? null) !== null && ($v['bp_diastolic'] ?? null) !== null ? $v['bp_systolic'].'/'.$v['bp_diastolic'] : null;
    $cell('bp', $bp === null ? null : (string) $bp, 'mmHg');
    $cell('pulse', ($v['pulse_bpm'] ?? null) === null ? null : (string) $v['pulse_bpm'], '/min');
    $cell('temp', ($v['temperature_c'] ?? null) === null || $v['temperature_c'] === '' ? null : \App\Domain\Prescription\Support\Temperature::formatF($v['temperature_c'], $labels->language()));
    $cell('spo2', ($v['spo2_percent'] ?? null) === null ? null : (string) $v['spo2_percent'], '%');
    $cell('weight', ($v['weight_kg'] ?? null) === null ? null : (string) $v['weight_kg'], 'kg');
    $cell('height', ($v['height_cm'] ?? null) === null ? null : (string) $v['height_cm'], 'cm');
    $cell('bmi', ($v['bmi'] ?? null) === null ? null : (string) $v['bmi']);
  @endphp
  @if ($cells !== [])
    <div class="section" data-section="vitals">
      <div class="section-title">{{ $labels->get('vitals') }}</div>
      <div class="vitals-grid">
        @foreach ($cells as $item)
          <div class="vital">
            <span class="vital-k">{{ $item['label'] }}</span>
            <span class="vital-v num">{{ $item['value'] }}@if ($item['unit'] !== '')<span class="vital-u"> {{ $item['unit'] }}</span>@endif</span>
          </div>
        @endforeach
      </div>
    </div>
  @endif
@endif
