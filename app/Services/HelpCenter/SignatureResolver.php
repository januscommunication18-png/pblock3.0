<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterSignature;
use App\Models\HelpCenterSpace;
use App\Models\User;

/**
 * Which signature goes on an agent's reply (docs/features/help-center.md, P48).
 *
 * The requirement's whole rule, in one place:
 *
 *     Agent Signature → Space Default Signature → No Signature
 *
 * One place because it is asked in three: the reply that is actually sent, the preview an
 * administrator looks at, and the test email. Three copies of a priority order is how a preview
 * comes to show something the send does not do.
 *
 * ## The agent's signature now lives on their PROFILE (P74)
 *
 * "Save the signature against the user profile" — so `users.signature` is checked first. P48
 * stored an agent's signature PER SPACE, and those rows are still honoured underneath it, so
 * nothing anybody configured has stopped working. The full order is therefore:
 *
 *     profile signature → this Space's row for this agent (P48) → Space default → none
 *
 * Four tiers where the requirement names three, because the middle two are both "the agent's
 * signature" and one of them is only there so existing configuration survives. When the
 * per-Space agent editor in Space Settings is retired, that tier goes with it and the order is
 * exactly the three the requirement asks for.
 */
class SignatureResolver
{
    /**
     * The signature to use for `$user` in `$space`, or null for none.
     *
     * "Has a signature" means has one with something IN it — see `hasContent()`. An agent who
     * enabled a signature and left every field blank falls through to the Space default rather
     * than stopping the search at a row that would render nothing, which is what "no signature
     * configured" means in the requirement's own terms.
     */
    public function for(HelpCenterSpace $space, ?User $user): ?HelpCenterSignature
    {
        /*
         * ONE query for both candidates.
         *
         * `whereIn` with a null in the list does not match nulls in SQL, so the default row is
         * fetched with an explicit `orWhereNull` — the kind of thing that silently returns fewer
         * rows than expected and looks like missing data rather than a bad query.
         */
        $rows = HelpCenterSignature::query()
            ->where('help_center_space_id', $space->id)
            ->where(function ($q) use ($user) {
                $q->whereNull('user_id');

                if ($user !== null) {
                    $q->orWhere('user_id', $user->id);
                }
            })
            ->get();

        $own = $user === null
            ? null
            : $rows->first(fn (HelpCenterSignature $s) => (int) $s->user_id === (int) $user->id);

        if ($own !== null && $own->hasContent()) {
            return $own;
        }

        $default = $rows->first(fn (HelpCenterSignature $s) => $s->user_id === null);

        return $default !== null && $default->hasContent() ? $default : null;
    }

    /**
     * The resolved signature as HTML, or an empty string.
     *
     * The PROFILE signature wins (P74). Checked here rather than inside `for()` because `for()`
     * returns a `HelpCenterSignature` row and a profile signature is not one — it is text on the
     * user. Callers that need the rendered result use this; the one caller that needs the row
     * itself is the Space settings editor, which is asking a different question ("what is
     * configured in this Space?").
     */
    public function html(HelpCenterSpace $space, ?User $user): string
    {
        if ($user !== null && $user->hasSignature()) {
            return $user->signatureHtml();
        }

        return $this->for($space, $user)?->toHtml() ?? '';
    }

    /**
     * Save one, creating it if it is not there.
     *
     * `updateOrCreate` rather than an insert, because this is the only thing holding the
     * one-default-per-Space rule — see the note on the model about the unique index and NULL.
     */
    public function save(HelpCenterSpace $space, ?int $userId, array $data): HelpCenterSignature
    {
        return HelpCenterSignature::updateOrCreate(
            ['help_center_space_id' => $space->id, 'user_id' => $userId],
            $data + ['tenant_id' => $space->tenant_id],
        );
    }
}
