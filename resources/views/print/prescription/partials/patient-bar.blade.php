{{-- §7.1 patient bar: name · age/sex · code · date · serial · version. Identifiers stay Latin; the phone is already
     masked in the snapshot and is never printed on the sheet at all. --}}
@php
  $version = (int) ($rx['version'] ?? 1);
  $date = $visit['date'] ?? null;
@endphp
<div class="patient-bar">
  <span><b>{{ $patient['name'] ?? '' }}</b></span>
  @if (! empty($patient['age_text']))<span class="muted">{{ $labels->get('age') }}: <span class="num">{{ $patient['age_text'] }}</span></span>@endif
  @if (! empty($patient['gender']))<span class="muted">{{ $labels->get('sex') }}: <span class="en">{{ strtoupper((string) $patient['gender']) }}</span></span>@endif
  @if (! empty($patient['weight_kg']))<span class="muted">{{ $labels->get('weight') }}: <span class="num">{{ $patient['weight_kg'] }} kg</span></span>@endif
  @if (! empty($patient['patient_code']))<span class="muted">{{ $labels->get('id') }}: <span class="code">{{ $patient['patient_code'] }}</span></span>@endif
  @if ($date !== null)<span class="muted">{{ $labels->get('date') }}: <span class="num">{{ $date }}</span></span>@endif
  @if (! empty($visit['serial']))<span class="muted">{{ $labels->get('serial') }}: <span class="code">{{ $visit['serial'] }}</span></span>@endif
  @if ($version > 1)<span class="muted code">v{{ $version }}</span>@endif
</div>
@if (! empty($snapshot->get('allergies')))
  {{-- Allergies print in a box on every copy: this is the line that stops a pharmacy dispensing the wrong drug. --}}
  <div class="allergy-bar">{{ $labels->get('allergies') }}: <span class="en">{{ implode(', ', array_map('strval', (array) $snapshot->get('allergies'))) }}</span></div>
@endif
<hr class="rule-soft">
