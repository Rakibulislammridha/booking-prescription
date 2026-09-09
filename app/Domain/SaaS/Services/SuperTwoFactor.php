<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Prescription\Render\QrCodeRenderer;
use App\Domain\SaaS\Enums\SuperTwoFactorPolicy;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Two-factor authentication for the `super` guard (ARCHITECTURE §6.5, BRIEF §5.N).
 *
 * The super console can mint an impersonation token into any clinic's patient records, so it is the single
 * highest-value credential in the product; a stolen password must not be enough. The threat model this class
 * implements, decision by decision:
 *
 *   · **Confirm before enable.** `beginEnrolment()` writes the secret with `two_factor_confirmed_at` NULL, which
 *     is "not enabled yet". Only a code that verifies against it flips `two_factor_confirmed_at`, so an operator
 *     who mis-scans the QR discovers it while still logged in rather than at their next login. Re-enrolment on an
 *     already-enabled account is refused for the same reason: a half-finished rotation must never be able to
 *     leave the account with no working factor.
 *   · **Replay.** A TOTP code is valid for its whole 30-second step (and, with skew tolerance, three steps'
 *     worth of codes are live at once). Anyone who can read one — over a shoulder, out of a phishing proxy, off a
 *     mis-typed field in a shared terminal — can use it again seconds later. So the step a code was accepted for
 *     is SPENT: `markStepSpent()` is an atomic `Cache::add`, and a second presentation of the same code by the
 *     same admin fails even though it is arithmetically correct.
 *   · **Clock skew.** ±1 step (±30 s). Wide enough for a phone whose clock nobody syncs, narrow enough that an
 *     attempt costs a guesser three codes out of a million rather than eleven.
 *   · **Lockout.** The challenge is rate-limited per admin+IP in the controller; every failure is audited. The
 *     limiter, not a wider window, is what pays for the skew tolerance.
 *   · **Recovery.** Eight single-use codes, shown exactly once, stored as SHA-256 digests inside the already
 *     ENCRYPTED `two_factor_recovery_codes` column. SHA-256 rather than bcrypt because these are 20 characters of
 *     our own CSPRNG output, not a human-chosen password: there is nothing to brute-force, and it is the same
 *     reasoning that makes Sanctum hash API tokens with SHA-256. Using one is audited, and the count that remains
 *     is on the screen — a recovery code spent by someone else is a visible event.
 *   · **Policy.** Whether any of this is enforced is the platform setting `security.super_two_factor`
 *     (`SuperTwoFactorPolicy`: required | optional | disabled), read through `policy()` at request time — never
 *     cached on this instance, never read at boot — so the console toggle takes effect on the next request. Under
 *     `disabled` nothing is challenged and nothing is wiped: `enabled()` still answers from the row, `challenges()`
 *     is what the login, the challenge and the middleware ask, and the two differ exactly when the policy says so.
 */
final class SuperTwoFactor
{
    /** Half-finished login: the password matched, the second factor has not been presented yet. */
    public const SESSION_PENDING = 'super.2fa.pending';

    /** Stamped on the session the moment the challenge is passed (or when there is no challenge to pass). */
    public const SESSION_PASSED_AT = 'super.2fa.passed_at';

    /** One-shot flash of freshly minted recovery codes — the only time they exist in plaintext. */
    public const SESSION_RECOVERY_CODES = 'super.2fa.recovery_codes';

    /** Steps of clock skew tolerated either side of now. */
    public const WINDOW = 1;

    public function __construct(
        private readonly CentralAudit $audit,
        private readonly Cache $cache,
        private readonly PlatformSettings $settings,
    ) {}

    /** The platform-wide policy, as of THIS request (one cache hit; the toggle needs no restart to take effect). */
    public function policy(): SuperTwoFactorPolicy
    {
        $value = $this->settings->get(PlatformSettingsRegistry::SUPER_TWO_FACTOR);

        return (is_string($value) ? SuperTwoFactorPolicy::tryFrom($value) : null) ?? SuperTwoFactorPolicy::Required;
    }

