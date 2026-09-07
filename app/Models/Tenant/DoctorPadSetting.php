<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Clinic\Enums\PadOrientation;
use App\Domain\Clinic\Enums\PadPaperSize;
use App\Domain\Clinic\Enums\TokenSlipTemplate;
use Database\Factories\Tenant\DoctorPadSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-doctor prescription pad designer output; snapshotted into prescriptions.pad_snapshot at issue.
 *
 * @property int $id
 * @property int $doctor_id
 * @property PadPaperSize $paper_size
 * @property PadOrientation $orientation
 * @property bool $letterhead_enabled
 * @property bool $preprinted_mode
 * @property array<string, int> $margins
 * @property int $header_height_mm
 * @property int $footer_height_mm
 * @property string $font_family
 * @property string $font_size_pt
 * @property bool $show_qr
 * @property bool $show_vitals
 * @property bool $show_drug_info_url
 * @property array<string, mixed> $layout
 * @property TokenSlipTemplate $token_slip_template
 * @property string $default_language
 */
final class DoctorPadSetting extends TenantModel
{
    /** @use HasFactory<DoctorPadSettingFactory> */
    use HasFactory;

    protected static string $factory = DoctorPadSettingFactory::class;

    protected $table = 'doctor_pad_settings';

    protected $fillable = [
        'doctor_id', 'paper_size', 'orientation', 'letterhead_enabled', 'preprinted_mode', 'logo_path', 'header_html', 'footer_html',
        'margins', 'header_height_mm', 'footer_height_mm', 'font_family', 'font_size_pt', 'show_qr', 'show_vitals',
        'show_drug_info_url', 'layout', 'token_slip_template', 'default_language', 'signature_path',
    ];

    /** @return array<string, mixed> the SCHEMA defaults for a new doctor */
    public static function defaults(): array
    {
        return [
            'paper_size' => PadPaperSize::A5,
            'orientation' => PadOrientation::Portrait,
            'letterhead_enabled' => true,
            'preprinted_mode' => false,
            'margins' => ['top' => 20, 'right' => 15, 'bottom' => 20, 'left' => 15],
            'header_height_mm' => 35,
            'footer_height_mm' => 20,
            'font_family' => 'Noto Sans Bengali',
            'font_size_pt' => '10.5',
            'show_qr' => true,
            'show_vitals' => true,
            'show_drug_info_url' => true,
            'layout' => [
                'sections' => array_map(
                    fn (string $key) => ['key' => $key, 'visible' => true],
                    ['vitals', 'complaints', 'examination', 'diagnosis', 'rx', 'investigations', 'advice', 'followup', 'referral', 'signature'],
                ),
                'columns' => 1,
                'rx_font_size_pt' => null,
                'flags' => ['icd_codes' => true, 'investigation_prices' => true, 'generic_names' => true],
            ],
            'token_slip_template' => TokenSlipTemplate::Thermal58,
            'default_language' => 'both',
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'paper_size' => PadPaperSize::class,
            'orientation' => PadOrientation::class,
            'letterhead_enabled' => 'boolean',
            'preprinted_mode' => 'boolean',
            'margins' => 'array',
            'header_height_mm' => 'integer',
            'footer_height_mm' => 'integer',
            'font_size_pt' => 'decimal:1',
            'show_qr' => 'boolean',
            'show_vitals' => 'boolean',
            'show_drug_info_url' => 'boolean',
            'layout' => 'array',
            'token_slip_template' => TokenSlipTemplate::class,
        ];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
