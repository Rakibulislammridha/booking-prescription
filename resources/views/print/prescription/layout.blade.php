{{--
  PRESCRIPTION.md §7.1 — the one print/PDF shell. Everything it draws comes from the frozen snapshot and the pad
  copy inside it (§6.2, §7.2): no model, no catalog, no live lookup. The same HTML is served three ways —
  purpose=print (browser, auto window.print()), purpose=pdf (Browsershot HTML string, fonts inlined),
  purpose=verify (public /rx/{code}, wrapped in the verification chrome) — so what the doctor sees on paper,
  what the patient downloads and what a pharmacy verifies online are byte-for-byte the same document.

  @vars snapshot,o,pad,labels,rx,clinic,doctor,patient,visit,items,investigations,advice,referrals,followUp,qr,
        handwritingPages,drawingSvg,drawingImage,sha256,fontCss
--}}
<!DOCTYPE html>
<html lang="{{ $o->primary() === 'bn' ? 'bn' : 'en' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $labels->in('en', 'prescription') }} · {{ $patient['name'] ?? '' }} · {{ $rx['verification_code'] ?? '' }}</title>
<style>
{!! $fontCss !!}
@page { size: {{ $pad->pageSize() }}; margin: {{ $pad->marginCss() }}; }
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #fff; }
body {
  font-family: '{{ $pad->fontFamily() }}', 'Noto Sans Bengali', 'Inter', system-ui, sans-serif;
  font-size: {{ $pad->fontSizePt() }}pt;
  line-height: 1.45;
  color: #111827;
  -webkit-font-smoothing: antialiased;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
/* Drug names, codes and identifiers are ALWAYS Latin (§7.2) — a molecule is never transliterated. */
.en, .drug, .code, .num { font-family: 'Inter', 'Noto Sans Bengali', system-ui, sans-serif; }
/* Bangla needs more leading than Latin at the same optical size or conjuncts collide with the line above. */
.bn { font-family: 'Noto Sans Bengali', 'Inter', sans-serif; line-height: 1.75; }
.sheet {
  display: flex;
  flex-direction: column;
  min-height: {{ $pad->contentHeightMm() }}mm;
  @if ($pad->reservedFooterMm() > 0) padding-bottom: {{ $pad->reservedFooterMm() }}mm; @endif
  position: relative;
}
.sheet-body { flex: 1 1 auto; }
.sheet-foot { flex: 0 0 auto; }

/* Preprinted pads: the doctor's own letterhead is already on the paper. Reserve exactly header_height_mm and
   put NOTHING in it — this band is the single most visible failure mode of a prescription printer. */
.header-spacer { height: {{ $pad->headerHeightMm() }}mm; }
.letterhead { display: flex; gap: 4mm; align-items: flex-start; justify-content: space-between; padding-bottom: 2mm; }
.letterhead-logo { max-height: 18mm; max-width: 34mm; object-fit: contain; }
.clinic-name { font-size: 1.35em; font-weight: 700; line-height: 1.2; }
.doctor-name { font-size: 1.15em; font-weight: 700; line-height: 1.2; }
.muted { color: #6b7280; }
.tiny { font-size: .78em; }
.small { font-size: .86em; }
.rule { border: 0; border-top: 1.2pt solid #111827; margin: 1.5mm 0 2mm; }
.rule-soft { border: 0; border-top: .6pt solid #d1d5db; margin: 2mm 0; }

.patient-bar { display: flex; flex-wrap: wrap; gap: 1mm 5mm; padding: 1.5mm 0; }
.patient-bar b { font-weight: 700; }
.allergy-bar { border: .8pt solid #b91c1c; color: #b91c1c; padding: 1mm 2mm; margin: 1mm 0; font-weight: 600; }

/* Pagination: a long Rx or investigation list may flow across pages, but never mid-row and never leaving its
   heading stranded at the foot of a page. Blocks that are short by nature stay whole. */
.section { margin-top: 2.5mm; }
.section[data-section="vitals"], .section[data-section="diagnosis"], .section[data-section="followup"],
.section[data-section="referral"], .section[data-section="drawing"] { break-inside: avoid; }
.section-title { font-weight: 700; font-size: .82em; letter-spacing: .04em; text-transform: uppercase; color: #374151; margin-bottom: .8mm; break-after: avoid; }
table.items tr, table.tests tr, ul.bullets li { break-inside: avoid; }
/* A price total that lands alone at the top of the next page reads like a second bill. */
table.tests tr.total-row { break-before: avoid; }
.kv { display: flex; flex-wrap: wrap; gap: 0 4mm; }
.kv span { white-space: nowrap; }

.body-2col { display: grid; grid-template-columns: 33% 1fr; gap: 0 5mm; align-items: start; }
.body-2col > .col-left { border-right: .6pt solid #d1d5db; padding-right: 4mm; }

.rx-symbol { font-family: 'Inter', serif; font-size: 2.4em; font-weight: 700; line-height: .9; }
table.items { width: 100%; border-collapse: collapse; font-size: {{ $pad->rxFontSizePt() }}pt; }
table.items td { vertical-align: top; padding: 1.1mm 0; border-bottom: .4pt dotted #d1d5db; }
table.items tr:last-child td { border-bottom: 0; }
/* Selector specificity matters here: `table.items td` above would otherwise win and flatten the gutters. */
table.items td.idx { width: 8mm; text-align: right; padding-right: 2.5mm; color: #6b7280; }
table.items td.qty { width: 22mm; text-align: right; padding-left: 3mm; white-space: nowrap; font-weight: 600; }
.drug-line { font-weight: 700; }
.generic { color: #4b5563; font-weight: 400; }
.dose { margin-top: .3mm; }
.instruction { margin-top: .3mm; }
.info-url { color: #6b7280; overflow-wrap: anywhere; }

ul.bullets { margin: 0; padding-left: 5mm; }
ul.bullets li { margin: .4mm 0; }
table.tests { width: 100%; border-collapse: collapse; }
table.tests td { padding: .8mm 0; vertical-align: top; }
table.tests td.price { text-align: right; white-space: nowrap; }
.total-row td { border-top: .6pt solid #9ca3af; font-weight: 700; }

.footer { display: flex; align-items: flex-end; justify-content: space-between; gap: 3mm; margin-top: 4mm; padding-top: 2mm; flex-wrap: wrap; }
.sign-block { text-align: center; min-width: 40mm; flex: 0 1 auto; }
.sign-img { max-height: 14mm; max-width: 46mm; object-fit: contain; display: block; margin: 0 auto .5mm; }
.sign-line { border-top: .8pt solid #111827; padding-top: .8mm; }
.qr-block { text-align: center; flex: 0 0 auto; }
.qr-block img { width: 20mm; height: 20mm; display: block; }
/* min-width:0 or the verification URL — one unbreakable token — pushes the QR off the sheet on a narrow screen. */
.pad-footer { flex: 1 1 40mm; min-width: 0; overflow-wrap: anywhere; }

.attachment-page { break-before: page; page-break-before: always; }
.attachment-img { width: 100%; height: auto; display: block; }
.drawing svg { width: 100%; height: auto; display: block; }
/* The annotation is an illustration, not the document: cap it so it never crowds the Rx off the page. */
.drawing-frame { max-width: 110mm; margin: 1mm 0; border: .4pt solid #e5e7eb; }

.watermark {
  position: fixed; inset: 0; display: flex; align-items: center; justify-content: center;
  font-size: 96pt; font-weight: 800; letter-spacing: .12em; color: #111827; opacity: .08;
  transform: rotate(-30deg); z-index: 40; pointer-events: none;
}
/* On screen the sheet is shown as the piece of paper it is — but it must still fit a low-end phone held by a
   patient reading /rx/{code}, so it shrinks below the paper width instead of forcing a horizontal scroll. */
@media screen {
  body { background: #eef1f5; padding: 8mm 0; }
  /* Full paper width, because box-sizing is border-box and the padding IS the print margin: the on-screen
     content column then measures exactly what it will measure on paper. */
  .sheet { width: {{ $pad->paperWidthMm() }}mm; max-width: calc(100% - 16px); margin: 0 auto; background: #fff; padding: {{ $pad->marginCss() }}; box-shadow: 0 1px 6px rgba(15,23,42,.18); }
}
@media screen and (max-width: 560px) {
  body { padding: 8px 0; }
  .sheet { padding: 14px; min-height: 0; }
  .watermark { font-size: 54pt; }
  .letterhead { flex-direction: column; gap: 2mm; }
  .letterhead > div:last-child { text-align: left !important; }
}
@yield('extra-style')
</style>
</head>
<body>
@if ($o->watermark !== null)
  <div class="watermark en" aria-hidden="true">{{ $o->watermark }}</div>
@endif
@yield('sheet')
@if ($o->purpose === 'print')
{{-- §7.6 one-click print: fire once the fonts are actually ready, or Bangla lays out with a fallback metric and
     the printed line breaks differ from the preview. Close only windows we opened ("Issue & Print" tab). --}}
<script>
(function () {
  var printed = false;
  function go() { if (printed) return; printed = true; window.print(); }
  function ready() { (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(function () { setTimeout(go, 60); }); }
  if (document.readyState === 'complete') { ready(); } else { window.addEventListener('load', ready); }
  window.addEventListener('afterprint', function () { if (window.opener) { window.close(); } });
})();
</script>
@endif
</body>
</html>
