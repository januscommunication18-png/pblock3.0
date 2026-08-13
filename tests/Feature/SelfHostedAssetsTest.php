<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nothing the browser loads may come from a third party.
 *
 * The app used to pull Tailwind from cdn.tailwindcss.com, Inter from Google Fonts, Tabulator
 * from unpkg and Quill from jsdelivr. That is now all served from public/assets. Two reasons
 * it has to stay that way, and a test rather than a convention because a single pasted <link>
 * undoes it silently:
 *
 *  - a Dev/UAT site behind the access gate should not be announcing itself to four CDNs, and
 *  - the app should render with no outbound network at all.
 */
class SelfHostedAssetsTest extends TestCase
{
    use RefreshDatabase;

    /** Hosts no page may reference. */
    private const FORBIDDEN = [
        'cdn.tailwindcss.com',
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'unpkg.com',
        'cdn.jsdelivr.net',
    ];

    public function test_no_blade_view_references_a_cdn(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $contents = (string) file_get_contents($file);

            foreach (self::FORBIDDEN as $host) {
                if (str_contains($contents, $host)) {
                    $offenders[] = str_replace(base_path().'/', '', $file)." → {$host}";
                }
            }
        }

        $this->assertSame([], $offenders, "These views load assets from a CDN:\n".implode("\n", $offenders));
    }

    public function test_the_signup_page_serves_every_asset_locally(): void
    {
        $html = $this->get(route('signup'))->assertOk()->getContent();

        foreach (self::FORBIDDEN as $host) {
            $this->assertStringNotContainsString($host, $html);
        }

        // …and the local ones it replaced are actually linked.
        $this->assertStringContainsString('assets/css/tailwind.css', $html);
        $this->assertStringContainsString('assets/css/inter.css', $html);
    }

    public function test_the_built_assets_exist_and_carry_the_projects_theme(): void
    {
        $css = public_path('assets/css/tailwind.css');
        $this->assertFileExists($css, 'Run `npm run build:css` — the compiled stylesheet is missing.');

        // The theme tokens have to survive the build, or the whole UI loses its palette.
        $compiled = (string) file_get_contents($css);
        foreach (['#1b5f8a', 'text-head', 'rounded-card'] as $token) {
            $this->assertStringContainsString($token, $compiled, "tailwind.css is missing {$token}");
        }

        foreach ([
            'assets/css/inter.css',
            'assets/vendor/tabulator/tabulator.min.js',
            'assets/vendor/tabulator/tabulator.min.css',
            'assets/vendor/datatables/datatables.min.js',
            'assets/vendor/datatables/datatables.min.css',
            'assets/vendor/quill/quill.js',
            'assets/vendor/quill/quill.snow.css',
        ] as $asset) {
            $this->assertFileExists(public_path($asset));
        }

        // The font stylesheet must point at local files, not back at Google.
        $this->assertStringNotContainsString('fonts.gstatic.com', (string) file_get_contents(public_path('assets/css/inter.css')));
    }

    /**
     * No class name may be assembled by string concatenation.
     *
     * This is the failure mode that comes with dropping the Play CDN. The CDN compiled
     * whatever it found in the live DOM, so `'h-[' + size + 'px]'` worked; a pre-built
     * stylesheet only ever sees the literal `'h-['`, generates nothing, and the element
     * silently loses that property. It cost the grid its avatars — they collapsed to the
     * size of the letter inside them — and nothing failed except the look of the page.
     *
     * A computed value belongs in an inline `style`, which is what wiAvatar() now does.
     * Anything genuinely dynamic and class-shaped needs a `safelist` entry in
     * tailwind.config.js instead.
     */
    public function test_no_class_name_is_built_by_concatenation(): void
    {
        // A string literal that ENDS mid-class — 'h-[', 'bg-', 'text-' — immediately followed
        // by a concatenation. Date building like `y + '-' + m` does not match: the dash has
        // no utility prefix in front of it.
        $pattern = '/\'(?:min-|max-)?(?:h|w|p|px|py|m|mx|my|top|left|right|bottom|z|gap|text|bg|border|rounded|leading|basis|grid-cols|translate-x|translate-y)-\[?\'\s*\.?\s*\+/';

        $offenders = [];
        foreach ($this->jsFiles() as $file) {
            foreach (preg_split('/\R/', (string) file_get_contents($file)) as $n => $line) {
                // Comments discuss the pattern — including the one above wiAvatar explaining
                // why it must not be used. Only code counts.
                if (preg_match('/^\s*(\/\/|\*|\/\*)/', $line)) {
                    continue;
                }

                if (preg_match($pattern, $line)) {
                    $offenders[] = str_replace(base_path().'/', '', $file).':'.($n + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders,
            "These build a Tailwind class by concatenation, which the compiled stylesheet cannot see:\n".implode("\n", $offenders));
    }

    /** @return array<int, string> */
    private function jsFiles(): array
    {
        return glob(public_path('assets/js/projects/*.js')) ?: [];
    }

    /** @return array<int, string> */
    private function bladeFiles(): array
    {
        $files = [];
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($dir as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
