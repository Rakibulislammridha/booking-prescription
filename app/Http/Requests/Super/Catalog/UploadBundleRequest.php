<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Catalog;

use App\Domain\Catalog\Enums\ImportSource;
use App\Domain\Catalog\Services\ImportBundleStore;
use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * A catalogue bundle from the console (CATALOG.md §5.1): one or more CSV files — or one zip of them — named after
 * the tables they fill, plus how to run them (source, version label, full vs incremental). The files' contents
 * are validated by ImportBundleStore after they land; this request only stops the obviously wrong upload.
 */
final class UploadBundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.ImportBundleStore::MAX_FILES],
            'files.*' => ['required', 'file', 'max:65536', 'extensions:csv,zip'],
            'source' => ['required', Rule::in(ImportSource::values())],
            'version' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-]{0,31}$/'],
            'release_ref' => ['nullable', 'string', 'max:120'],
            'full' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'files.*.extensions' => __('super.catalog.imports.validation.extensions'),
            'files.*.max' => __('super.catalog.imports.validation.size'),
            'version.regex' => __('super.catalog.imports.validation.version'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['full' => $this->boolean('full')]);
    }

    /** @return array<int, UploadedFile> */
    public function uploads(): array
    {
        /** @var array<int, UploadedFile> $files */
        $files = array_values(array_filter((array) $this->file('files'), fn ($f) => $f instanceof UploadedFile));

        return $files;
    }

    public function source(): ImportSource
    {
        return ImportSource::from((string) $this->validated('source'));
    }

    public function admin(): SuperAdmin
    {
        $admin = $this->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
