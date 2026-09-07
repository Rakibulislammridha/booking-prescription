<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\BranchData;
use App\Domain\Clinic\Events\BranchCreated;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Branch;
use Illuminate\Support\Facades\DB;

final class CreateBranch
{
    public function handle(BranchData $data, Actor $actor): Branch
    {
        return DB::transaction(function () use ($data): Branch {
            $isMain = $data->isMain || ! Branch::query()->where('is_main', true)->exists();

            if ($isMain) {
                Branch::query()->where('is_main', true)->update(['is_main' => false]);
            }

            $branch = Branch::query()->create(array_merge($data->toAttributes(), ['is_main' => $isMain]));

            event(new BranchCreated($branch));

            return $branch;
        });
    }
}
