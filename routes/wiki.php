<?php

use App\Http\Controllers\Wiki\CollectionController;
use App\Http\Controllers\Wiki\CoverController;
use App\Http\Controllers\Wiki\GroupController;
use App\Http\Controllers\Wiki\PageController;
use App\Http\Controllers\Wiki\PublicCollectionController;
use App\Http\Controllers\Wiki\WikiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Wiki & Knowledge Management (docs/features/wiki.md)
|--------------------------------------------------------------------------
| Behind 'workspace.tenancy' like the other workspace-level areas, and behind the
| workspace's own Wiki switch — a workspace that has not enabled Wiki has no Wiki
| to reach, so these 404 rather than rendering an area its owner never turned on.
| require __DIR__.'/wiki.php'; from web.php
*/

Route::middleware(['auth', 'workspace.tenancy'])
    ->prefix('wiki')
    ->name('wiki.')
    ->group(function () {
        Route::get('/', [WikiController::class, 'home'])->name('home');
        Route::post('/collections', [WikiController::class, 'storeCollection'])->name('collections.store');

        /*
         * Shared / Private / Archived — the same collections seen through a different lens, so
         * one action rather than three that would differ only in a where clause.
         *
         * The segment is ENUMERATED, so `/wiki/anything-else` falls through to a 404 rather than
         * rendering an empty list, and the sidebar can build these hrefs straight off the config
         * key it already loops. Same shape as routes/auth.php's `whereIn('provider', …)`.
         */
        Route::get('/{section}', [WikiController::class, 'section'])
            ->whereIn('section', WikiController::LENSES)
            ->name('section');

        Route::get('/collections/{collection}', [CollectionController::class, 'show'])
            ->whereNumber('collection')->name('collections.show');
        Route::get('/collections/{collection}/preview', [CollectionController::class, 'preview'])
            ->whereNumber('collection')->name('collections.preview');
        Route::patch('/collections/{collection}', [CollectionController::class, 'update'])
            ->whereNumber('collection')->name('collections.update');
        Route::patch('/collections/{collection}/status', [CollectionController::class, 'updateStatus'])
            ->whereNumber('collection')->name('collections.status');
        Route::patch('/collections/{collection}/archive', [CollectionController::class, 'archive'])
            ->whereNumber('collection')->name('collections.archive');
        Route::delete('/collections/{collection}', [CollectionController::class, 'destroy'])
            ->whereNumber('collection')->name('collections.destroy');
        Route::post('/collections/{collection}/public-url', [CollectionController::class, 'generatePublicUrl'])
            ->whereNumber('collection')->name('collections.public-url');
        Route::post('/collections/{collection}/members', [CollectionController::class, 'storeMember'])
            ->whereNumber('collection')->name('collections.members.store');
        Route::delete('/collections/{collection}/members/{member}', [CollectionController::class, 'destroyMember'])
            ->whereNumber(['collection', 'member'])->name('collections.members.destroy');

        // The Cover Page — the card pinned above the Group view's sections
        // (docs/features/wiki-cover-page.md).
        Route::patch('/collections/{collection}/cover', [CoverController::class, 'update'])
            ->whereNumber('collection')->name('cover.update');

        // Groups — the Group view's sections.
        Route::post('/collections/{collection}/groups', [GroupController::class, 'store'])
            ->whereNumber('collection')->name('groups.store');
        // BEFORE {group}, or `reorder` is read as a group id.
        Route::patch('/collections/{collection}/groups/reorder', [GroupController::class, 'reorder'])
            ->whereNumber('collection')->name('groups.reorder');
        Route::patch('/collections/{collection}/groups/{group}', [GroupController::class, 'update'])
            ->whereNumber(['collection', 'group'])->name('groups.update');
        Route::delete('/collections/{collection}/groups/{group}', [GroupController::class, 'destroy'])
            ->whereNumber(['collection', 'group'])->name('groups.destroy');
        Route::patch('/collections/{collection}/pages/{page}/group', [GroupController::class, 'assign'])
            ->whereNumber(['collection', 'page'])->name('pages.group');

        // Pages inside a collection.
        Route::post('/collections/{collection}/pages', [PageController::class, 'store'])
            ->whereNumber('collection')->name('pages.store');
        // BEFORE the {page} routes, or `reorder` is swallowed as a page id.
        Route::patch('/collections/{collection}/pages/reorder', [PageController::class, 'reorder'])
            ->whereNumber('collection')->name('pages.reorder');
        Route::get('/collections/{collection}/pages/{page}', [PageController::class, 'show'])
            ->whereNumber(['collection', 'page'])->name('pages.show');
        Route::patch('/collections/{collection}/pages/{page}', [PageController::class, 'update'])
            ->whereNumber(['collection', 'page'])->name('pages.update');
        Route::patch('/collections/{collection}/pages/{page}/details', [PageController::class, 'details'])
            ->whereNumber(['collection', 'page'])->name('pages.details');
        Route::delete('/collections/{collection}/pages/{page}', [PageController::class, 'destroy'])
            ->whereNumber(['collection', 'page'])->name('pages.destroy');
    });

/*
| The public address of a published collection: /{workspace}/{slug}.
|
| Registered LAST, and required last from web.php, so every real route in the application
| wins before this pattern is even considered. `config('workspace.reserved_slugs')` keeps a
| workspace from being named after one of those paths, so the two can never be ambiguous.
|
| No auth and no tenancy middleware — whoever follows this link is a stranger.
*/
Route::get('/{workspace}/{slug}', [PublicCollectionController::class, 'show'])
    ->where('workspace', '[a-z0-9-]+')
    ->where('slug', '[a-z0-9-]+')
    ->name('wiki.public');