    /** Enrolment is compulsory for every operator (policy `required`). */
    public function required(): bool
    {
        return $this->policy()->forcesEnrolment();
    }

    /** The operator has a confirmed, working factor on their row — whatever the policy says about using it. */
    public function enabled(SuperAdmin $admin): bool
    {
        return $admin->two_factor_confirmed_at !== null && $this->secretOf($admin) !== null;
    }

    /**
     * This operator is challenged at sign-in and their session must carry the challenge marker: enrolled AND the
     * policy enforces it. Under `disabled` an enrolled operator signs in with the password alone.
     */
    public function challenges(SuperAdmin $admin): bool
    {
        return $this->policy()->challengesEnrolled() && $this->enabled($admin);
    }

    /** Enrolment is not optional for this operator and they have not done it: the console is closed to them. */
    public function mustEnrol(SuperAdmin $admin): bool
    {
        return $this->required() && ! $this->enabled($admin);
    }

    /** A secret that has been generated but never confirmed — the enrolment in progress, if any. */
    public function pendingSecret(SuperAdmin $admin): ?string
    {
        return $admin->two_factor_confirmed_at === null ? $this->secretOf($admin) : null;
    }

    /** Mint a fresh secret in the un-confirmed state. Refuses to touch an account that already has a working factor. */
    public function beginEnrolment(SuperAdmin $admin): string
    {
        if ($this->enabled($admin)) {
            return $this->secretOf($admin) ?? '';
        }

        $secret = Totp::generateSecret();

        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secret;
    }

    /**
     * Turn a confirmed enrolment on. Returns the plaintext recovery codes (shown once) or null when the code is
     * wrong — a wrong code here is a mis-scan, not an attack, so it is not audited as a failed challenge.
     *
     * @return array<int, string>|null
     */
    public function confirm(SuperAdmin $admin, string $code): ?array
    {
        $secret = $this->pendingSecret($admin);

        if ($secret === null) {
            return null;
        }

        $step = Totp::verify($secret, $code, window: self::WINDOW);

        if ($step === null || ! $this->markStepSpent($admin, $step)) {
            return null;
        }

        $codes = $this->freshRecoveryCodes();

        $admin->forceFill([
            'two_factor_confirmed_at' => CarbonImmutable::now(),
            'two_factor_recovery_codes' => array_map(self::digest(...), $codes),
        ])->save();

        $this->audit->record(CentralAuditAction::TwoFactorEnabled, null, $admin, null, ['recovery_codes' => count($codes)], $admin->id);

        return $codes;
    }

