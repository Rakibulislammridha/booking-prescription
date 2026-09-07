<?php

declare(strict_types=1);

/*
 * Broadcast channel inventory (REALTIME.md §2). Foundation-owned; required from AppServiceProvider::boot()
 * after both Broadcast::routes() calls, so a module MAY register a channel here — but none does today.
 *
 * The three queue channels are registered in code, not here:
 *
 *   App\Domain\Queue\QueueServiceProvider::registerChannels()   (app/Domain/Queue/QueueServiceProvider.php)
 *     tenant.{tenant}.reception.{branch}   ChannelGuards::reception   guards: web, sanctum, device
 *     tenant.{tenant}.doctor.{doctor}      ChannelGuards::doctor      guards: web, sanctum
 *     tenant.{tenant}.display.{branch}     ChannelGuards::display     guards: device, web
 *
 * The public queue channel `tenant.{tenant}.queue.{sessionInstance}` needs no guard (REALTIME.md §2).
 * The prescription writer channel `tenant.{tenant}.prescription.{prescription}`
 * (App\Domain\Prescription\Services\PrescriptionChannelGuard) is registered by the Prescription module.
 *
 * Add a Broadcast::channel() call below only for a channel that has no owning module provider.
 */
