{{--
  §7.1 Rx table. The brand is the loud line and it is ALWAYS English (§7.2) — a pharmacist reads "Napa 500 mg",
  never a transliteration. The dose line underneath is the patient's line and follows the print language, with
  Bangla digits already frozen into snapshot.items[].display.bn at issue. Both renderings print for `both`.
--}}
@php $showTypedHeading = $handwritingPages !== [] && $items !== []; @endphp
@if ($items !== [])
  <div class="section" data-section="rx">
    <div style="display:flex; align-items:flex-start; gap:2mm">
      <div class="rx-symbol" aria-hidden="true">℞</div>
      <div style="flex:1 1 auto">
        @if ($showTypedHeading)
          <div class="section-title">{{ $labels->get('typed_rx') }}</div>
        @endif
        <table class="items">
          <tbody>
          @foreach ($items as $index => $item)
            @php
              $brand = $item['brand_name'] ?? null;
              $generic = $item['generic_name'] ?? null;
              $headline = $brand !== null && $brand !== '' ? $brand : $generic;
              $display = (array) ($item['display'] ?? []);
              $bn = (array) ($display['bn'] ?? []);
              $en = (array) ($display['en'] ?? []);
              // The dose line must never disappear. Normally it is the bilingual interpretation frozen at issue;
              // an item whose shorthand produced no schedule (a free-text or handwriting-mode line) falls back to
              // the raw dose_schedule rather than printing a drug with no instructions at all.
              $bnLine = trim((string) ($bn['interpretation'] ?? ''));
              $enLine = trim((string) ($en['interpretation'] ?? ''));

              if ($bnLine === '' && $enLine === '') {
                  $enLine = trim((string) ($item['dose_schedule'] ?? ''));
              }
            @endphp
            <tr>
              <td class="idx num">{{ $index + 1 }}.</td>
              <td>
                <div class="drug-line drug">
                  {{ trim($headline.' '.($item['strength'] ?? '')) }}
                  @if (! empty($item['form']))<span class="muted" style="font-weight:400">{{ $item['form'] }}</span>@endif
                </div>
                @if ($pad->flag('generic_names') && $generic !== null && $generic !== '' && $generic !== $headline)
                  <div class="generic drug tiny">{{ $generic }}</div>
                @endif
                @php $bnPrinted = $o->bn() && $bnLine !== ''; @endphp
                @if ($bnPrinted)
                  <div class="dose bn">{{ $bnLine }}</div>
                @endif
                @if ($enLine !== '' && ($o->en() || ! $bnPrinted))
                  <div class="dose en {{ $bnPrinted ? 'tiny muted' : '' }}">{{ $enLine }}</div>
                @endif
                @if ($o->bn() && ! empty($item['instruction_bn']))
                  <div class="instruction bn">{{ $item['instruction_bn'] }}</div>
                @endif
                @if ($o->en() && ! empty($item['instruction']))
                  <div class="instruction en {{ $o->both() && ! empty($item['instruction_bn']) ? 'tiny muted' : '' }}">{{ $item['instruction'] }}</div>
                @endif
                @if ($pad->showDrugInfoUrl() && ! empty($item['info_url']))
                  <div class="info-url tiny code">{{ $labels->get('more_info') }}: {{ $item['info_url'] }}</div>
                @endif
              </td>
              <td class="qty">
                @if ($o->primary() === 'bn' && ! empty($bn['quantity']))
                  <span class="bn">{{ $bn['quantity'] }}</span>
                @elseif (! empty($en['quantity']))
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
    </div>
  </div>
@endif
