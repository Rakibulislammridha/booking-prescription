<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** dosage_forms.code — the shorthand grammar's closed form_code vocabulary (SCHEMA.md Appendix A). */
enum DosageFormCode: string
{
    case Tab = 'tab';
    case Cap = 'cap';
    case Syr = 'syr';
    case Susp = 'susp';
    case Sol = 'sol';
    case OralDrop = 'oral_drop';
    case EyeDrop = 'eye_drop';
    case EarDrop = 'ear_drop';
    case NasalDrop = 'nasal_drop';
    case NasalSpray = 'nasal_spray';
    case InhMdi = 'inh_mdi';
    case InhDpi = 'inh_dpi';
    case Neb = 'neb';
    case Inj = 'inj';
    case Insulin = 'insulin';
    case Cream = 'cream';
    case Oint = 'oint';
    case Gel = 'gel';
    case Lotion = 'lotion';
    case Powder = 'powder';
    case Shampoo = 'shampoo';
    case Mouthwash = 'mouthwash';
    case Paint = 'paint';
    case Supp = 'supp';
    case Pessary = 'pessary';
    case Sachet = 'sachet';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
