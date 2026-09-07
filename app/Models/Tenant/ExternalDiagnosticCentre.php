<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Database\Factories\Tenant\ExternalDiagnosticCentreFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Diagnostic centre the clinic refers patients to (SCHEMA §3.4).
 *
 * @property int $id
 * @property string $name
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $contact_person
 * @property string|null $notes
 * @property bool $is_active
 */
final class ExternalDiagnosticCentre extends TenantModel
{
    /** @use HasFactory<ExternalDiagnosticCentreFactory> */
    use HasFactory;

    protected static string $factory = ExternalDiagnosticCentreFactory::class;

    protected $table = 'external_diagnostic_centres';

    protected $fillable = ['name', 'address', 'phone', 'contact_person', 'notes', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @param  Builder<ExternalDiagnosticCentre>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
