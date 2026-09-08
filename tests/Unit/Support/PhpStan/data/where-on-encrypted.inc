<?php

declare(strict_types=1);

namespace Tests\Fixtures\PhpStan;

use App\Models\Tenant\Patient;
use App\Models\Tenant\Visit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class WhereOnEncryptedFixture
{
    public function viaQueryBuilder(): mixed
    {
        return Patient::query()->where('national_id', '1234')->first();
    }

    public function viaStaticCall(): mixed
    {
        return Patient::where('notes', 'diabetic')->first();
    }

    /** @param  Builder<Patient>  $builder */
    public function viaTypedBuilder(Builder $builder): mixed
    {
        return $builder->whereIn('national_id', ['a', 'b'])->get();
    }

    public function viaRelation(Patient $patient): mixed
    {
        return $patient->allergies()->where('notes', 'peanuts')->get();
    }

    public function viaModelInstance(Visit $visit): mixed
    {
        return $visit->orWhere('private_notes', 'x')->get();
    }

    public function plainColumnsAreFine(): mixed
    {
        return Patient::query()->where('mobile', '01700000000')->where('id', 1)->first();
    }

    public function nullChecksAreFine(): mixed
    {
        return Patient::query()->whereNull('national_id')->whereNotNull('notes')->first();
    }

    public function nonLiteralColumnIsIgnored(string $column): mixed
    {
        return Patient::query()->where($column, 'x')->first();
    }

    public function queryBuilderHasNoModel(): mixed
    {
        return DB::table('patients')->where('national_id', 'x')->first();
    }
}
