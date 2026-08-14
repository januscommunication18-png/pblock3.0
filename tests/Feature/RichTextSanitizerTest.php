<?php

namespace Tests\Feature;

use App\Services\RichTextSanitizer;
use Tests\TestCase;

/**
 * What survives the sanitizer on the way into a description, a comment or a page.
 *
 * Every rich-text field in the application goes through this, so a format the editors can
 * PRODUCE but the sanitizer strips is the worst kind of bug: the toolbar works, the text
 * changes on screen, and the formatting is gone by the time anyone reads it back. These pin
 * the formats the toolbar offers — added when the format-block and font-size controls did.
 */
class RichTextSanitizerTest extends TestCase
{
    private function clean(string $html): string
    {
        return (string) app(RichTextSanitizer::class)->sanitize($html);
    }

    public function test_the_format_block_menu_survives(): void
    {
        // Jodit's `paragraph` control writes real heading elements.
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $this->assertStringContainsString("<{$tag}>", $this->clean("<{$tag}>Heading</{$tag}>"));
        }

        $this->assertStringContainsString('<blockquote>', $this->clean('<blockquote>Quoted</blockquote>'));
        $this->assertStringContainsString('<pre>', $this->clean('<pre>code()</pre>'));
    }

    public function test_the_font_size_menu_survives(): void
    {
        // Jodit's `fontsize` control writes an inline style on a span.
        $clean = $this->clean('<p>Normal <span style="font-size: 18px">bigger</span></p>');

        $this->assertStringContainsString('font-size', $clean);
        $this->assertStringContainsString('bigger', $clean);

        // …and on a heading, which is styleable for the same reason.
        $this->assertStringContainsString('font-size', $this->clean('<h2 style="font-size: 24px">Big</h2>'));
    }

    public function test_the_rest_of_the_toolbar_survives(): void
    {
        $cases = [
            '<p><strong>bold</strong></p>' => '<strong>',
            '<p><em>italic</em></p>' => '<em>',
            '<p><u>underline</u></p>' => '<u>',
            '<p><s>struck</s></p>' => '<s>',
            '<ul><li>one</li></ul>' => '<ul>',
            '<ol><li>one</li></ol>' => '<ol>',
            '<p><a href="https://example.com">link</a></p>' => 'href="https://example.com"',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertStringContainsString($expected, $this->clean($input), $input);
        }
    }

    public function test_it_still_refuses_what_it_always_refused(): void
    {
        // The formats above are additions to what is allowed, not a relaxation of the rest.
        $this->assertStringNotContainsString('<script', $this->clean('<p>hi</p><script>alert(1)</script>'));
        $this->assertStringNotContainsString('onerror', $this->clean('<img src="x" onerror="alert(1)">'));
        $this->assertStringNotContainsString('javascript:', $this->clean('<a href="javascript:alert(1)">x</a>'));
        // An inline style is allowed to size text, not to smuggle a URL or an expression.
        $this->assertStringNotContainsString('expression', $this->clean('<p style="width:expression(alert(1))">x</p>'));
    }
}
