<?php

declare(strict_types=1);

namespace Sacco;

/**
 * Which SACCO a request belongs to. Each SACCO has its own database, listed in the platform
 * database's `saccos` table. The SACCO is found, in order, from:
 *
 * 1. the signed-in session (chosen at login)
 * 2. the web address (saccos.domain, e.g. stmarks.sacco.example)
 * 3. DEFAULT_SACCO_CODE in .env (handy locally and for a one-SACCO installation)
 */
final class Tenant
{
    private static array $byCode = [];

    /** A SACCO by its code, or null */
    public static function find(string $code): ?array
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return null;
        }
        if (!array_key_exists($code, self::$byCode)) {
            $stmt = Database::platform()->prepare('SELECT * FROM saccos WHERE code = ?');
            $stmt->execute([$code]);
            self::$byCode[$code] = $stmt->fetch() ?: null;
        }
        return self::$byCode[$code];
    }

    public static function findByDomain(string $host): ?array
    {
        $host = strtolower(preg_replace('/:\d+$/', '', $host));
        $stmt = Database::platform()->prepare('SELECT code FROM saccos WHERE domain = ?');
        $stmt->execute([$host]);
        $code = $stmt->fetchColumn();
        return $code ? self::find($code) : null;
    }

    /** The SACCO for this request when nobody is signed in yet (the login page) */
    public static function fromRequest(): ?array
    {
        return self::findByDomain($_SERVER['HTTP_HOST'] ?? '')
            ?? self::find((string) Config::get('DEFAULT_SACCO_CODE', ''));
    }

    /** The signed-in SACCO, else the one the request points at */
    public static function current(): ?array
    {
        $code = $_SESSION['sacco'] ?? null;
        return is_string($code) ? self::find($code) : self::fromRequest();
    }

    public static function databaseName(): string
    {
        $sacco = self::current();
        if (!$sacco) {
            throw new \RuntimeException('No SACCO selected');
        }
        return $sacco['db_name'];
    }

    /** Public details for the login page and the app header - never the database name */
    public static function publicInfo(array $sacco): array
    {
        return [
            'code' => $sacco['code'],
            'name' => $sacco['name'],
            'short_name' => $sacco['short_name'],
            'logo_url' => $sacco['logo_url'],
            'brand_color' => $sacco['brand_color'],
            'currency' => $sacco['currency'],
        ];
    }
}
