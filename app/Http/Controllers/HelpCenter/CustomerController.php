<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Models\HelpCenterCustomer;
use App\Models\HelpCenterSpace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Editing what we know about a customer (docs/features/help-center.md, P33).
 *
 * The spec's "Create / Update Customer" quick action. There is no create: a customer record is
 * created by the email that opened the ticket, never by hand — inventing one from a panel would
 * mean an address nobody has written from, which no ticket can ever match.
 *
 * So this is the update, and it accepts exactly the three things a person can know that an email
 * header cannot: their name, their company, their phone number. The EMAIL is not editable —
 * it is the identity every Request was matched on, and changing it would silently re-point a
 * history at somebody else.
 */
class CustomerController extends Controller
{
    use GuardsHelpCenter;

    /** PATCH /help-center/customers/{customer} */
    public function update(Request $request, HelpCenterCustomer $customer): JsonResponse
    {
        $this->helpCenterWorkspace();

        /*
         * Anyone who may manage ANY Space may edit a customer.
         *
         * A customer belongs to the workspace rather than to a Space (see the migration), so
         * there is no single Space to check against — and the alternative, checking the Space
         * whose ticket happens to be open, would let the same person edit the record through
         * one Space and not another.
         */
        $canManage = HelpCenterSpace::query()->get()
            ->contains(fn (HelpCenterSpace $space) => Auth::user()->can('update', $space));

        abort_unless($canManage, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tags' => ['sometimes', 'array', 'max:20'],
            /*
             * `nullable`, because a blank one arrives as NULL, not as "".
             *
             * TrimStrings turns "  " into "" and ConvertEmptyStringsToNull turns that into null
             * before any rule runs — so a chip somebody emptied instead of removing failed with
             * "tags.2 must be a string", naming an index the person cannot see. Blanks are
             * dropped below, which is what they meant.
             */
            'tags.*' => ['nullable', 'string', 'max:40'],
            /*
             * Which Company they belong to (P75 §12) — set by hand as well as by the mapping.
             *
             * `exists` and nothing more: the Companies table is tenant-scoped, so the global
             * scope already stops an id from another workspace resolving. Nullable, because
             * "not with a company" is a legitimate answer somebody may need to give back.
             */
            'help_center_company_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('help_center_companies', 'id')->where('tenant_id', $customer->tenant_id)],
        ]);

        foreach ($data as $key => $value) {
            if ($key === 'tags') {
                // Trimmed, blanks dropped, duplicates removed keeping the first spelling — two
                // chips nobody can tell apart look like a mistake.
                $customer->tags = array_values(array_unique(array_filter(
                    array_map(fn ($t) => trim((string) $t), (array) $value),
                )));

                continue;
            }

            // Blank means "clear it", not "leave it" — a field emptied on purpose must empty.
            $customer->{$key} = is_string($value) && trim($value) === '' ? null : $value;
        }

        $customer->save();

        return response()->json([
            'ok' => true,
            'customer' => $customer->fresh()->toPanel(),
            'message' => 'Customer updated.',
        ]);
    }
}
