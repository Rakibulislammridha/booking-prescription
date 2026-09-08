<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Telemedicine\WebhookController;
use Illuminate\Support\Facades\Route;

// Provider callbacks (api.telemedicine.webhook). CSRF-exempt through the `api/webhooks/*` rule in
// bootstrap/app.php and authenticated by the driver's own signature verification — never by a session.
// `plan:telemedicine` still applies: a clinic without the add-on has no rooms for a webhook to reach.
Route::post('webhooks/telemedicine', WebhookController::class)
    ->middleware('plan:telemedicine')
    ->name('telemedicine.webhook');
