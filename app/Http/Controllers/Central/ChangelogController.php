<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Domain\SaaS\Support\CentralCopy;
use App\Domain\SaaS\Support\Changelog;
use App\Http\Controllers\Central\Concerns\BuildsCentralLinks;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** The in-app changelog (BRIEF §5.M), translated, newest first. */
final class ChangelogController extends Controller
{
    use BuildsCentralLinks;

    public function __invoke(): Response
    {
        return Inertia::render('Central/Changelog', [
            'entries' => Changelog::entries(),
            'links' => $this->centralLinks(),
            'copy' => CentralCopy::for('changelog'),
        ]);
    }
}
