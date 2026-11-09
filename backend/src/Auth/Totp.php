<?php

declare(strict_types=1);

namespace Sacco\Auth;

/** Six-digit codes from an authenticator app (RFC 6238: 30-second steps, SHA-1) */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const STEP = 30;

    /** A new random secret in base32, the form authenticator apps take */
    public static function secret(): string
    {
        $out = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $out .= self::ALPHABET[ord($byte) & 31];
        }
        return $out . self::randomTail();
    }

    private static function randomTail(): string
    {
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }
        return $out;
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);
        $offset = ord($hash[19]) & 15;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** The step a code is good for (allowing one step of clock drift), or null. A used step can't be replayed. */
    public static function verify(string $secret, string $code, ?int $lastStep = null): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = (int) floor(time() / self::STEP);
        foreach ([$now, $now - 1, $now + 1] as $step) {
            if (($lastStep === null || $step > $lastStep) && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }
        return $out;
    }
}
