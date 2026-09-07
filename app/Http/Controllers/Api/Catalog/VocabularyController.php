<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** GET /api/catalog/vocabulary — active dosage forms and routes (the grammar's closed code lists). */
final class VocabularyController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $forms = DB::connection('catalog')->table('dosage_forms')->where('is_active', true)->orderBy('id')
            ->get(['id', 'code', 'name', 'name_bn', 'abbreviation', 'default_unit', 'default_route_id', 'is_liquid', 'pack_unit']);
        $routes = DB::connection('catalog')->table('routes')->where('is_active', true)->orderBy('id')
            ->get(['id', 'code', 'name', 'name_bn', 'abbreviation', 'is_systemic']);

        return response()->json([
            'forms' => $forms->map(fn ($f) => ['id' => (int) $f->id, 'code' => $f->code, 'name' => $f->name, 'name_bn' => $f->name_bn, 'abbreviation' => $f->abbreviation,
                'default_unit' => $f->default_unit, 'default_route_id' => $f->default_route_id === null ? null : (int) $f->default_route_id, 'is_liquid' => (bool) $f->is_liquid, 'pack_unit' => $f->pack_unit])->values(),
            'routes' => $routes->map(fn ($r) => ['id' => (int) $r->id, 'code' => $r->code, 'name' => $r->name, 'name_bn' => $r->name_bn, 'abbreviation' => $r->abbreviation, 'is_systemic' => (bool) $r->is_systemic])->values(),
        ])->header('Cache-Control', 'private, max-age=300');
    }
}
