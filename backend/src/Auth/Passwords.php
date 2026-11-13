<?php

declare(strict_types=1);

namespace Sacco\Auth;

/**
 * Temporary passwords and the rules for the password a user chooses.
 *
 * Every user gets their own random temporary password (never a shared default), and must replace
 * it at first login. We deliberately do NOT refuse a password because another user has it:
 * that error would tell the person someone else's password. Instead a user can't reuse any of
 * their own last HISTORY passwords.
 */
final class Passwords
{
    public const MIN_LENGTH = 8;
    public const HISTORY = 5;

    /** Letters and digits that can't be mistaken for each other when read off a slip (no 0/O, 1/l/I) */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** e.g. "Hk7m-q4Tx" */
    public static function temporary(): string
    {
        $pick = fn (int $n) => implode('', array_map(
            fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)],
            range(1, $n)
        ));
        do {
            $password = $pick(4) . '-' . $pick(4);
        } while (!preg_match('/\d/', $password) || !preg_match('/[a-z]/i', $password));
        return $password;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * A one-time temporary password, hashed more cheaply so a bulk import of hundreds of members
     * finishes in seconds (it is random, and replaced at first login with a fully hashed one).
     */
    public static function hashTemporary(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 8]);
    }

    /**
     * What's wrong with a new password, or null if it's fine.
     *
     * @param string[] $previousHashes this user's current and recent password hashes
     */
    public static function problem(string $password, array $user, array $previousHashes): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'Use at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (!preg_match('/[a-z]/i', $password) || !preg_match('/\d/', $password)) {
            return 'Use both letters and numbers.';
        }
        $lower = strtolower($password);
        foreach ([strstr((string) $user['email'], '@', true), $user['employee_no']] as $own) {
            if ($own && strlen($own) >= 3 && str_contains($lower, strtolower($own))) {
                return 'Don\'t use your email or employee number in your password.';
            }
        }
        foreach ($previousHashes as $hash) {
            if (password_verify($password, $hash)) {
                return 'Choose a password you haven\'t used before.';
            }
        }
        return null;
    }
}
