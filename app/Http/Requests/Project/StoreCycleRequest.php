<?php

namespace App\Http\Requests\Project;

use App\Models\Cycle;
use App\Services\CycleOverlapGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create Cycle validation (Cycles §6.1/§6.2).
 *
 * Authorization is HERE rather than in the controller, unlike the older work-item requests:
 * Laravel runs `authorize()` before the rules, so someone without permission is refused
 * outright instead of first being told which dates their proposed cycle would collide with.
 *
 * The overlap rule is a cross-field, cross-row check, so it runs in `withValidator` after the
 * individual fields are known good — there is no point asking whether a malformed date range
 * collides with anything.
 */
class StoreCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Cycle::class, $this->route('project')]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.config('projects.cycle_name_max')],
            'description' => ['nullable', 'string', 'max:'.config('projects.cycle_description_max')],
            'start_date' => ['required', 'date'],
            // §6.2: the end date may not be EARLIER than the start — equal is a valid
            // one-day cycle, so after_or_equal rather than after (which is what the work
            // item's due date uses, where a zero-length range would be meaningless).
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            $conflict = app(CycleOverlapGuard::class)->conflicting(
                $this->route('project'),
                $this->date('start_date')->toDateString(),
                $this->date('end_date')->toDateString(),
                $this->cycleBeingEdited(),
            );

            if ($conflict) {
                $validator->errors()->add('start_date', app(CycleOverlapGuard::class)->message($conflict));
            }
        });
    }

    /** Null on create; UpdateCycleRequest overrides it so a cycle cannot conflict with itself. */
    protected function cycleBeingEdited(): ?int
    {
        return null;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Give the cycle a name.',
            'start_date.required' => 'Choose a start date.',
            'end_date.required' => 'Choose an end date.',
            'end_date.after_or_equal' => 'The end date cannot be earlier than the start date.',
        ];
    }
}
