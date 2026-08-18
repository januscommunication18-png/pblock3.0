<?php

use App\Http\Controllers\Project\CycleController;
use App\Http\Controllers\Project\EpicController;
use App\Http\Controllers\Project\EstimationController;
use App\Http\Controllers\Project\MentionController;
use App\Http\Controllers\Project\ModuleController;
use App\Http\Controllers\Project\PageController;
use App\Http\Controllers\Project\ProjectController;
use App\Http\Controllers\Project\ProjectLabelController;
use App\Http\Controllers\Project\ProjectMembersController;
use App\Http\Controllers\Project\ProjectOverviewController;
use App\Http\Controllers\Project\ProjectSettingsController;
use App\Http\Controllers\Project\ProjectStateController;
use App\Http\Controllers\Project\ProjectViewColumnController;
use App\Http\Controllers\Project\ProjectViewController;
use App\Http\Controllers\Project\ProjectViewGridController;
use App\Http\Controllers\Project\WorkItemCollaborationController;
use App\Http\Controllers\Project\WorkItemController;
use App\Http\Controllers\Project\WorkItemAttachmentController;
use App\Http\Controllers\Project\WorkItemMediaController;
use App\Http\Controllers\Project\WorkItemReactionController;
use App\Http\Controllers\Project\WorkItemStructureController;
use App\Http\Controllers\WorkItemQuickCreateController;
use App\Models\Project;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Phase 4 — Projects routes (loaded by routes/web.php)
|--------------------------------------------------------------------------
| Projects live under the active workspace. 'workspace.tenancy' initializes
| tenancy so Project bindings/queries are auto-confined to the workspace.
| This file is the one web.php requires (require __DIR__.'/project.php';) and
| now carries the full list + lifecycle + settings route set.
*/

