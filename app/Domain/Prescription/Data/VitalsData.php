<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

use App\Domain\Prescription\Support\Temperature;
use App\Models\Tenant\Vital;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /panel/visits/{visit}/vitals and PATCH /panel/vitals/{vital} bodies (PRESCRIPTION.md §4.2). The body's
 * `temperature_f` becomes the stored `temperature_c` HERE — the one °F→°C conversion on the write path, shared by
 * RecordVitals and UpdateVitals.
 */
final readonly class VitalsData
{
    /** @param  array<string, mixed>  $measurements  the Vital::MEASUREMENTS keys present in the request (temperature already in °C) */
    public function __construct(public array $measurements, public ?bool $reviewed = null) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();
        $measurements = array_intersect_key($v, array_flip(Vital::MEASUREMENTS));

        if (array_key_exists('temperature_f', $v)) {
            $measurements['temperature_c'] = $v['temperature_f'] === null || $v['temperature_f'] === '' ? null : Temperature::fToC((float) $v['temperature_f']);
        }

        return new self($measurements, array_key_exists('reviewed', $v) ? (bool) $v['reviewed'] : null);
    }

    /** @return array<string, mixed> measurements + computed bmi */
    public function toAttributes(): array
    {
        $a = $this->measurements;
        $weight = isset($a['weight_kg']) ? (float) $a['weight_kg'] : null;
        $height = isset($a['height_cm']) ? (float) $a['height_cm'] : null;

        if (array_key_exists('weight_kg', $a) || array_key_exists('height_cm', $a)) {
            $a['bmi'] = Vital::computeBmi($weight, $height);
        }

        return $a;
    }
}
