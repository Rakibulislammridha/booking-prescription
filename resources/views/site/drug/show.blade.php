{{--
  §7.8 GET /drug/{slug} — the "click here for more information" target printed under every Rx line. A catalog
  read, not a prescription render, so it may query the catalog (cached 1 h by CatalogCache).

  It is a public page a patient opens on a phone from a paper prescription, so: no login, no framework bundle,
  one self-contained HTML document, and a language toggle that defaults to the tenant's locale — the Bangla
  patient-advice text is the reason this page exists.
--}}
@php $locale = app()->getLocale() === 'bn' ? 'bn' : 'en'; @endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $generic_name ?? $slug }} — {{ config('app.name') }}</title>
<style>
:root { color-scheme: light; }
* { box-sizing: border-box; }
body { margin: 0; background: #f8fafc; color: #0f172a; font-family: 'Inter', 'Noto Sans Bengali', system-ui, -apple-system, sans-serif; line-height: 1.55; }
.wrap { max-width: 640px; margin: 0 auto; padding: 20px 16px 56px; }
h1 { font-size: 24px; margin: 0 0 2px; }
h1 .bn { font-family: 'Noto Sans Bengali', system-ui, sans-serif; font-weight: 600; font-size: 20px; color: #334155; display: block; line-height: 1.7; }
h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .05em; color: #475569; margin: 20px 0 6px; }
.card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; }
.toggle { display: inline-flex; border: 1px solid #cbd5e1; border-radius: 999px; overflow: hidden; margin: 12px 0 4px; }
.toggle button { appearance: none; border: 0; background: #fff; padding: 6px 16px; font: inherit; font-size: 13px; cursor: pointer; color: #334155; }
.toggle button[aria-pressed="true"] { background: #0f766e; color: #fff; }
.bn { font-family: 'Noto Sans Bengali', 'Inter', system-ui, sans-serif; line-height: 1.85; }
.muted { color: #64748b; font-size: 13px; }
.notice { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; border-radius: 8px; padding: 12px 14px; font-size: 14px; }
.disclaimer { margin-top: 24px; font-size: 12px; color: #64748b; }
p { margin: 0 0 10px; white-space: pre-line; }
[hidden] { display: none !important; }
</style>
</head>
<body>
<div class="wrap">
  <h1>
    <span class="en">{{ $generic_name ?? \Illuminate\Support\Str::headline($slug) }}</span>
    @if (! empty($generic_name_bn))<span class="bn">{{ $generic_name_bn }}</span>@endif
  </h1>
  <div class="muted">{{ config('app.name') }}</div>

  @if (! $published)
    <div class="card" style="margin-top:16px">
      <div class="notice">
        <div>Detailed information for this medicine is not published yet.</div>
        <div class="bn" style="margin-top:6px">এই ওষুধের বিস্তারিত তথ্য এখনও প্রকাশ করা হয়নি।</div>
      </div>
      <p class="disclaimer">Ask your doctor or pharmacist before taking this medicine. · ওষুধ খাওয়ার আগে আপনার ডাক্তার বা ফার্মাসিস্টের পরামর্শ নিন।</p>
    </div>
  @else
    <div class="toggle" role="group" aria-label="Language">
      <button type="button" data-lang="bn" aria-pressed="{{ $locale === 'bn' ? 'true' : 'false' }}">বাংলা</button>
      <button type="button" data-lang="en" aria-pressed="{{ $locale === 'en' ? 'true' : 'false' }}">English</button>
    </div>

    <div class="card" data-pane="bn" @if ($locale !== 'bn') hidden @endif>
      @if (! empty($patient_advice_bn))
        <h2>পরামর্শ</h2>
        <p class="bn">{{ $patient_advice_bn }}</p>
      @endif
      @if (! empty($indications_bn))
        <h2>কী কাজে লাগে</h2>
        <p class="bn">{{ $indications_bn }}</p>
      @endif
      @if (! empty($side_effects_bn))
        <h2>পার্শ্বপ্রতিক্রিয়া</h2>
        <p class="bn">{{ $side_effects_bn }}</p>
      @endif
      @if (empty($patient_advice_bn) && empty($indications_bn) && empty($side_effects_bn))
        <p class="bn">এই ওষুধের বাংলা তথ্য এখনও যোগ করা হয়নি। ইংরেজি অংশটি দেখুন।</p>
      @endif
    </div>

    <div class="card" data-pane="en" @if ($locale !== 'en') hidden @endif>
      @if (! empty($indications))
        <h2>Indications</h2>
        <p>{{ $indications }}</p>
      @endif
      @if (! empty($side_effects))
        <h2>Side effects</h2>
        <p>{{ $side_effects }}</p>
      @endif
      @if (! empty($contraindications))
        <h2>Contraindications</h2>
        <p>{{ $contraindications }}</p>
      @endif
      @if (! empty($precautions))
        <h2>Precautions</h2>
        <p>{{ $precautions }}</p>
      @endif
    </div>

    <p class="disclaimer">
      This page is general information, not medical advice. Follow the dose written on your prescription and ask
      your doctor before stopping or changing any medicine.
      <span class="bn" style="display:block; margin-top:4px">এটি সাধারণ তথ্য, চিকিৎসা পরামর্শ নয়। প্রেসক্রিপশনে লেখা মাত্রা অনুসরণ করুন এবং ওষুধ বন্ধ বা পরিবর্তনের আগে ডাক্তারের পরামর্শ নিন।</span>
    </p>
  @endif
</div>
<script>
document.querySelectorAll('.toggle button').forEach(function (button) {
  button.addEventListener('click', function () {
    var lang = button.getAttribute('data-lang');
    document.querySelectorAll('.toggle button').forEach(function (b) { b.setAttribute('aria-pressed', String(b === button)); });
    document.querySelectorAll('[data-pane]').forEach(function (pane) { pane.hidden = pane.getAttribute('data-pane') !== lang; });
  });
});
</script>
</body>
</html>
