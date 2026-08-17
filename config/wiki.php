<?php

/*
|--------------------------------------------------------------------------
| Wiki & Knowledge Management (docs/features/wiki.md)
|--------------------------------------------------------------------------
| The navigation the Wiki presents once a workspace enables it. Declared here rather
| than in the view so the sidebar, the home screen and the routes that fill them in
| later all read one list.
*/

return [

    'sections' => [
        ['key' => 'home', 'label' => 'Home', 'icon' => 'house',
            'blurb' => 'Start from one central place and reach the knowledge that matters to you.'],
        ['key' => 'collections', 'label' => 'Collections', 'icon' => 'folder',
            'blurb' => 'Browse every collection you have permission to open.'],
        ['key' => 'shared', 'label' => 'Shared', 'icon' => 'users',
            'blurb' => 'Knowledge other people have shared with you.'],
        ['key' => 'private', 'label' => 'Private', 'icon' => 'lock',
            'blurb' => 'The private collections and pages available to you.'],
        ['key' => 'archived', 'label' => 'Archived', 'icon' => 'box-archive',
            'blurb' => 'Retired knowledge, out of the way but still readable.'],
    ],

    /*
     * What a Cover Page section card can wear (docs/features/wiki-cover-page.md, FR-WC-007).
     *
     * A shortlist, not the whole of App\Support\IconRegistry: a landing page wants a handful of
     * recognisable shapes, and a grid of a hundred is a decision nobody wants to make. Anything
     * outside the registry is refused whatever this list says, so growing it is a one-line change
     * rather than a migration.
     */
    'cover_icons' => [
        'folder', 'file-lines', 'house', 'users', 'gear', 'lock', 'globe',
        'lightbulb', 'star', 'gem', 'key', 'clock', 'calendar', 'tag',
        'inbox', 'grid', 'list-ul', 'table', 'link', 'play', 'circle-info',
    ],
];
