<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/** The visit keys the writer edits (SCHEMA §3.4 visits): complaints, findings, diagnoses, follow-up, private notes. */
final readonly class VisitData
{
    /**
     * @param  list<array<string, mixed>>|null  $chiefComplaints
     * @param  list<array<string, mixed>>|null  $diagnoses
     */
    public function __construct(
        public ?array $chiefComplaints = null,
        public ?string $examinationFindings = null,
        public ?array $diagnoses = null,
        public ?string $followUpOn = null,
        public ?string $followUpNote = null,
        public ?string $privateNotes = null,
        public bool $clearFollowUp = false,
    ) {}

    /** @param  array<string, mixed>  $v */
    public static function fromArray(array $v): self
    {
        return new self(
            chiefComplaints: array_key_exists('chief_complaints', $v) ? self::sorted((array) $v['chief_complaints']) : null,
            examinationFindings: array_key_exists('examination_findings', $v) ? (is_string($v['examination_findings']) && trim($v['examination_findings']) !== '' ? trim($v['examination_findings']) : null) : null,
            diagnoses: array_key_exists('diagnoses', $v) ? self::sorted((array) $v['diagnoses']) : null,
            followUpOn: isset($v['follow_up_on']) && $v['follow_up_on'] !== '' ? (string) $v['follow_up_on'] : null,
            followUpNote: isset($v['follow_up_note']) && trim((string) $v['follow_up_note']) !== '' ? trim((string) $v['follow_up_note']) : null,
            privateNotes: isset($v['private_notes']) && trim((string) $v['private_notes']) !== '' ? (string) $v['private_notes'] : null,
            clearFollowUp: array_key_exists('follow_up_on', $v) && ($v['follow_up_on'] === null || $v['follow_up_on'] === ''),
        );
    }

    /**
     * Normalises the chip list: re-indexed, `sort` rewritten, string keys only.
     *
     * @param  array<int|string, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => is_array($r)));
        usort($rows, fn ($a, $b) => (int) ($a['sort'] ?? 0) <=> (int) ($b['sort'] ?? 0));

        return array_map(fn ($r, $i) => array_intersect_key($r, array_flip(['key', 'text', 'text_bn', 'duration', 'icd10_code', 'title', 'kind'])) + ['sort' => $i], $rows, array_keys($rows));
    }

    /** @return array<string, mixed> attributes to fill on the Visit (only the keys present) */
    public function toAttributes(): array
    {
        $a = [];

        if ($this->chiefComplaints !== null) {
            $a['chief_complaints'] = $this->chiefComplaints;
        }

        if ($this->examinationFindings !== null || $this->chiefComplaints !== null) {
            $a['examination_findings'] = $this->examinationFindings;
        }

        if ($this->diagnoses !== null) {
            $a['diagnoses'] = $this->diagnoses;
        }

        if ($this->followUpOn !== null) {
            $a['follow_up_on'] = $this->followUpOn;
        } elseif ($this->clearFollowUp) {
            $a['follow_up_on'] = null;
        }

        if ($this->followUpNote !== null || $this->followUpOn !== null || $this->clearFollowUp) {
            $a['follow_up_note'] = $this->followUpNote;
        }

        if ($this->privateNotes !== null) {
            $a['private_notes'] = $this->privateNotes;
        }

        return $a;
    }
}
