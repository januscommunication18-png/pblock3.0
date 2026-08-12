<?php

namespace App\Console\Commands;

use App\Support\IconRegistry;
use Illuminate\Console\Command;

/**
 * Generates the JS twin of pb_icon() from the PHP registry.
 *
 * Most of this app's icons live in Vue string templates and Tabulator formatters under
 * public/assets/js, which Blade cannot reach. Rather than keep a second hand-written icon
 * list there — the thing that guarantees the two drift — the registry is exported to a
 * generated file that ships alongside the screen scripts.
 *
 * The generated file carries BOTH sets. Which one renders is decided at runtime from
 * `window.PB_ICON_SET`, set by the layout, so flipping config/icons.php still switches the
 * whole UI without regenerating anything.
 */
class BuildIconScript extends Command
{
    protected $signature = 'icons:build';

    protected $description = 'Generate public/assets/js/icons.js from App\\Support\\IconRegistry';

    public function handle(): int
    {
        $icons = IconRegistry::all();
        $json = json_encode($icons, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $js = <<<JS
        /* GENERATED — do not edit. Run `php artisan icons:build` after changing
           App\\Support\\IconRegistry.
           ------------------------------------------------------------------
           The JS twin of pb_icon(). Same registry, same names, same two sets, so an icon
           cannot mean one thing in Blade and another in a Vue template.

           Which set renders is read at runtime from window.PB_ICON_SET (the layout sets it
           from config/icons.php), so switching the config switches these too — no rebuild.
           ------------------------------------------------------------------ */
        var PB_ICONS = {$json};

        /**
         * Render a named icon.
         *
         *   wiIcon('gear')                     16px, inherits colour
         *   wiIcon('gear', 18)                 at 18px
         *   wiIcon('gear', 15, 'text-faint')   with extra classes
         *
         * Decorative: the control around it carries the label, so the output is aria-hidden.
         * An unknown name returns '' rather than throwing — a formatter that throws takes out
         * the whole grid, and a missing icon is the smaller failure.
         */
        function wiIcon(name, size, cls) {
            var icon = PB_ICONS[name];
            if (!icon) {
                if (window.console && console.warn) console.warn('Unknown icon: ' + name);

                return '';
            }

            size = size || 16;
            var classes = ('pb-icon ' + (cls || '')).trim();

            if (window.PB_ICON_SET === 'fontawesome') {
                return '<i class="' + (window.PB_ICON_PREFIX || 'fa-regular') + ' fa-' + icon.fa + ' ' + classes +
                    '" style="font-size:' + size + 'px;line-height:1" aria-hidden="true"></i>';
            }

            return '<svg width="' + size + '" height="' + size + '" viewBox="' + (icon.viewBox || '0 0 24 24') + '" fill="none" class="' +
                classes + '" aria-hidden="true">' + icon.svg + '</svg>';
        }
        JS;

        // Trim the heredoc's indentation so the generated file is not indented as a block.
        $js = implode("\n", array_map(fn ($l) => preg_replace('/^        /', '', $l), explode("\n", $js)));

        file_put_contents(public_path('assets/js/icons.js'), $js."\n");

        $this->info(count($icons).' icons written to public/assets/js/icons.js');

        return self::SUCCESS;
    }
}
