<?php

declare(strict_types=1);

namespace App\Domain\Patients\Sources;

use App\Domain\Patients\Contracts\PatientTimelineSource;
use App\Domain\Patients\Data\TimelineCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared keyset plumbing for Eloquent-backed sources: rows strictly older than the cursor in
 * (column DESC, kind DESC, id DESC) order. Other modules may extend it or implement the interface directly.
 */
abstract class ModelTimelineSource implements PatientTimelineSource
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function applyCursor(Builder $query, string $column, ?TimelineCursor $cursor): Builder
    {
        if ($cursor === null) {
            return $query->orderByDesc($column)->orderByDesc('id');
        }

        $at = $cursor->occurredAt->utc()->format('Y-m-d H:i:s.u');
        $kindOrder = strcmp($this->kind(), $cursor->kind);

        $query->where(function (Builder $q) use ($column, $at, $kindOrder, $cursor): void {
            $q->where($column, '<', $at);

            if ($kindOrder < 0) {
                $q->orWhere($column, '=', $at);
            } elseif ($kindOrder === 0) {
                $q->orWhere(fn (Builder $same) => $same->where($column, '=', $at)->where('id', '<', $cursor->id));
            }
        });

        return $query->orderByDesc($column)->orderByDesc('id');
    }
}
