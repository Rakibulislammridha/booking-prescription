<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** routes.code — the grammar's closed route_code vocabulary (SCHEMA.md Appendix A). */
enum RouteCode: string
{
    case Po = 'po';
    case Sl = 'sl';
    case Buccal = 'buccal';
    case Pr = 'pr';
    case Pv = 'pv';
    case Top = 'top';
    case Iv = 'iv';
    case Im = 'im';
    case Sc = 'sc';
    case Id = 'id';
    case Inh = 'inh';
    case Neb = 'neb';
    case Ng = 'ng';
    case Le = 'le';
    case Re = 're';
    case Be = 'be';
    case Lear = 'lear';
    case Rear = 'rear';
    case Bear = 'bear';
    case Nasal = 'nasal';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
