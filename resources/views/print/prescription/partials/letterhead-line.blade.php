{{--
  One line of the structured letterhead (App\Domain\Prescription\Data\LetterheadLine).

  Every value interpolated into the style attribute has already been through the DTO: the colour is one of three
  hexes matched against /^#[0-9A-Fa-f]{6}$/, the size a float clamped to 0.7–2.0 em, weight/transform/align members
  of fixed lists. The text itself is `strip_tags`ed on the way into the column and escaped again here — the same
  markup is served to the public at /rx/{code}, so a letterhead is never a place HTML can survive.

  @vars line (LetterheadLine), lh (Letterhead), o (RenderOptions)
--}}
@php
  $style = 'color:'.$lh->color($line->color).';font-size:'.$line->size.'em';

  if ($line->weight === 'bold') { $style .= ';font-weight:700'; }
  if ($line->transform === 'uppercase') { $style .= ';text-transform:uppercase'; }
  if ($line->align !== null) { $style .= ';text-align:'.$line->align; }
@endphp
<div class="lh-line" style="{{ $style }}">
  <span class="en">{{ $line->text }}</span>
  @if ($o->bn() && $line->textBn !== null)<span class="lh-bn bn">{{ $line->textBn }}</span>@endif
</div>
