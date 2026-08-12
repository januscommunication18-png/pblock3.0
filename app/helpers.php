<?php

use App\Support\IconRegistry;

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
            $url .= (str_contains($url, '?') ? '&' : '?').'v='.filemtime($full);
        }

        return $url;
    }
}

if (! function_exists('pb_icon')) {
    /**
     * Render a named icon from whichever set is configured (config/icons.php).
     *
     * One call site, two possible outputs — the hand-drawn SVG or a Font Awesome Pro glyph —
     * so switching the whole UI over, or back, is a config value rather than a diff.
     *
     *   pb_icon('gear')                        16px, inherits colour
     *   pb_icon('gear', 18)                    at 18px
     *   pb_icon('gear', 15, 'text-faint')      with extra classes
     *
     * Icons here are decorative: the control around them carries the label, so the output is
     * aria-hidden. An icon that is the ONLY content of a control still needs an aria-label on
     * that control — which is how every button in this app is already written.
     */
    function pb_icon(string $name, int $size = 16, string $class = ''): string
    {
        $icons = IconRegistry::all();

        // A missing name is a typo, and a silently empty icon is a UI that quietly loses a
        // control's meaning. Fail loudly in development, render nothing in production.
        if (! isset($icons[$name])) {
            if (config('app.debug')) {
                throw new InvalidArgumentException("Unknown icon [{$name}]. Add it to App\\Support\\IconRegistry.");
            }

            return '';
        }

        $classes = trim('pb-icon '.$class);

        if (config('icons.set') === 'fontawesome') {
            return sprintf(
                '<i class="%s fa-%s %s" style="font-size:%dpx;line-height:1" aria-hidden="true"></i>',
                e(pb_icon_family()['prefix']),
                e($icons[$name]['fa']),
                e($classes),
                $size,
            );
        }

        return sprintf(
            '<svg width="%d" height="%d" viewBox="0 0 24 24" fill="none" class="%s" aria-hidden="true">%s</svg>',
            $size,
            $size,
            e($classes),
            $icons[$name]['svg'],
        );
    }
}

if (! function_exists('pb_icon_styles')) {
    /**
     * The stylesheet links the current icon set needs — nothing at all for the legacy SVGs,
     * so the default build sends no webfont.
     */
    function pb_icon_styles(): string
    {
        if (config('icons.set') !== 'fontawesome') {
            return '';
        }

        // The engine first, then the one family in use — not `all.min.css`, which would pull
        // in every family in the pack and each one's webfont.
        return collect([config('icons.fa_core_css'), pb_icon_family()['css']])
            ->filter()
            ->map(fn (string $path) => sprintf('<link rel="stylesheet" href="%s" />', pb_asset($path)))
            ->implode("\n  ");
    }
}

if (! function_exists('pb_icon_family')) {
    /**
     * The configured Font Awesome family — its class prefix and its stylesheet.
     *
     * Falls back to the first family defined rather than throwing: a mistyped family name
     * should cost the chosen look, not the whole page.
     *
     * @return array{prefix: string, css: string}
     */
    function pb_icon_family(): array
    {
        $families = config('icons.fa_families', []);
        $name = config('icons.fa_family');

        return $families[$name] ?? reset($families) ?: ['prefix' => 'fa-regular', 'css' => ''];
    }
}

if (! function_exists('pb_icon_boot')) {
    /**
     * Tells the JS icon helper which set to draw — the runtime half of the same switch.
     *
     * Emitted before the screen scripts, so a Vue template or a grid formatter renders the
     * same set as the Blade around it. Without this the two halves could disagree, which is
     * exactly the drift the shared registry exists to prevent.
     */
    function pb_icon_boot(): string
    {
        if (config('icons.set') !== 'fontawesome') {
            return '';
        }

        return sprintf(
            '<script>window.PB_ICON_SET=%s;window.PB_ICON_PREFIX=%s;</script>',
            json_encode('fontawesome'),
            json_encode(pb_icon_family()['prefix']),
        );
    }
}
