<?php

/**
 * Proves the end-to-end chain works at all: Pest → Playwright → Chromium → a real Laravel
 * server → the real database. Everything else in tests/Browser assumes this passes.
 *
 * `/` is the sign-up screen — this app has no route named `login`, which is worth knowing
 * before writing a test that assumes the Laravel default.
 */
it('serves the entry page in a real browser', function () {
    $page = visit('/');

    $page->assertSee('Project Block')
        // The point of a browser test: the page's JavaScript actually ran.
        ->assertNoJavascriptErrors();
});
