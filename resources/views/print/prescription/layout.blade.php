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
/* One page must stay one page. `min-height` is the printable box MINUS a millimetre of rounding slack
   (PadGeometry::sheetMinHeightMm) — claim the full height and Chrome's own page box, computed from a rounded inch
   paper size, comes out a fraction shorter, the column overflows by a sub-pixel and the footer is pushed onto a
   second sheet carrying nothing but a signature and a QR code. */
.sheet {
  display: flex;
  flex-direction: column;
  min-height: {{ $pad->sheetMinHeightMm() }}mm;
  @if ($pad->reservedFooterMm() > 0) padding-bottom: {{ $pad->reservedFooterMm() }}mm; @endif
  position: relative;
}
.sheet-body { flex: 1 1 auto; min-height: 0; }
/* The foot never splits: a signature line orphaned from the name under it is not a signature. */
.sheet-foot { flex: 0 0 auto; break-inside: avoid; }

/* Preprinted pads: the doctor's own letterhead is already on the paper. Reserve exactly header_height_mm and
   put NOTHING in it — this band is the single most visible failure mode of a prescription printer. */
.header-spacer { height: {{ $pad->headerHeightMm() }}mm; }
/* The structured letterhead (Letterhead / LetterheadLine). Colour, weight, size and transform arrive per line as
   data and are written inline by the partial; everything here is only the block geometry. Leading is tight on
   purpose — a real chamber pad stacks six or seven lines into 25 mm. */
