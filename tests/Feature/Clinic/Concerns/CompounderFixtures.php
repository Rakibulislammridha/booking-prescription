<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic\Concerns;

use App\Domain\Clinic\Actions\AssignCompounder;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use Tests\Feature\Billing\Concerns\BillingFixtures;

/**
 * The shared stage of the compounder proofs: two doctors holding a session each on today's board, and a desk user
 * who works for one of them. Two doctors is the minimum that can tell a scope from a no-op — every claim in these
 * files is "this URL answers for mine and refuses for theirs", which one doctor cannot express.
 *
 * Doctors are created WITHOUT a weekly template on purpose: BoardBuilder materialises a day for every doctor that
 * has one, so a template would put sessions on the board that no test opened and make "the board holds exactly one
 * session" mean nothing.
 */
trait CompounderFixtures
{
    use BillingFixtures;

    /**
     * Route path without the host, which is what the assertions read like at the call site.
     *
     * @param  array<string, mixed>  $params
     */
    protected function url(string $name, array $params = []): string
    {
        return route($name, $params, false);
    }

    protected function freshDoctor(): Doctor
    {
        return Doctor::factory()->complete()->create();
    }

    /**
     * Signed in as a compounder on these doctors' desks — put there by the real action, so the fixture itself is
     * proof that the role and tenant invariants held, rather than a pivot row written behind their back.
     */
    protected function actingAsCompounder(Doctor ...$doctors): User
    {
        $user = $this->actingAsStaff(Role::Compounder);

        foreach ($doctors as $doctor) {
            app(AssignCompounder::class)->handle($doctor, $user, Actor::system());
        }

        return $user;
    }

    /** A booked patient's serial (BillingFixtures::book bills the signed-in user as the actor, so sign in first). */
    protected function serialOn(SessionInstance $session, string $mobile, string $name): Serial
    {
        return Serial::query()->findOrFail($this->book($session, mobile: $mobile, name: $name)->appointment->serial_id);
    }

    /**
     * Today's board as the desk polls it every five seconds.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function polledSessions(): array
    {
        /** @var array{sessions: array<int, array<string, mixed>>} $json */
        $json = $this->getJson($this->url('panel.reception.board.data'))->assertOk()->json();

        return $json['sessions'];
    }

    /**
     * The display codes of one session's rows, in the order the board listed them.
     *
     * @param  array<int, array<string, mixed>>  $sessions
     * @return array<int, string>
     */
    protected function codesOf(array $sessions, string $sessionPublicId): array
    {
        foreach ($sessions as $session) {
            if (($session['public_id'] ?? null) === $sessionPublicId) {
                /** @var array<int, array<string, mixed>> $serials */
                $serials = $session['serials'];

                return array_map(fn (array $s) => (string) $s['display_code'], $serials);
            }
        }

        self::fail("the board carries no session {$sessionPublicId}");
    }
}
