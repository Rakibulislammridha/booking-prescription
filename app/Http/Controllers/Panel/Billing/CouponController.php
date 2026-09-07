<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Billing;

use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Services\Paisa;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Billing\StoreCouponRequest;
use App\Http\Resources\Billing\CouponResource;
use App\Models\Tenant\Coupon;
use App\Models\Tenant\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Coupon management (Billing/Coupons). */
final class CouponController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Coupon::class);
        /** @var User $user */
        $user = $request->user('web');

        return Inertia::render('Billing/Coupons', [
            'coupons' => CouponResource::collection(Coupon::query()->orderByDesc('id')->paginate(50))->response()->getData(true),
            'types' => DiscountType::values(),
            'can' => ['manage' => $user->can('create', Coupon::class)],
        ]);
    }

    public function store(StoreCouponRequest $request): RedirectResponse
    {
        $coupon = new Coupon;
        $coupon->forceFill($this->columns($request) + ['created_by_user_id' => $request->user('web')?->getKey()])->save();

        return back()->with('flash.success', __('billing.flash.coupon_saved'));
    }

    public function update(StoreCouponRequest $request, Coupon $coupon): RedirectResponse
    {
        $coupon->forceFill($this->columns($request))->save();

        return back()->with('flash.success', __('billing.flash.coupon_saved'));
    }

    public function destroy(Request $request, Coupon $coupon): RedirectResponse
    {
        $this->authorize('delete', $coupon);
        // Redemptions RESTRICT the delete on purpose: a coupon that discounted a real bill is deactivated, not erased.
        $coupon->forceFill(['is_active' => false])->save();

        return back()->with('flash.success', __('billing.flash.coupon_deactivated'));
    }

    /** @return array<string, mixed> */
    private function columns(StoreCouponRequest $request): array
    {
        return [
            'code' => mb_strtoupper(trim((string) $request->validated('code'))),
            'name' => (string) $request->validated('name'),
            'type' => DiscountType::from((string) $request->validated('type')),
            'value' => Paisa::toDecimal(Paisa::fromDecimal((string) $request->validated('value'))),
            'max_discount_paisa' => $request->validated('max_discount_paisa'),
            'min_invoice_paisa' => (int) ($request->validated('min_invoice_paisa') ?? 0),
            'max_uses' => $request->validated('max_uses'),
            'max_uses_per_patient' => (int) ($request->validated('max_uses_per_patient') ?? 1),
            'applies_to' => $request->validated('applies_to') ?? [],
            'valid_from' => $request->validated('valid_from'),
            'valid_until' => $request->validated('valid_until'),
            'is_active' => (bool) ($request->validated('is_active') ?? true),
        ];
    }
}
