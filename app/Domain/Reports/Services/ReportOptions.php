<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Data\ReportScope;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Specialty;

/**
 * The choices in the filter bar. A scoped doctor gets exactly one entry in the doctor list — his own — so the
 * UI cannot even offer a filter the server would overwrite, and a doctor never learns his colleagues' names
 * from a dropdown he is not allowed to use.
 *
 * Three tiny indexed reads; they are what makes the page's query count bounded regardless of the report.
 */
final class ReportOptions
{
    /** @return array<string, mixed> */
    public function all(ReportScope $scope): array
    {
        return [
            'branches' => Branch::query()->orderByDesc('is_main')->orderBy('name')
                ->get(['public_id', 'name'])
                ->map(fn (Branch $b): array => ['public_id' => $b->public_id, 'name' => $b->name])->all(),
            'doctors' => $this->doctors($scope),
            'specialties' => Specialty::query()->orderBy('sort_order')->orderBy('name')
                ->get(['slug', 'name', 'name_bn'])
                ->map(fn (Specialty $s): array => ['slug' => $s->slug, 'name' => $s->name, 'name_bn' => $s->name_bn])->all(),
        ];
    }

    /** @return array<int, array{public_id: string, name: string}> */
    private function doctors(ReportScope $scope): array
    {
        $query = Doctor::query()->orderBy('name');

        if (! $scope->allDoctors) {
            $query->whereKey($scope->doctorId ?? 0);
        }

        return $query->get(['public_id', 'name'])
            ->map(fn (Doctor $d): array => ['public_id' => $d->public_id, 'name' => $d->name])
            ->all();
    }
}
