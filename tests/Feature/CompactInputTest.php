<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `.pb-input` plus a narrowing width utility is a silent no-op.
 *
 * public/assets/css/styles.css declares `.pb-input { display:block; width:100% }` and is loaded
 * AFTER tailwind.css. Both are single-class selectors, so the later one wins and a `w-20` on the
 * same element is simply ignored: the field stretches to fill its row and every sibling in that
 * row is squashed or wrapped.
 *
 * Nothing about this fails loudly. The page renders, the input works, and it looks like a
 * layout opinion rather than a bug — which is exactly why it survived review once already, on
 * the Capacity Mapping row. `.pb-input.is-compact` is the way to narrow one.
 */
class CompactInputTest extends TestCase
{
    /**
     * Utilities that try to make an element NARROWER than its container.
     *
     * `!w-20` is excluded: Tailwind's important prefix does beat `.pb-input`, and the codebase
     * already uses it that way. The trap is only the un-prefixed form, which looks identical
     * in the markup and does nothing.
     */
    private const NARROWING = '/(?<![!\w-])w-(?!full\b|auto\b)(\d+|\[[^\]]+\])/';

    public function test_no_input_tries_to_narrow_pb_input_with_a_width_utility(): void
    {
        $offenders = [];

        foreach ($this->stylesheets() as $file) {
            $code = file_get_contents($file);

            // Every class="..." literal that mentions pb-input.
            preg_match_all('/class="([^"]*\bpb-input\b[^"]*)"/', $code, $matches);

            foreach ($matches[1] ?? [] as $classes) {
                if (str_contains($classes, 'is-compact')) {
                    continue;
                }

                if (preg_match(self::NARROWING, $classes, $hit)) {
                    $offenders[] = basename($file).' → "'.$hit[0].'" in ['.$classes.']';
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A width utility on .pb-input is silently ignored — use `pb-input is-compact`:'],
            $offenders,
        )));
    }

    public function test_the_compact_variant_still_exists_to_be_used(): void
    {
        // The advice above is only advice if the class is real.
        $css = file_get_contents(public_path('assets/css/styles.css'));

        $this->assertStringContainsString('.pb-input.is-compact', $css);
    }

    /** @return array<int, string> */
    private function stylesheets(): array
    {
        $files = [];

        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(public_path('assets/js'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($dir as $file) {
            if ($file->getExtension() === 'js' && ! str_contains($file->getPathname(), '/vendor/')) {
                $files[] = $file->getPathname();
            }
        }

        foreach (glob(resource_path('views/**/*.blade.php')) ?: [] as $blade) {
            $files[] = $blade;
        }

        return $files;
    }
}
