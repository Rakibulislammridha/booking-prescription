<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Catalog;

use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

/** `POST catalog/imports/{job}/dry-run|apply {force?}` — `force` re-runs a bundle whose checksum was imported before. */
final class QueueImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['force' => ['boolean']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['force' => $this->boolean('force')]);
    }

    public function admin(): SuperAdmin
    {
        $admin = $this->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
