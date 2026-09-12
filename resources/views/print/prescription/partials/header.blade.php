{{--
  §7.1 header — the structured letterhead (App\Domain\Prescription\Data\Letterhead), three mutually exclusive states:

   1. preprinted_mode — the doctor's pad is ALREADY printed on this paper. Reserve exactly header_height_mm and emit
      nothing inside it. Anything drawn here lands on top of the printed letterhead.
   2. letterhead_enabled — `pad.letterhead` as frozen at issue, or, when the doctor never designed one, the fallback
      that `Letterhead::fromSnapshot()` builds out of this same snapshot's clinic and doctor blocks. Either way the
      header is DATA: lines with a palette colour, a weight, an em size and an optional Bangla twin.
   3. letterhead off, no preprinted band — only a rule; the paper already carries whatever it carries.

  `pad.header_html` is no longer rendered anywhere. The column survives on doctor_pad_settings (and inside every
  pad_snapshot ever frozen) for one release so nothing is lost, but free HTML is not how a letterhead is described.
--}}
@if ($o->preprinted)
  <div class="header-spacer" data-preprinted-header="{{ $pad->headerHeightMm() }}mm" aria-hidden="true"></div>
@elseif ($o->letterhead)
  @php
    $lh = $pad->letterhead($clinic, $doctor);
    // A designed letterhead places the clinic mark itself, through the footer column whose `logo` flag is set.
    // Only the generated fallback puts it in the header — which is where a pad that was never designed had it.
    $logo = $lh->generated && ! empty($clinic['logo_data_uri']) ? $clinic['logo_data_uri'] : null;
  @endphp
  <div class="letterhead letterhead-{{ $lh->headerAlign }}">
    @if ($lh->headerAlign === 'split')
      {{-- split = doctor block left, clinic block right; a line carrying align="right" belongs to the right. --}}
      <div class="lh-block lh-block-left">
        @foreach ($lh->headerSide('left') as $line)
          @include('print.prescription.partials.letterhead-line', ['line' => $line, 'lh' => $lh])
        @endforeach
      </div>
      <div class="lh-block lh-block-right">
        @if ($logo !== null)<img class="letterhead-logo" src="{{ $logo }}" alt="">@endif
        @foreach ($lh->headerSide('right') as $line)
          @include('print.prescription.partials.letterhead-line', ['line' => $line, 'lh' => $lh])
        @endforeach
      </div>
    @else
      <div class="lh-block">
        @if ($logo !== null)<img class="letterhead-logo" src="{{ $logo }}" alt="">@endif
        @foreach ($lh->headerLines as $line)
          @include('print.prescription.partials.letterhead-line', ['line' => $line, 'lh' => $lh])
        @endforeach
      </div>
    @endif
  </div>
  @if ($lh->headerRule)
    <hr class="rule" style="border-top-color:{{ $lh->textColor }}">
  @endif
@else
  <hr class="rule">
@endif
