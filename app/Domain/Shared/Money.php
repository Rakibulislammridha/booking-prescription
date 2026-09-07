<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Integer paisa (BDT minor unit). Never floats.
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(public int $paisa, public string $currency = 'BDT') {}

    public static function bdt(int $paisa): self
    {
        return new self($paisa);
    }

    public static function fromTaka(int|float|string $taka): self
    {
        if (! is_numeric($taka)) {
            throw new InvalidArgumentException("Invalid amount [{$taka}].");
        }

        return new self((int) round(((float) $taka) * 100));
    }

    public function add(Money $other): self
    {
        return new self($this->paisa + $other->paisa);
    }

    public function subtract(Money $other): self
    {
        return new self($this->paisa - $other->paisa);
    }

    public function isZero(): bool
    {
        return $this->paisa === 0;
    }

    public function format(): string
    {
        $sign = $this->paisa < 0 ? '-' : '';
        $abs = abs($this->paisa);

        return sprintf('%s৳%s.%02d', $sign, number_format(intdiv($abs, 100)), $abs % 100);
    }

    /** @return array{paisa: int, formatted: string} */
    public function jsonSerialize(): array
    {
        return ['paisa' => $this->paisa, 'formatted' => $this->format()];
    }
}
