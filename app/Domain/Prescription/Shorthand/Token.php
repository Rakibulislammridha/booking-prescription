<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Shorthand;

/**
 * One lexical unit of the shorthand body. type ∈ slots | amount | freq | interval | stat | sos | hs | max | duration |
 * timing | route | qty | unknown. `data` carries the parsed payload (amounts, codes, numbers).
 */
final readonly class Token
{
    /** @param  array<string, mixed>  $data */
    public function __construct(
        public string $type,
        public string $text,
        public array $data = [],
    ) {}
}
