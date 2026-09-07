<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /panel/prescriptions/{prescription}/draft body (PRESCRIPTION.md §4.13). Every list is keyed by the client
 * `key`; rows carrying `id` are updated, others inserted, absent ids deleted.
 */
final readonly class DraftPayload
{
    /**
     * @param  array<string, mixed>|null  $visit  chief_complaints, examination_findings, diagnoses, follow_up_on, follow_up_note
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $investigations
     * @param  list<array<string, mixed>>  $advice
     * @param  list<array<string, mixed>>  $referrals
     */
    public function __construct(
        public ?string $language,
        public ?array $visit,
        public ?int $followUpDays,
        public bool $createBooking,
        public ?bool $vitalsReviewed,
        public array $items,
        public array $investigations,
        public array $advice,
        public array $referrals,
        public ?string $expectedUpdatedAt,
        public bool $partial = false,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return self::fromArray($v);
    }

    /** @param  array<string, mixed>  $v */
    public static function fromArray(array $v): self
    {
        return new self(
            language: isset($v['language']) ? (string) $v['language'] : null,
            visit: isset($v['visit']) && is_array($v['visit']) ? $v['visit'] : null,
            followUpDays: isset($v['follow_up_days']) ? (int) $v['follow_up_days'] : null,
            createBooking: (bool) ($v['create_booking'] ?? true),
            vitalsReviewed: array_key_exists('vitals_reviewed', $v) ? (bool) $v['vitals_reviewed'] : null,
            items: array_values((array) ($v['items'] ?? [])),
            investigations: array_values((array) ($v['investigations'] ?? [])),
            advice: array_values((array) ($v['advice'] ?? [])),
            referrals: array_values((array) ($v['referrals'] ?? [])),
            expectedUpdatedAt: isset($v['expected_updated_at']) ? (string) $v['expected_updated_at'] : null,
            partial: ! array_key_exists('items', $v),
        );
    }
}
