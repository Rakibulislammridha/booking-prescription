<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

use App\Models\Central\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;

/**
 * `doctor_pad_settings.letterhead` — the printed identity of a prescription pad (BRIEF §5.A, PRESCRIPTION.md §7.2).
 *
 * Until this existed the letterhead was one free-HTML textarea, which is exactly why every clinic printed the same
 * generic block: nobody types a three-column footer into a textarea, and HTML a doctor pasted had to be sanitised,
 * inlined and re-sanitised on every path that touched it. A real Bangladeshi pad is not markup — it is a stack of
 * short lines in two or three weights and one accent colour, a rule, a large empty body, a second rule, and a
 * footer of one to three blocks. So that is the shape stored here, as DATA:
 *
 *   accent_color / text_color / muted_color   three hexes; every line names one of them by token, so one colour
 *                                             edit restyles the whole pad instead of thirty inline styles
 *   header { align, lines[], rule }           align `left`, `center`, or `split` (doctor block left, clinic right —
 *                                             a line whose own `align` is `right` is what lands in the right block)
 *   footer { columns[1..3], rule }            each column { align, logo, lines[] }
 *
 * Three invariants this class exists to keep:
 *
 *  1. TEXT IS TEXT. `LetterheadLine::text()` runs `strip_tags` on every string on the way in. The letterhead is
 *     interpolated into a document that is also served to the public at /rx/{code}; markup here would be stored XSS.
 *  2. A PAD ALWAYS PRINTS. `fromArray()` never throws: a missing, mistyped or out-of-range field falls back to that
 *     field's default. The column holds user data that may be a decade old by the time it is next printed.
 *  3. AN UNDESIGNED DOCTOR STILL PRINTS CORRECTLY. `defaults()` builds a real letterhead from the doctor's own
 *     profile and the clinic, and `fromSnapshot()` builds the same thing from a frozen snapshot that predates the
 *     column — so an issued prescription from before this feature still renders its proper header, with no model
 *     access at render time (invariant I6).
 *
 * `header_html` / `footer_html` remain on the table and in `pad_snapshot` for one more release but are NO LONGER
 * RENDERED by any print path; this structure replaces them.
 */
