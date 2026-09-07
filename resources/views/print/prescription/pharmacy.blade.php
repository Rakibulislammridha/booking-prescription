{{--
  §7.3 pharmacy-friendly view. Deliberately a privacy-reduced document: drug, strength/form and quantity, plus
  just enough identity for the counter to match it to the person standing there (name, age, date, doctor, code).
  NO diagnosis, NO vitals, NO complaints, NO advice — a pharmacy has no business reading them, and a dispensing
  copy that leaks a diagnosis is a real harm in a small town.
--}}
@extends('print.prescription.layout')

@section('sheet')
@include('print.prescription.partials.pharmacy-body')
@endsection
