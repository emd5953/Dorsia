<?php

declare(strict_types=1);

namespace App\Shared\Money;

use InvalidArgumentException;
use OverflowException;

final readonly class Money
{
    /**
     * @throws InvalidArgumentException if $cents < 0
     */
    public function __construct(public int $cents, public Currency $currency)
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }
    }

    public static function of(int $cents, Currency|string $currency): self
    {
        return new self($cents, $currency instanceof Currency ? $currency : Currency::from($currency));
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->cents + $other->cents), $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new InvalidArgumentException('Money cannot be multiplied by a negative factor.');
        }

        return new self(self::checked($this->cents * $factor), $this->currency);
    }

    public function min(Money $other): self
    {
        $this->assertSameCurrency($other);

        return $other->cents < $this->cents ? $other : $this;
    }

    public function bps(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('Basis points cannot be negative.');
        }

        return new self(intdiv(self::checked($this->cents * $basisPoints), 10000), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function equals(Money $other): bool
    {
        return $this->cents === $other->cents && $this->currency->value === $other->currency->value;
    }

    public function greaterThan(Money $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents > $other->cents;
    }

    public function greaterThanOrEqual(Money $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents >= $other->cents;
    }

    /**
     * @return array{cents: int, currency: string}
     */
    public function toArray(): array
    {
        return ['cents' => $this->cents, 'currency' => $this->currency->value];
    }

    /**
     * PHP turns int overflow into float; refuse it instead of letting a TypeError escape.
     */
    private static function checked(int|float $value): int
    {
        if (! is_int($value)) {
            throw new OverflowException('Money amount overflows the integer range.');
        }

        return $value;
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency->value !== $other->currency->value) {
            throw new CurrencyMismatchException(
                "Currency mismatch: {$this->currency->value} vs {$other->currency->value}."
            );
        }
    }
}
