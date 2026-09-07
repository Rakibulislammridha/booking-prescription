{{-- The full prescription body (§7.1). Extracted from sheet.blade.php so the public verification page can wrap
     exactly the same markup in its own chrome — a "verified copy" that differed from the paper would be worthless. --}}
@php
  $sections = $pad->orderedSections();
  $leftKeys = ['vitals', 'complaints', 'examination', 'diagnosis'];
  $rightKeys = ['rx', 'investigations', 'advice', 'followup', 'referral'];
  $twoColumn = $pad->columns() === 2;
@endphp
<div class="sheet">
  <div class="sheet-body">
    @include('print.prescription.partials.header')
    @include('print.prescription.partials.patient-bar')

    @if ($twoColumn)
      {{-- The classic Bangladeshi pad: history and findings down the narrow left rail, Rx in the wide right column. --}}
      <div class="body-2col">
        <div class="col-left">
          @foreach ($sections as $section)
            @if (in_array($section, $leftKeys, true))
              @include('print.prescription.partials.section', ['section' => $section])
            @endif
          @endforeach
        </div>
        <div class="col-right">
          @foreach ($sections as $section)
            @if (in_array($section, $rightKeys, true))
              @include('print.prescription.partials.section', ['section' => $section])
            @endif
          @endforeach
        </div>
      </div>
    @else
      @foreach ($sections as $section)
        @if ($section !== 'signature')
          @include('print.prescription.partials.section', ['section' => $section])
        @endif
      @endforeach
    @endif

    @include('print.prescription.partials.drawing')
  </div>

  <div class="sheet-foot">
    @include('print.prescription.partials.footer')
  </div>
</div>

@include('print.prescription.partials.handwriting')
