{{-- §7.1 investigations with the clinic's own prices (pad flag investigation_prices) and any external-centre
     referral line. Prices are paisa in the snapshot — never re-read from the clinic catalog at print time. --}}
@if ($investigations !== [])
  @php $showPrices = $pad->flag('investigation_prices'); $total = (int) ($snapshot->get('investigations_total_paisa') ?? 0); @endphp
  <div class="section" data-section="investigations">
    <div class="section-title">{{ $labels->get('investigations') }}</div>
    <table class="tests">
      <tbody>
      @foreach ($investigations as $index => $test)
        <tr>
          <td style="width:7mm; text-align:right; padding-right:2mm" class="muted num">{{ $index + 1 }}.</td>
          <td>
            <span class="{{ $o->primary() === 'bn' && ! empty($test['name_bn']) ? 'bn' : 'en' }}">{{ $o->primary() === 'bn' && ! empty($test['name_bn']) ? $test['name_bn'] : ($test['name'] ?? '') }}</span>
            @if (! empty($test['is_urgent']))<span class="tiny" style="color:#b91c1c"> · {{ $labels->get('urgent') }}</span>@endif
            @if (! empty($test['external_centre']))<div class="tiny muted en">→ {{ $test['external_centre'] }}@if (! empty($test['referral_note'])) — {{ $test['referral_note'] }}@endif</div>@endif
          </td>
          @if ($showPrices)
            <td class="price num">@if (($test['price_paisa'] ?? null) !== null)৳{{ number_format(((int) $test['price_paisa']) / 100, 2) }}@endif</td>
          @endif
        </tr>
      @endforeach
      @if ($showPrices && $total > 0)
        <tr class="total-row">
          <td></td>
          <td class="en">{{ $labels->get('total') }}</td>
          <td class="price num">৳{{ number_format($total / 100, 2) }}</td>
        </tr>
      @endif
      </tbody>
    </table>
  </div>
@endif
