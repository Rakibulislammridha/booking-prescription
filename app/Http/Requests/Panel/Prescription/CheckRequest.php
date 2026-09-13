<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Clinic\Services\DoctorScope;
use App\Models\Tenant\Prescription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /panel/prescriptions/{prescription}/check — the `items` and `overrides` of §4.13, nothing persisted (§5.5).
 *
 * `view` alone let anyone holding `prescriptions.vitals.record` run the interaction checker over a basket of drugs —
 * a compounder included, on a sheet they may legitimately read. Demanding `prescriptions.write` instead would have
 * shut the door on the receptionist too, who has been able to run this since it shipped and is not what the
 * compounder work was about. The conjunct is therefore the scope: restricted (a non-null `doctorIds()`) is refused,
 * everyone else keeps the rule they had.
 *
 * `$scope` arrives by method injection — FormRequest::passesAuthorization() calls authorize() through the container
 * (`$this->container->call`), so a dependency here is resolved the same way a controller method's is.
 */
final class CheckRequest extends FormRequest
{
    public function authorize(DoctorScope $scope): bool
    {
        $rx = $this->route('prescription');
        $user = $this->user('web');

        return $rx instanceof Prescription && $user !== null
            && $scope->doctorIds($user) === null
            && $user->can('view', $rx);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:40'],
            'items.*.key' => ['required', 'string', 'max:40'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.drug' => ['nullable', 'array'],
            'items.*.drug.generic_id' => ['nullable', 'integer'],
            'items.*.drug.brand_id' => ['nullable', 'integer'],
            'items.*.drug.strength_id' => ['nullable', 'integer'],
            'items.*.drug.custom_brand_id' => ['nullable', 'integer', Rule::exists('custom_brands', 'id')],
            'items.*.shorthand' => ['present', 'nullable', 'string', 'max:300'],
            'items.*.safety_overrides' => ['sometimes', 'array'],
            'items.*.safety_overrides.*.fingerprint' => ['required', 'string', 'max:200'],
            'items.*.safety_overrides.*.reason' => ['required', 'string', 'max:500'],
            'overrides' => ['sometimes', 'array'],
            'overrides.*.fingerprint' => ['required', 'string', 'max:200'],
            'overrides.*.reason' => ['required', 'string', 'max:500'],
        ];
    }
}
