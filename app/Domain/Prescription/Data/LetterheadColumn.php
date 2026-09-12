<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/**
 * One column of a pad's printed footer (PRESCRIPTION.md §7.2, BRIEF §5.A).
 *
 * Bangladeshi pads end in a band of two or three stacked blocks — the hospital logo with its address on the left,
 * the chamber hours in the middle, the number you ring for a serial on the right — and that is all this is: an
 * alignment, an optional logo slot, and the lines. One to three columns, because a fourth does not fit across
 * 148 mm of A5 and still leave a readable measure.
 */
final readonly class LetterheadColumn
{
    public const ALIGNS = ['left', 'center', 'right'];

    /** @param  list<LetterheadLine>  $lines */
    public function __construct(
        public string $align = 'left',
        public bool $logo = false,
        public array $lines = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  string  $fallbackAlign  the column's position decides its alignment when the stored value is junk
     */
    public static function fromArray(array $data, string $fallbackAlign = 'left'): self
    {
        $lines = [];

        foreach (array_slice(is_array($data['lines'] ?? null) ? $data['lines'] : [], 0, Letterhead::MAX_LINES) as $line) {
            $line = LetterheadLine::fromArray(is_array($line) ? $line : []);

            if (! $line->isEmpty()) {
                $lines[] = $line;
            }
        }

        return new self(
            align: is_string($data['align'] ?? null) && in_array($data['align'], self::ALIGNS, true)
                ? $data['align']
                : (in_array($fallbackAlign, self::ALIGNS, true) ? $fallbackAlign : 'left'),
            logo: (bool) ($data['logo'] ?? false),
            lines: $lines,
        );
    }

    /** @return array{align: string, logo: bool, lines: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'align' => $this->align,
            'logo' => $this->logo,
            'lines' => array_map(fn (LetterheadLine $line): array => $line->toArray(), $this->lines),
        ];
    }

    /** A column with no lines and no logo occupies width for nothing; the footer drops it. */
    public function isEmpty(): bool
    {
        return $this->lines === [] && ! $this->logo;
    }
}
