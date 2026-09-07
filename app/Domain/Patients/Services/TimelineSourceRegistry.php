<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Contracts\PatientTimelineSource;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The registry PatientTimelineQuery merges. Modules register their sources from their ServiceProvider::boot():
 *   app(TimelineSourceRegistry::class)->register(VisitsTimelineSource::class);
 * Singleton; holds class names (resolved lazily) or instances.
 */
final class TimelineSourceRegistry
{
    /** @var array<string, PatientTimelineSource|class-string<PatientTimelineSource>> keyed by kind or class */
    private array $sources = [];

    public function __construct(private readonly Container $container) {}

    /** @param  PatientTimelineSource|class-string<PatientTimelineSource>  $source */
    public function register(PatientTimelineSource|string $source): void
    {
        if (is_string($source) && ! is_subclass_of($source, PatientTimelineSource::class)) {
            throw new InvalidArgumentException("{$source} does not implement ".PatientTimelineSource::class);
        }

        $this->sources[is_string($source) ? $source : $source::class] = $source;
    }

    /** @return array<int, PatientTimelineSource> */
    public function sources(): array
    {
        $resolved = [];

        foreach ($this->sources as $key => $source) {
            $instance = is_string($source) ? $this->container->make($source) : $source;
            $this->sources[$key] = $instance;
            $resolved[] = $instance;
        }

        return $resolved;
    }

    /** @return array<int, string> */
    public function kinds(): array
    {
        return array_values(array_unique(array_map(fn (PatientTimelineSource $s) => $s->kind(), $this->sources())));
    }

    public function flush(): void
    {
        $this->sources = [];
    }
}
