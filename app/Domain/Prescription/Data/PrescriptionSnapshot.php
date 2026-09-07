<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

use App\Domain\Prescription\Data\Casts\AsPrescriptionSnapshot;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Support\Arr;
use JsonSerializable;

/**
 * Read-only view of prescriptions.snapshot (PRESCRIPTION.md §6.2, SCHEMA §5.3.2): the ONLY render source once issued.
 * Renderers receive this DTO and nothing else (I6) — it carries no model, no repository, no catalog access.
 */
final readonly class PrescriptionSnapshot implements Castable, JsonSerializable
{
    /** @param  array<string, mixed>  $data */
    public function __construct(private array $data) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    /** Dot-path read (`items.0.brand_name`). */
    public function get(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->data, $path, $default);
    }

    public function schema(): int
    {
        return (int) ($this->data['schema'] ?? 1);
    }

    /** @return array<string, mixed> */
    public function prescription(): array
    {
        return (array) ($this->data['prescription'] ?? []);
    }

    /** @return array<string, mixed> */
    public function clinic(): array
    {
        return (array) ($this->data['clinic'] ?? []);
    }

    /** @return array<string, mixed> */
    public function doctor(): array
    {
        return (array) ($this->data['doctor'] ?? []);
    }

    /** @return array<string, mixed> */
    public function patient(): array
    {
        return (array) ($this->data['patient'] ?? []);
    }

    /** @return array<string, mixed> */
    public function visit(): array
    {
        return (array) ($this->data['visit'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return array_values((array) ($this->data['items'] ?? []));
    }

    /** @return list<array<string, mixed>> */
    public function investigations(): array
    {
        return array_values((array) ($this->data['investigations'] ?? []));
    }

    /** @return list<array<string, mixed>> */
    public function advice(): array
    {
        return array_values((array) ($this->data['advice'] ?? []));
    }

    /** @return list<array<string, mixed>> */
    public function referrals(): array
    {
        return array_values((array) ($this->data['referrals'] ?? []));
    }

    /** @return array<string, mixed> */
    public function followUp(): array
    {
        return (array) ($this->data['follow_up'] ?? []);
    }

    /** @return array<string, mixed> */
    public function pad(): array
    {
        return (array) ($this->data['pad'] ?? []);
    }

    /** @return array<string, mixed> */
    public function safety(): array
    {
        return (array) ($this->data['safety'] ?? []);
    }

    /** @return array<string, mixed> */
    public function qr(): array
    {
        return (array) ($this->data['qr'] ?? []);
    }

    /** @return list<string> */
    public function handwritingPages(): array
    {
        return array_values((array) ($this->data['handwriting_pages'] ?? []));
    }

    public function mode(): string
    {
        return (string) ($this->data['prescription']['mode'] ?? 'structured');
    }

    public function language(): string
    {
        return (string) ($this->data['prescription']['language'] ?? 'both');
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return class-string<AsPrescriptionSnapshot>
     */
    public static function castUsing(array $arguments): string
    {
        return AsPrescriptionSnapshot::class;
    }
}
