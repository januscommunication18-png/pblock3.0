<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\BusinessHoursRequest;
use App\Http\Requests\HelpCenter\SlaHolidayRequest;
use App\Models\HelpCenterBusinessHours;
use App\Models\HelpCenterSlaHoliday;
use App\Models\HelpCenterSpace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Business Hours and the Holiday Calendar (docs/features/helpdesk-sla.md, §5–§7).
 *
 * ONE controller over both, because they are one screen and one idea: when does the SLA clock
 * run? A calendar says which hours of the week; a holiday says which days are struck out of it.
 * Splitting them would mean two files that always change together.
 *
 * Nested under the Space, like every other Help Desk resource — the row and the Space are
 * checked together, so a calendar belonging to another Space cannot be reached through this
 * Space's URL.
 */
class SlaCalendarController extends Controller
{
    use GuardsHelpCenter;

    /** POST /help-center/spaces/{space}/sla/business-hours */
    public function storeHours(BusinessHoursRequest $request, HelpCenterSpace $space): JsonResponse
    {
        $this->guard($space);

        $hours = DB::transaction(function () use ($request, $space) {
            // The FIRST calendar is the default whatever the form said. A Space with calendars
            // and no default is a Space where every new policy opens with an empty picker.
            $first = ! $space->businessHours()->exists();
            $default = $first || $request->boolean('is_default');

            $hours = $space->businessHours()->create([
                'tenant_id' => $space->tenant_id,
                'name' => $request->validated('name'),
                'timezone' => $request->validated('timezone'),
                'schedule' => $request->schedule(),
                'is_default' => $default,
                'created_by' => Auth::id(),
            ]);

            $this->settleDefaultHours($space, $hours);

            return $hours;
        });

        return response()->json([
            'ok' => true,
            'businessHours' => $hours->toPayload(),
            'message' => 'Business hours created.',
        ]);
    }

    /** PATCH /help-center/spaces/{space}/sla/business-hours/{hours} */
    public function updateHours(BusinessHoursRequest $request, HelpCenterSpace $space, int $hours): JsonResponse
    {
        $this->guard($space);

        $row = $this->hours($space, $hours);

        DB::transaction(function () use ($request, $space, $row) {
            $row->forceFill([
                'name' => $request->validated('name'),
                'timezone' => $request->validated('timezone'),
                'schedule' => $request->schedule(),
                // The default cannot be turned OFF here, only moved: unchecking the box on the
                // only default would leave the Space without one. Choosing a different default
                // is what clears this row's.
                'is_default' => $row->is_default || $request->boolean('is_default'),
            ])->save();

            $this->settleDefaultHours($space, $row);
        });

        return response()->json([
            'ok' => true,
            'businessHours' => $row->fresh()->toPayload(),
            'message' => 'Business hours saved.',
        ]);
    }

    /**
     * DELETE /help-center/spaces/{space}/sla/business-hours/{hours}
     *
     * Policies pointing at it are NOT deleted — the FK is `nullOnDelete`, so they fall back to
     * counting round the clock (SLA-D3), which is the stricter reading. The confirmation on the
     * way in names the policies that will change, because "your SLAs got tighter" is not
     * something to discover from a breach report.
     */
    public function destroyHours(HelpCenterSpace $space, int $hours): JsonResponse
    {
        $this->guard($space);

        $row = $this->hours($space, $hours);

        // The last calendar may go — a Space is allowed to run 24/7 — but the DEFAULT may not,
        // while others remain: deleting it would leave the Space with calendars and no default,
        // which is the one state the picker cannot represent.
        if ($row->is_default && $space->businessHours()->count() > 1) {
            return response()->json([
                'ok' => false,
                'message' => 'Make another calendar the default before deleting this one.',
            ], 422);
        }

        $row->delete();

        return response()->json(['ok' => true, 'message' => 'Business hours deleted.']);
    }

    /** POST /help-center/spaces/{space}/sla/holidays */
    public function storeHoliday(SlaHolidayRequest $request, HelpCenterSpace $space): JsonResponse
    {
        $this->guard($space);

        $holiday = $space->slaHolidays()->create([
            'tenant_id' => $space->tenant_id,
            'name' => $request->validated('name'),
            'date' => $request->validated('date'),
            'repeats_annually' => $request->boolean('repeats_annually'),
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'ok' => true,
            'holiday' => $this->holidayPayload($holiday),
            'message' => 'Holiday added.',
        ]);
    }

    /** PATCH /help-center/spaces/{space}/sla/holidays/{holiday} */
    public function updateHoliday(SlaHolidayRequest $request, HelpCenterSpace $space, int $holiday): JsonResponse
    {
        $this->guard($space);

        $row = $this->holiday($space, $holiday);

        $row->forceFill([
            'name' => $request->validated('name'),
            'date' => $request->validated('date'),
            'repeats_annually' => $request->boolean('repeats_annually'),
        ])->save();

        return response()->json([
            'ok' => true,
            'holiday' => $this->holidayPayload($row->fresh()),
            'message' => 'Holiday saved.',
        ]);
    }

    /** DELETE /help-center/spaces/{space}/sla/holidays/{holiday} */
    public function destroyHoliday(HelpCenterSpace $space, int $holiday): JsonResponse
    {
        $this->guard($space);

        $this->holiday($space, $holiday)->delete();

        return response()->json(['ok' => true, 'message' => 'Holiday deleted.']);
    }

    /**
     * Exactly one default calendar in the Space.
     *
     * Written as "clear everybody else" rather than as a unique index, because MySQL has no
     * partial unique and a unique on (space, is_default) would refuse a Space its second
     * NON-default calendar. Runs inside the caller's transaction, so the moment where two rows
     * are default is never visible to another request.
     */
    private function settleDefaultHours(HelpCenterSpace $space, HelpCenterBusinessHours $keep): void
    {
        if (! $keep->is_default) {
            return;
        }

        $space->businessHours()->whereKeyNot($keep->id)->update(['is_default' => false]);
    }

    /** @return array<string, mixed> */
    private function holidayPayload(HelpCenterSlaHoliday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'name' => $holiday->name,
            'date' => $holiday->date->toDateString(),
            // Formatted server-side: a repeating holiday has no year to show, and letting the
            // browser decide would put the authoring year back on it in some locales.
            'date_label' => $holiday->repeats_annually
                ? $holiday->date->format('j F').' (every year)'
                : $holiday->date->format('j F Y'),
            'repeats_annually' => (bool) $holiday->repeats_annually,
        ];
    }

    private function guard(HelpCenterSpace $space): void
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);
    }

    private function hours(HelpCenterSpace $space, int $id): HelpCenterBusinessHours
    {
        $row = $space->businessHours()->find($id);

        abort_if($row === null, 404);

        return $row;
    }

    private function holiday(HelpCenterSpace $space, int $id): HelpCenterSlaHoliday
    {
        $row = $space->slaHolidays()->find($id);

        abort_if($row === null, 404);

        return $row;
    }
}
