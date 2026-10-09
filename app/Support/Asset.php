<?php

namespace App\Support;

/**
 * Public asset URL with a content hash, so browsers and Cloudflare (which keep CSS/JS for hours)
 * fetch the new file right after a deploy instead of showing a new page with an old stylesheet.
 */
class Asset
{
    private static array $versions = [];

    public static function url(string $path): string
    {
        $file = public_path($path);
        $v = self::$versions[$path] ??= is_file($file) ? substr(hash_file('sha256', $file), 0, 10) : '';

        return asset($path).($v !== '' ? '?v='.$v : '');
    }
}
