{{-- Money receipt (BRIEF §5.I). One payment, rendered from the STORED `payments` row: the amount, the method
     and the receipt number the patient was given. Nothing is recomputed, so a reprint of an old receipt can
     never disagree with the original. A4 by default; `?paper=80|58` for the thermal roll at the counter.
     Same Bangla-capable font stack as the rest of the print pipeline. --}}
<title>{{ $payment['receipt_number'] ?? $invoice['number'] }}</title>
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
@page { size: A4 portrait; margin: 16mm 14mm; }
@endif

*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #fff; }
body {
  font-family: 'Inter', 'Noto Sans Bengali', system-ui, sans-serif;
  font-size: {{ $thermal ? ($widthMm == 58 ? '9pt' : '10pt') : '11pt' }};
  line-height: {{ $thermal ? 1.3 : 1.5 }};
  color: #111827;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
.bn { font-family: 'Noto Sans Bengali', 'Inter', sans-serif; line-height: 1.75; }
.doc { width: {{ $thermal ? ($widthMm - ($widthMm == 58 ? 4 : 6)) . 'mm' : '100%' }}; {{ $thermal ? '' : 'max-width: 150mm; border: 1px solid #111827; padding: 8mm;' }} }
.head { text-align: center; border-bottom: 1px solid #111827; padding-bottom: 3mm; margin-bottom: 3mm; }
.clinic { font-size: {{ $thermal ? '11pt' : '15pt' }}; font-weight: 700; }
.muted { color: #4b5563; font-size: {{ $thermal ? '8pt' : '9pt' }}; }
.title { margin-top: 2mm; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; font-size: {{ $thermal ? '9pt' : '12pt' }}; }
.row { display: flex; justify-content: space-between; gap: 4mm; padding: {{ $thermal ? '.8mm 0' : '1.4mm 0' }}; border-bottom: 1px dotted #d1d5db; }
.row .k { color: #4b5563; }
.row .v { text-align: right; font-variant-numeric: tabular-nums; }
.amount { margin-top: 3mm; padding: {{ $thermal ? '2mm 0' : '3mm' }}; border-top: 1px solid #111827; border-bottom: 1px solid #111827; display: flex; justify-content: space-between; align-items: baseline; font-weight: 700; font-size: {{ $thermal ? '12pt' : '16pt' }}; }
.words { margin-top: 2mm; font-size: {{ $thermal ? '8pt' : '9.5pt' }}; font-style: italic; }
.refund { margin-top: 2mm; color: #b91c1c; font-weight: 700; font-size: {{ $thermal ? '8pt' : '10pt' }}; }
.foot { margin-top: {{ $thermal ? '5mm' : '16mm' }}; display: flex; justify-content: space-between; align-items: flex-end; }
.sig { border-top: 1px solid #111827; padding-top: 1mm; min-width: 45mm; text-align: center; font-size: 9pt; }
.center { text-align: center; }
@media screen { body { background: #eef1f5; padding: 6mm 0; } .doc { margin: 0 auto; background: #fff; box-shadow: 0 1px 6px rgba(15,23,42,.18); } }
</style>

<div class="doc" data-paper="{{ $paper }}" data-document="receipt">
  <div class="head">
    <div class="clinic bn">{{ $clinic['name'] }}</div>
    @if ($clinic['branch'])<div class="muted bn">{{ $clinic['branch'] }}</div>@endif
    @if ($clinic['address'])<div class="muted bn">{{ $clinic['address'] }}</div>@endif
    @if ($clinic['phone'])<div class="muted">{{ $clinic['phone'] }}</div>@endif
    <div class="title">{{ $labels['money_receipt']['en'] }} / <span class="bn">{{ $labels['money_receipt']['bn'] }}</span></div>
  </div>

  <div class="row"><span class="k">{{ $labels['receipt_no']['en'] }} / <span class="bn">{{ $labels['receipt_no']['bn'] }}</span></span><span class="v"><strong>{{ $payment['receipt_number'] }}</strong></span></div>
  <div class="row"><span class="k">{{ $labels['date']['en'] }}</span><span class="v">{{ $payment['paid_at'] }}</span></div>
  <div class="row"><span class="k">{{ $labels['invoice_no']['en'] }}</span><span class="v">{{ $invoice['number'] }}</span></div>
  <div class="row"><span class="k">{{ $labels['patient']['en'] }} / <span class="bn">{{ $labels['patient']['bn'] }}</span></span><span class="v bn">{{ $patient['name'] }} ({{ $patient['code'] }})</span></div>
  @if ($doctor)<div class="row"><span class="k">{{ $labels['doctor']['en'] }}</span><span class="v bn">{{ $doctor['name_bn'] ?? $doctor['name'] }}</span></div>@endif
  <div class="row"><span class="k">{{ $labels['method']['en'] }} / <span class="bn">{{ $labels['method']['bn'] }}</span></span><span class="v bn">{{ $payment['method'] }}</span></div>
  @if ($payment['received_by'])<div class="row"><span class="k">{{ $labels['received_by']['en'] }}</span><span class="v bn">{{ $payment['received_by'] }}</span></div>@endif

  <div class="amount" data-section="amount">
    <span>{{ $labels['received']['en'] }} / <span class="bn">{{ $labels['received']['bn'] }}</span></span>
    <span>{{ $payment['amount'] }}</span>
  </div>
  <div class="words">{{ $labels['in_words']['en'] }}: {{ $payment['in_words'] }}</div>

  @if ($payment['refunded'])<div class="refund" data-section="refunded">{{ $labels['method']['en'] }} refund: {{ $payment['refunded'] }}</div>@endif

  <div class="row" style="margin-top:3mm;border:0"><span class="k">{{ $labels['total']['en'] }}</span><span class="v">{{ $invoice['total'] }}</span></div>
  @if ($invoice['has_due'])<div class="row" style="border:0;color:#b91c1c"><span class="k">{{ $labels['due']['en'] }} / <span class="bn">{{ $labels['due']['bn'] }}</span></span><span class="v"><strong>{{ $invoice['due'] }}</strong></span></div>@endif

  <div class="foot">
    <div class="bn muted">{{ $labels['thank_you']['bn'] }} · {{ $labels['thank_you']['en'] }}</div>
    @unless ($thermal)<div class="sig">{{ $labels['signature']['en'] }}</div>@endunless
  </div>
</div>

<script>
(function () {
  var done = false;
  function go() { if (done) return; done = true; window.print(); }
  (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(function () { setTimeout(go, 60); });
  setTimeout(go, 1500);
})();
</script>
