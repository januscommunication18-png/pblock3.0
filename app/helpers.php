<?php

/*
 * Global helpers (loaded from AppServiceProvider::register()).
 * Kept namespace-free so Blade's compiled views can call them directly.
 */

if (! function_exists('pb_asset')) {
    /**
     * Versioned asset URL — appends ?v=<filemtime> so browsers refetch whenever
     * the file on disk changes, instead of serving a stale cached copy.
     */
    function pb_asset(string $path): string
    {
        $url = asset($path);
        $full = public_path($path);

        if (is_file($full)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . filemtime($full);
        }

        return $url;
    }
}