final readonly class Letterhead
{
    /** `split` = doctor block left, clinic block right; a line with `align: right` is one of the right block's. */
    public const HEADER_ALIGNS = ['left', 'center', 'split'];

    /** A pad with more than twenty header lines is not a pad. */
    public const MAX_LINES = 20;

    /** Three footer blocks is what 148 mm of A5 fits and still leaves a readable measure. */
    public const MAX_COLUMNS = 3;

    /** The maroon of the reference pad — a Bangladeshi chamber pad is near-black with one warm accent. */
    public const DEFAULT_ACCENT = '#B03A2E';

    public const DEFAULT_TEXT = '#1A1A1A';

    public const DEFAULT_MUTED = '#666666';

    /**
     * @param  list<LetterheadLine>  $headerLines
     * @param  list<LetterheadColumn>  $footerColumns
     * @param  bool  $generated  built by `defaults()`/`fromSnapshot()` rather than designed. Presentation-only and
     *                           NOT part of `toArray()`: it is what lets the header still print the clinic logo for
     *                           a doctor who never opened the designer, while a designed letterhead places its own
     *                           logo through the footer column that asks for one.
     */
    public function __construct(
        public string $accentColor = self::DEFAULT_ACCENT,
        public string $textColor = self::DEFAULT_TEXT,
        public string $mutedColor = self::DEFAULT_MUTED,
        public string $headerAlign = 'left',
        public array $headerLines = [],
        public bool $headerRule = true,
        public array $footerColumns = [],
        public bool $footerRule = true,
        public bool $generated = false,
    ) {}

    /** Stored jsonb (or `pad_snapshot.letterhead`) → the contract shape. Never throws; see invariant 2 above. */
    public static function fromArray(mixed $stored): self
    {
        $data = is_array($stored) ? $stored : [];
        $header = is_array($data['header'] ?? null) ? $data['header'] : [];
        $footer = is_array($data['footer'] ?? null) ? $data['footer'] : [];

        $lines = [];

        foreach (array_slice(is_array($header['lines'] ?? null) ? $header['lines'] : [], 0, self::MAX_LINES) as $line) {
            $line = LetterheadLine::fromArray(is_array($line) ? $line : []);

            if (! $line->isEmpty()) {
                $lines[] = $line;
            }
        }

        $columns = [];
        $stack = array_values(array_filter(is_array($footer['columns'] ?? null) ? $footer['columns'] : [], 'is_array'));

        foreach (array_slice($stack, 0, self::MAX_COLUMNS) as $index => $column) {
            $columns[] = LetterheadColumn::fromArray($column, LetterheadColumn::ALIGNS[$index] ?? 'left');
        }

        return new self(
            accentColor: self::hex($data['accent_color'] ?? null, self::DEFAULT_ACCENT),
            textColor: self::hex($data['text_color'] ?? null, self::DEFAULT_TEXT),
            mutedColor: self::hex($data['muted_color'] ?? null, self::DEFAULT_MUTED),
            headerAlign: is_string($header['align'] ?? null) && in_array($header['align'], self::HEADER_ALIGNS, true) ? $header['align'] : 'left',
            headerLines: $lines,
            headerRule: (bool) ($header['rule'] ?? true),
            footerColumns: $columns,
            footerRule: (bool) ($footer['rule'] ?? true),
        );
    }

    /**
     * @return array{accent_color: string, text_color: string, muted_color: string, header: array{align: string, lines: list<array<string, mixed>>, rule: bool}, footer: array{columns: list<array<string, mixed>>, rule: bool}}
     */
    public function toArray(): array
    {
        return [
            'accent_color' => $this->accentColor,
            'text_color' => $this->textColor,
            'muted_color' => $this->mutedColor,
            'header' => [
                'align' => $this->headerAlign,
                'lines' => array_map(fn (LetterheadLine $line): array => $line->toArray(), $this->headerLines),
                'rule' => $this->headerRule,
            ],
            'footer' => [
                'columns' => array_map(fn (LetterheadColumn $column): array => $column->toArray(), $this->footerColumns),
                'rule' => $this->footerRule,
            ],
        ];
    }

    /** Nothing designed: the caller falls back to `defaults()` / `fromSnapshot()` rather than printing a blank band. */
    public function isEmpty(): bool
    {
        return $this->headerLines === [] && $this->footerColumns === [];
    }

    /** A palette token (`accent`, `text`, `muted`) → the hex a line is printed in. */
    public function color(string $token): string
    {
        return match ($token) {
            'accent' => $this->accentColor,
            'muted' => $this->mutedColor,
            default => $this->textColor,
        };
    }

    /**
     * Header lines for one side of a `split` header; everything not explicitly right-aligned belongs to the left.
     *
     * @return list<LetterheadLine>
     */
    public function headerSide(string $side): array
    {
        return array_values(array_filter(
            $this->headerLines,
            fn (LetterheadLine $line): bool => $side === 'right' ? $line->align === 'right' : $line->align !== 'right',
        ));
    }

    /**
     * Footer columns worth the width they take.
     *
     * @return list<LetterheadColumn>
     */
    public function columns(): array
    {
        return array_values(array_filter($this->footerColumns, fn (LetterheadColumn $column): bool => ! $column->isEmpty()));
    }

    /**
     * The letterhead a doctor who has never opened the designer prints: their own name, degrees, designation,
     * specialties and BMDC number on the left, the clinic and its main branch on the right — the same information
     * architecture the old generated block had, minus its duplicated clinic name. This is both the designer's
     * "reset to my profile" starting point and what a pad row with an empty `letterhead` renders.
     */
    public static function defaults(Doctor $doctor, ?Tenant $tenant = null): self
    {
        $doctor->loadMissing(['profile', 'specialties']);
        $profile = $doctor->profile;
        $branding = $tenant === null ? [] : (array) $tenant->branding;
        $clinicNameBn = isset($branding['name_bn']) && is_string($branding['name_bn']) ? $branding['name_bn'] : null;

        /** @var Branch|null $branch */
        $branch = Branch::query()->orderByDesc('is_main')->orderBy('id')->first();

        return self::build(
            doctorName: $doctor->name,
            doctorNameBn: $doctor->name_bn,
            degrees: $profile?->degrees,
            degreesBn: $profile?->degrees_bn,
            designation: $profile?->designation,
            specialties: $doctor->specialties->map(fn ($specialty) => (string) $specialty->name)->values()->all(),
            bmdc: $profile?->bmdc_reg_no,
            clinicName: $tenant?->name,
            clinicNameBn: $clinicNameBn,
            branchName: $branch?->name,
            branchAddress: $branch?->address,
            branchPhone: $branch?->phone,
        );
    }

    /**
     * The same fallback built from a FROZEN snapshot instead of from models — what an issued prescription written
     * before the letterhead column existed prints. The render path may not touch a model (invariant I6), so the
     * only inputs are `snapshot.clinic` and `snapshot.doctor`.
     *
     * @param  array<string, mixed>  $clinic  snapshot.clinic
     * @param  array<string, mixed>  $doctor  snapshot.doctor
     */
    public static function fromSnapshot(array $clinic, array $doctor): self
    {
        $branch = (array) ($clinic['branch'] ?? []);

        return self::build(
            doctorName: self::str($doctor['name'] ?? null),
            doctorNameBn: self::str($doctor['name_bn'] ?? null),
            degrees: self::str($doctor['degrees'] ?? null),
            degreesBn: self::str($doctor['degrees_bn'] ?? null),
            designation: self::str($doctor['designation'] ?? null),
            specialties: array_values(array_map('strval', (array) ($doctor['specialties'] ?? []))),
            bmdc: self::str($doctor['bmdc_reg_no'] ?? null),
            clinicName: self::str($clinic['name'] ?? null),
            clinicNameBn: self::str($clinic['name_bn'] ?? null),
            branchName: self::str($branch['name'] ?? null),
            branchAddress: self::str($branch['address'] ?? null),
            branchPhone: self::str($branch['phone'] ?? null),
        );
    }

    /**
     * One builder for both fallbacks, so the designer's starting point and the printed sheet can never disagree.
     *
     * @param  list<string>  $specialties
     */
    private static function build(
        ?string $doctorName,
        ?string $doctorNameBn,
        ?string $degrees,
        ?string $degreesBn,
        ?string $designation,
        array $specialties,
        ?string $bmdc,
        ?string $clinicName,
        ?string $clinicNameBn,
        ?string $branchName,
        ?string $branchAddress,
        ?string $branchPhone,
    ): self {
        $left = [
            new LetterheadLine(text: (string) $doctorName, textBn: $doctorNameBn, color: 'accent', weight: 'bold', size: 1.3),
            new LetterheadLine(text: (string) $degrees, textBn: $degreesBn, color: 'text', weight: 'normal', size: 0.92),
            new LetterheadLine(text: (string) $designation, color: 'muted', size: 0.86),
            new LetterheadLine(text: implode(', ', array_filter($specialties)), color: 'muted', size: 0.86),
            new LetterheadLine(text: $bmdc === null || $bmdc === '' ? '' : 'BMDC '.$bmdc, color: 'muted', size: 0.82),
        ];

        // The clinic name printed twice — once as the title and again as the subtitle — is what the generated
        // block did whenever a single-branch clinic named its branch after itself. One of them is enough.
        $branchLabel = $branchName !== null && self::same($branchName, $clinicName) ? null : $branchName;

        $right = [
            new LetterheadLine(text: (string) $clinicName, textBn: $clinicNameBn, color: 'text', weight: 'bold', size: 1.15, align: 'right'),
            new LetterheadLine(text: (string) $branchLabel, color: 'muted', size: 0.86, align: 'right'),
            new LetterheadLine(text: (string) $branchAddress, color: 'muted', size: 0.82, align: 'right'),
            new LetterheadLine(text: (string) $branchPhone, color: 'muted', size: 0.82, align: 'right'),
        ];

        $lines = array_values(array_filter([...$left, ...$right], fn (LetterheadLine $line): bool => ! $line->isEmpty()));

        return new self(
            headerAlign: 'split',
            headerLines: $lines,
            headerRule: true,
            // No generated footer band: the signature, QR and verification code already close the sheet, and a
            // clinic block repeated at the foot would only restate the header. A doctor adds columns in the designer.
            footerColumns: [],
            footerRule: false,
            generated: true,
        );
    }

    private static function same(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function hex(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? strtoupper($value) : $fallback;
    }
}
