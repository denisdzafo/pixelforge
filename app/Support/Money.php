<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

final readonly class Money implements JsonSerializable, Stringable
{
    public const int SCALE = 9;

    public const int NANOS_PER_USD = 1_000_000_000;

    public function __construct(public int $nanos) {}

    public static function nanos(int $nanos): self
    {
        return new self($nanos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromUsd(string $usd): self
    {
        return new self(self::parseDecimal($usd, self::SCALE));
    }

    public static function parseDecimal(string $value, int $scale): int
    {
        $value = trim($value);

        if (! preg_match('/^([+-])?(\d+)(?:\.(\d*))?$/', $value, $m)) {
            throw new InvalidArgumentException("Not a decimal number: [{$value}]");
        }

        $negative = $m[1] === '-';
        $whole = ltrim($m[2], '0');
        $fraction = $m[3] ?? '';

        if (strlen($fraction) > $scale) {
            if (trim(substr($fraction, $scale), '0') !== '') {
                throw new InvalidArgumentException("[{$value}] has more than {$scale} significant decimals");
            }
            $fraction = substr($fraction, 0, $scale);
        }

        $digits = ltrim($whole.str_pad($fraction, $scale, '0'), '0');

        if ($digits === '') {
            return 0;
        }

        if (strlen($digits) > 18) {
            throw new InvalidArgumentException("[{$value}] is too large");
        }

        $int = (int) $digits;

        return $negative ? -$int : $int;
    }

    public function plus(self $other): self
    {
        return new self($this->nanos + $other->nanos);
    }

    public function minus(self $other): self
    {
        return new self($this->nanos - $other->nanos);
    }

    public function negate(): self
    {
        return new self(-$this->nanos);
    }

    public function isNegative(): bool
    {
        return $this->nanos < 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->nanos > $other->nanos;
    }

    public function lessThan(self $other): bool
    {
        return $this->nanos < $other->nanos;
    }

    public function equals(self $other): bool
    {
        return $this->nanos === $other->nanos;
    }

    public function toDecimal(): string
    {
        $abs = abs($this->nanos);
        $whole = intdiv($abs, self::NANOS_PER_USD);
        $fraction = str_pad((string) ($abs % self::NANOS_PER_USD), self::SCALE, '0', STR_PAD_LEFT);

        return ($this->nanos < 0 ? '-' : '').$whole.'.'.$fraction;
    }

    public function format(): string
    {
        [$whole, $fraction] = explode('.', ltrim($this->toDecimal(), '-'));
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, 2, '0');

        return ($this->nanos < 0 ? '-' : '').'$'.number_format((int) $whole).'.'.$fraction;
    }

    public function __toString(): string
    {
        return (string) $this->nanos;
    }

    public function jsonSerialize(): string
    {
        return (string) $this->nanos;
    }
}