Route::middleware(['auth', 'workspace.tenancy'])
    ->prefix('projects')
    ->name('projects.')
    ->group(function () {
        // List + create
        Route::get('/', [ProjectController::class, 'index'])->name('index');
        Route::post('/', [ProjectController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::get('/identifier-available', [ProjectController::class, 'identifierAvailable'])->name('identifier');

        // Open + lifecycle
        Route::get('/{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('show');
        Route::patch('/{project}', [ProjectController::class, 'update'])->whereNumber('project')->name('update');
        Route::patch('/{project}/state', [ProjectController::class, 'setState'])->whereNumber('project')->name('state');
        Route::patch('/{project}/lead', [ProjectController::class, 'setLead'])->whereNumber('project')->name('lead');
        Route::patch('/{project}/priority', [ProjectController::class, 'setPriority'])->whereNumber('project')->name('priority');
        Route::patch('/{project}/dates', [ProjectController::class, 'setDates'])->whereNumber('project')->name('dates');
        Route::post('/{project}/archive', [ProjectController::class, 'archive'])->whereNumber('project')->name('archive');
        Route::post('/{project}/restore', [ProjectController::class, 'restore'])->whereNumber('project')->name('restore');
        Route::delete('/{project}', [ProjectController::class, 'destroy'])->whereNumber('project')->name('destroy');

        // Project Workspace (Phase 5). Work Items is the only functional tab; the other five
        // resolve to a Coming Soon page. `tab` is whitelisted so these never shadow
        // /{project}/settings below.
        Route::get('/{project}/work-items', [WorkItemController::class, 'index'])
            ->whereNumber('project')->name('work-items');
        Route::post('/{project}/work-items', [WorkItemController::class, 'store'])
            ->whereNumber('project')->middleware('throttle:60,1')->name('work-items.store');
        // Description editor media (SunEditor image upload + gallery). These sit ABOVE the
        // /{workItem} routes so "media" is never read as a work item id.
        Route::post('/{project}/work-items/media', [WorkItemMediaController::class, 'store'])
            ->whereNumber('project')->middleware('throttle:60,1')->name('work-items.media.store');
        Route::get('/{project}/work-items/media', [WorkItemMediaController::class, 'index'])
            ->whereNumber('project')->name('work-items.media.index');
        Route::get('/{project}/work-items/media/{media}', [WorkItemMediaController::class, 'show'])
            ->whereNumber(['project', 'media'])->name('work-items.media.show');

        // Work item attachments (docs/features/work-item-attachments.md). These DO nest under
        // /{workItem} — an attachment belongs to one item, unlike editor media, which is
        // uploaded before an item necessarily exists.
        Route::post('/{project}/work-items/{workItem}/attachments', [WorkItemAttachmentController::class, 'store'])
            ->whereNumber(['project', 'workItem'])->middleware('throttle:60,1')->name('work-items.attachments.store');
        Route::get('/{project}/work-items/{workItem}/attachments', [WorkItemAttachmentController::class, 'index'])
            ->whereNumber(['project', 'workItem'])->name('work-items.attachments.index');
        Route::get('/{project}/work-items/{workItem}/attachments/{attachment}', [WorkItemAttachmentController::class, 'show'])
            ->whereNumber(['project', 'workItem', 'attachment'])->name('work-items.attachments.show');
        Route::delete('/{project}/work-items/{workItem}/attachments/{attachment}', [WorkItemAttachmentController::class, 'destroy'])
            ->whereNumber(['project', 'workItem', 'attachment'])->name('work-items.attachments.destroy');

        // The quick-create modal's pickers for one project. Above the /{workItem} routes so
        // "options" is never read as a work item id.
        Route::get('/{project}/work-items/options', [WorkItemQuickCreateController::class, 'options'])
            ->whereNumber('project')->name('work-items.options');

        // Who the editor may offer when somebody types `@` (mentions §5). Above the
        // /{workItem} routes so "mentionable-users" is never read as a work item id.
        Route::get('/{project}/mentionable-users', [MentionController::class, 'index'])
            ->whereNumber('project')->name('mentionable-users');

        // Structure: sub-tasks, dependencies, relations, links (Collaboration §19-§41).
        Route::get('/{project}/work-items/{workItem}/structure', [WorkItemStructureController::class, 'show'])
            ->whereNumber(['project', 'workItem'])->name('work-items.structure');
        Route::get('/{project}/work-items/{workItem}/search', [WorkItemStructureController::class, 'search'])
            ->whereNumber(['project', 'workItem'])->name('work-items.search');
        Route::post('/{project}/work-items/{workItem}/subtasks', [WorkItemStructureController::class, 'storeSubtasks'])
            ->whereNumber(['project', 'workItem'])->name('work-items.subtasks.store');
        Route::delete('/{project}/work-items/{workItem}/subtasks/{child}', [WorkItemStructureController::class, 'destroySubtask'])
            ->whereNumber(['project', 'workItem', 'child'])->name('work-items.subtasks.destroy');
        Route::post('/{project}/work-items/{workItem}/relations', [WorkItemStructureController::class, 'storeRelations'])
            ->whereNumber(['project', 'workItem'])->name('work-items.relations.store');
        Route::delete('/{project}/work-items/{workItem}/relations/{relation}', [WorkItemStructureController::class, 'destroyRelation'])
            ->whereNumber(['project', 'workItem', 'relation'])->name('work-items.relations.destroy');
        // Create a project label from the work item's label picker (applied by the normal
        // label PATCH straight after).
        // Linked pages (§ Pages): the documentation a work item points at.
        Route::get('/{project}/work-items/{workItem}/pages/search', [WorkItemStructureController::class, 'searchPages'])
            ->whereNumber(['project', 'workItem'])->name('work-items.pages.search');
        Route::post('/{project}/work-items/{workItem}/pages', [WorkItemStructureController::class, 'storePages'])
            ->whereNumber(['project', 'workItem'])->name('work-items.pages.store');
        Route::delete('/{project}/work-items/{workItem}/pages/{page}', [WorkItemStructureController::class, 'destroyPage'])
            ->whereNumber(['project', 'workItem', 'page'])->name('work-items.pages.destroy');

        Route::post('/{project}/work-items/{workItem}/labels', [WorkItemStructureController::class, 'storeLabel'])
            ->whereNumber(['project', 'workItem'])->middleware('throttle:30,1')->name('work-items.labels.store');
        Route::post('/{project}/work-items/{workItem}/links', [WorkItemStructureController::class, 'storeLink'])
            ->whereNumber(['project', 'workItem'])->name('work-items.links.store');
        Route::patch('/{project}/work-items/{workItem}/links/{link}', [WorkItemStructureController::class, 'updateLink'])
            ->whereNumber(['project', 'workItem', 'link'])->name('work-items.links.update');
        Route::delete('/{project}/work-items/{workItem}/links/{link}', [WorkItemStructureController::class, 'destroyLink'])
            ->whereNumber(['project', 'workItem', 'link'])->name('work-items.links.destroy');

        // Collaboration: the seven-tab feed plus comments, updates and worklogs
        // (Activity & Audit §15). Activity / history / transitions are read-only by design.
        Route::get('/{project}/work-items/{workItem}/feed', [WorkItemCollaborationController::class, 'show'])
            ->whereNumber(['project', 'workItem'])->name('work-items.feed');
        Route::post('/{project}/work-items/{workItem}/comments', [WorkItemCollaborationController::class, 'storeComment'])
            ->whereNumber(['project', 'workItem'])->middleware('throttle:60,1')->name('work-items.comments.store');
        Route::patch('/{project}/work-items/{workItem}/comments/{comment}', [WorkItemCollaborationController::class, 'updateComment'])
            ->whereNumber(['project', 'workItem', 'comment'])->name('work-items.comments.update');
        Route::delete('/{project}/work-items/{workItem}/comments/{comment}', [WorkItemCollaborationController::class, 'destroyComment'])
            ->whereNumber(['project', 'workItem', 'comment'])->name('work-items.comments.destroy');
        Route::post('/{project}/work-items/{workItem}/updates', [WorkItemCollaborationController::class, 'storeUpdate'])
            ->whereNumber(['project', 'workItem'])->name('work-items.updates.store');
        Route::patch('/{project}/work-items/{workItem}/updates/{update}', [WorkItemCollaborationController::class, 'updateUpdate'])
            ->whereNumber(['project', 'workItem', 'update'])->name('work-items.updates.update');
        Route::delete('/{project}/work-items/{workItem}/updates/{update}', [WorkItemCollaborationController::class, 'destroyUpdate'])
            ->whereNumber(['project', 'workItem', 'update'])->name('work-items.updates.destroy');
        Route::post('/{project}/work-items/{workItem}/worklogs', [WorkItemCollaborationController::class, 'storeWorklog'])
            ->whereNumber(['project', 'workItem'])->name('work-items.worklogs.store');
        Route::patch('/{project}/work-items/{workItem}/worklogs/{worklog}', [WorkItemCollaborationController::class, 'updateWorklog'])
            ->whereNumber(['project', 'workItem', 'worklog'])->name('work-items.worklogs.update');
        Route::delete('/{project}/work-items/{workItem}/worklogs/{worklog}', [WorkItemCollaborationController::class, 'destroyWorklog'])
            ->whereNumber(['project', 'workItem', 'worklog'])->name('work-items.worklogs.destroy');

        Route::get('/{project}/work-items/{workItem}/activity', [WorkItemController::class, 'activity'])
            ->whereNumber(['project', 'workItem'])->name('work-items.activity');
        // Detail toolbar: vote and subscribe (POC html/work-items.html). Gated on VIEWING the
        // item, not editing it — see WorkItemReactionController.
        Route::post('/{project}/work-items/{workItem}/vote', [WorkItemReactionController::class, 'vote'])
            ->whereNumber(['project', 'workItem'])->middleware('throttle:60,1')->name('work-items.vote');
        Route::post('/{project}/work-items/{workItem}/subscribe', [WorkItemReactionController::class, 'subscribe'])
            ->whereNumber(['project', 'workItem'])->middleware('throttle:60,1')->name('work-items.subscribe');
        // Row actions + inline chip edits (§4.2/§4.4). `show` is the stable per-item URL that
        // "Open in new tab" and "Copy link" resolve to.
        Route::get('/{project}/work-items/{workItem}', [WorkItemController::class, 'show'])
            ->whereNumber(['project', 'workItem'])->name('work-items.show');
        // The same detail without the app chrome, for the slide-over panel the Views grid
        // opens a work item into (Views §7.3). Same guard as `show`.
        Route::get('/{project}/work-items/{workItem}/frame', [WorkItemController::class, 'frame'])
            ->whereNumber(['project', 'workItem'])->name('work-items.frame');
        Route::patch('/{project}/work-items/{workItem}', [WorkItemController::class, 'update'])
            ->whereNumber(['project', 'workItem'])->name('work-items.update');
        Route::post('/{project}/work-items/{workItem}/archive', [WorkItemController::class, 'archive'])
            ->whereNumber(['project', 'workItem'])->name('work-items.archive');
        Route::post('/{project}/work-items/{workItem}/restore', [WorkItemController::class, 'restore'])
            ->whereNumber(['project', 'workItem'])->name('work-items.restore');
        Route::post('/{project}/work-items/{workItem}/duplicate', [WorkItemController::class, 'duplicate'])
            ->whereNumber(['project', 'workItem'])->name('work-items.duplicate');
        Route::delete('/{project}/work-items/{workItem}', [WorkItemController::class, 'destroy'])
            ->whereNumber(['project', 'workItem'])->name('work-items.destroy');
        // ---- Cycles (Cycles §17). Registered BEFORE the {tab} catch-all below, which would
        //      otherwise swallow /cycles and render Coming Soon over a built feature.
        //      Every action re-checks CyclePolicy, which refuses when the project has the
        //      feature switched off — the routes existing is not permission to use them.
        Route::get('/{project}/cycles', [CycleController::class, 'index'])
            ->whereNumber('project')->name('cycles');
        Route::post('/{project}/cycles', [CycleController::class, 'store'])
            ->whereNumber('project')->name('cycles.store');
        Route::get('/{project}/cycles/{cycle}/search', [CycleController::class, 'search'])
            ->whereNumber(['project', 'cycle'])->name('cycles.search');
        Route::post('/{project}/cycles/{cycle}/transfer', [CycleController::class, 'transfer'])
            ->whereNumber(['project', 'cycle'])->name('cycles.transfer');
        Route::post('/{project}/cycles/{cycle}/work-items', [CycleController::class, 'addWorkItems'])
            ->whereNumber(['project', 'cycle'])->name('cycles.items.store');
        Route::delete('/{project}/cycles/{cycle}/work-items/{workItem}', [CycleController::class, 'removeWorkItem'])
            ->whereNumber(['project', 'cycle', 'workItem'])->name('cycles.items.destroy');
        Route::get('/{project}/cycles/{cycle}', [CycleController::class, 'show'])
            ->whereNumber(['project', 'cycle'])->name('cycles.show');
        Route::patch('/{project}/cycles/{cycle}', [CycleController::class, 'update'])
            ->whereNumber(['project', 'cycle'])->name('cycles.update');
        Route::delete('/{project}/cycles/{cycle}', [CycleController::class, 'destroy'])
            ->whereNumber(['project', 'cycle'])->name('cycles.destroy');

        // ---- Modules (Module Management §19). Registered BEFORE the {tab} catch-all, which
        //      would otherwise swallow /modules and render Coming Soon over a built feature.
        //      Every action re-checks ModulePolicy, which refuses when the project has the
        //      feature switched off — the routes existing is not permission to use them.
        Route::get('/{project}/modules', [ModuleController::class, 'index'])
            ->whereNumber('project')->name('modules');
        Route::post('/{project}/modules', [ModuleController::class, 'store'])
            ->whereNumber('project')->name('modules.store');
        Route::get('/{project}/modules/{module}/search', [ModuleController::class, 'search'])
            ->whereNumber(['project', 'module'])->name('modules.search');
        Route::post('/{project}/modules/{module}/archive', [ModuleController::class, 'archive'])
            ->whereNumber(['project', 'module'])->name('modules.archive');
        Route::post('/{project}/modules/{module}/restore', [ModuleController::class, 'restore'])
            ->whereNumber(['project', 'module'])->name('modules.restore');
        Route::post('/{project}/modules/{module}/work-items', [ModuleController::class, 'addWorkItems'])
            ->whereNumber(['project', 'module'])->name('modules.items.store');
        Route::delete('/{project}/modules/{module}/work-items/{workItem}', [ModuleController::class, 'removeWorkItem'])
            ->whereNumber(['project', 'module', 'workItem'])->name('modules.items.destroy');
        Route::get('/{project}/modules/{module}', [ModuleController::class, 'show'])
            ->whereNumber(['project', 'module'])->name('modules.show');
        Route::patch('/{project}/modules/{module}', [ModuleController::class, 'update'])
            ->whereNumber(['project', 'module'])->name('modules.update');
        Route::delete('/{project}/modules/{module}', [ModuleController::class, 'destroy'])
            ->whereNumber(['project', 'module'])->name('modules.destroy');

        // ---- Epics (Epic §21). Registered BEFORE the {tab} catch-all, which would otherwise
        //      swallow /epics and render Coming Soon over a built feature. Every action
        //      re-checks EpicPolicy, which refuses when the project has the feature switched
        //      off — the routes existing is not permission to use them.
        Route::get('/{project}/epics', [EpicController::class, 'index'])
            ->whereNumber('project')->name('epics');
        Route::post('/{project}/epics', [EpicController::class, 'store'])
            ->whereNumber('project')->name('epics.store');
        Route::get('/{project}/epics/{epic}/search', [EpicController::class, 'search'])
            ->whereNumber(['project', 'epic'])->name('epics.search');
        Route::post('/{project}/epics/{epic}/archive', [EpicController::class, 'archive'])
            ->whereNumber(['project', 'epic'])->name('epics.archive');
        Route::post('/{project}/epics/{epic}/restore', [EpicController::class, 'restore'])
            ->whereNumber(['project', 'epic'])->name('epics.restore');
        Route::post('/{project}/epics/{epic}/work-items', [EpicController::class, 'addWorkItems'])
            ->whereNumber(['project', 'epic'])->name('epics.items.store');
        Route::delete('/{project}/epics/{epic}/work-items/{workItem}', [EpicController::class, 'removeWorkItem'])
            ->whereNumber(['project', 'epic', 'workItem'])->name('epics.items.destroy');
        Route::get('/{project}/epics/{epic}', [EpicController::class, 'show'])
            ->whereNumber(['project', 'epic'])->name('epics.show');
        Route::patch('/{project}/epics/{epic}', [EpicController::class, 'update'])
            ->whereNumber(['project', 'epic'])->name('epics.update');
        Route::delete('/{project}/epics/{epic}', [EpicController::class, 'destroy'])
            ->whereNumber(['project', 'epic'])->name('epics.destroy');

        // ---- Pages (§4/§17). Registered BEFORE the {tab} catch-all, which would otherwise
        //      swallow /pages and render Coming Soon over a built feature. Every action
        //      re-checks ProjectPagePolicy, which refuses when the project has the feature
        //      switched off — the routes existing is not permission to use them.
        Route::get('/{project}/pages', [PageController::class, 'index'])
            ->whereNumber('project')->name('pages');
        Route::post('/{project}/pages', [PageController::class, 'store'])
            ->whereNumber('project')->name('pages.store');
        Route::post('/{project}/pages/{page}/archive', [PageController::class, 'archive'])
            ->whereNumber(['project', 'page'])->name('pages.archive');
        Route::get('/{project}/pages/{page}/versions', [PageController::class, 'versions'])
            ->whereNumber(['project', 'page'])->name('pages.versions');
        Route::post('/{project}/pages/{page}/versions/{version}/restore', [PageController::class, 'restoreVersion'])
            ->whereNumber(['project', 'page', 'version'])->name('pages.versions.restore');
        Route::get('/{project}/pages/{page}', [PageController::class, 'show'])
            ->whereNumber(['project', 'page'])->name('pages.show');
        Route::patch('/{project}/pages/{page}', [PageController::class, 'update'])
            ->whereNumber(['project', 'page'])->name('pages.update');
        Route::delete('/{project}/pages/{page}', [PageController::class, 'destroy'])
            ->whereNumber(['project', 'page'])->name('pages.destroy');

        // ---- Views (§4/§23). Registered BEFORE the {tab} catch-all for the same reason Pages
        //      is: the catch-all still lists 'views' and would render Coming Soon over the
        //      built feature. Every action re-checks ProjectViewPolicy — the routes existing
        //      is not permission to use them.
        Route::get('/{project}/views', [ProjectViewController::class, 'index'])
            ->whereNumber('project')->name('views');
        Route::post('/{project}/views', [ProjectViewController::class, 'store'])
            ->whereNumber('project')->name('views.store');

        // Data. `rows` is hit once per page of scroll, so it is the one route here worth
        // keeping cheap; the cell edit is throttled because it writes.
        Route::get('/{project}/views/{view}/rows', [ProjectViewGridController::class, 'rows'])
            ->whereNumber(['project', 'view'])->name('views.rows');
        Route::patch('/{project}/views/{view}/rows/{workItem}', [ProjectViewGridController::class, 'updateCell'])
            ->whereNumber(['project', 'view', 'workItem'])->name('views.rows.update');

        // Configuration (§8/§9/§10). A separate permission from the data routes above (§14).
        Route::get('/{project}/views/{view}/fields', [ProjectViewColumnController::class, 'fields'])
            ->whereNumber(['project', 'view'])->name('views.fields');
        Route::post('/{project}/views/{view}/columns', [ProjectViewColumnController::class, 'store'])
            ->whereNumber(['project', 'view'])->name('views.columns.store');
        // BEFORE the {column} routes: 'order' would otherwise be read as a column id.
        Route::put('/{project}/views/{view}/columns/order', [ProjectViewColumnController::class, 'order'])
            ->whereNumber(['project', 'view'])->name('views.columns.order');
        Route::patch('/{project}/views/{view}/columns/{column}', [ProjectViewColumnController::class, 'update'])
            ->whereNumber(['project', 'view', 'column'])->name('views.columns.update');
        Route::delete('/{project}/views/{view}/columns/{column}', [ProjectViewColumnController::class, 'destroy'])
            ->whereNumber(['project', 'view', 'column'])->name('views.columns.destroy');

        // Lifecycle (§5.3).
        Route::post('/{project}/views/{view}/duplicate', [ProjectViewController::class, 'duplicate'])
            ->whereNumber(['project', 'view'])->name('views.duplicate');
        Route::post('/{project}/views/{view}/favorite', [ProjectViewController::class, 'favorite'])
            ->whereNumber(['project', 'view'])->name('views.favorite');
        // The grid on its own — no sidebar, no project tabs (§18.3's shape, internally).
        // BEFORE the /{view} route so 'external' is not read as part of it.
        Route::get('/{project}/views/{view}/external', [ProjectViewController::class, 'external'])
            ->whereNumber(['project', 'view'])->name('views.external');
        Route::get('/{project}/views/{view}', [ProjectViewController::class, 'show'])
            ->whereNumber(['project', 'view'])->name('views.show');
        Route::patch('/{project}/views/{view}', [ProjectViewController::class, 'update'])
            ->whereNumber(['project', 'view'])->name('views.update');
        Route::delete('/{project}/views/{view}', [ProjectViewController::class, 'destroy'])
            ->whereNumber(['project', 'view'])->name('views.destroy');

        // ---- Overview + Milestones (Project Overview §1).
        //      Overview was the last tab still resolving to Coming Soon through the catch-all
        //      below; it has a real screen now, so it is routed ahead of it like every other
        //      built tab. Milestones is the same screen's second segment and 404s while the
        //      feature is off, so the URL and the segment agree.
        Route::get('/{project}/overview', [ProjectOverviewController::class, 'show'])
            ->whereNumber('project')->name('overview');
        Route::get('/{project}/milestones', [ProjectOverviewController::class, 'milestones'])
            ->whereNumber('project')->name('milestones');

        // ---- Estimation configuration (§10/§20-§23). Project Admin only; every action
        //      re-checks it, because the routes existing is not permission to use them.
        Route::post('/{project}/settings/estimation', [EstimationController::class, 'configure'])
            ->whereNumber('project')->name('settings.estimation.configure');
        Route::post('/{project}/settings/estimation/reorder', [EstimationController::class, 'reorder'])
            ->whereNumber('project')->name('settings.estimation.reorder');
        Route::post('/{project}/settings/estimation/values', [EstimationController::class, 'storeValue'])
            ->whereNumber('project')->name('settings.estimation.values.store');
        Route::post('/{project}/settings/estimation/values/{value}/restore', [EstimationController::class, 'restoreValue'])
            ->whereNumber(['project', 'value'])->name('settings.estimation.values.restore');
        Route::patch('/{project}/settings/estimation/values/{value}', [EstimationController::class, 'updateValue'])
            ->whereNumber(['project', 'value'])->name('settings.estimation.values.update');
        Route::delete('/{project}/settings/estimation/values/{value}', [EstimationController::class, 'destroyValue'])
            ->whereNumber(['project', 'value'])->name('settings.estimation.values.destroy');

        // Project settings actions
        Route::prefix('/{project}/settings')->name('settings.')->group(function () {
            Route::patch('/general', [ProjectSettingsController::class, 'updateGeneral'])->name('general.update');
            Route::post('/cover', [ProjectSettingsController::class, 'uploadCover'])->name('cover');
            Route::post('/features/toggle', [ProjectSettingsController::class, 'toggleFeature'])->name('features.toggle');

            Route::post('/members', [ProjectMembersController::class, 'store'])->name('members.store');
            Route::patch('/members/{member}/role', [ProjectMembersController::class, 'updateRole'])->name('members.role');
            Route::delete('/members/{member}', [ProjectMembersController::class, 'remove'])->name('members.remove');

            Route::post('/states', [ProjectStateController::class, 'store'])->name('states.store');
            Route::patch('/states/{state}', [ProjectStateController::class, 'update'])->name('states.update');
            Route::delete('/states/{state}', [ProjectStateController::class, 'destroy'])->name('states.destroy');

            Route::post('/labels', [ProjectLabelController::class, 'store'])->name('labels.store');
            Route::patch('/labels/{label}', [ProjectLabelController::class, 'update'])->name('labels.update');
            Route::delete('/labels/{label}', [ProjectLabelController::class, 'destroy'])->name('labels.destroy');
            // §11: Active → Archived → Restored, one endpoint because it is one switch.
            Route::post('/labels/{label}/archive', [ProjectLabelController::class, 'archive'])->name('labels.archive');
        });

        // Section pages — LAST so the static settings routes above win. GET only.
        Route::get('/{project}/settings', fn (Project $project) => redirect()->route('projects.settings', ['project' => $project->id, 'section' => 'general']))
            ->whereNumber('project')->name('settings.index');
        Route::get('/{project}/settings/{section}', [ProjectSettingsController::class, 'show'])
            ->whereNumber('project')->where('section', '[a-z-]+')->name('settings');
    });