    /** A TOTP code from the authenticator app. False for a wrong code AND for a correct code already spent. */
    public function verifyCode(SuperAdmin $admin, #[SensitiveParameter] string $code): bool
    {
        $secret = $this->enabled($admin) ? $this->secretOf($admin) : null;

        if ($secret === null) {
            return false;
        }

        $step = Totp::verify($secret, $code, window: self::WINDOW);

        return $step !== null && $this->markStepSpent($admin, $step);
    }

    /**
     * A recovery code. Single use even under concurrency (B5): the read-strike-save happens inside a transaction
     * that holds `FOR UPDATE` on the super_admins row, so six parallel presentations of one code serialise on the
     * lock — the first consumes the digest, the rest re-read a list it is no longer in and fail. A lock-free
     * read-modify-write let all six win.
     */
    public function verifyRecoveryCode(SuperAdmin $admin, #[SensitiveParameter] string $code): bool
    {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        $offered = self::digest($code);

        return (bool) DB::connection($admin->getConnectionName())->transaction(function () use ($admin, $offered): bool {
            $locked = SuperAdmin::query()->whereKey($admin->getKey())->lockForUpdate()->first();

            if (! $locked instanceof SuperAdmin) {
                return false;
            }

            $stored = $this->recoveryDigests($locked);
            $match = null;

            foreach ($stored as $index => $digest) {
                if (hash_equals($digest, $offered)) {
                    $match = $index;                   // no early break: the loop costs the same for every input
                }
            }

            if ($match === null) {
                return false;
            }

            unset($stored[$match]);
            $remaining = array_values($stored);

            $locked->forceFill(['two_factor_recovery_codes' => $remaining])->save();

            // Keep the caller's instance consistent with what was persisted (the old code mutated it in place).
            $admin->setRawAttributes($locked->getAttributes(), sync: true);

            $this->audit->record(CentralAuditAction::TwoFactorRecoveryUsed, null, $admin, null, ['remaining' => count($remaining)], $admin->id);

            return true;
        });
    }

    /**
     * Replace the recovery codes of an already-enabled account; the old ones stop working immediately.
     *
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(SuperAdmin $admin): array
    {
        $codes = $this->freshRecoveryCodes();

        $admin->forceFill(['two_factor_recovery_codes' => array_map(self::digest(...), $codes)])->save();

        $this->audit->record(CentralAuditAction::TwoFactorEnabled, null, $admin, null, ['recovery_codes' => count($codes), 'regenerated' => true], $admin->id);

        return $codes;
    }

    public function disable(SuperAdmin $admin): void
    {
        $was = $this->enabled($admin);

        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        if ($was) {
            $this->audit->record(CentralAuditAction::TwoFactorDisabled, null, $admin, ['two_factor' => 'enabled'], ['two_factor' => 'disabled'], $admin->id);
        }
    }

    public function recordFailedChallenge(SuperAdmin $admin, string $kind): void
    {
        $this->audit->record(CentralAuditAction::TwoFactorFailed, null, $admin, null, ['kind' => $kind], $admin->id);
    }

    public function recoveryCodesRemaining(SuperAdmin $admin): int
    {
        return count($this->recoveryDigests($admin));
    }

    /**
     * What the enrolment screen needs: the secret to type, the URI to scan and the QR itself.
     *
     * @return array{secret: string, otpauth_uri: string, qr_svg: string}
     */
    public function enrolmentPayload(SuperAdmin $admin, #[SensitiveParameter] string $secret): array
    {
        $uri = Totp::provisioningUri($secret, $admin->email, $this->issuer());

        return [
            'secret' => Totp::formatSecret($secret),
            'otpauth_uri' => $uri,
            // The prescription module's Endroid wrapper (ARCHITECTURE §8.5) — one QR renderer in the codebase.
            'qr_svg' => QrCodeRenderer::svgDataUri($uri),
        ];
    }

    public function issuer(): string
    {
        $issuer = trim((string) config('saas.two_factor.issuer', ''));

        return $issuer !== '' ? $issuer : (string) config('app.name', 'Booking to Prescription');
    }

    /**
     * Atomically spend a time step for this admin. `add()` is the whole point: two requests presenting the same
     * code at the same instant race here, and exactly one of them wins.
     */
    private function markStepSpent(SuperAdmin $admin, int $step): bool
    {
        return $this->cache->add('bp:super2fa:step:'.$admin->id.':'.$step, true, Totp::PERIOD * (2 * self::WINDOW + 2));
    }

    /** @return array<int, string> */
    private function freshRecoveryCodes(): array
    {
        $count = max(1, (int) config('saas.two_factor.recovery_codes', 8));

        return array_map(fn () => Str::random(10).'-'.Str::random(10), range(1, $count));
    }

    /** @return array<int, string> */
    private function recoveryDigests(SuperAdmin $admin): array
    {
        $codes = $admin->two_factor_recovery_codes;

        return is_array($codes) ? array_values(array_filter($codes, is_string(...))) : [];
    }

    private function secretOf(SuperAdmin $admin): ?string
    {
        $secret = $admin->two_factor_secret;

        return is_string($secret) && trim($secret) !== '' ? $secret : null;
    }

    private static function digest(#[SensitiveParameter] string $code): string
    {
        return hash('sha256', $code);
    }
}