.letterhead { display: flex; gap: 4mm; align-items: flex-start; padding-bottom: 1.2mm; }
.letterhead-split { justify-content: space-between; }
.letterhead-left { justify-content: flex-start; }
.letterhead-center { justify-content: center; text-align: center; }
.letterhead-center .lh-block { max-width: 100%; }
.lh-block { min-width: 0; }
.letterhead-split .lh-block { flex: 1 1 0; }
.letterhead-split .lh-block-right { text-align: right; }
.lh-line { line-height: 1.22; }
.lh-bn { display: block; font-size: .92em; line-height: 1.5; }
.letterhead-logo { max-height: 16mm; max-width: 32mm; object-fit: contain; margin-bottom: 1mm; }
/* The pad's own footer band: one to three columns across the foot of the sheet. */
.pad-band { display: flex; gap: 4mm; align-items: flex-start; justify-content: space-between; margin-top: 1mm; }
.pad-col { flex: 1 1 0; min-width: 0; line-height: 1.25; }
.pad-col-logo { max-height: 12mm; max-width: 26mm; object-fit: contain; display: block; margin-bottom: .8mm; }
.pad-band .lh-line { overflow-wrap: anywhere; }
.clinic-name { font-size: 1.35em; font-weight: 700; line-height: 1.2; }
.doctor-name { font-size: 1.15em; font-weight: 700; line-height: 1.2; }
.muted { color: #6b7280; }
.tiny { font-size: .78em; }
.small { font-size: .86em; }
.rule { border: 0; border-top: 1.2pt solid #111827; margin: 1.2mm 0 1.8mm; }
.rule-soft { border: 0; border-top: .6pt solid #d1d5db; margin: 1.4mm 0; }

/* The identity strip, two deliberate rows: who this is, then which record it is. It used to be one run of
   bilingual labels at body size that wrapped to four lines on A5 — four lines of scaffolding taken off the
   prescription. Captions now carry the same small uppercase treatment as the vitals grid, so the sheet reads as
   one system and the bar costs two lines whatever the language. */
.patient-bar { padding: 1.2mm 0; }
.pb-row { display: flex; flex-wrap: wrap; align-items: baseline; gap: .2mm 4mm; }
.pb-row + .pb-row { margin-top: .4mm; }
.pb-name { font-weight: 700; }
.pb-k { font-size: .68em; letter-spacing: .03em; text-transform: uppercase; color: #6b7280; margin-right: .8mm; }
.pb-v { font-size: .95em; white-space: nowrap; }
.allergy-bar { border: .8pt solid #b91c1c; color: #b91c1c; padding: 1mm 2mm; margin: 1mm 0; font-weight: 600; }

/* Pagination: a long Rx or investigation list may flow across pages, but never mid-row and never leaving its
   heading stranded at the foot of a page. Blocks that are short by nature stay whole. */
.section { margin-top: 2mm; }
.section[data-section="vitals"], .section[data-section="diagnosis"], .section[data-section="followup"],
.section[data-section="referral"], .section[data-section="drawing"] { break-inside: avoid; }
.section-title { font-weight: 700; font-size: .82em; letter-spacing: .04em; text-transform: uppercase; color: #374151; margin-bottom: .5mm; break-after: avoid; }
table.items tr, table.tests tr, ul.bullets li { break-inside: avoid; }
/* A price total that lands alone at the top of the next page reads like a second bill. */
table.tests tr.total-row { break-before: avoid; }
.kv { display: flex; flex-wrap: wrap; gap: 0 4mm; }
.kv span { white-space: nowrap; }

/* Vitals as a grid, not a sentence: four fixed cells per row, caption over value, so a doctor hunting for one
   measurement lands on it instead of reading a grey run-on line. Seven measurements = exactly two rows. */
.vitals-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: .6mm 3mm; }
.vital { min-width: 0; line-height: 1.2; }
.vital-k { display: block; font-size: .68em; letter-spacing: .03em; text-transform: uppercase; color: #6b7280; }
.vital-v { font-size: .92em; font-weight: 600; white-space: nowrap; }
.vital-u { font-weight: 400; color: #6b7280; font-size: .86em; }

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
/* §7.8 became a footnote: a marker on the drug, one grey line naming the domain, and the QR for the rest. */
.info-mark { font-size: .6em; font-weight: 400; color: #6b7280; vertical-align: super; margin-left: .4mm; }
.info-note { margin-top: 1mm; }

ul.bullets { margin: 0; padding-left: 5mm; }
ul.bullets li { margin: .4mm 0; }
table.tests { width: 100%; border-collapse: collapse; }
table.tests td { padding: .8mm 0; vertical-align: top; }
table.tests td.price { text-align: right; white-space: nowrap; }
.total-row td { border-top: .6pt solid #9ca3af; font-weight: 700; }

.footer { display: flex; align-items: flex-end; justify-content: space-between; gap: 3mm; margin-top: 2mm; padding-top: 1.2mm; flex-wrap: wrap; }
.sign-block { text-align: center; flex: 0 1 42mm; min-width: 0; font-size: .95em; }
.sign-img { max-height: 14mm; max-width: 46mm; object-fit: contain; display: block; margin: 0 auto .5mm; }
/* Room to sign when no scanned signature exists — enough for a pen, not enough to cost a page. */
.sign-space { height: 5mm; }
.sign-line { border-top: .8pt solid #111827; padding-top: .8mm; }
/* 16 mm at print resolution is ~0.5 mm per module for a verification URL — well inside what a phone reads, and
   four millimetres of an A5 sheet handed back to the prescription instead of to chrome. */
.qr-block { text-align: center; flex: 0 0 auto; max-width: 26mm; }
.qr-block img { width: 16mm; height: 16mm; display: block; margin: 0 auto; }
.qr-block .qr-line { overflow-wrap: anywhere; }
/* The three blocks must fit ONE row across a 118 mm A5 measure: 30 + 42 + 26 + two 3 mm gaps = 104 mm. When they
   wrapped instead the foot doubled to 44 mm and carried the signature and QR onto a page of their own — the exact
   empty second sheet this rewrite exists to kill. The signature block gets the widest basis because a doctor's
   name wrapping to two lines is what made the foot tall in the first place. */
.pad-footer { flex: 1 1 30mm; min-width: 0; overflow-wrap: anywhere; }

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
  .letterhead, .pad-band, .footer { flex-direction: column; gap: 2mm; }
  .sign-block, .qr-block { max-width: none; }
  .letterhead-split .lh-block-right, .pad-col { text-align: left !important; }
  .vitals-grid { grid-template-columns: repeat(2, 1fr); }
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
