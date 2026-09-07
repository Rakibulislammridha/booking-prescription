{{-- One body section, selected by its pad.layout.sections key so the doctor's own order is what prints. --}}
@switch($section)
  @case('vitals')
    @include('print.prescription.partials.vitals')
    @break
  @case('complaints')
  @case('examination')
  @case('diagnosis')
    @include('print.prescription.partials.clinical', ['block' => $section])
    @break
  @case('rx')
    @include('print.prescription.partials.rx-items')
    @break
  @case('investigations')
    @include('print.prescription.partials.investigations')
    @break
  @case('advice')
    @include('print.prescription.partials.advice')
    @break
  @case('followup')
    @include('print.prescription.partials.follow-up')
    @break
  @case('referral')
    @include('print.prescription.partials.referral')
    @break
@endswitch
