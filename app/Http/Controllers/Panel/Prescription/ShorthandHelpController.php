<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Data\ParseContext;
use App\Domain\Prescription\Shorthand\Keywords;
use App\Domain\Prescription\Shorthand\ShorthandParser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /panel/help/shorthand — the keyword table for the cheat-sheet (§2.14) + an optional `try` parse. */
final class ShorthandHelpController extends Controller
{
    public function __invoke(Request $request, ShorthandParser $parser): JsonResponse
    {
        $keywords = Keywords::all();
        unset($keywords['_comment']);
        $try = $request->filled('try') ? $parser->parse((string) $request->query('try'), new ParseContext(formCode: 'tab', defaultUnit: 'tab', strengthMg: 500, strengthLabel: '500 mg', formLabel: 'tablet'))->toArray() : null;

        return response()->json(['version' => Keywords::version(), 'keywords' => $keywords, 'try' => $try])->header('Cache-Control', 'private, max-age=300');
    }
}
