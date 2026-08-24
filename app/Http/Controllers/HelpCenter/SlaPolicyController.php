<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\SlaPolicyRequest;
use App\Models\HelpCenterSlaPolicy;
use App\Models\HelpCenterSpace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SLA policies (docs/features/helpdesk-sla.md, §3–§4, §8, §12–§14).
 *
 * A policy, its per-priority targets and its "SLA Applies When" conditions are written together
 * in one transaction: they are one object to the person editing them (SLA-D5), and a half-saved
 * policy is one that promises something nobody can see.
 */
class SlaPolicyController extends Controller
{
    use GuardsHelpCenter;

    /** POST /help-center/spaces/{space}/sla/policies */
    public function store(SlaPolicyRequest $request, HelpCenterSpace $space): JsonResponse
    {
        $this->guard($space);

        $policy = DB::transaction(function () use ($request, $space) {
            // The FIRST policy is the default whatever the form said — §13 falls back to it, and
            // a Space whose only policy is not the default has an SLA that applies to nothing.
            $first = ! $space->slaPolicies()->exists();

            $policy = $space->slaPolicies()->create($this->attributes($request, $space) + [
                'tenant_id' => $space->tenant_id,
                'is_default' => $first || $request->boolean('is_default'),
                // Onto the end of the evaluation order. A new policy must not silently outrank
                // the ones already there — §14 makes position the rule, so a new row starts last
                // and is dragged up deliberately.
                'position' => (int) $space->slaPolicies()->max('position') + 1,
                'created_by' => Auth::id(),
            ]);

            $this->syncTargets($policy, (array) $request->validated('targets'));
            $this->settleDefault($space, $policy);

            return $policy;
        });

        return response()->json([
            'ok' => true,
            'policy' => $this->payload($policy),
            'message' => 'SLA policy created.',
        ]);
    }

    /** PATCH /help-center/spaces/{space}/sla/policies/{policy} */
    public function update(SlaPolicyRequest $request, HelpCenterSpace $space, int $policy): JsonResponse
    {
        $this->guard($space);

        $row = $this->policy($space, $policy);

        DB::transaction(function () use ($request, $space, $row) {
            $row->forceFill($this->attributes($request, $space) + [
                // As with the calendar's default: the box moves the default, it does not clear
                // it. Unchecking the only default would leave §13 with no fallback.
                'is_default' => $row->is_default || $request->boolean('is_default'),
            ])->save();

            $this->syncTargets($row, (array) $request->validated('targets'));
            $this->settleDefault($space, $row);
        });

        return response()->json([
            'ok' => true,
            'policy' => $this->payload($row->fresh()),
            'message' => 'SLA policy saved.',
        ]);
    }

    /**
     * POST /help-center/spaces/{space}/sla/policies/{policy}/duplicate (§3)
     *
     * The copy is never the default and is always INACTIVE, whatever the original was. A
     * duplicate is a draft — the reason to make one is to change it — and a copy that went live
     * the moment it was created would start competing with its own original for tickets.
     */
    public function duplicate(HelpCenterSpace $space, int $policy): JsonResponse
    {
        $this->guard($space);

        $source = $this->policy($space, $policy);

        $copy = DB::transaction(function () use ($space, $source) {
            $copy = $space->slaPolicies()->create([
                'tenant_id' => $space->tenant_id,
                'name' => $this->copyName($space, $source->name),
                'description' => $source->description,
                'is_active' => false,
                'is_default' => false,
                'help_center_business_hours_id' => $source->help_center_business_hours_id,
                'warning_percent' => $source->warning_percent,
                'match_type' => $source->match_type,
                'conditions' => $source->conditions,
                'reopen_behavior' => $source->reopen_behavior,
                'position' => (int) $space->slaPolicies()->max('position') + 1,
                'created_by' => Auth::id(),
            ]);

            foreach ($source->targets as $target) {
                $copy->targets()->create(
                    collect($target->getAttributes())
                        ->except(['id', 'help_center_sla_policy_id', 'created_at', 'updated_at'])
                        ->all()
                );
            }

            return $copy;
        });

        return response()->json([
            'ok' => true,
            'policy' => $this->payload($copy),
            'message' => 'SLA policy duplicated.',
        ]);
    }

    /**
     * PATCH /help-center/spaces/{space}/sla/policies/order (§14)
     *
     * The whole ordered list of ids, not a from/to pair: drag-and-drop already knows the final
     * order, and reconstructing it from a move is how a list ends up disagreeing with the screen
     * that produced it.
     */
    public function reorder(Request $request, HelpCenterSpace $space): JsonResponse
    {
        $this->guard($space);

        $ids = collect($request->input('ids', []))->map(fn ($id) => (int) $id)->values();
        $known = $space->slaPolicies()->pluck('id');

        // Every id, and only this Space's ids. A partial list would leave the policies it omitted
        // holding stale positions, which is an evaluation order nobody authored.
        if ($ids->sort()->values()->all() !== $known->sort()->values()->all()) {
            throw ValidationException::withMessages(['ids' => 'The order must list every policy in this Space.']);
        }

        DB::transaction(function () use ($space, $ids) {
            foreach ($ids as $position => $id) {
                $space->slaPolicies()->whereKey($id)->update(['position' => $position]);
            }
        });

        return response()->json([
            'ok' => true,
            'policies' => $space->slaPolicies()->with('targets', 'businessHours')->get()
                ->map(fn ($p) => $this->payload($p))->all(),
            'message' => 'Order saved.',
        ]);
    }

