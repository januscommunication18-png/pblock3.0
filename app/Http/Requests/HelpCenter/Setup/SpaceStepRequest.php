<?php

namespace App\Http\Requests\HelpCenter\Setup;

use App\Models\HelpCenterSpace;
use App\Services\HelpCenter\EligibleLeads;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Step 1 — Create Your Space (docs/features/help-center.md, P2 §4–§6).
 *
 * Validated on Continue even though nothing is written yet (HC-D11): P2 §2 asks for required
 * fields to be checked before the user may move on, and validating at Step 6 instead would mean
 * telling somebody five steps later that their Space name was taken.
 */
class SpaceStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller holds the gate.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $typeMax = (int) config('help-center.space_type_max', 8);
        $typeLen = (int) config('help-center.space_type_max_length', 40);

        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            // The name customers see on mail from this Space (P65). Optional — blank means the
            // Space name, which is the requirement's own default.
            'inbound_display_name' => ['nullable', 'string', 'max:100'],

            // Free text, many per Space — no Rule::in (HC-D9).
            'types' => ['required', 'array', 'min:1', 'max:'.$typeMax],
            'types.*' => ['required', 'string', 'max:'.$typeLen],

            // Optional (P2 §4): a Space with one team has no groups to name.
            'department_groups' => ['nullable', 'array', 'max:'.(int) config('help-center.department_group_max', 20)],
            'department_groups.*' => ['required', 'string', 'max:'.$typeLen],

            'lead_user_id' => ['required', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $workspace = Auth::user()?->currentWorkspace;

            if ($workspace === null) {
                return;
            }

            $name = trim((string) $this->input('name'));

            /*
             * Unique within the workspace, case-insensitively (§3).
             *
             * Checked here as well as at commit, because the point of per-step validation is to
             * say so NOW. The commit is what actually enforces it — between Continue and Step 6
             * somebody else could take the name.
             */
            if ($name !== '' && mb_strlen($name) <= 100) {
                $taken = HelpCenterSpace::query()
                    ->where('tenant_id', $workspace->id)
                    ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('name', 'A Space with this name already exists.');
                }
            }

            $lead = $this->input('lead_user_id');

            if ($lead !== null && ! app(EligibleLeads::class)->includes($workspace, $lead)) {
                $validator->errors()->add('lead_user_id', 'Choose an active member of this workspace.');
            }
        });
    }

    /** Normalize before the rules see it, so counts and blanks reflect what will be stored. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => $this->input('description') === null ? null : trim((string) $this->input('description')),
            'inbound_display_name' => $this->input('inbound_display_name') === null
                ? null
                : trim((string) $this->input('inbound_display_name')),
            'types' => is_array($this->input('types'))
                ? HelpCenterSpace::normalizeTypes($this->input('types'))
                : $this->input('types'),
            'department_groups' => is_array($this->input('department_groups'))
                ? HelpCenterSpace::normalizeTypes($this->input('department_groups'))
                : [],
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the Space a name.',
            'name.max' => 'A Space name can be at most 100 characters.',
            'description.max' => 'A description can be at most 500 characters.',
            'types.required' => 'Add at least one Space type.',
            'types.min' => 'Add at least one Space type.',
            'types.max' => 'A Space can have at most :max types.',
            'types.*.max' => 'A Space type can be at most :max characters.',
            'department_groups.max' => 'A Space can have at most :max department groups.',
            'department_groups.*.max' => 'A department group can be at most :max characters.',
            'lead_user_id.required' => 'Choose a Space Lead.',
        ];
    }
}
