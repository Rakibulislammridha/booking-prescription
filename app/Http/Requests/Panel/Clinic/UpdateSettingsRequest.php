<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Exceptions\InvalidSettingValue;
use App\Domain\Clinic\Support\SettingsRegistry;
use App\Models\Tenant\Setting;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The registry-driven settings page saves a whole prefix group at once as `values: {<key>: <value>}`.
 *
 * The keys are dotted (`queue.auto_noshow_after`), and a `values.queue.auto_noshow_after` rule string would be read
 * by the validator as three levels of nesting rather than one literal key — so the per-key check runs in an `after`
 * hook against `SettingsRegistry::validate()` itself (the same authority `Settings::set()` uses) and the message is
 * added under the literal key. The page then finds it at `errors['values.queue.auto_noshow_after']` and the input
 * shows it, instead of a single form-level banner for a whole screen of fields.
 */
final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Setting::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'values' => ['sometimes', 'array'],
            'remove' => ['sometimes', 'array'],
            'remove.*' => ['string'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->values() as $key => $value) {
                try {
                    SettingsRegistry::validate($key, $value);
                } catch (InvalidSettingValue $e) {
                    $validator->errors()->add('values.'.$key, $e->getMessage());
                }
            }
        }];
    }

    /**
     * The submitted registry keys with their raw values. A key outside the closed registry (SCHEMA Appendix B) is
     * dropped rather than rejected: the page posts what it was given, and a stale key must not block a save.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        /** @var array<string, mixed> $submitted */
        $submitted = is_array($this->input('values')) ? $this->input('values') : [];
        $out = [];

        foreach ($submitted as $key => $value) {
            $key = (string) $key;

            if (SettingsRegistry::has($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Keys the operator asked to REMOVE, back to the registry default.
     *
     * Its own list rather than a magic value inside `values`, because the one key kind that needs removing is a
     * `secret`, and there a blank field means "unchanged" — after ConvertEmptyStringsToNull an untouched password
     * box and a deliberate deletion are byte-for-byte the same input. A key that also appears in `values` with a
     * real value is a contradictory payload; the write wins and the removal is dropped.
     *
     * @return array<int, string>
     */
    public function removals(): array
    {
        $submitted = is_array($this->input('remove')) ? $this->input('remove') : [];
        $written = array_filter($this->values(), fn (mixed $v) => $v !== null && $v !== '');

        return array_values(array_filter(
            array_map(fn (mixed $key) => (string) $key, $submitted),
            fn (string $key) => SettingsRegistry::has($key) && ! array_key_exists($key, $written),
        ));
    }
}
