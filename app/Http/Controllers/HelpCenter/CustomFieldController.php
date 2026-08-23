<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\CustomFieldRequest;
use App\Models\HelpCenterCompanyField;
use App\Models\HelpCenterCustomerField;
use App\Models\HelpCenterCustomFieldValue;
use App\Models\HelpCenterSpace;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * A Space's Customer and Company custom fields (docs/features/help-center.md, P18 and P75 §2).
 *
 * ONE controller over both kinds, keyed by a `{kind}` segment. It was `CompanyFieldController`;
 * P75 added the Customer half, and the two differ only in which table they write — the policy
 * check, the Space check, the position rule and the payload are identical. A second copy would
 * be a second place to fix the next thing either of them gets wrong.
 *
 * Nested under the Space, like its tags and its members: the field and the Space are checked
 * together, so a field belonging to another Space cannot be reached through this Space's URL.
 *
 * ONE update endpoint, and it takes the WHOLE field. Save Changes sends it, and so does the
 * row's Disable — the client has the row in front of it either way, and two shapes for one
 * write would be two places that have to agree about what a missing key means.
 */
class CustomFieldController extends Controller
{
    use GuardsHelpCenter;

    /** POST /help-center/spaces/{space}/custom-fields/{kind} */
    public function store(CustomFieldRequest $request, HelpCenterSpace $space, string $kind): JsonResponse
    {
        $this->guard($space, $kind);

        $data = $request->validated();
        $fields = $this->relation($space, $kind);

        $field = $fields->create([
            'tenant_id' => $space->tenant_id,
            'name' => $data['name'],
            'type' => $data['type'],
            'is_required' => (bool) ($data['is_required'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'options' => $data['options'],
            // Onto the end of the form. The order is authored, and a new question goes where
            // the person adding it just looked — the bottom of the list they were reading.
            'position' => (int) $fields->max('position') + 1,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'ok' => true,
            'field' => $field->toPayload(),
            'message' => 'Field created.',
        ]);
    }

    /**
     * PATCH /help-center/spaces/{space}/custom-fields/{kind}/{field}
     *
     * The field is checked to belong to the Space in the URL, not merely to exist: without
     * that, an id from another Space in the same workspace would be rewritten by whoever may
     * manage THIS one.
     */
    public function update(CustomFieldRequest $request, HelpCenterSpace $space, string $kind, int $field): JsonResponse
    {
        $this->guard($space, $kind);

        $row = $this->field($space, $kind, $field);
        $data = $request->validated();

        $row->forceFill([
            'name' => $data['name'],
            'type' => $data['type'],
            'is_required' => (bool) ($data['is_required'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'options' => $data['options'],
        ])->save();

        return response()->json([
            'ok' => true,
            'field' => $row->toPayload(),
            'message' => 'Field saved.',
        ]);
    }

    /**
     * DELETE /help-center/spaces/{space}/custom-fields/{kind}/{field}
     *
     * Removes the field AND the answers recorded against it.
     *
     * P18 deliberately left the answers alone, because nothing stored them yet. P75 gave them a
     * table, and leaving them now would mean rows keyed to a `field_id` that resolves to
     * nothing — invisible on every panel, counted by nothing, and impossible to attribute if the
     * id were ever reused. The confirmation on the way in says the answers go too.
     *
     * Mappings pointing at the field are left in place on purpose: `destinationField()` returns
     * null for a deleted field and the engine skips the row, so a mapping survives as something
     * a person can see and repoint rather than vanishing along with the field.
     */
    public function destroy(HelpCenterSpace $space, string $kind, int $field): JsonResponse
    {
        $this->guard($space, $kind);

        $row = $this->field($space, $kind, $field);

        HelpCenterCustomFieldValue::query()
            ->where('field_kind', $kind)
            ->where('field_id', $row->id)
            ->delete();

        $row->delete();

        return response()->json(['ok' => true, 'message' => 'Field deleted.']);
    }

    /** The workspace check, the kind check and the policy check, in the one place all three run. */
    private function guard(HelpCenterSpace $space, string $kind): void
    {
        $this->helpCenterWorkspace();
        abort_unless(in_array($kind, HelpCenterCustomFieldValue::kinds(), true), 404);
        abort_unless(Auth::user()->can('update', $space), 403);
    }

    private function relation(HelpCenterSpace $space, string $kind): HasMany
    {
        return $kind === HelpCenterCustomFieldValue::KIND_COMPANY
            ? $space->companyFields()
            : $space->customerFields();
    }

    /**
     * The field, or a 404.
     *
     * Bound by hand rather than by the router: which model to bind depends on `{kind}`, which
     * the router resolves at the same moment and cannot be asked about.
     */
    private function field(HelpCenterSpace $space, string $kind, int $id): HelpCenterCompanyField|HelpCenterCustomerField
    {
        $row = $this->relation($space, $kind)->find($id);

        abort_if($row === null, 404);

        return $row;
    }
}
