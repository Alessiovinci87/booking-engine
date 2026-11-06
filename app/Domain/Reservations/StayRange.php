<?php

namespace App\Domain\Reservations;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Stringable;

/**
 * Intervallo di soggiorno [check_in, check_out) (02; CLAUDE.md regola 6): le notti sono le date d con
 * check_in <= d < check_out e il giorno di check-out non è occupato (04, "Notti").
 *
 * Corrisponde a una colonna DATERANGE di PostgreSQL, che normalizza sempre in forma [).
 */
final class StayRange implements Stringable
{
    private function __construct(
        public readonly CarbonImmutable $checkIn,
        public readonly CarbonImmutable $checkOut,
    ) {}

    public static function of(DateTimeInterface|string $checkIn, DateTimeInterface|string $checkOut): self
    {
        $in = self::date($checkIn);
        $out = self::date($checkOut);

        if ($out->lessThanOrEqualTo($in)) {
            throw new InvalidArgumentException('Il check-out deve essere successivo al check-in.');
        }

        return new self($in, $out);
    }

    /**
     * Legge un daterange di PostgreSQL. Accetta anche limiti inclusi o esclusi in modo diverso da [)
     * e li riporta alla forma canonica.
     */
    public static function fromPostgres(string $value): self
    {
        if (preg_match('/^([\[(])(\d{4}-\d{2}-\d{2}),(\d{4}-\d{2}-\d{2})([\])])$/', trim($value), $m) !== 1) {
            throw new InvalidArgumentException('Intervallo di date non valido.');
        }

        $in = self::date($m[2]);
        $out = self::date($m[3]);

        if ($m[1] === '(') {
            $in = $in->addDay();
        }

        if ($m[4] === ']') {
            $out = $out->addDay();
        }

        return self::of($in, $out);
    }

    public function toPostgres(): string
    {
        return '['.$this->checkIn->toDateString().','.$this->checkOut->toDateString().')';
    }

    public function nights(): int
    {
        return (int) $this->checkIn->diffInDays($this->checkOut);
    }

    /** @return list<CarbonImmutable> */
    public function nightDates(): array
    {
        $dates = [];

        for ($d = $this->checkIn; $d->lessThan($this->checkOut); $d = $d->addDay()) {
            $dates[] = $d;
        }

        return $dates;
    }

    public function overlaps(self $other): bool
    {
        return $this->checkIn->lessThan($other->checkOut) && $other->checkIn->lessThan($this->checkOut);
    }

    public function equals(self $other): bool
    {
        return $this->toPostgres() === $other->toPostgres();
    }

    public function __toString(): string
    {
        return $this->toPostgres();
    }

    private static function date(DateTimeInterface|string $value): CarbonImmutable
    {
        if (is_string($value)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                throw new InvalidArgumentException('Data non valida: formato atteso YYYY-MM-DD.');
            }

            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

            if ($parsed === null || $parsed->toDateString() !== $value) {
                throw new InvalidArgumentException('Data non valida.');
            }

            return $parsed;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value->format('Y-m-d'), 'UTC')
            ?? throw new InvalidArgumentException('Data non valida.');
    }
}
