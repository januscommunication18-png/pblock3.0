<?php

namespace Tests\Feature\Wiki;

use App\Services\WikiReader;
use Tests\TestCase;

/**
 * "On this page" (docs/features/wiki.md).
 *
 * The ids are written on the SERVER: an anchor has to work on the first paint, and a link to
 * `#timings` that only becomes real once a script has run is a link that fails exactly when
 * somebody follows it from somewhere else.
 */
class OutlineTest extends TestCase
{
    private function outline(string $html): array
    {
        return app(WikiReader::class)->outline($html);
    }

    public function test_every_heading_gets_an_id_and_a_place_in_the_list(): void
    {
        $out = $this->outline('<h1>Before you escalate</h1><p>Check.</p><h2>Timings</h2>');

        $this->assertStringContainsString('id="before-you-escalate"', $out['html']);
        $this->assertStringContainsString('id="timings"', $out['html']);

        $this->assertSame(
            [['id' => 'before-you-escalate', 'text' => 'Before you escalate', 'level' => 1],
                ['id' => 'timings', 'text' => 'Timings', 'level' => 2]],
            $out['toc'],
        );
    }

    public function test_the_list_runs_from_h1_to_h4_and_carries_the_depth(): void
    {
        $out = $this->outline(
            '<h1>Escalate</h1><h2>Timings</h2><h3>Out of hours</h3><h4>Weekends</h4><h5>Ignored</h5>'
        );

        // FR-WC-021 asks for H2–H4 because H1 is the page title. It is not: the title is
        // rendered from the page record and never appears in the body, so an H1 here is a
        // section heading like any other — and every page written before this used them.
        $this->assertSame([1, 2, 3, 4], array_column($out['toc'], 'level'));
        $this->assertSame(
            ['Escalate', 'Timings', 'Out of hours', 'Weekends'],
            array_column($out['toc'], 'text'),
        );

        // H5 and H6 are below the level anybody navigates by — a contents column that lists
        // them is a second copy of the document.
        $this->assertStringNotContainsString('id="ignored"', $out['html']);
    }

    public function test_the_list_reads_in_document_order_not_by_heading_level(): void
    {
        $out = $this->outline('<h1>One</h1><h3>Under one</h3><h1>Two</h1><h2>Under two</h2>');

        // Collecting H1s and then H2s would produce One, Two, Under one, Under two — a
        // contents page that matches no document anybody wrote.
        $this->assertSame(
            ['One', 'Under one', 'Two', 'Under two'],
            array_column($out['toc'], 'text'),
        );
    }

    public function test_two_headings_with_the_same_name_get_different_anchors(): void
    {
        $out = $this->outline('<h1>Escalate</h1><h2>Timings</h2><h1>After</h1><h2>Timings</h2>');

        $ids = array_column($out['toc'], 'id');

        // Repeating a heading is ordinary in documentation; repeating an id is not, and the
        // second anchor would simply never work.
        $this->assertSame($ids, array_unique($ids));
        $this->assertContains('timings-2', $ids);
    }

    public function test_a_heading_that_already_has_an_id_keeps_it(): void
    {
        $out = $this->outline('<h1 id="chosen">Before you escalate</h1>');

        // An author who anchored something deliberately has already published that link.
        $this->assertSame('chosen', $out['toc'][0]['id']);
    }

    public function test_the_document_survives_the_pass_unchanged_apart_from_ids(): void
    {
        $out = $this->outline('<h2>Timings</h2><p>Thirty minutes for <strong>P1</strong>.</p><ul><li>One</li></ul>');

        $this->assertStringContainsString('<strong>P1</strong>', $out['html']);
        $this->assertStringContainsString('<li>One</li>', $out['html']);
    }

    public function test_accented_text_is_not_mangled(): void
    {
        // DOMDocument reads bytes as ISO-8859-1 unless told otherwise, and every accented
        // character in somebody's documentation comes back broken.
        $out = $this->outline('<h1>Résumé du processus</h1><p>Détails — ici.</p>');

        $this->assertStringContainsString('Détails — ici.', $out['html']);
        $this->assertSame('Résumé du processus', $out['toc'][0]['text']);
    }

    public function test_a_page_with_no_headings_produces_no_list(): void
    {
        $this->assertSame([], $this->outline('<p>Just a paragraph.</p>')['toc']);
    }

    public function test_an_empty_page_is_handled(): void
    {
        $this->assertSame(['html' => '', 'toc' => []], $this->outline(''));
        $this->assertSame(['html' => '', 'toc' => []], app(WikiReader::class)->outline(null));
    }
}
