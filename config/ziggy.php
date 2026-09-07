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
    ],
];
