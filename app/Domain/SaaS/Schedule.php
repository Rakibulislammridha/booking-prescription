<?php

declare(strict_types=1);

namespace App\Domain\SaaS;

use App\Support\Scheduling\RegistersSchedule;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;

/**
 * The control plane's clock (ARCHITECTURE §4.7). Everything here is CENTRAL work — it never enters a tenant
 * schema except through `Tenancy::run()` inside the command — so nothing is fanned out with `tenants:run`.
 *
 * Times are Asia/Dhaka because they are about a Bangladeshi clinic's day, not the server's:
 *   01:00  backups, before the traffic starts
 *   02:00  renewals, so a new period's invoice exists before anyone looks
 *   03:00  gauge recount, after the day's writes have settled
 *   09:00  dunning — reminders about money should arrive when a manager can act on them, not at 3 a.m.
 *   hourly domain verification, so a customer who creates a TXT record sees it verified within the hour
 */
final class Schedule implements RegistersSchedule
{
    public function register(LaravelSchedule $schedule): void
    {
        $schedule->command('tenants:backup --prune')->dailyAt('01:00')->timezone('Asia/Dhaka')->onOneServer()->withoutOverlapping();
        $schedule->command('saas:renew-subscriptions')->dailyAt('02:00')->timezone('Asia/Dhaka')->onOneServer()->withoutOverlapping();
        $schedule->command('saas:recount-usage')->dailyAt('03:00')->timezone('Asia/Dhaka')->onOneServer()->withoutOverlapping();
        $schedule->command('saas:prune-impersonation-tokens')->dailyAt('04:00')->timezone('Asia/Dhaka')->onOneServer();
        $schedule->command('saas:dun')->dailyAt('09:00')->timezone('Asia/Dhaka')->onOneServer()->withoutOverlapping();
        $schedule->command('saas:verify-domains')->hourly()->onOneServer()->withoutOverlapping();
    }
}
