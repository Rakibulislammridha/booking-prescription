<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Prescription;

use App\Domain\Catalog\Services\CatalogCache;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /drug/{slug} (site.prescription.drug, §7.8): a catalog read (not a prescription render), cached 1 h by CatalogCache. */
final class DrugInfoController extends Controller
{
    public function show(Request $request, string $slug, CatalogCache $catalog): View|JsonResponse
    {
        abort_unless(preg_match('/^[a-z0-9-]{1,120}$/', $slug) === 1, 404);
        $row = $catalog->drugInformation($slug);
        $published = $row !== null && ! empty($row['published_at']);

        $data = [
            'slug' => $slug,
            'published' => $published,
            'generic_name' => $row['generic_name'] ?? null,
            'generic_name_bn' => $row['generic_name_bn'] ?? null,
            'indications' => $published ? ($row['indications'] ?? null) : null,
            'indications_bn' => $published ? ($row['indications_bn'] ?? null) : null,
            'side_effects' => $published ? ($row['side_effects'] ?? null) : null,
            'side_effects_bn' => $published ? ($row['side_effects_bn'] ?? null) : null,
            'contraindications' => $published ? ($row['contraindications'] ?? null) : null,
            'precautions' => $published ? ($row['precautions'] ?? null) : null,
            'patient_advice_bn' => $published ? ($row['patient_advice_bn'] ?? null) : null,
        ];

        $view = (string) config('prescription.views.drug', 'site.drug.show');

        if (! $request->wantsJson() && view()->exists($view)) {
            return view($view, $data);
        }

        return response()->json($data)->header('Cache-Control', 'public, max-age=3600');
    }
}
