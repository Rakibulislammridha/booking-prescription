{{-- The platform's invoice to a clinic (SCHEMA §2.5), rendered from the STORED row — frozen totals and frozen
     line items — never from a live recomputation, so a reprint is the paper the customer already has. Same
     print pipeline as the tenant-side invoice: inline CSS, Inter + Noto Sans Bengali (system font first, the
     committed woff2 second; inlined as data URIs for the PDF), A4 portrait. --}}
<title>{{ $invoice['number'] }}</title>
<style>
{!! $fontCss !!}
@page { size: A4 portrait; margin: 14mm 12mm; }
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #fff; }
body { font-family: 'Inter', 'Noto Sans Bengali', system-ui, sans-serif; font-size: 10.5pt; line-height: 1.45; color: #111827; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.bn { font-family: 'Noto Sans Bengali', 'Inter', sans-serif; line-height: 1.75; }
.doc { width: 100%; position: relative; }
.head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0f766e; padding-bottom: 4mm; margin-bottom: 5mm; }
.brand { font-size: 16pt; font-weight: 700; color: #0f766e; letter-spacing: .02em; }
.brand small { display: block; font-size: 9pt; font-weight: 400; color: #4b5563; letter-spacing: 0; }
.title { text-align: right; }
.title .en { font-weight: 700; letter-spacing: .08em; text-transform: uppercase; font-size: 12pt; }
.title .bn { font-size: 10pt; color: #4b5563; }
.title .num { margin-top: 1mm; font-size: 13pt; font-weight: 700; font-variant-numeric: tabular-nums; }
.grid { display: flex; gap: 8mm; margin-bottom: 5mm; }
.grid > div { flex: 1; }
.k { color: #4b5563; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .05em; }
.v { margin-bottom: 1.5mm; }
.customer { font-size: 12pt; font-weight: 700; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 1.8mm 2mm; text-align: left; vertical-align: top; }
thead th { border-bottom: 1px solid #111827; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .05em; color: #374151; }
tbody td { border-bottom: 1px dotted #d1d5db; }
.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.totals { margin-top: 4mm; margin-left: auto; width: 72mm; }
.totals td { border: 0; padding: 1mm 2mm; }
.totals .grand td { border-top: 1px solid #111827; border-bottom: 1px solid #111827; font-weight: 700; font-size: 12pt; }
.totals .balance td { color: #b91c1c; font-weight: 700; }
.words { margin-top: 2mm; font-size: 9pt; color: #374151; }
.pay { margin-top: 5mm; padding: 3mm; border: 1px dashed #9ca3af; font-size: 9pt; }
.payments { margin-top: 5mm; }
.payments caption { text-align: left; font-weight: 700; font-size: 9.5pt; padding: 0 0 1.5mm; }
.stamp { position: absolute; right: 4mm; top: 28mm; border: 3px solid #047857; color: #047857; padding: 1.5mm 4mm; font-weight: 800; letter-spacing: .18em; font-size: 15pt; transform: rotate(-9deg); opacity: .85; }
.stamp.void { border-color: #b91c1c; color: #b91c1c; }
.stamp.overdue { border-color: #b45309; color: #b45309; }
.foot { margin-top: 14mm; display: flex; justify-content: space-between; align-items: flex-end; font-size: 9pt; color: #4b5563; }
.sig { border-top: 1px solid #111827; padding-top: 1mm; min-width: 50mm; text-align: center; color: #111827; }
@media screen { body { background: #eef1f5; padding: 6mm 0; } .doc { margin: 0 auto; background: #fff; padding: 12mm; max-width: 210mm; box-shadow: 0 1px 6px rgba(15,23,42,.18); } }
</style>

<div class="doc" data-status="{{ $invoice['status'] }}">
  @if ($invoice['is_paid'])<div class="stamp">{{ $labels['paid_stamp']['en'] }} · <span class="bn">{{ $labels['paid_stamp']['bn'] }}</span></div>
  @elseif ($invoice['is_void'])<div class="stamp void">{{ $labels['void_stamp']['en'] }} · <span class="bn">{{ $labels['void_stamp']['bn'] }}</span></div>
  @elseif ($invoice['is_overdue'])<div class="stamp overdue">{{ $labels['overdue_stamp']['en'] }}</div>
  @endif

  <div class="head">
    <div class="brand">{{ $platform['name'] }}<small>{{ $platform['domain'] }}</small></div>
    <div class="title">
      <div class="en">{{ $labels['title']['en'] }}</div>
      <div class="bn">{{ $labels['title']['bn'] }}</div>
      <div class="num">{{ $invoice['number'] }}</div>
    </div>
  </div>

  <div class="grid">
    <div>
      <div class="k">{{ $labels['billed_to']['en'] }} / <span class="bn">{{ $labels['billed_to']['bn'] }}</span></div>
      <div class="v customer bn">{{ $customer['name'] }}</div>
      <div class="v">{{ $customer['host'] }}</div>
      <div class="v"><span class="k">{{ $labels['owner']['en'] }}:</span> <span class="bn">{{ $customer['owner'] }}</span></div>
      <div class="v"><span class="k">{{ $labels['email']['en'] }}:</span> {{ $customer['email'] }}</div>
      <div class="v"><span class="k">{{ $labels['mobile']['en'] }}:</span> {{ $customer['mobile'] }}</div>
    </div>
    <div>
      <div class="v"><span class="k">{{ $labels['issued']['en'] }}:</span> {{ $invoice['issued_at'] ?? '—' }}</div>
      <div class="v"><span class="k">{{ $labels['due']['en'] }}:</span> {{ $invoice['due_at'] ?? '—' }}</div>
      @if ($invoice['period_start'])<div class="v"><span class="k">{{ $labels['period']['en'] }}:</span> {{ $invoice['period_start'] }} – {{ $invoice['period_end'] }}</div>@endif
      @if ($invoice['plan'])<div class="v"><span class="k">Plan:</span> {{ $invoice['plan'] }}@if ($invoice['cycle']) · {{ $invoice['cycle'] }}@endif</div>@endif
    </div>
  </div>

  <table data-section="items">
    <thead>
      <tr>
        <th>{{ $labels['description']['en'] }} / <span class="bn">{{ $labels['description']['bn'] }}</span></th>
        <th class="num">{{ $labels['qty']['en'] }}</th>
        <th class="num">{{ $labels['rate']['en'] }}</th>
        <th class="num">{{ $labels['amount']['en'] }}</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($items as $item)
      <tr>
        <td>{{ $item['description'] }}</td>
        <td class="num">{{ $item['quantity'] }}</td>
        <td class="num">{{ $item['unit'] }}</td>
        <td class="num">{{ $item['total'] }}</td>
      </tr>
      @empty
      <tr><td colspan="4" class="k">—</td></tr>
      @endforelse
    </tbody>
  </table>

  <table class="totals" data-section="totals">
    <tr><td>{{ $labels['subtotal']['en'] }}</td><td class="num">{{ $invoice['subtotal'] }}</td></tr>
    @if ($invoice['has_discount'])<tr><td>{{ $labels['discount']['en'] }}</td><td class="num">− {{ $invoice['discount'] }}</td></tr>@endif
    @if ($invoice['has_vat'])<tr><td>{{ $labels['vat']['en'] }}</td><td class="num">{{ $invoice['vat'] }}</td></tr>@endif
    <tr class="grand"><td>{{ $labels['total']['en'] }} / <span class="bn">{{ $labels['total']['bn'] }}</span></td><td class="num">{{ $invoice['total'] }}</td></tr>
    <tr><td>{{ $labels['paid']['en'] }} / <span class="bn">{{ $labels['paid']['bn'] }}</span></td><td class="num">{{ $invoice['paid'] }}</td></tr>
    @if ($invoice['has_balance'])<tr class="balance"><td>{{ $labels['balance']['en'] }} / <span class="bn">{{ $labels['balance']['bn'] }}</span></td><td class="num">{{ $invoice['balance'] }}</td></tr>@endif
  </table>

  <div class="words">{{ $labels['in_words']['en'] }}: {{ $invoice['in_words'] }}</div>

  @if ($invoice['has_balance'])
  <div class="pay" data-section="how-to-pay">{{ $labels['how_to_pay']['en'] }}<br><span class="bn">{{ $labels['how_to_pay']['bn'] }}</span></div>
  @endif

  @if (count($payments) > 0)
  <table class="payments" data-section="payments">
    <caption>{{ $labels['payments']['en'] }} / <span class="bn">{{ $labels['payments']['bn'] }}</span></caption>
    <thead><tr><th>{{ $labels['date']['en'] }}</th><th>{{ $labels['method']['en'] }}</th><th>{{ $labels['reference']['en'] }}</th><th class="num">{{ $labels['amount']['en'] }}</th></tr></thead>
    <tbody>
      @foreach ($payments as $p)
      <tr><td>{{ $p['paid_at'] }}</td><td class="bn">{{ $p['method'] }}</td><td>{{ $p['reference'] }}</td><td class="num">{{ $p['amount'] }}</td></tr>
      @endforeach
    </tbody>
  </table>
  @endif

  <div class="foot">
    <div>{{ $labels['thank_you']['en'] }} · <span class="bn">{{ $labels['thank_you']['bn'] }}</span><br>{{ $labels['printed']['en'] }}: {{ $printed_at }}</div>
    <div class="sig">{{ $labels['signature']['en'] }}<br><span class="bn">{{ $labels['signature']['bn'] }}</span></div>
  </div>
</div>
