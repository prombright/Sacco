<?php

declare(strict_types=1);

namespace Sacco\Support;

/**
 * Money is handled as whole cents (int) in PHP and DECIMAL(15,2) in the database - never float,
 * which can't hold amounts like 0.10 exactly.
 */
final class Money
{
    /** Largest amount DECIMAL(15,2) holds, in cents */
    public const MAX_CENTS = 9_999_999_999_999_99;

    /** "1,250,000.50" or 1250000.5 -> 125000050; null if it isn't a valid amount */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }
        if (!is_string($value)) {
            return null;
        }
        $value = str_replace([',', ' '], '', trim($value));
        if (!preg_match('/^(-?)(\d{1,13})(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }
        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');
        return $m[1] === '-' ? -$cents : $cents;
    }

    /** 125000050 -> "1250000.50", as the database stores it */
    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** A DECIMAL from the database -> cents */
    public static function fromDecimal(?string $decimal): int
    {
        return $decimal === null ? 0 : (int) self::parse($decimal);
    }
}
