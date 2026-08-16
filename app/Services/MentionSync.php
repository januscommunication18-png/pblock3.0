<?php

namespace App\Services;

use App\Models\Mention;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Collection;

/**
 * Turns submitted rich text into validated mention records (mentions §10-§15, §23, §24, §27).
 *
 * The single rule this class exists to enforce: **the editor is not the source of truth.**
 * A `<span data-user-id="999">` arriving from a browser is a claim, not a mention. Nothing is
 * recorded until the id names a real user who may be mentioned in this project, so a crafted
 * payload naming an administrator produces exactly nothing (§24).
 *
 * `sync()` is deliberately a DIFF rather than an insert:
 *
 *  - §11/§12 — editing content must notify only the people newly named. Somebody already
 *    mentioned in the previous version has already been told, and telling them again on every
 *    subsequent edit is how a mention becomes noise.
 *  - §14 — the same person named three times in one comment is one mention. Enforced by the
 *    unique index as well as here, so it cannot be got wrong by a future caller.
 *  - Names removed from the text have their records deleted, so the content and the records
 *    cannot drift apart.
 *
 * It returns only the people who are NEW to this piece of content — the list the notifier acts
 * on — which is why the self-mention rule (§15) lives at the notifier rather than here: the
 * record is worth keeping either way, the email is not.
 */
class MentionSync
{
    public function __construct(private readonly MentionableUsers $mentionable) {}

    /**
     * Reconcile the mentions on one piece of content, and report who is newly named.
     *
     * @return Collection<int, User> the users to notify — newly mentioned, in this project
     */
    public function sync(
        string $sourceType,
        int $sourceId,
        ?string $html,
        Project $project,
        ?WorkItem $workItem,
        ?User $actor,
    ): Collection {
        $claimed = $this->parse($html);
        $valid = $this->mentionable->filterIds($project, $claimed->all());

        $existing = Mention::query()
            ->forSource($sourceType, $sourceId)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);

        // Gone from the text: the record goes with it, or "who is mentioned here" stops
        // matching what the content says.
        $removed = $existing->diff($valid);
        if ($removed->isNotEmpty()) {
            Mention::query()->forSource($sourceType, $sourceId)->whereIn('user_id', $removed)->delete();
        }

        $added = $valid->diff($existing);
        foreach ($added as $userId) {
            Mention::create([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'work_item_id' => $workItem?->id,
                'user_id' => $userId,
                'mentioned_by' => $actor?->id,
            ]);
        }

        if ($added->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $added)->get();
    }

    /**
     * The user ids a piece of HTML claims to mention (§27).
     *
     * Only STRUCTURED mentions count — a `<span>` carrying `data-user-id`, which is what the
     * editor's autocomplete inserts. Typing "@Rohit Philip" by hand is plain text and produces
     * nothing, which is the rule that stops pasted or spoofed text raising notifications (§26).
     *
     * Read with a regex rather than a DOM parse on purpose: the markup has already been
     * through RichTextSanitizer, which is what decides that `data-user-id` may exist at all,
     * so this only has to read what survived that.
     *
     * @return Collection<int, int>
     */
    public function parse(?string $html): Collection
    {
        if (! $html) {
            return collect();
        }

        if (! preg_match_all('/data-user-id="(\d+)"/i', $html, $matches)) {
            return collect();
        }

        return collect($matches[1])
            ->map(fn (string $id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            // §14: the same person twice in one body is one mention.
            ->unique()
            ->values();
    }
}
