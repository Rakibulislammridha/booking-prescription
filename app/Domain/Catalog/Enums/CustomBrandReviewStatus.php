<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** custom_brands.review_status (SCHEMA.md Appendix A). */
enum CustomBrandReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Promoted = 'promoted';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
