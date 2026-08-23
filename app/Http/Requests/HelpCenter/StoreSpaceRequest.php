<?php

namespace App\Http\Requests\HelpCenter;

use App\Models\HelpCenterSpace;
use App\Services\HelpCenter\EligibleLeads;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Creating a Space (docs/features/help-center.md §3).
 */
class StoreSpaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller holds the gate (HelpCenterSpacePolicy::create); this is only
        // reachable through it.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],

            /*
             * The name customers see on mail from this Space (P65).
             *
             * OPTIONAL, because the requirement gives it a default — the Space name — and asking
             * somebody to type "eBay Support" twice on the same form to get the obvious result
             * is a field that only exists to be ignored. `max:100` matches the column and the
             * Space name beside it.
             */
            'inbound_display_name' => ['nullable', 'string', 'max:100'],

            /*
             * Space Types — free text, many per Space (§3, HC-D9).
             *
             * Deliberately NO `Rule::in`: there is no fixed vocabulary, and validating against
             * config('help-center.space_type_suggestions') would turn a suggestion list back
             * into the dropdown this field exists to replace. Length and count are the only
             * limits, because they are the only ones that protect anything.
             */
            'types' => ['required', 'array', 'min:1', 'max:'.config('help-center.space_type_max', 8)],
            'types.*' => ['required', 'string', 'max:'.config('help-center.space_type_max_length', 40)],

            'lead_user_id' => ['required', 'integer'],
        ];
    }

    /**
     * The normalized types, which is what should be stored (§3).
     *
     * `validated()` returns the values as they were VALIDATED, and validation ran against the
     * already-normalized input from prepareForValidation() — so this simply hands back that
     * same tidy list, and the caller never has to remember to clean it up.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        if (is_array($data) && array_key_exists('types', $data)) {
            $data['types'] = HelpCenterSpace::normalizeTypes((array) $data['types']);
        }

        return $key === null ? $data : data_get($data, $key, $default);
    }

    /**
     * The two rules a plain rule list cannot state.
     *
     * `after` rather than `rules()` because both need the OTHER fields to have passed first:
     * a uniqueness query on a 5,000-character name is a query that should never have been run,
     * and "is this person eligible" is meaningless when no person was submitted.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $workspace = Auth::user()->currentWorkspace;

            if ($workspace === null) {
                return;
            }

            $name = trim((string) $this->input('name'));

            /*
             * "Space name should be unique within the Workspace" (§3), compared
             * CASE-INSENSITIVELY: "Billing" and "billing" are the same Space to everybody
             * reading the navigation, and letting both exist makes the nav unreadable.
             *
             * `lower()` explicitly rather than leaning on the column's collation — MySQL's
             * default collation would compare this way and SQLite's would not, and a rule that
             * depends on the engine is a rule that behaves differently in tests than in
             * production. The database's own unique index is the backstop against two
             * simultaneous submissions; this is the one that produces a readable message.
             */
            if ($name !== '' && mb_strlen($name) <= 100) {
                $exists = HelpCenterSpace::query()
                    ->where('tenant_id', $workspace->id)
                    ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'A Space with this name already exists.');
                }
            }

            // The picker and the validator ask the same service, so a person the form offered
            // can never be rejected here (§3).
            $lead = $this->input('lead_user_id');

            if ($lead !== null && ! app(EligibleLeads::class)->includes($workspace, $lead)) {
                $validator->errors()->add('lead_user_id', 'Choose an active member of this workspace.');
            }
        });
    }

    /**
     * Tidy the types BEFORE the rules see them (§3: trim, no duplicates).
     *
     * Before, not after, so `min:1` is answered by real values rather than by a list of blanks,
     * and so `max:8` counts what will actually be stored rather than what was typed — three
     * spellings of "Billing" are one type, and rejecting a nine-item list that dedupes to five
     * would be rejecting something the user never submitted.
     */
    protected function prepareForValidation(): void
    {
        $types = $this->input('types');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => $this->input('description') === null
                ? null
                : trim((string) $this->input('description')),
            'inbound_display_name' => $this->input('inbound_display_name') === null
                ? null
                : trim((string) $this->input('inbound_display_name')),
            'types' => is_array($types) ? HelpCenterSpace::normalizeTypes($types) : $types,
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the Space a name.',
            'name.max' => 'A Space name can be at most 100 characters.',
            'description.max' => 'A description can be at most 500 characters.',
            'inbound_display_name.max' => 'A sender name can be at most 100 characters.',
            'types.required' => 'Add at least one Space type.',
            'types.min' => 'Add at least one Space type.',
            'types.max' => 'A Space can have at most :max types.',
            'types.*.max' => 'A Space type can be at most :max characters.',
            'lead_user_id.required' => 'Choose a Space Lead.',
        ];
    }
}
