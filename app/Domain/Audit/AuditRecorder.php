<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Enums\AuditActorType;
use App\Models\Tenant\AuditLog;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Writes audit_logs rows with the request context (ip, user agent, request id, actor, impersonator).
 * Singleton, listed in config/octane.php 'flush'; append-only — there is no update/delete path.
 */
final class AuditRecorder
{
    private ?string $requestId = null;

    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function flush(): void
    {
        $this->requestId = null;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     */
    public function record(AuditAction $action, Model $auditable, ?array $before = null, ?array $after = null, array $context = []): AuditLog
    {
        [$actorType, $actorId, $impersonator] = $this->actor();
        $request = $this->request();

        $log = new AuditLog;
        $log->forceFill([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'impersonator_super_admin_id' => $impersonator,
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => (int) $auditable->getKey(),
            'patient_id' => $this->patientIdOf($auditable),
            'before' => $before === null ? null : self::redact($auditable, $before),
            'after' => $after === null ? null : self::redact($auditable, $after),
            'context' => array_merge(['route' => Route::currentRouteName()], $context),
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'request_id' => $this->requestId,
            'occurred_at' => now(),
        ]);
        $log->save();

        return $log;
    }

    /** @param  array<string, mixed>  $context */
    public function view(Model $subject, array $context = []): AuditLog
    {
        return $this->record(AuditAction::View, $subject, null, null, $context);
    }

    /**
     * Attributes with an encrypted cast are logged as "[encrypted]"; secrets are never logged.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function redact(Model $model, array $attributes): array
    {
        $casts = $model->getCasts();

        foreach ($attributes as $key => $value) {
            if (isset($casts[$key]) && str_starts_with((string) $casts[$key], 'encrypted')) {
                $attributes[$key] = '[encrypted]';
            } elseif (in_array($key, ['password', 'remember_token'], true)) {
                $attributes[$key] = '[redacted]';
            }
        }

        return $attributes;
    }

    /** @return array{0: AuditActorType, 1: int|null, 2: int|null} */
    private function actor(): array
    {
        $impersonator = null;

        if ($this->request()?->hasSession() && $this->request()->session()->has('impersonated_by')) {
            $impersonator = (int) $this->request()->session()->get('impersonated_by');
        }

        // Tenant guards exist only inside a tenant: on central surfaces a stray login_web_* marker must not be resolved.
        if (Tenancy::check()) {
            if (($user = Auth::guard('web')->user()) instanceof Authenticatable) {
                return [AuditActorType::User, (int) $user->getAuthIdentifier(), $impersonator];
            }

            if (class_exists((string) config('auth.providers.patients.model')) && ($patientId = Auth::guard('patient')->id()) !== null) {
                return [AuditActorType::Patient, (int) $patientId, null];
            }
        }

        if (($super = Auth::guard('super')->user()) instanceof Authenticatable) {
            return [AuditActorType::SuperAdmin, (int) $super->getAuthIdentifier(), null];
        }

        if (Tenancy::check() && class_exists((string) config('auth.providers.reception_devices.model')) && ($deviceId = Auth::guard('device')->id()) !== null) {
            return [AuditActorType::Device, (int) $deviceId, null];
        }

        return [AuditActorType::System, null, null];
    }

    private function patientIdOf(Model $auditable): ?int
    {
        if ($auditable->getMorphClass() === 'App\\Models\\Tenant\\Patient' || $auditable::class === 'App\\Models\\Tenant\\Patient') {
            return (int) $auditable->getKey();
        }

        $patientId = $auditable->getAttribute('patient_id');

        return $patientId === null ? null : (int) $patientId;
    }

    private function request(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }
}
