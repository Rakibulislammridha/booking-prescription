<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Domain\SaaS\Support\CentralCopy;
use App\Domain\SaaS\Support\DocsLibrary;
use App\Http\Controllers\Central\Concerns\BuildsCentralLinks;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** The documentation shell (BRIEF §5.M). Bodies are translated server-side; the page never builds a lang key. */
final class DocsController extends Controller
{
    use BuildsCentralLinks;

    public function index(): Response
    {
        return $this->show(DocsLibrary::default());
    }

    public function show(string $section): Response
    {
        $url = fn (string $slug): string => route('central.docs.show', ['section' => $slug]);

        abort_unless(DocsLibrary::has($section), 404);

        return Inertia::render('Central/Docs', [
            'sections' => DocsLibrary::index($url),
            'current' => DocsLibrary::section($section, $url),
            'links' => $this->centralLinks(),
            'copy' => CentralCopy::for('docs'),
        ]);
    }
}
