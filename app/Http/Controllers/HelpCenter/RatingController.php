<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Models\HelpCenterRatingSettings;
use App\Models\HelpCenterSpace;
use App\Models\HelpCenterSpaceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A Space's CSAT configuration (docs/features/help-center.md, P56).
 *
 * Its own controller rather than another branch on `SpaceSettingsController::update()`, for the
 * reason the email templates got one (P48): this is twenty related fields written to a table of
 * their own, not a switch on the shared settings row.
 */
class RatingController extends Controller
{
    use GuardsHelpCenter;

    /** PUT /help-center/spaces/{space}/rating */
    public function update(Request $request, HelpCenterSpace $space): JsonResponse
    {
        $this->helpCenterWorkspace();
        // §28 — configuring CSAT is administering the Space, the same permission every other
        // settings write on this screen is behind.
        abort_unless(Auth::user()->can('update', $space), 403);

        $types = array_keys((array) config('help-center.rating_types'));
        $triggers = array_keys((array) config('help-center.rating_triggers'));
        $requirements = array_keys((array) config('help-center.rating_comment_requirements'));

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'rating_type' => ['required', 'string', 'in:'.implode(',', $types)],
            'labels' => ['nullable', 'array'],
            'labels.*' => ['nullable', 'string', 'max:60'],
            'allow_comment' => ['required', 'boolean'],
            'comment_requirement' => ['required', 'string', 'in:'.implode(',', $requirements)],
            'trigger' => ['required', 'string', 'in:'.implode(',', $triggers)],
            'trigger_status_id' => ['nullable', 'integer'],
            // A year in minutes. Not a policy — a guard against a typo that would park a request
            // past the heat death of the queue.
            'delay_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'reminder_enabled' => ['required', 'boolean'],
            'reminder_after_days' => ['required', 'integer', 'min:1', 'max:30'],
            'reminder_max' => ['required', 'integer', 'min:1', 'max:3'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'allow_change' => ['required', 'boolean'],
            'rerequest_after_reopen' => ['required', 'boolean'],
            'low_threshold' => ['required', 'integer', 'min:1', 'max:5'],
            'low_notify_agent' => ['required', 'boolean'],
            'low_notify_admin' => ['required', 'boolean'],
            'low_internal_note' => ['required', 'boolean'],
            'low_reopen' => ['required', 'boolean'],
            'low_tag_id' => ['nullable', 'integer'],
        ]);

        /*
         * The trigger status must belong to THIS Space's workflow.
         *
         * The same rule the Request update applies to a status id, and for the same reason: the
         * client's list came from a page that may have been open since before somebody edited
         * the workflow, and a Space's states are its own.
         */
        if ($data['trigger'] === 'status') {
            $valid = $space->statuses()->where('id', $data['trigger_status_id'])->exists();

            if (! $valid) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Choose a status from this Space’s own workflow.',
                ], 422);
            }
        } else {
            // Cleared when the trigger is not a specific status: a stored id behind an inactive
            // option is a value whose meaning the next reader has to guess.
            $data['trigger_status_id'] = null;
        }

        if ($data['low_tag_id'] !== null
            && ! $space->tags()->where('id', $data['low_tag_id'])->exists()) {
            $data['low_tag_id'] = null;
        }

        // Blank labels fall back to the packaged wording on read (see labelList), so only what
        // somebody actually typed is stored.
        $data['labels'] = collect((array) ($data['labels'] ?? []))
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn (string $v) => $v !== '')
            ->all();

        $settings = HelpCenterRatingSettings::updateOrCreate(
            ['help_center_space_id' => $space->id],
            $data + ['tenant_id' => $space->tenant_id],
        );

        /*
         * MIRRORED onto the old `metadata.rating` switch.
         *
         * That flag predates this page and other screens read it. Two booleans for one fact is a
         * bug waiting to happen, so this page is the authority and the old one follows — rather
         * than leaving somebody to discover that Rating is on in one place and off in another.
         */
        $cfg = HelpCenterSpaceSettings::firstOrCreate(
            ['help_center_space_id' => $space->id],
            ['tenant_id' => $space->tenant_id],
        );

        // Into the `metadata` JSON, which is where every switch on that row lives — merged over
        // the defaults exactly as `SpaceSettingsController::applyMetadata()` does, so a Space
        // that has never saved a switch does not lose the other twelve.
        $cfg->forceFill([
            'metadata' => array_merge(
                HelpCenterSpaceSettings::defaultMetadata(),
                (array) $cfg->metadata,
                ['rating' => (bool) $data['enabled']],
            ),
        ])->save();

        return response()->json([
            'ok' => true,
            'settings' => $settings->fresh()->toPayload(),
            'message' => 'Rating settings saved.',
        ]);
    }
}
