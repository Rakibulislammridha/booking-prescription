<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/**
 * One printed line of a pad letterhead (PRESCRIPTION.md §7.2, BRIEF §5.A).
 *
 * A real Bangladeshi pad is a stack of short lines that differ only in weight, size and colour — the doctor's name
 * large and in the clinic's accent, the degrees under it in black, the unit in grey. So a line is exactly that:
 * text plus four presentation choices, and NOTHING else. In particular it is not HTML. `text()` runs `strip_tags`
 * on every value that enters the DTO, so a `<script>` pasted from a website is stored and printed as the empty
 * string it deserves to be — the letterhead is interpolated into a print stylesheet that is also served to a
 * patient at /rx/{code}, and markup there would be a stored XSS on a public page.
 *
 * `size` is an em multiplier over the pad's own `font_size_pt`, never a point size: a doctor who later moves the
 * pad from 10.5 pt to 12 pt gets a letterhead that scales with it instead of a header that no longer balances.
 */
final readonly class LetterheadLine
{
    /** Palette tokens, not colours: the three hexes live on the Letterhead so one edit restyles the whole pad. */
    public const COLORS = ['accent', 'text', 'muted'];

    public const WEIGHTS = ['normal', 'bold'];

    public const TRANSFORMS = ['none', 'uppercase'];

    /** Per-line override of the block's own alignment; null inherits it. */
    public const ALIGNS = ['left', 'center', 'right'];

    /** One line of a pad is a heading at most — the column is jsonb, but the paper is 148 mm wide. */
    public const MAX_TEXT = 160;

    public const SIZE_MIN = 0.7;

    public const SIZE_MAX = 2.0;

    public function __construct(
        public string $text,
        public ?string $textBn = null,
        public string $color = 'text',
        public string $weight = 'normal',
        public float $size = 1.0,
        public string $transform = 'none',
        public ?string $align = null,
    ) {}

    /**
     * Stored jsonb → the contract shape. Anything missing, mistyped or out of range becomes this field's default
     * rather than an exception: the column is user data that may be years old and a pad must always print.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: self::text($data['text'] ?? null) ?? '',
            textBn: self::text($data['text_bn'] ?? null),
            color: self::one($data['color'] ?? null, self::COLORS, 'text'),
            weight: self::one($data['weight'] ?? null, self::WEIGHTS, 'normal'),
            size: self::size($data['size'] ?? null),
            transform: self::one($data['transform'] ?? null, self::TRANSFORMS, 'none'),
            align: is_string($data['align'] ?? null) && in_array($data['align'], self::ALIGNS, true) ? $data['align'] : null,
        );
    }

    /** @return array{text: string, text_bn: string|null, color: string, weight: string, size: float, transform: string, align: string|null} */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'text_bn' => $this->textBn,
            'color' => $this->color,
            'weight' => $this->weight,
            'size' => $this->size,
            'transform' => $this->transform,
            'align' => $this->align,
        ];
    }

    /** A line with no primary text prints nothing; `Letterhead` drops these rather than emitting a blank row. */
    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    /**
     * The single place a letterhead string is cleaned: tags stripped, whitespace collapsed, length capped.
     * Runs on the way in, so nothing that is not plain text is ever stored or rendered.
     */
    public static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));

        return $text === '' ? null : mb_substr($text, 0, self::MAX_TEXT);
    }

    private static function size(mixed $value): float
    {
        $size = is_numeric($value) ? (float) $value : 1.0;

        return round(max(self::SIZE_MIN, min(self::SIZE_MAX, $size)), 2);
    }

    /** @param  array<int, string>  $allowed */
    private static function one(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }
}
