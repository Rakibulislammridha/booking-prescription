<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Data;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pad designer output; only the keys present are applied (partial updates from the designer).
 */
final readonly class PadSettingsData
{
    private const KEYS = [
        'paper_size', 'orientation', 'letterhead_enabled', 'preprinted_mode', 'logo_path', 'header_html', 'footer_html', 'margins',
        'header_height_mm', 'footer_height_mm', 'font_family', 'font_size_pt', 'show_qr', 'show_vitals', 'show_drug_info_url',
        'layout', 'token_slip_template', 'default_language', 'signature_path',
    ];

    /** @param  array<string, mixed>  $attributes */
    public function __construct(public array $attributes) {}

    public static function fromRequest(FormRequest $request): self
    {
        return self::fromArray($request->validated());
    }

    /** @param  array<string, mixed>  $values */
    public static function fromArray(array $values): self
    {
        return new self(array_intersect_key($values, array_flip(self::KEYS)));
    }
}
