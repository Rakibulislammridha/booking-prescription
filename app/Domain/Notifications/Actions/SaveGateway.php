<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Models\Tenant\SmsGatewaySetting;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a gateway row. Two rules the panel cannot be trusted to keep on its own:
 *  - `sms_gateway_settings_channel_uniq_p` allows ONE default per channel, so promoting a row demotes the others
 *    inside the same transaction rather than hitting a constraint violation;
 *  - an update that leaves the credential fields blank keeps the stored secret, so an admin editing the sender id
 *    does not have to re-type an API token they cannot read back (the column is `$hidden` and encrypted).
 */
final class SaveGateway
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $credentials  blank values are ignored on update
     */
    public function handle(?SmsGatewaySetting $gateway, array $attributes, array $credentials, ?User $by): SmsGatewaySetting
    {
        return DB::transaction(function () use ($gateway, $attributes, $credentials, $by): SmsGatewaySetting {
            $gateway ??= new SmsGatewaySetting;
            $existing = $gateway->exists ? $gateway->credentials : [];
            $merged = [...$existing, ...array_filter($credentials, fn ($v) => is_scalar($v) && (string) $v !== '')];

            $gateway->fill([...$attributes, 'credentials' => $merged, 'updated_by_user_id' => $by?->id]);

            if ((bool) ($attributes['is_default'] ?? false)) {
                SmsGatewaySetting::query()
                    ->where('channel', $gateway->channel->value)
                    ->when($gateway->exists, fn ($q) => $q->whereKeyNot($gateway->getKey()))
                    ->where('is_default', true)
                    ->get()
                    ->each(fn (SmsGatewaySetting $other) => $other->forceFill(['is_default' => false])->save());
            }

            $gateway->save();

            return $gateway;
        });
    }
}
