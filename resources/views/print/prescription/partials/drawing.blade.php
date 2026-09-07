{{-- §4.11 — the annotation diagram, redrawn as inline SVG from drawing_json so it prints as vector at any paper
     size. The PNG data URI is only the fallback for a row saved before the JSON existed. --}}
@if ($drawingSvg !== null || $drawingImage !== null)
  <div class="section drawing" data-section="drawing">
    <div class="section-title">{{ $labels->get('diagram') }}</div>
    <div class="drawing-frame">
      @if ($drawingSvg !== null)
        {!! $drawingSvg !!}
      @else
        <img class="attachment-img" src="{{ $drawingImage }}" alt="">
      @endif
    </div>
  </div>
@endif
