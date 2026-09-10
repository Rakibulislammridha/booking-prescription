<?php

declare(strict_types=1);

namespace App\Models\Central;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outbound message the PLATFORM sent (`public.platform_messages`): welcome, dunning, suspension, receipt,
 * catalogue notices and the console's own test sends. The recipient is stored masked and the body is never stored;
 * the row exists so an operator can answer "did the final notice reach this owner, and when".
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $channel
 * @property string $kind
 * @property string $recipient
 * @property string|null $subject
 * @property string $locale
 * @property string $status
 * @property string|null $provider
 * @property string|null $error
 * @property int|null $sent_by_super_admin_id
 * @property CarbonImmutable|null $created_at
 * @property-read Tenant|null $tenant
 * @property-read SuperAdmin|null $sentBy
 */
final class PlatformMessage extends CentralModel
{
    public const UPDATED_AT = null;

    protected $table = 'public.platform_messages';

    protected $fillable = ['tenant_id', 'channel', 'kind', 'recipient', 'subject', 'locale', 'status', 'provider', 'error', 'sent_by_super_admin_id', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<SuperAdmin, $this> */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'sent_by_super_admin_id');
    }
}
