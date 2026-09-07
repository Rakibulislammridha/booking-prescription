<?php

declare(strict_types=1);

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @phpstan-consistent-constructor
 *
 * public-schema models. Subclasses MUST declare protected $table = 'public.<name>' — Grammar::wrapTable() splits on
 * '.' and quotes each segment ("public"."tenants"), so the model is correct under any search path (ARCHITECTURE §5.1).
 */
abstract class CentralModel extends Model
{
    protected $connection = 'pgsql';

    protected static function boot(): void
    {
        parent::boot();
        static::bootCentralModel();
    }

    public static function bootCentralModel(): void
    {
        str_starts_with((new static)->getTable(), 'public.') || throw new LogicException(static::class.' must set $table = "public.…"');
    }
}
