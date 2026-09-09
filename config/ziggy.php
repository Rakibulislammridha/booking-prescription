<?php

declare(strict_types=1);

// Route groups shared with the client per surface (ARCHITECTURE §7.4). 'except' trims the generated
// resources/js/shared/types/ziggy.d.ts (ziggy:generate --types-only) — the groups already scope the runtime payload.
return [
    'except' => ['horizon.*', 'storage.*', 'sanctum.*'],
    'groups' => [
        'panel' => ['panel.*', 'api.*'],
        'site' => ['site.*', 'api.*'],
        'super' => ['super.*'],
        // The marketing/onboarding/invoice pages on the bare central domain. They are rendered by the SITE
        // bundle but live on a host where no `site.*` or `api.*` route is registered, so they get their own
        // group rather than borrowing one: a page that could name a route its host does not serve is a 404
        // waiting to be written. `central.*` is the whole surface (routes/central/*.php).
        'central' => ['central.*'],
    ],
];
