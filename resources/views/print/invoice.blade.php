{{-- Invoice (BRIEF §5.I). Rendered from the STORED invoice rows — the frozen totals and the frozen line items —
     never from a live recomputation, so a reprint six months later is byte-identical to the paper the patient
     was handed. A4 by default; `?paper=80|58` switches to a thermal roll (the same widths the token slip uses).
     Bangla font stack matches the rest of the print pipeline: system Noto Sans Bengali first, then the
     committed woff2, so Chrome shapes conjuncts correctly with or without a network. --}}
<title>{{ $invoice['number'] }}</title>
<style>
@font-face { font-family: 'Noto Sans Bengali'; font-style: normal; font-weight: 400; font-display: block;
  src: local('Noto Sans Bengali'), url('{{ asset('fonts/noto-sans-bengali/noto-sans-bengali-bengali-400-normal.woff2') }}') format('woff2');
  unicode-range: U+0964-0965, U+0980-09FF, U+200C-200D, U+20B9, U+25CC; }
@font-face { font-family: 'Noto Sans Bengali'; font-style: normal; font-weight: 700; font-display: block;
  src: local('Noto Sans Bengali'), url('{{ asset('fonts/noto-sans-bengali/noto-sans-bengali-bengali-700-normal.woff2') }}') format('woff2');
  unicode-range: U+0964-0965, U+0980-09FF, U+200C-200D, U+20B9, U+25CC; }
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 400; font-display: block;
  src: local('Inter'), url('{{ asset('fonts/inter/inter-latin-400-normal.woff2') }}') format('woff2'); }
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 700; font-display: block;
  src: local('Inter'), url('{{ asset('fonts/inter/inter-latin-700-normal.woff2') }}') format('woff2'); }

@if ($thermal)
@page { size: {{ $widthMm }}mm auto; margin: {{ $widthMm == 58 ? '2mm' : '3mm' }}; }
@else
@page { size: A4 portrait; margin: 14mm 12mm; }
@endif

