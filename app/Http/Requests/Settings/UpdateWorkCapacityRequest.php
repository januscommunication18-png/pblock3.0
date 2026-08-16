<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Settings > Work Capacity (§13, §14, §29).
 */
class UpdateWorkCapacityRequest extends FormRequest
{
    public function authorize(): bool
    {
        // guardManage() in the controller is the gate; this is only reachable through it.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Decimal hours are the point (§13): 7.5-hour days are ordinary.
            'hours_per_day' => [
                'required', 'numeric',
                'min:'.config('settings.capacity.min_hours_per_day'),
                'max:'.config('settings.capacity.max_hours_per_day'),
            ],
            // At least one day. A workspace that works no days has no capacity, which would
            // make every member's utilization undefined rather than merely low.
            'working_days' => ['required', 'array', 'min:1', 'max:7'],
            'working_days.*' => ['integer', 'between:1,7'],

            'near_threshold' => ['required', 'integer', 'between:1,500'],
            'over_threshold' => ['required', 'integer', 'between:1,500'],
            'high_threshold' => ['required', 'integer', 'between:1,500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $near = (int) $this->input('near_threshold');
            $over = (int) $this->input('over_threshold');
            $high = (int) $this->input('high_threshold');

            // The three thresholds are a ladder, and CapacityStatus reads them from the top
            // down. Out of order, a band becomes unreachable — set "over" below "near" and
            // nothing is ever Near Capacity, silently.
            if (! ($near < $over && $over < $high)) {
                $v->errors()->add(
                    'near_threshold',
                    'Thresholds must increase: Near Capacity below Over Capacity, and Over Capacity below High Workload.',
                );
            }
        });
    }
}
