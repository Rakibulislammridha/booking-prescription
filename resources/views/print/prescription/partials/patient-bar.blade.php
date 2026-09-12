{{-- §7.1 patient bar, two deliberate rows: WHO this is (name · age · sex), then WHICH record it is (patient code ·
     date · serial · version). Identifiers stay Latin; the phone is already masked in the snapshot and is never
     printed on the sheet at all.

     Weight is NOT here. It used to print twice — once in this bar and again three lines down under VITALS — which
     is how a sheet ends up looking like it was assembled by two people who never met. It belongs with the other
     measurements the compounder took, so vitals is where it prints. --}}
@php
  $version = (int) ($rx['version'] ?? 1);
  $date = $visit['date'] ?? null;
  $sex = $labels->sex($patient['gender'] ?? null);
@endphp
<div class="patient-bar">
  <div class="pb-row">
    <span class="pb-name">{{ $patient['name'] ?? '' }}</span>
    @if (! empty($patient['age_text']))
      <span><span class="pb-k">{{ $labels->get('age') }}</span><span class="pb-v num">{{ $patient['age_text'] }}</span></span>
    @endif
    @if ($sex !== '')
      <span><span class="pb-k">{{ $labels->get('sex') }}</span><span class="pb-v">{{ $sex }}</span></span>
    @endif
  </div>
  <div class="pb-row">
    @if (! empty($patient['patient_code']))
      <span><span class="pb-k">{{ $labels->get('id') }}</span><span class="pb-v code">{{ $patient['patient_code'] }}</span></span>
    @endif
    @if ($date !== null)
      <span><span class="pb-k">{{ $labels->get('date') }}</span><span class="pb-v num">{{ $date }}</span></span>
    @endif
    @if (! empty($visit['serial']))
      <span><span class="pb-k">{{ $labels->get('serial') }}</span><span class="pb-v code">{{ $visit['serial'] }}</span></span>
    @endif
    @if ($version > 1)
      <span><span class="pb-k">{{ $labels->get('version') }}</span><span class="pb-v code">v{{ $version }}</span></span>
    @endif
  </div>
</div>
@if (! empty($snapshot->get('allergies')))
  {{-- Allergies print in a box on every copy: this is the line that stops a pharmacy dispensing the wrong drug. --}}
  <div class="allergy-bar">{{ $labels->get('allergies') }}: <span class="en">{{ implode(', ', array_map('strval', (array) $snapshot->get('allergies'))) }}</span></div>
@endif
<hr class="rule-soft">
