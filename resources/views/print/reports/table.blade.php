{{-- One printed report table (BRIEF §5.L "export to … PDF"). The fonts are INLINED by PrintFonts: Browsershot
     renders this as an HTML string with no base URL, so a linked /fonts/*.woff2 would silently not load and the
     Bangla doctor names would come out as tofu boxes. Same faces, same @font-face block and same
     `font-render-hinting=none` as the prescription sheet, so a report and a prescription print identically. --}}
<title>{{ $table->title }}</title>
<style>
{!! $fontCss !!}
@page { size: A4 landscape; margin: 10mm 10mm 12mm; }
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #fff; }
body {
  font-family: 'Inter', 'Noto Sans Bengali', system-ui, sans-serif;
  font-size: 9pt; line-height: 1.45; color: #111827;
  -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.head { border-bottom: 1px solid #111827; padding-bottom: 3mm; margin-bottom: 3mm; }
.clinic { font-size: 9pt; color: #4b5563; letter-spacing: .04em; text-transform: uppercase; }
h1 { font-size: 15pt; margin: 1mm 0 0; }
.sub { color: #4b5563; font-size: 9pt; margin-top: 1mm; }
table { width: 100%; border-collapse: collapse; }
thead { display: table-header-group; }
th, td { padding: 1.4mm 2mm; text-align: left; vertical-align: top; }
thead th { border-bottom: 1px solid #111827; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; }
tbody tr { page-break-inside: avoid; }
tbody td { border-bottom: 1px dotted #d1d5db; }
tbody tr:nth-child(even) td { background: #f9fafb; }
.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.notes { margin-top: 4mm; padding-top: 2mm; border-top: 1px solid #d1d5db; color: #4b5563; font-size: 8pt; }
.notes li { margin-bottom: .8mm; }
.warn { margin-top: 3mm; padding: 2mm; border: 1px dashed #b45309; color: #b45309; font-size: 8.5pt; }
.foot { margin-top: 4mm; display: flex; justify-content: space-between; color: #6b7280; font-size: 8pt; }
.bn { font-family: 'Noto Sans Bengali', 'Inter', sans-serif; }
@media screen { body { background: #eef1f5; padding: 6mm; } }
</style>

<div class="head">
  @if ($clinic !== '')<div class="clinic bn">{{ $clinic }}</div>@endif
  <h1 class="bn">{{ $table->title }}</h1>
  <div class="sub bn">{{ $table->subtitle }}</div>
</div>

<table>
  <thead>
    <tr>
      @foreach ($table->columns as $i => $column)
        <th class="{{ $table->alignFor($i) === 'right' ? 'num' : '' }}">{{ $column }}</th>
      @endforeach
    </tr>
  </thead>
  <tbody>
    @forelse ($rows as $row)
      <tr>
        @foreach ($row as $i => $cell)
          <td class="bn {{ $table->alignFor($i) === 'right' ? 'num' : '' }}">{{ $cell === null ? '—' : $cell }}</td>
        @endforeach
      </tr>
    @empty
      <tr><td colspan="{{ max(1, count($table->columns)) }}" style="text-align:center;padding:6mm 0;color:#6b7280">—</td></tr>
    @endforelse
  </tbody>
</table>

@if ($truncated)
  <div class="warn">{{ __('reports.export.truncated', ['rows' => number_format($maxRows)]) }}</div>
@endif

@if (count($table->notes) > 0)
  <div class="notes">
    <ul>
      @foreach ($table->notes as $note)
        <li class="bn">{{ $note }}</li>
      @endforeach
    </ul>
  </div>
@endif

<div class="foot">
  <span class="bn">{{ $clinic }}</span>
  <span>{{ $printedAt }}</span>
</div>
