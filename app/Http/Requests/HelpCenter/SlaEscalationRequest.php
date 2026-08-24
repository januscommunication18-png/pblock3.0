<?php

namespace App\Http\Requests\HelpCenter;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One escalation rule (docs/features/helpdesk-sla.md, §24–§25).
 */
class SlaEscalationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', Rule::in(array_keys((array) config('help-center.sla_escalation_triggers')))],
            // Null is "any clock" — see the model. The stage is what turns "SLA Breached" into
            // §24's "First Response SLA Breached" rather than a sixth trigger value.
            'kind' => ['nullable', Rule::in(array_keys((array) config('help-center.sla_timer_kinds')))],
            // Null is "every policy in this Space". Scoped to the Space in the controller.
            'policy_id' => ['nullable', 'integer'],
            'is_active' => ['boolean'],

            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'actions.*.action' => ['required', Rule::in(array_keys((array) config('help-center.sla_escalation_actions')))],
            'actions.*.value' => ['nullable'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $catalog = (array) config('help-center.sla_escalation_actions');

            foreach ((array) $this->input('actions', []) as $i => $action) {
                $key = (string) ($action['action'] ?? '');

                // Each action declares in config whether it needs a value. "Change Priority"
                // without one is a rule that would run and do nothing — and do it silently, on a
                // breach, which is the worst moment to discover a rule was never finished.
                if (($catalog[$key]['needs'] ?? null) === null) {
                    continue;
                }

                $value = $action['value'] ?? null;

                if ($value === null || $value === '' || $value === []) {
                    $validator->errors()->add("actions.$i.value", 'This action needs a value.');
                }
            }
        });
    }
}
