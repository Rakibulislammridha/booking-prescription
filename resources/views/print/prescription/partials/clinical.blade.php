{{-- §7.1 clinical block. One key per call ($block) so pad.layout.sections can order C/C, O/E and Dx freely.
     ICD-10 codes print only when the pad flag says so — many doctors want the title, not the code. --}}
@switch($block)
  @case('complaints')
    @php $complaints = array_values((array) ($visit['chief_complaints'] ?? [])); @endphp
    @if ($complaints !== [])
      <div class="section" data-section="complaints">
        <div class="section-title">{{ $labels->get('complaints') }}</div>
        <ul class="bullets">
          @foreach ($complaints as $c)
            <li>
              <span class="{{ $o->primary() === 'bn' && ! empty($c['text_bn']) ? 'bn' : 'en' }}">{{ $o->primary() === 'bn' && ! empty($c['text_bn']) ? $c['text_bn'] : ($c['text'] ?? '') }}</span>
              @php $duration = (array) ($c['duration_label'] ?? []); $durationText = $duration[$o->primary()] ?? ($c['duration'] ?? null); @endphp
              @if (! empty($durationText)) — <span class="{{ $o->primary() === 'bn' ? 'bn' : 'num' }}">{{ $durationText }}</span>@endif
            </li>
          @endforeach
        </ul>
      </div>
    @endif
    @break

  @case('examination')
    @if (! empty($visit['examination_findings']))
      <div class="section" data-section="examination">
        <div class="section-title">{{ $labels->get('examination') }}</div>
        <div>{{ $visit['examination_findings'] }}</div>
      </div>
    @endif
    @break

  @case('diagnosis')
    @php $diagnoses = array_values((array) ($visit['diagnoses'] ?? [])); @endphp
    @if ($diagnoses !== [])
      <div class="section" data-section="diagnosis">
        <div class="section-title">{{ $labels->get('diagnosis') }}</div>
        <ul class="bullets">
          @foreach ($diagnoses as $d)
            <li>
              <span class="en">{{ $d['title'] ?? '' }}</span>
              @if ($pad->flag('icd_codes') && ! empty($d['icd10_code']))<span class="muted code tiny"> ({{ $d['icd10_code'] }})</span>@endif
              @if (($d['kind'] ?? null) === 'provisional')<span class="muted tiny en"> · provisional</span>@endif
            </li>
          @endforeach
        </ul>
      </div>
    @endif
    @break
@endswitch
