{{-- §7.1 advice bullets in the print language. The Bangla text is the patient's line; when only English exists
     it still prints (an empty advice block would be worse than a mixed-language one). --}}
@if ($advice !== [])
  <div class="section" data-section="advice">
    <div class="section-title">{{ $labels->get('advice') }}</div>
    <ul class="bullets">
      @foreach ($advice as $line)
        <li>
          @if ($o->bn() && ! empty($line['text_bn']))
            <span class="bn">{{ $line['text_bn'] }}</span>
            @if ($o->both() && ! empty($line['text']))<div class="tiny muted en">{{ $line['text'] }}</div>@endif
          @else
            <span class="en">{{ $line['text'] ?? $line['text_bn'] ?? '' }}</span>
          @endif
        </li>
      @endforeach
    </ul>
  </div>
@endif
