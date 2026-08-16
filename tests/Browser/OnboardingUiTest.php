<?php

use App\Models\User;

/**
 * The two UI bugs from the checkbox report, pinned in a browser.
 *
 * Both were invisible to the HTTP suite by construction: one was Font Awesome's stylesheet
 * beating Tailwind's `.hidden` at equal specificity, the other an icon whose Font Awesome
 * glyph is a different picture from its legacy SVG. Only a rendered page can tell.
 */
it('draws the goal checkboxes with nothing ticked to begin with', function () {
    $user = User::factory()->create(['full_name' => 'Thomas V. Brinson']);
    $this->actingAs($user);

    $page = visit('/onboarding/goals');

    $page->assertSee('What brings you to Project Block?')
        ->assertSee('Select one or more')
        ->assertNoJavascriptErrors();

    // The tick is inside every option and starts hidden. Before the fix Font Awesome's
    // `display: inline-block` beat `.hidden`, so every UNSELECTED option showed a checkmark.
    $ticks = $page->script('document.querySelectorAll("#goal-list .tick").length');
    $visibleTicks = $page->script(
        'Array.from(document.querySelectorAll("#goal-list .tick")).filter(function (t) {'
        .' return getComputedStyle(t).display !== "none"; }).length'
    );

    expect($ticks)->toBeGreaterThan(0)
        ->and($visibleTicks)->toBe(0, 'An unselected option is showing a checkmark.');
});

it('ticks an option when it is selected', function () {
    $user = User::factory()->create(['full_name' => 'Thomas V. Brinson']);
    $this->actingAs($user);

    $page = visit('/onboarding/goals');
    $page->click('Plan and track product roadmaps');

    $visibleTicks = $page->script(
        'Array.from(document.querySelectorAll("#goal-list .tick")).filter(function (t) {'
        .' return getComputedStyle(t).display !== "none"; }).length'
    );

    expect($visibleTicks)->toBe(1, 'A selected option is not showing its checkmark.');
});
