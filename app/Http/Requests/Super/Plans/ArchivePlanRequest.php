<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Plans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `DELETE plans/{plan}` archives (or, with `restore`, un-archives). `confirm` is the guard: a plan with live
 * subscribers is only archived when the operator has seen the count (ArchivePlan throws `PlanInUse` otherwise).
 */
final class ArchivePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'restore' => ['boolean'],
            'confirm' => ['boolean'],
        ];
    }

    public function restoring(): bool
    {
        return $this->boolean('restore');
    }

    public function confirmed(): bool
    {
        return $this->boolean('confirm');
    }
}
