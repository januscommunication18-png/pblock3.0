<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\SlaEscalationRequest;
use App\Models\HelpCenterSlaEscalation;
use App\Models\HelpCenterSpace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Escalation rules (docs/features/helpdesk-sla.md, §24–§25).
 *
 * Configuration only. What a rule DOES when it fires belongs to the engine (S6) — this file
 * decides what may be written down, and nothing here ever runs an action.
 */
class SlaEscalationController extends Controller
{
    use GuardsHelpCenter;

    /** POST /help-center/spaces/{space}/sla/escalations */
    public function store(SlaEscalationRequest $request, HelpCenterSpace $space): JsonResponse
    {
        $this->guard($space);

        $rule = $space->slaEscalations()->create($this->attributes($request, $space) + [
            'tenant_id' => $space->tenant_id,
            'position' => (int) $space->slaEscalations()->max('position') + 1,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'ok' => true,
            'escalation' => $rule->toPayload(),
            'message' => 'Escalation rule created.',
        ]);
    }

    /** PATCH /help-center/spaces/{space}/sla/escalations/{escalation} */
    public function update(SlaEscalationRequest $request, HelpCenterSpace $space, int $escalation): JsonResponse
    {
        $this->guard($space);

        $rule = $this->escalation($space, $escalation);
        $rule->forceFill($this->attributes($request, $space))->save();

        return response()->json([
            'ok' => true,
            'escalation' => $rule->fresh()->toPayload(),
            'message' => 'Escalation rule saved.',
        ]);
    }

    /**
     * DELETE /help-center/spaces/{space}/sla/escalations/{escalation}
     *
     * Its run ledger goes with it (cascade). The ledger only answers "has this rule already
     * fired for this timer?", and a deleted rule can never fire again — keeping the rows would
     * be keeping the answer to a question nobody can ask.
     */
    public function destroy(HelpCenterSpace $space, int $escalation): JsonResponse
    {
        $this->guard($space);

        $this->escalation($space, $escalation)->delete();

        return response()->json(['ok' => true, 'message' => 'Escalation rule deleted.']);
    }

    /** @return array<string, mixed> */
    private function attributes(SlaEscalationRequest $request, HelpCenterSpace $space): array
    {
        $policyId = $request->validated('policy_id');

        // Scoped here for the reason the policy's calendar is: the form request does not know
        // which Space the URL named, so an `exists` there would accept a neighbour's policy.
        if ($policyId !== null && $policyId !== '') {
            abort_unless($space->slaPolicies()->whereKey((int) $policyId)->exists(), 404);
        }

        return [
            'name' => $request->validated('name'),
            'trigger' => $request->validated('trigger'),
            'kind' => $request->validated('kind') ?: null,
            'help_center_sla_policy_id' => ($policyId === null || $policyId === '') ? null : (int) $policyId,
            'actions' => array_values((array) $request->validated('actions')),
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    private function guard(HelpCenterSpace $space): void
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);
    }

    private function escalation(HelpCenterSpace $space, int $id): HelpCenterSlaEscalation
    {
        $row = $space->slaEscalations()->find($id);

        abort_if($row === null, 404);

        return $row;
    }
}
