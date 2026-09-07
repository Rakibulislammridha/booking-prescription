<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Billing;

use App\Domain\Billing\Actions\ApplyCoupon;
use App\Domain\Billing\Actions\ApplyDiscount;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Billing\ApplyCouponRequest;
use App\Http\Requests\Panel\Billing\ApplyDiscountRequest;
use App\Http\Resources\Billing\InvoiceResource;
use App\Models\Tenant\Coupon;
use App\Models\Tenant\Invoice;
use Illuminate\Http\JsonResponse;

/** Discounts (with reason + approval threshold) and coupon redemption on one invoice. */
final class DiscountController extends Controller
{
    public function store(ApplyDiscountRequest $request, Invoice $invoice, ApplyDiscount $action): JsonResponse
    {
        $action->handle($invoice, $request->toData(), Actor::fromRequest($request));

        return response()->json(['invoice' => (new InvoiceResource($invoice->refresh()->load(['items', 'payments', 'refunds', 'discounts'])))->resolve()]);
    }

    public function coupon(ApplyCouponRequest $request, Invoice $invoice, ApplyCoupon $action): JsonResponse
    {
        $coupon = Coupon::query()->where('code', mb_strtoupper(trim((string) $request->validated('code'))))->firstOrFail();
        $action->handle($invoice, $coupon, Actor::fromRequest($request));

        return response()->json(['invoice' => (new InvoiceResource($invoice->refresh()->load(['items', 'payments', 'refunds', 'discounts'])))->resolve()]);
    }
}
