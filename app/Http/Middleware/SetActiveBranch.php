<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Clinic\Services\ActiveBranch;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel: resolves the staff user's active branch (session choice → default branch → main branch) into ActiveBranch.
 */
final class SetActiveBranch
{
    public const SESSION_KEY = 'active_branch_id';

    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if ($user instanceof User) {
            $this->activeBranch->set($this->resolve($request, $user));
        }

        return $next($request);
    }

    private function resolve(Request $request, User $user): ?Branch
    {
        $sessionId = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if ($sessionId !== null) {
            $branch = Branch::query()->active()->find((int) $sessionId);

            if ($branch !== null) {
                return $branch;
            }

            $request->session()->forget(self::SESSION_KEY);
        }

        if ($user->default_branch_id !== null) {
            $branch = Branch::query()->active()->find($user->default_branch_id);

            if ($branch !== null) {
                return $branch;
            }
        }

        return Branch::query()->active()->orderByDesc('is_main')->orderBy('id')->first();
    }
}
