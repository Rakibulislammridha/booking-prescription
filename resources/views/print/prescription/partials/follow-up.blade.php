{{-- §7.1 follow-up: the date plus the human label ("৭ দিন পর"), both frozen at issue. --}}
@if (! empty($followUp['on']))
  <div class="section" data-section="followup">
    <div class="section-title">{{ $labels->get('follow_up') }}</div>
    <div>
      <span class="num">{{ $followUp['on'] }}</span>
      @php $label = (array) ($followUp['label'] ?? []); @endphp
      @if (! empty($label[$o->primary()]))
        <span class="{{ $o->primary() === 'bn' ? 'bn' : 'en' }}"> · {{ $label[$o->primary()] }}</span>
      @endif
      @if (! empty($followUp['note']))<div class="small">{{ $followUp['note'] }}</div>@endif
    </div>
  </div>
@endif
