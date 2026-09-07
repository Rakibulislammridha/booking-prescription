<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Render;

/**
 * PRESCRIPTION.md §7.1 — the only knobs the templates read besides the frozen snapshot. Defaults come from
 * `snapshot.pad` (`fromPad()`); query parameters override per print (`override()`), never the other way round.
 * `orientation` is additive to the documented shape: `@page` needs it and it is a pad key (SCHEMA §3.1).
 */
final readonly class RenderOptions
{
    public const LAYOUTS = ['full', 'pharmacy'];

    public const PAPERS = ['A4', 'A5'];

    public const WATERMARKS = ['DRAFT', 'VOID', 'COPY'];

    public const PURPOSES = ['print', 'pdf', 'verify'];

    public function __construct(
        public string $layout = 'full',
        public string $paper = 'A4',
        public string $orientation = 'portrait',
        public bool $letterhead = true,
        public bool $preprinted = false,
        public ?string $watermark = null,
        public string $language = 'both',
        public string $purpose = 'print',
    ) {}

    /**
     * @param  array<string, mixed>  $pad  snapshot.pad (= pad_snapshot, SCHEMA §3.1)
     */
    public static function fromPad(array $pad, string $purpose = 'print', ?string $language = null, string $layout = 'full'): self
    {
        return new self(
            layout: in_array($layout, self::LAYOUTS, true) ? $layout : 'full',
            paper: self::paper($pad['paper_size'] ?? null),
            orientation: ($pad['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait',
            letterhead: (bool) ($pad['letterhead_enabled'] ?? true),
            preprinted: (bool) ($pad['preprinted_mode'] ?? false),
            watermark: null,
            language: self::language($language ?? ($pad['default_language'] ?? 'both')),
            purpose: in_array($purpose, self::PURPOSES, true) ? $purpose : 'print',
        );
    }

    /**
     * Per-print overrides (`?paper=A5&letterhead=0&preprinted=1&layout=pharmacy&lang=bn`). Absent keys keep the pad
     * value; an unknown value is ignored rather than throwing — a mistyped query must still print the prescription.
     *
     * @param  array<string, mixed>  $query
     */
    public function override(array $query): self
    {
        return new self(
            layout: isset($query['layout']) && in_array((string) $query['layout'], self::LAYOUTS, true) ? (string) $query['layout'] : $this->layout,
            paper: isset($query['paper']) ? self::paper($query['paper']) : $this->paper,
            orientation: isset($query['orientation']) ? ((string) $query['orientation'] === 'landscape' ? 'landscape' : 'portrait') : $this->orientation,
            letterhead: isset($query['letterhead']) ? self::bool($query['letterhead']) : $this->letterhead,
            preprinted: isset($query['preprinted']) ? self::bool($query['preprinted']) : $this->preprinted,
            watermark: $this->watermark,
            language: isset($query['lang']) ? self::language($query['lang']) : $this->language,
            purpose: $this->purpose,
        );
    }

    public function withWatermark(?string $watermark): self
    {
        $watermark = $watermark === null ? null : strtoupper($watermark);

        return new self($this->layout, $this->paper, $this->orientation, $this->letterhead, $this->preprinted,
            in_array($watermark, self::WATERMARKS, true) ? $watermark : null, $this->language, $this->purpose);
    }

    public function withPurpose(string $purpose): self
    {
        return new self($this->layout, $this->paper, $this->orientation, $this->letterhead, $this->preprinted,
            $this->watermark, $this->language, in_array($purpose, self::PURPOSES, true) ? $purpose : $this->purpose);
    }

    public function isPharmacy(): bool
    {
        return $this->layout === 'pharmacy';
    }

    /** Bangla patient-facing text is printed for `bn` and `both` (BRIEF §5.G.4 multi-language print). */
    public function bn(): bool
    {
        return $this->language !== 'en';
    }

    /** English is printed for `en` and `both`; drug names are always English regardless (§7.2). */
    public function en(): bool
    {
        return $this->language !== 'bn';
    }

    public function both(): bool
    {
        return $this->language === 'both';
    }

    /** The single language to use where only one string fits (dose line, follow-up label). */
    public function primary(): string
    {
        return $this->language === 'en' ? 'en' : 'bn';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'layout' => $this->layout, 'paper' => $this->paper, 'orientation' => $this->orientation,
            'letterhead' => $this->letterhead, 'preprinted' => $this->preprinted, 'watermark' => $this->watermark,
            'language' => $this->language, 'purpose' => $this->purpose,
        ];
    }

    private static function paper(mixed $value): string
    {
        $paper = strtoupper((string) $value);

        return in_array($paper, self::PAPERS, true) ? $paper : 'A4';
    }

    private static function language(mixed $value): string
    {
        $language = strtolower((string) $value);

        return in_array($language, ['bn', 'en', 'both'], true) ? $language : 'both';
    }

    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
