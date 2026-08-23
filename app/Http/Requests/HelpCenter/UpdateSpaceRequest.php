<?php

namespace App\Http\Requests\HelpCenter;

use App\Models\HelpCenterSpace;
use App\Services\HelpCenter\EligibleLeads;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Editing an existing Space (docs/features/help-center.md, P3 §5 — "Edit Existing Space").
 *
 * The same rules as creating one, with a single difference that matters: the name-uniqueness
 * check EXCLUDES this Space. Without that, saving a Space without renaming it would fail
 * validation against itself — the classic edit-form bug.
 */
class UpdateSpaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller holds the gate (HelpCenterSpacePolicy::update).
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $typeLen = (int) config('help-center.space_type_max_length', 40);

        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            // The customer-facing sender name (P65); blank means "use the Space name".
            'inbound_display_name' => ['nullable', 'string', 'max:100'],
            'types' => ['required', 'array', 'min:1', 'max:'.(int) config('help-center.space_type_max', 8)],
            'types.*' => ['required', 'string', 'max:'.$typeLen],
            'department_groups' => ['nullable', 'array', 'max:'.(int) config('help-center.department_group_max', 20)],
            'department_groups.*' => ['required', 'string', 'max:'.$typeLen],
            'lead_user_id' => ['required', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $workspace = Auth::user()?->currentWorkspace;
            $space = $this->route('space');

            if ($workspace === null || $space === null) {
                return;
            }

            $name = trim((string) $this->input('name'));

            if ($name !== '' && mb_strlen($name) <= 100) {
                $taken = HelpCenterSpace::query()
                    ->where('tenant_id', $workspace->id)
                    ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                    // The one line that separates this from StoreSpaceRequest.
                    ->whereKeyNot(is_object($space) ? $space->id : $space)
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

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        if (is_array($data)) {
            $data['types'] = HelpCenterSpace::normalizeTypes((array) ($data['types'] ?? []));
            $data['department_groups'] = HelpCenterSpace::normalizeTypes((array) ($data['department_groups'] ?? []));
        }

        return $key === null ? $data : data_get($data, $key, $default);
    }

    protected function prepareForValidation(): void
    {
        $groups = $this->input('department_groups');

        /*
         * Merged only when it was actually SENT (P65).
         *
         * The Space edit drawer does not render this field — it is edited under Settings → Inbox
         * — so an unconditional merge would put `null` into `validated()` on every save from
         * that drawer and quietly clear a display name somebody had configured. A field absent
         * from the request is a field nobody was editing.
         */
        if ($this->exists('inbound_display_name')) {
            $this->merge([
                'inbound_display_name' => $this->input('inbound_display_name') === null
                    ? null
                    : trim((string) $this->input('inbound_display_name')),
            ]);
        }

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => $this->input('description') === null ? null : trim((string) $this->input('description')),
            'types' => is_array($this->input('types'))
                ? HelpCenterSpace::normalizeTypes($this->input('types')) : $this->input('types'),
            'department_groups' => is_array($groups) ? HelpCenterSpace::normalizeTypes($groups) : [],
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the Space a name.',
            'types.required' => 'Add at least one Space type.',
            'types.min' => 'Add at least one Space type.',
            'lead_user_id.required' => 'Choose a Space Lead.',
        ];
    }
}
