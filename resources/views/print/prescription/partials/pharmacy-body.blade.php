{{-- The pharmacy dispensing body (§7.3). Extracted so /rx/{code}?layout=pharmacy shows the identical table. --}}
<div class="sheet">
  <div class="sheet-body">
    @if ($o->preprinted)
      <div class="header-spacer" data-preprinted-header="{{ $pad->headerHeightMm() }}mm" aria-hidden="true"></div>
    @endif
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:4mm">
      <div>
        <div class="clinic-name en">{{ $clinic['name'] ?? '' }}</div>
        <div class="small muted en">{{ $labels->get('pharmacy_copy') }}</div>
      </div>
      <div style="text-align:right">
        <div class="en" style="font-weight:700">{{ $doctor['name'] ?? '' }}</div>
        @if (! empty($doctor['bmdc_reg_no']))<div class="tiny muted code">BMDC {{ $doctor['bmdc_reg_no'] }}</div>@endif
      </div>
    </div>
    <hr class="rule">

    <div class="patient-bar">
      <span><b>{{ $patient['name'] ?? '' }}</b></span>
      @if (! empty($patient['age_text']))<span class="muted">{{ $labels->get('age') }}: <span class="num">{{ $patient['age_text'] }}</span></span>@endif
      @php $sex = $labels->sex($patient['gender'] ?? null); @endphp
      @if ($sex !== '')<span class="muted">{{ $labels->get('sex') }}: {{ $sex }}</span>@endif
      @if (! empty($visit['date']))<span class="muted">{{ $labels->get('date') }}: <span class="num">{{ $visit['date'] }}</span></span>@endif
      @if (! empty($rx['verification_code']))<span class="muted code">{{ $rx['verification_code'] }}</span>@endif
    </div>
    <hr class="rule-soft">

    @if (! empty($snapshot->get('allergies')))
      <div class="allergy-bar">{{ $labels->get('allergies') }}: <span class="en">{{ implode(', ', array_map('strval', (array) $snapshot->get('allergies'))) }}</span></div>
    @endif

    <table class="items" data-section="pharmacy-items">
      <thead>
        <tr>
          <td class="idx tiny muted">#</td>
          <td class="tiny muted">{{ $labels->get('drug') }}</td>
          <td class="qty tiny muted">{{ $labels->get('quantity') }}</td>
        </tr>
      </thead>
      <tbody>
      @foreach ($items as $index => $item)
        @php
          $brand = $item['brand_name'] ?? null;
          $headline = $brand !== null && $brand !== '' ? $brand : ($item['generic_name'] ?? '');
          $display = (array) ($item['display'] ?? []);
          $en = (array) ($display['en'] ?? []);
        @endphp
        <tr>
          <td class="idx num">{{ $index + 1 }}.</td>
          <td>
            <div class="drug-line drug">{{ trim($headline.' '.($item['strength'] ?? '')) }}@if (! empty($item['form']))<span class="muted" style="font-weight:400"> {{ $item['form'] }}</span>@endif</div>
            @if ($pad->flag('generic_names') && ! empty($item['generic_name']) && $item['generic_name'] !== $headline)
              <div class="generic drug tiny">{{ $item['generic_name'] }}</div>
            @endif
            <div class="tiny muted">{{ $labels->get('instruction') }}</div>
            {{-- The dispensing copy is read at a counter, often on a phone: here the drug-information address is
                 printed in full, which is exactly where the sheet itself only carries a footnote marker. --}}
            @if ($pad->showDrugInfoUrl() && ! empty($item['info_url']))
              <div class="info-url tiny code">{{ $item['info_url'] }}</div>
            @endif
          </td>
          <td class="qty">
            @if (! empty($en['quantity']))
              <span class="num">{{ $en['quantity'] }}</span>
            @elseif (($item['quantity'] ?? null) !== null)
              <span class="num">{{ $item['quantity'] }} {{ $item['quantity_unit'] ?? '' }}</span>
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>

  <div class="sheet-foot">
    <div class="footer">
      <div class="pad-footer tiny muted">
        <span class="code">{{ $labels->get('verification_code') }}: {{ $rx['verification_code'] ?? '' }}</span>
        @if (! empty($rx['verify_url']))<div class="code">{{ $rx['verify_url'] }}</div>@endif
      </div>
      @include('print.prescription.partials.qr')
    </div>
  </div>
</div>