*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #fff; }
body {
  font-family: 'Inter', 'Noto Sans Bengali', system-ui, sans-serif;
  font-size: {{ $thermal ? ($widthMm == 58 ? '9pt' : '10pt') : '10.5pt' }};
  line-height: {{ $thermal ? 1.3 : 1.45 }};
  color: #111827;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
.bn { font-family: 'Noto Sans Bengali', 'Inter', sans-serif; line-height: 1.75; }
.doc { width: {{ $thermal ? ($widthMm - ($widthMm == 58 ? 4 : 6)) . 'mm' : '100%' }}; }
.head { text-align: center; border-bottom: 1px solid #111827; padding-bottom: 3mm; margin-bottom: 3mm; }
.clinic { font-size: {{ $thermal ? '11pt' : '15pt' }}; font-weight: 700; }
.muted { color: #4b5563; font-size: {{ $thermal ? '8pt' : '9pt' }}; }
.title { margin-top: 2mm; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; font-size: {{ $thermal ? '9pt' : '11pt' }}; }
.meta { display: flex; flex-wrap: wrap; gap: 1mm 6mm; margin-bottom: 3mm; }
.meta > div { {{ $thermal ? 'width:100%;' : 'min-width: 38%;' }} }
.k { color: #4b5563; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: {{ $thermal ? '1mm 0' : '1.6mm 2mm' }}; text-align: left; vertical-align: top; }
thead th { border-bottom: 1px solid #111827; font-size: {{ $thermal ? '8pt' : '9pt' }}; text-transform: uppercase; letter-spacing: .04em; }
tbody td { border-bottom: 1px dotted #d1d5db; }
.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.totals { margin-top: 3mm; margin-left: auto; width: {{ $thermal ? '100%' : '62mm' }}; }
.totals td { border: 0; padding: {{ $thermal ? '.6mm 0' : '1mm 2mm' }}; }
.totals .grand td { border-top: 1px solid #111827; border-bottom: 1px solid #111827; font-weight: 700; font-size: {{ $thermal ? '10pt' : '12pt' }}; }
.totals .due td { color: #b91c1c; font-weight: 700; }
.words { margin-top: 2mm; font-size: {{ $thermal ? '8pt' : '9pt' }}; }
.note { margin-top: 3mm; padding: 2mm; border: 1px dashed #9ca3af; font-size: {{ $thermal ? '8pt' : '9pt' }}; }
.foot { margin-top: {{ $thermal ? '4mm' : '12mm' }}; display: flex; justify-content: space-between; align-items: flex-end; }
.sig { border-top: 1px solid #111827; padding-top: 1mm; min-width: 45mm; text-align: center; font-size: 9pt; }
.stamp { border: 2px solid #047857; color: #047857; padding: 1mm 3mm; font-weight: 700; letter-spacing: .1em; transform: rotate(-6deg); }
.stamp.void { border-color: #b91c1c; color: #b91c1c; }
.center { text-align: center; }
@media screen { body { background: #eef1f5; padding: 6mm 0; } .doc { margin: 0 auto; background: #fff; padding: 8mm; max-width: 210mm; box-shadow: 0 1px 6px rgba(15,23,42,.18); } }
</style>

<div class="doc" data-paper="{{ $paper }}">
  <div class="head">
    <div class="clinic bn">{{ $clinic['name'] }}</div>
    @if ($clinic['branch'])<div class="muted bn">{{ $clinic['branch'] }}</div>@endif
    @if ($clinic['address'])<div class="muted bn">{{ $clinic['address'] }}</div>@endif
    @if ($clinic['phone'])<div class="muted">{{ $clinic['phone'] }}</div>@endif
    <div class="title">{{ $labels['invoice']['en'] }} / <span class="bn">{{ $labels['invoice']['bn'] }}</span></div>
  </div>

  <div class="meta">
    <div><span class="k">{{ $labels['invoice_no']['en'] }}:</span> <strong>{{ $invoice['number'] }}</strong></div>
    <div><span class="k">{{ $labels['date']['en'] }}:</span> {{ $invoice['issued_at'] }}</div>
    <div><span class="k">{{ $labels['patient']['en'] }}:</span> <span class="bn">{{ $patient['name'] }}</span></div>
    <div><span class="k">{{ $labels['patient_id']['en'] }}:</span> {{ $patient['code'] }}</div>
    <div><span class="k">{{ $labels['mobile']['en'] }}:</span> {{ $patient['mobile'] }}</div>
    @if ($doctor)<div><span class="k">{{ $labels['doctor']['en'] }}:</span> <span class="bn">{{ $doctor['name_bn'] ?? $doctor['name'] }}</span></div>@endif
  </div>

  <table data-section="items">
    <thead>
      <tr>
        <th>{{ $labels['description']['en'] }} / <span class="bn">{{ $labels['description']['bn'] }}</span></th>
        @unless ($thermal)<th class="num">{{ $labels['qty']['en'] }}</th><th class="num">{{ $labels['rate']['en'] }}</th>@endunless
        <th class="num">{{ $labels['amount']['en'] }}</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($items as $item)
      <tr>
        <td>{{ $item['description'] }}@if ($thermal && $item['quantity'] > 1) × {{ $item['quantity'] }}@endif</td>
        @unless ($thermal)<td class="num">{{ $item['quantity'] }}</td><td class="num">{{ $item['unit_price'] }}</td>@endunless
        <td class="num">{{ $item['line_total'] }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <table class="totals" data-section="totals">
    <tr><td>{{ $labels['subtotal']['en'] }}</td><td class="num">{{ $invoice['subtotal'] }}</td></tr>
    @if ($invoice['has_discount'])<tr><td>{{ $labels['discount']['en'] }}</td><td class="num">− {{ $invoice['discount'] }}</td></tr>@endif
    @if ($invoice['has_coupon'])<tr><td>{{ $labels['coupon']['en'] }}</td><td class="num">− {{ $invoice['coupon_discount'] }}</td></tr>@endif
    @if ($invoice['has_vat'])<tr><td>{{ $labels['vat']['en'] }}</td><td class="num">{{ $invoice['vat'] }}</td></tr>@endif
    <tr class="grand"><td>{{ $labels['total']['en'] }} / <span class="bn">{{ $labels['total']['bn'] }}</span></td><td class="num">{{ $invoice['total'] }}</td></tr>
    <tr><td>{{ $labels['paid']['en'] }}</td><td class="num">{{ $invoice['paid'] }}</td></tr>
    @if ($invoice['has_due'])<tr class="due"><td>{{ $labels['due']['en'] }} / <span class="bn">{{ $labels['due']['bn'] }}</span></td><td class="num">{{ $invoice['due'] }}</td></tr>@endif
  </table>

  <div class="words">{{ $labels['in_words']['en'] }}: {{ $invoice['in_words'] }}</div>

  @if ($fee_note)<div class="note bn" data-section="fee-note">{{ $fee_note }}</div>@endif

  @if (count($payments) > 0)
  <table data-section="payments" style="margin-top:3mm">
    <thead><tr><th>{{ $labels['receipt_no']['en'] }}</th><th>{{ $labels['method']['en'] }}</th><th class="num">{{ $labels['amount']['en'] }}</th></tr></thead>
    <tbody>
      @foreach ($payments as $p)
      <tr><td>{{ $p['receipt_number'] }}</td><td class="bn">{{ $p['method'] }}</td><td class="num">{{ $p['amount'] }}</td></tr>
      @endforeach
    </tbody>
  </table>
  @endif

  <div class="foot">
    <div>
      @if ($invoice['is_void'])
        <span class="stamp void">{{ $labels['void_stamp']['en'] }}</span>
      @elseif (! $invoice['has_due'])
        <span class="stamp">{{ $labels['paid_stamp']['en'] }}</span>
      @endif
    </div>
    @unless ($thermal)<div class="sig">{{ $labels['signature']['en'] }}</div>@endunless
  </div>

  @if ($thermal)<div class="center bn" style="margin-top:3mm">{{ $labels['thank_you']['bn'] }}</div>@endif
</div>

<script>
(function () {
  var done = false;
  function go() { if (done) return; done = true; window.print(); }
  (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(function () { setTimeout(go, 60); });
  setTimeout(go, 1500);
})();
</script>
