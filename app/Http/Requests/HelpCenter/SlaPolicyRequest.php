<?php

namespace App\Http\Requests\HelpCenter;

use App\Models\HelpCenterSlaPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing an SLA policy, its targets and its conditions
 * (docs/features/helpdesk-sla.md, §3–§4, §8, §12, §17, §23).
 *
 * ONE request for the whole policy. The drawer saves the name, the calendar, the per-priority
 * targets and the "SLA Applies When" block together, and a policy whose targets saved but whose
 * conditions did not is a policy that promises something to nobody.
 */
class SlaPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $priorities = array_keys((array) config('help-center.priorities'));
        $units = array_keys((array) config('help-center.sla_units'));
        $kinds = array_keys((array) config('help-center.sla_timer_kinds'));

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],

            /*
             * Nullable is 24/7 (SLA-D3). `exists` is scoped to this Space in the controller,
             * which is the only place that knows which Space the URL named — a calendar id from
             * a neighbouring Space would otherwise be a perfectly valid integer.
             */
            'business_hours_id' => ['nullable', 'integer'],

            // 1–99, not 0–100. Zero would make a clock Due Soon before it started, and 100 is
            // the breach itself — neither is a warning.
            'warning_percent' => ['required', 'integer', 'min:1', 'max:99'],

            'match_type' => ['required', Rule::in([HelpCenterSlaPolicy::MATCH_ALL, HelpCenterSlaPolicy::MATCH_ANY])],
            'reopen_behavior' => ['required', Rule::in(array_keys((array) config('help-center.sla_reopen_behaviors')))],

            'conditions' => ['array', 'max:20'],
            'conditions.*.field' => ['required', Rule::in(array_keys((array) config('help-center.sla_condition_fields')))],
            'conditions.*.operator' => ['required', Rule::in(array_keys((array) config('help-center.sla_condition_operators')))],
            'conditions.*.value' => ['nullable'],
            // Only the two custom-field conditions carry one; it names which field is being read.
            'conditions.*.field_id' => ['nullable', 'integer'],

            'targets' => ['required', 'array', 'min:1'],
            'targets.*.priority' => ['required', Rule::in($priorities)],
        ];

        foreach ($kinds as $kind) {
            // Null is "no promise at this stage" and is the whole reason these are nullable
            // rather than required. `min:1` refuses zero, which would breach on arrival.
            $rules["targets.*.{$kind}_value"] = ['nullable', 'integer', 'min:1', 'max:100000'];
            $rules["targets.*.{$kind}_unit"] = ['nullable', Rule::in($units)];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $kinds = array_keys((array) config('help-center.sla_timer_kinds'));
            $seen = [];

            foreach ((array) $this->input('targets', []) as $i => $target) {
                $priority = (string) ($target['priority'] ?? '');

                // Two rows for one priority is two answers to "what do we promise an Urgent
                // ticket?", and the unique index would reject the second with a 500.
                if (isset($seen[$priority])) {
                    $validator->errors()->add("targets.$i.priority", 'Each priority may appear once.');
                }

                $seen[$priority] = true;

                foreach ($kinds as $kind) {
                    $value = $target[$kind.'_value'] ?? null;
                    $unit = $target[$kind.'_unit'] ?? null;

                    // A duration is a value AND a unit. "4" on its own is not a promise, and a
                    // unit with no number is a field somebody half-filled.
                    if ($value !== null && $value !== '' && ($unit === null || $unit === '')) {
                        $validator->errors()->add("targets.$i.{$kind}_unit", 'Choose a unit for this target.');
                    }
                }
            }

            $operators = (array) config('help-center.sla_condition_operators');

            foreach ((array) $this->input('conditions', []) as $i => $condition) {
                $operator = (string) ($condition['operator'] ?? '');

                // `is set` / `is not set` take no value; everything else is meaningless without
                // one — an empty "Company is" would match every ticket or none, depending on
                // which reader you asked.
                if ($operators[$operator]['valueless'] ?? false) {
                    continue;
                }

                $value = $condition['value'] ?? null;

                if ($value === null || $value === '' || $value === []) {
                    $validator->errors()->add("conditions.$i.value", 'Choose a value for this condition.');
                }
            }
        });
    }
}
