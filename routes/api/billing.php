<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Billing\WebhookController;
use Illuminate\Support\Facades\Route;

// Gateway webhooks (engineer B, names api.billing.*). The path lives under `api/webhooks/*`, which
// bootstrap/app.php exempts from CSRF. Unauthenticated by nature: the driver verifies the gateway's own
// signature, the amount is fetched server-to-server, and settlement is idempotent on the payment row.
Route::post('webhooks/payments/{gateway}', WebhookController::class)
    ->middleware('throttle:billing-webhook')
    ->name('billing.webhook');
