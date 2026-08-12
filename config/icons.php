<?php

/*
|--------------------------------------------------------------------------
| Icon set
|--------------------------------------------------------------------------
| The whole UI draws its icons through pb_icon(), which renders from ONE of
| two sources. Switching between them is this file — there is no second copy
| of the markup to keep in step, and reverting is not a revert commit.
|
|   legacy       the hand-drawn inline SVGs the app shipped with
|   fontawesome  Font Awesome Pro 7, self-hosted
|
| Set ICONS_SET in .env to switch a single environment without touching code.
*/

return [
    'set' => env('ICONS_SET', 'legacy'),

    /*
     * Which Font Awesome family. Also a switch rather than a hard-coded prefix, because
     * choosing between these is a look-at-it decision, not one to make from a docs page:
     *
     *   sharp    flat terminals and square corners — the geometric look
     *   classic  the rounded original
     *
     * Both are vendored, so flipping this needs no download. Delete whichever set of files
     * you settle away from once the choice is made.
     */
    'fa_family' => env('ICONS_FA_FAMILY', 'sharp'),

    /*
     * Per family: the class prefix an <i> needs, and the stylesheet that defines it.
     *
     * Sharp needs BOTH classes — `fa-sharp` selects the family, `fa-regular` the weight.
     * Classic is implied, so it takes the weight alone.
     *
     * Regular weight only: it is the closest match to the hand-drawn icons, which are stroked
     * at 1.6–1.8. Add an entry here to offer light or solid.
     */
    'fa_families' => [
        'sharp' => [
            'prefix' => 'fa-sharp fa-regular',
            'css' => 'assets/vendor/fontawesome/css/sharp-regular.min.css',
        ],
        'classic' => [
            'prefix' => 'fa-regular',
            'css' => 'assets/vendor/fontawesome/css/regular.min.css',
        ],
    ],

    /*
     * The engine every family needs. Vendored from the Pro package rather than `all.min.css`,
     * which pulls in every family in the pack and each one's webfont. Loaded only when the
     * set is `fontawesome`, so the legacy build sends no font at all.
     */
    'fa_core_css' => 'assets/vendor/fontawesome/css/fontawesome.min.css',
];
