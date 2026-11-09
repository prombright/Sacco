<?php

declare(strict_types=1);

namespace Sacco\Controllers;

use Sacco\Auth\Auth;
use Sacco\Auth\Roles;
use Sacco\Database;
use Sacco\Http\Request;
use Sacco\Http\Response;
use Sacco\Tenant;

/**
 * Profile photos. Every upload is redrawn as a 400 x 400 JPEG, so only a plain picture is kept
 * (nothing else hidden in the file, no camera details or location) and it's always small.
 */
final class PhotoController
{
    private const DIR = __DIR__ . '/../../storage/photos';
    private const SIZE = 400;

    /** POST /api/me/photo (multipart, field "photo") */
    public function upload(Request $request): never
    {
        $f = $_FILES['photo'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            Response::error('Choose a photo.', 422);
        }
        if ($f['size'] > 5 * 1024 * 1024) {
            Response::error('The photo must be under 5 MB.', 422);
        }
        // Trust the picture's own contents, not its name
        $info = @getimagesize($f['tmp_name']);
        if (!in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
            Response::error('Use a JPG, PNG or WebP photo.', 422);
        }
        if ($info[0] * $info[1] > 40_000_000) {
            Response::error('That photo is too large. Try a smaller one.', 422);
        }
        $src = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
        if (!$src) {
            Response::error('That photo couldn\'t be read.', 422);
        }
        $src = self::upright($src, $f['tmp_name'], $info['mime']);

        // The middle square, scaled down
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $out = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);

        $file = self::file((int) Auth::id());
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        if (!imagejpeg($out, $file, 85)) {
            Response::error('The photo couldn\'t be saved on the server.', 500);
        }
        $version = (string) time();
        Database::tenant()->prepare('UPDATE users SET photo = ? WHERE id = ?')->execute([$version, Auth::id()]);
        Auth::refresh();
        Response::ok(['user' => AuthController::present(Auth::user())]);
    }

    /** DELETE /api/me/photo */
    public function remove(Request $request): never
    {
        @unlink(self::file((int) Auth::id()));
        Database::tenant()->prepare('UPDATE users SET photo = NULL WHERE id = ?')->execute([Auth::id()]);
        Auth::refresh();
        Response::ok(['user' => AuthController::present(Auth::user())]);
    }

    /** GET /api/users/{id}/photo - your own, or anyone's for the office */
    public function show(Request $request): never
    {
        $id = (int) $request->param('id');
        $me = Auth::user();
        if ($id !== (int) $me['id'] && !in_array($me['role'], [Roles::ADMIN, Roles::SUPER_ADMIN], true)) {
            Response::error('You do not have permission to do that.', 403);
        }
        $file = self::file($id);
        if (!is_file($file)) {
            Response::error('No photo', 404);
        }
        header('Content-Type: image/jpeg');
        // The address changes with every new photo, so it can be kept a while (but only by this browser)
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    private static function file(int $userId): string
    {
        $code = preg_replace('/[^a-z0-9_-]/i', '', (string) (Tenant::current()['code'] ?? '_'));
        return self::DIR . "/$code/$userId.jpg";
    }

    /** Phone photos are often stored sideways with a note saying which way is up */
    private static function upright(\GdImage $img, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $img;
        }
        $turn = match ((int) (@exif_read_data($path)['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        return $turn ? (imagerotate($img, $turn, 0) ?: $img) : $img;
    }
}
