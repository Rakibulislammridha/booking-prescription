<?php

declare(strict_types=1);

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;

/**
 * Opt-in model auditing: set `protected static bool $audited = true` (ARCHITECTURE §8.1). created/updated/deleted
 * write before/after restricted to auditedAttributes() (empty = every attribute except timestamps).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            if (! $model->isAudited()) {
                return;
            }

            app(AuditRecorder::class)->record(AuditAction::Create, $model, null, $model->auditedSubset($model->getAttributes()));
        });

        static::updated(function (self $model): void {
            if (! $model->isAudited()) {
                return;
            }

            $dirty = $model->auditedSubset($model->getDirty());

            if ($dirty === []) {
                return;
            }

            $before = $model->auditedSubset(array_intersect_key($model->getOriginal(), $dirty));

            app(AuditRecorder::class)->record(AuditAction::Update, $model, $before, $dirty);
        });

        static::deleted(function (self $model): void {
            if (! $model->isAudited()) {
                return;
            }

            app(AuditRecorder::class)->record(AuditAction::Delete, $model, $model->auditedSubset($model->getAttributes()), null);
        });
    }

    public function isAudited(): bool
    {
        return property_exists(static::class, 'audited') && static::$audited === true;
    }

    /** @return array<int, string> attributes to log; empty = all */
    public function auditedAttributes(): array
    {
        return property_exists(static::class, 'auditedAttributes') ? static::$auditedAttributes : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditedSubset(array $attributes): array
    {
        unset($attributes['created_at'], $attributes['updated_at']);

        $only = $this->auditedAttributes();

        if ($only !== []) {
            $attributes = array_intersect_key($attributes, array_flip($only));
        }

        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $attributes);
    }
}
