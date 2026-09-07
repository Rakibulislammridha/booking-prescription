{{--
  §7.1 header. Three mutually exclusive states, in priority order:
   1. preprinted_mode — the doctor's pad is ALREADY printed on this paper. Reserve exactly header_height_mm and
      emit nothing inside it. Anything drawn here lands on top of the printed letterhead.
   2. letterhead_enabled + pad.header_html — the designer's own markup (sanitised on save, images inlined at issue).
   3. letterhead_enabled — the generated clinic/doctor block.
  With letterhead off and no preprinted band we print only a rule: the paper already carries whatever it carries.
--}}
@if ($o->preprinted)
  <div class="header-spacer" data-preprinted-header="{{ $pad->headerHeightMm() }}mm" aria-hidden="true"></div>
@elseif ($o->letterhead && $pad->headerHtml() !== null)
  <div class="letterhead-html">{!! $pad->headerHtml() !!}</div>
  <hr class="rule">
@elseif ($o->letterhead)
  <div class="letterhead">
    <div>
      @if (! empty($clinic['logo_data_uri']))
        <img class="letterhead-logo" src="{{ $clinic['logo_data_uri'] }}" alt="">
      @endif
      <div class="clinic-name en">{{ $clinic['name'] ?? '' }}</div>
      @if ($o->bn() && ! empty($clinic['name_bn']))
        <div class="clinic-name bn">{{ $clinic['name_bn'] }}</div>
      @endif
      @php $branch = (array) ($clinic['branch'] ?? []); @endphp
      <div class="small muted">
        {{ $branch['name'] ?? '' }}@if (! empty($branch['address'])) · {{ $branch['address'] }}@endif
      </div>
      @if (! empty($branch['phone']))
        <div class="small muted num">{{ $branch['phone'] }}</div>
      @endif
    </div>
    <div style="text-align: right">
      <div class="doctor-name en">{{ $doctor['name'] ?? '' }}</div>
      @if ($o->bn() && ! empty($doctor['name_bn']))
        <div class="doctor-name bn">{{ $doctor['name_bn'] }}</div>
      @endif
      @if (! empty($doctor['degrees']))
        <div class="small en">{{ $doctor['degrees'] }}</div>
      @endif
      @if ($o->bn() && ! empty($doctor['degrees_bn']))
        <div class="small bn">{{ $doctor['degrees_bn'] }}</div>
      @endif
      @if (! empty($doctor['designation']))
        <div class="small muted en">{{ $doctor['designation'] }}</div>
      @endif
      @if (! empty($doctor['specialties']))
        <div class="small muted en">{{ implode(', ', array_map('strval', (array) $doctor['specialties'])) }}</div>
      @endif
      @if (! empty($doctor['bmdc_reg_no']))
        <div class="small muted code">BMDC {{ $doctor['bmdc_reg_no'] }}</div>
      @endif
    </div>
  </div>
  <hr class="rule">
@else
  <hr class="rule">
@endif
