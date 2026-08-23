<?php

namespace App\Http\Requests\HelpCenter\Setup;

use App\Services\HelpCenter\WorkflowStatusPayload;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 4 — Configure Your Workflow (docs/features/help-center.md, P2 §11–§16).
 *
 * The rules live in `WorkflowStatusPayload`, not here, because Settings → Workflow's editor
 * (P16) sends the same rows to a different endpoint and must apply the same rules. A rule
 * written in two form requests is a rule that will hold in one of them after the next change.
 *
 * What that class validates is the part a person can get wrong: a custom status with no name, a
 * duplicate name, too many of them. The system constraints of P2 §16 are NORMALIZED instead —
 * rejecting a reordered payload would mean showing an error for something the UI does not let
 * you do.
 */
class WorkflowStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return WorkflowStatusPayload::rules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            WorkflowStatusPayload::check($validator, (array) $this->input('statuses', []));
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['statuses' => WorkflowStatusPayload::normalize($this->input('statuses'))]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return WorkflowStatusPayload::messages();
    }
}
