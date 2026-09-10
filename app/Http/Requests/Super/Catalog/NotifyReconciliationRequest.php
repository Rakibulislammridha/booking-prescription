<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Catalog;

use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

/** `POST catalog/reconciliation/{report}/notify {note?}` — an optional line from the operator, appended to the owner mail. */
final class NotifyReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:1000']];
    }

    public function admin(): SuperAdmin
    {
        $admin = $this->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
