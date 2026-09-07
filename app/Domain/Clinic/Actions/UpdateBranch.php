<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\BranchData;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Branch;
use Illuminate\Support\Facades\DB;

final class UpdateBranch
{
    public function handle(Branch $branch, BranchData $data, Actor $actor): Branch
    {
        return DB::transaction(function () use ($branch, $data): Branch {
            if ($data->isMain) {
                Branch::query()->whereKeyNot($branch->id)->where('is_main', true)->update(['is_main' => false]);
            }

            $branch->fill($data->toAttributes())->save();

            return $branch;
        });
    }
}
