<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Models\HelpCenterCompany;
use App\Models\HelpCenterSpace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Editing what we know about a company (docs/features/help-center.md, P75 §13).
 *
 * The counterpart of `CustomerController`, and deliberately the same shape: one PATCH, the
 * fields a person can know that an inbound message cannot, and the same "may manage any Space"
 * rule — a Company belongs to the workspace, so there is no single Space to check against.
 *
 * Unlike a Customer, a Company's DOMAIN is editable. It is not an identity anybody wrote from —
 * it is a claim about the organisation, and correcting a typo in it is exactly the kind of thing
 * this screen is for. The unique index still refuses a domain another Company already holds,
 * which is reported as a field error rather than a 500.
 */
class CompanyController extends Controller
{
    use GuardsHelpCenter;

    /** PATCH /help-center/companies/{company} */
    public function update(Request $request, HelpCenterCompany $company): JsonResponse
    {
        $this->helpCenterWorkspace();

        $canManage = HelpCenterSpace::query()->get()
            ->contains(fn (HelpCenterSpace $space) => Auth::user()->can('update', $space));

        abort_unless($canManage, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
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
        ]);

        foreach ($data as $key => $value) {
            if ($key === 'tags') {
                // Trimmed, blanks dropped, duplicates removed keeping the first spelling — the
                // same normalisation a Space's types get, and for the same reason: two chips
                // nobody can tell apart look like a mistake.
                $company->tags = array_values(array_unique(array_filter(
                    array_map(fn ($t) => trim((string) $t), (array) $value),
                )));

                continue;
            }

            // Blank means "clear it", not "leave it" — a field emptied on purpose must empty.
            $company->{$key} = is_string($value) && trim($value) === '' ? null : $value;
        }

        try {
            $company->save();
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'ok' => false,
                'message' => 'Another company already uses that domain or ID.',
                'errors' => ['domain' => ['Another company already uses that domain or ID.']],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'company' => $company->fresh()->toPanel(),
            'message' => 'Company updated.',
        ]);
    }
}
