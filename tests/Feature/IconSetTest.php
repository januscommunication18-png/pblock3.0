<?php

namespace Tests\Feature;

use App\Support\IconRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The icon set switch.
 *
 * The whole point of routing icons through pb_icon() is that swapping the UI to Font Awesome
 * Pro — or back — is one config value. These tests are what make that claim true rather than
 * aspirational: they check both sets render, that the legacy set asks for no webfont, and
 * that no screen references an icon the registry does not hold.
 */
class IconSetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legacy_set_renders_inline_svg_and_loads_no_font(): void
    {
        config()->set('icons.set', 'legacy');

        $icon = pb_icon('gear', 15, 'text-faint');

        $this->assertStringStartsWith('<svg', $icon);
        $this->assertStringContainsString('width="15" height="15"', $icon);
        $this->assertStringContainsString('text-faint', $icon);
        $this->assertStringContainsString('aria-hidden="true"', $icon);

        // The default build must not ship a 350KB webfont for icons it does not use.
        $this->assertSame('', pb_icon_styles());
        $this->assertStringNotContainsString('fontawesome', $this->get(route('signup'))->getContent());
    }

    public function test_the_fontawesome_set_renders_a_glyph_and_loads_the_stylesheet(): void
    {
        config()->set('icons.set', 'fontawesome');
        config()->set('icons.fa_family', 'sharp');

        $icon = pb_icon('gear', 15, 'text-faint');

        // Sharp needs BOTH classes: fa-sharp picks the family, fa-regular the weight.
        // Emitting only one renders a blank square, which is the failure this pins.
        $this->assertStringContainsString('fa-sharp fa-regular', $icon);
        $this->assertStringContainsString('fa-gear', $icon);
        $this->assertStringContainsString('font-size:15px', $icon);
        $this->assertStringContainsString('text-faint', $icon);

        $this->assertStringContainsString('sharp-regular.min.css', pb_icon_styles());

        // Every layout links the stylesheet when the set is on — including screens whose own
        // icons have not been converted yet, so a half-migrated app still renders correctly.
        $this->assertStringContainsString('fontawesome', $this->get(route('signup'))->getContent());
    }

    public function test_every_icon_used_by_a_view_is_registered(): void
    {
        $unknown = [];

        foreach ($this->bladeFiles() as $file) {
            preg_match_all("/pb_icon\('([^']+)'/", (string) file_get_contents($file), $m);

            foreach ($m[1] as $name) {
                if (! IconRegistry::has($name)) {
                    $unknown[] = str_replace(base_path().'/', '', $file)." → {$name}";
                }
            }
        }

        // A name with no entry renders nothing, so a control silently loses its meaning.
        $this->assertSame([], $unknown, "Icons used but not registered:\n".implode("\n", $unknown));
    }

    public function test_an_unknown_icon_is_loud_in_development(): void
    {
        config()->set('app.debug', true);

        // Failing quietly would mean a typo ships as an invisible button.
        $this->expectException(InvalidArgumentException::class);
        pb_icon('not-a-real-icon');
    }

    public function test_both_sets_define_every_registered_icon(): void
    {
        foreach (IconRegistry::all() as $name => $icon) {
            $this->assertArrayHasKey('fa', $icon, "{$name} has no Font Awesome name");
            $this->assertArrayHasKey('svg', $icon, "{$name} has no legacy SVG");
            $this->assertNotSame('', trim($icon['fa']), "{$name} has an empty Font Awesome name");
            $this->assertStringContainsString('<', $icon['svg'], "{$name} has no legacy markup to fall back to");
        }
    }

    public function test_the_family_is_a_switch_of_its_own(): void
    {
        config()->set('icons.set', 'fontawesome');

        config()->set('icons.fa_family', 'classic');
        $this->assertStringContainsString('fa-regular fa-gear', pb_icon('gear'));
        $this->assertStringNotContainsString('fa-sharp', pb_icon('gear'));
        $this->assertStringContainsString('css/regular.min.css', pb_icon_styles());

        config()->set('icons.fa_family', 'sharp');
        $this->assertStringContainsString('fa-sharp fa-regular fa-gear', pb_icon('gear'));
        $this->assertStringContainsString('sharp-regular.min.css', pb_icon_styles());

        // A mistyped family costs the chosen look, not the page.
        config()->set('icons.fa_family', 'nonsense');
        $this->assertStringContainsString('fa-gear', pb_icon('gear'));
    }

    public function test_every_vendored_family_is_self_hosted(): void
    {
        $this->assertFileExists(public_path(config('icons.fa_core_css')));

        foreach (config('icons.fa_families') as $family => $meta) {
            $css = public_path($meta['css']);
            $this->assertFileExists($css, "The {$family} stylesheet is not vendored.");

            // Same rule as every other asset: fonts are served from here, never a CDN.
            preg_match_all('/url\(([^)]+)\)/', (string) file_get_contents($css), $m);
            $this->assertNotEmpty($m[1], "The {$family} stylesheet references no webfont.");

            foreach ($m[1] as $url) {
                $this->assertStringNotContainsString('http', $url, "{$family} loads a font from {$url}");
                $this->assertFileExists(
                    public_path('assets/vendor/fontawesome/'.ltrim(str_replace('../', '', $url), '/')),
                    "{$family} references a webfont that is not vendored: {$url}",
                );
            }
        }
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
