{{-- §7.1 full layout: header → patient-bar → vitals → clinical → body → footer, in the doctor's own section order
     (pad.layout.sections). Handwriting sheets and the annotation diagram follow as their own pages (§4.11–§4.12). --}}
@extends('print.prescription.layout')

@section('sheet')
@include('print.prescription.partials.sheet-body')
@endsection
