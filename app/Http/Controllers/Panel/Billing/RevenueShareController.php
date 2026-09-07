<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Billing;

use App\Domain\Billing\Enums\RevenueShareItemType;
use App\Domain\Billing\Enums\RevenueShareType;
use App\Domain\Billing\Services\Paisa;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Billing\StoreRevenueShareRequest;
use App\Http\Resources\Billing\DoctorRevenueShareResource;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorRevenueShare;
use App\Models\Tenant\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Commission RULES. Editing one never changes a historical split: every issued invoice item carries its own
 * frozen `doctor_share_paisa`.
 */
final class RevenueShareController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DoctorRevenueShare::class);
        /** @var User $user */
        $user = $request->user('web');

        return Inertia::render('Billing/RevenueShares', [
            'shares' => DoctorRevenueShareResource::collection(DoctorRevenueShare::query()->with(['doctor', 'branch'])->orderBy('doctor_id')->orderByDesc('effective_from')->get())->resolve(),
            'doctors' => Doctor::query()->where('is_active', true)->orderBy('name')->get(['public_id', 'name'])->map(fn (Doctor $d) => ['public_id' => $d->public_id, 'name' => $d->name])->all(),
            'branch_options' => Branch::query()->active()->orderBy('name')->get(['public_id', 'name'])->map(fn (Branch $b) => ['public_id' => $b->public_id, 'name' => $b->name])->all(),
            'item_types' => RevenueShareItemType::values(),
            'share_types' => RevenueShareType::values(),
            'can' => ['manage' => $user->can('create', DoctorRevenueShare::class)],
        ]);
    }

    public function store(StoreRevenueShareRequest $request): RedirectResponse
    {
        $share = new DoctorRevenueShare;
        $share->forceFill($this->columns($request) + ['created_by_user_id' => $request->user('web')?->getKey()])->save();

        return back()->with('flash.success', __('billing.flash.share_saved'));
    }

    public function update(StoreRevenueShareRequest $request, DoctorRevenueShare $share): RedirectResponse
    {
        $share->forceFill($this->columns($request))->save();

        return back()->with('flash.success', __('billing.flash.share_saved'));
    }

    public function destroy(Request $request, DoctorRevenueShare $share): RedirectResponse
    {
        $this->authorize('delete', $share);
        // Deactivated, never deleted: invoice_items point at the rule that produced their split.
        $share->forceFill(['is_active' => false])->save();

        return back()->with('flash.success', __('billing.flash.share_deactivated'));
    }

    /** @return array<string, mixed> */
    private function columns(StoreRevenueShareRequest $request): array
    {
        $branch = $request->validated('branch');

        return [
            'doctor_id' => (int) Doctor::query()->where('public_id', (string) $request->validated('doctor'))->value('id'),
            'branch_id' => $branch === null ? null : (int) Branch::query()->where('public_id', (string) $branch)->value('id'),
            'item_type' => RevenueShareItemType::from((string) $request->validated('item_type')),
            'share_type' => RevenueShareType::from((string) $request->validated('share_type')),
            'share_value' => Paisa::toDecimal(Paisa::fromDecimal((string) $request->validated('share_value'))),
            'effective_from' => (string) $request->validated('effective_from'),
            'effective_to' => $request->validated('effective_to'),
            'is_active' => (bool) ($request->validated('is_active') ?? true),
        ];
    }
}
