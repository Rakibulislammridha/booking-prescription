<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\AdviceSnippet;
use App\Models\Tenant\Doctor;

/** Create / update an advice snippet (PRESCRIPTION.md §4.7). @phpstan-type SnippetInput array{shorthand?: string|null, category?: string|null, text: string, text_bn?: string|null, is_shared?: bool, is_active?: bool, clinic?: bool} */
final class SaveAdviceSnippet
{
    /** @param  array<string, mixed>  $data  validated request body */
    public function handle(array $data, ?Doctor $owner, Actor $actor, ?AdviceSnippet $existing = null): AdviceSnippet
    {
        $snippet = $existing ?? new AdviceSnippet;
        $shorthand = isset($data['shorthand']) && trim((string) $data['shorthand']) !== '' ? '/'.ltrim(trim((string) $data['shorthand']), '/') : null;

        $snippet->fill([
            'doctor_id' => $existing !== null ? $existing->doctor_id : ((bool) ($data['clinic'] ?? false) ? null : $owner?->id),
            'shorthand' => $shorthand,
            'category' => $data['category'] ?? ($snippet->category !== null ? $snippet->category->value : 'general'),
            'text' => trim((string) $data['text']),
            'text_bn' => isset($data['text_bn']) && trim((string) $data['text_bn']) !== '' ? trim((string) $data['text_bn']) : null,
            'is_shared' => (bool) ($data['is_shared'] ?? $snippet->is_shared ?? false),
            'is_active' => (bool) ($data['is_active'] ?? $snippet->is_active ?? true),
        ]);
        $snippet->save();

        return $snippet;
    }
}