    /**
     * DELETE /help-center/spaces/{space}/sla/policies/{policy}
     *
     * Tickets already running under it keep their timers: `help_center_ticket_slas` holds the
     * policy by a `nullOnDelete` FK and keeps its NAME, so a ticket's history still reads
     * "Enterprise Support SLA applied" after the policy is gone.
     */
    public function destroy(HelpCenterSpace $space, int $policy): JsonResponse
    {
        $this->guard($space);

        $row = $this->policy($space, $policy);

        // The default may not be deleted while another policy exists to be promoted. Otherwise
        // §13's fallback would vanish and every ticket that matched nothing would arrive with no
        // SLA at all — silently, one ticket at a time.
        if ($row->is_default && $space->slaPolicies()->count() > 1) {
            return response()->json([
                'ok' => false,
                'message' => 'Make another policy the default before deleting this one.',
            ], 422);
        }

        $row->delete();

        return response()->json(['ok' => true, 'message' => 'SLA policy deleted.']);
    }

    /** The columns both writes share. @return array<string, mixed> */
    private function attributes(SlaPolicyRequest $request, HelpCenterSpace $space): array
    {
        return [
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'is_active' => $request->boolean('is_active', true),
            'help_center_business_hours_id' => $this->businessHoursId($request, $space),
            'warning_percent' => (int) $request->validated('warning_percent'),
            'match_type' => $request->validated('match_type'),
            'conditions' => array_values((array) $request->validated('conditions', [])),
            'reopen_behavior' => $request->validated('reopen_behavior'),
        ];
    }

    /**
     * The calendar, checked to belong to THIS Space.
     *
     * The rule cannot live in the form request: it does not know which Space the URL named, and
     * an `exists` against the whole table would accept a neighbouring Space's calendar — a
     * perfectly valid integer that would quietly move this Space's working week.
     */
    private function businessHoursId(SlaPolicyRequest $request, HelpCenterSpace $space): ?int
    {
        $id = $request->validated('business_hours_id');

        if ($id === null || $id === '') {
            return null;
        }

        abort_unless($space->businessHours()->whereKey((int) $id)->exists(), 404);

        return (int) $id;
    }

    /**
     * The policy's targets, replaced wholesale.
     *
     * Matched by PRIORITY rather than deleted and recreated: the row for Urgent is the same
     * promise before and after an edit, and recreating it would churn ids for nothing. A
     * priority the form no longer sends is removed — that is the admin taking a promise back.
     */
    private function syncTargets(HelpCenterSlaPolicy $policy, array $targets): void
    {
        $kinds = array_keys((array) config('help-center.sla_timer_kinds'));
        $keep = [];

        foreach ($targets as $target) {
            $attributes = [];

            foreach ($kinds as $kind) {
                $value = $target[$kind.'_value'] ?? null;

                // Empty string from a cleared input is NULL, not 0: the two mean "no promise"
                // and "due immediately", and only one of them is what somebody typing over a
                // number meant.
                $attributes[$kind.'_value'] = ($value === null || $value === '') ? null : (int) $value;
                $attributes[$kind.'_unit'] = $attributes[$kind.'_value'] === null
                    ? null
                    : ($target[$kind.'_unit'] ?? null);
            }

            $row = $policy->targets()->updateOrCreate(
                ['priority' => (string) $target['priority']],
                $attributes + ['tenant_id' => $policy->tenant_id],
            );

            $keep[] = $row->id;
        }

        $policy->targets()->whereKeyNot($keep)->delete();
    }

    /** Exactly one default policy per Space — see SlaCalendarController for why it is written. */
    private function settleDefault(HelpCenterSpace $space, HelpCenterSlaPolicy $keep): void
    {
        if (! $keep->is_default) {
            return;
        }

        $space->slaPolicies()->whereKeyNot($keep->id)->update(['is_default' => false]);
    }

    /** "Enterprise SLA (copy)", then "(copy 2)" — a name somebody can tell apart in the list. */
    private function copyName(HelpCenterSpace $space, string $name): string
    {
        $base = mb_substr($name, 0, 108);
        $candidate = $base.' (copy)';
        $n = 2;

        while ($space->slaPolicies()->where('name', $candidate)->exists()) {
            $candidate = $base.' (copy '.$n++.')';
        }

        return $candidate;
    }

    /** @return array<string, mixed> */
    private function payload(HelpCenterSlaPolicy $policy): array
    {
        return $policy->load('targets', 'businessHours')->toPayload();
    }

    private function guard(HelpCenterSpace $space): void
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);
    }

    private function policy(HelpCenterSpace $space, int $id): HelpCenterSlaPolicy
    {
        $row = $space->slaPolicies()->with('targets')->find($id);

        abort_if($row === null, 404);

        return $row;
    }
}
