<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Prescription\Enums\VisitType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /panel/patients/{patient}/visits {type?} — the doctor opens a visit without a serial. */
final class StartAdhocVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can(Permission::PrescriptionsWrite->value) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['type' => ['sometimes', Rule::enum(VisitType::class)]];
    }
}
