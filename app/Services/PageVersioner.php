<?php

namespace App\Services;

use App\Models\ProjectPage;
use App\Models\ProjectPageVersion;
use App\Models\User;

/**
 * Keeps a page's version history.
 *
 * The hard part is not storing versions, it is storing the RIGHT number of them. The editor
 * autosaves after every pause in typing, so a version per save would bury one afternoon's work
 * in hundreds of near-identical rows and make the history useless for the thing it exists for
 * — finding the state you want to go back to.
 *
 * So consecutive saves by the SAME author inside a short window update the version already
 * being written rather than adding another. A different author, or a long enough gap, starts a
 * new one. That is what makes the list read like "Rohit, 10:42 · Sarah, 11:15" instead of a
 * scroll of timestamps a minute apart.
 */
class PageVersioner
{
    /**
     * Record the page's current state, coalescing with the version in progress.
     *
     * Called AFTER the page has been saved, so a version always describes a state the document
     * genuinely reached.
     */
    public function record(ProjectPage $page, ?User $actor): ?ProjectPageVersion
    {
        $latest = $this->latest($page);

        // Nothing changed that a reader would notice. Autosave fires on a timer, not only on
        // an edit, so this is the common case and must not produce a version.
        if ($latest
            && $latest->title === $page->title
            && $latest->content === $page->content
            && $latest->status === $page->status) {
            return $latest;
        }

        // Publishing or unpublishing is a milestone, not a keystroke — it gets its own entry
        // however recently the body was touched, so the history can answer "when did this go
        // live?".
        $statusChanged = $latest && $latest->status !== $page->status;

        if ($latest && ! $statusChanged && $this->shouldCoalesce($latest, $actor)) {
            $latest->forceFill([
                'title' => $page->title,
                'content' => $page->content,
                'status' => $page->status,
            ])->save();

            return $latest;
        }

        $version = ProjectPageVersion::create([
            'project_page_id' => $page->id,
            'title' => $page->title,
            'content' => $page->content,
            'status' => $page->status,
            'edited_by' => $actor?->id,
        ]);

        $this->prune($page);

        return $version;
    }

    /**
     * Restore a version's content onto the page.
     *
     * The restore is itself an edit: the state being replaced was already captured as a
     * version, so nothing is lost and going back is undoable. Restoring the title and status
     * too, because "what this page looked like" includes both.
     */
    public function restore(ProjectPage $page, ProjectPageVersion $version, ?User $actor): ProjectPage
    {
        $page->forceFill([
            'title' => $version->title,
            'content' => $version->content,
            'status' => $version->status,
            'updated_by' => $actor?->id,
        ])->save();

        // A fresh version for the restored state, attributed to whoever restored it — the
        // history should show that a restore happened, not silently rewind.
        ProjectPageVersion::create([
            'project_page_id' => $page->id,
            'title' => $page->title,
            'content' => $page->content,
            'status' => $page->status,
            'edited_by' => $actor?->id,
        ]);

        $this->prune($page);

        return $page->fresh();
    }

    public function latest(ProjectPage $page): ?ProjectPageVersion
    {
        return ProjectPageVersion::query()
            ->where('project_page_id', $page->id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Same author, and recent enough to still be "this editing session".
     *
     * Measured from when the version was CREATED, not when it was last written to. Using
     * `updated_at` looked equivalent and was not: every coalesce refreshes it, so the window
     * slid forward with each save and never closed — an afternoon of editing collapsed into a
     * single version that kept absorbing everything. From `created_at` the session is a fixed
     * span, and the next save after it opens a new one.
     *
     * Attribution is the other half: two people editing in turn always get their own versions
     * however fast they swap, which is what makes the window safe to have at all.
     */
    private function shouldCoalesce(ProjectPageVersion $latest, ?User $actor): bool
    {
        if ((int) $latest->edited_by !== (int) $actor?->id) {
            return false;
        }

        $minutes = (int) config('projects.page_version_window_minutes');

        return $latest->created_at !== null
            && $latest->created_at->diffInMinutes(now()) < $minutes;
    }

    /**
     * Keep the history bounded.
     *
     * A page edited daily for a year would otherwise accumulate versions nobody will open,
     * each carrying a full copy of the document. The cap is generous enough that it only ever
     * bites on genuinely long-lived pages.
     */
    private function prune(ProjectPage $page): void
    {
        $max = (int) config('projects.page_versions_max');

        $ids = ProjectPageVersion::query()
            ->where('project_page_id', $page->id)
            ->orderByDesc('id')
            ->pluck('id');

        if ($ids->count() <= $max) {
            return;
        }

        ProjectPageVersion::query()->whereIn('id', $ids->slice($max))->delete();
    }
}
