<?php

namespace App\Http\Requests\HelpCenter;

use App\Models\HelpCenterCompanyField;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing one custom field, Customer or Company (P18, P75 §2).
 *
 * ONE request for create and edit, because a field is the same thing whether it is being made or
 * changed — and the alternative, two classes with the same rules, is two places to fix the next
 * one. ONE request for both KINDS for the same reason: they are the same eight types over two
 * tables (HC-D53), and the rules that make a Dropdown valid do not change with the record it
 * hangs off.
 *
 * The choice rules all come off `HelpCenterCompanyField::hasOptions()`: a Dropdown, Multiple
 * Select, Checkbox or Radio with no options is a question with no answers, and the four types
 * that take none must not carry a stale list from a type somebody changed away from.
 */
class CustomFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller asks the Space policy; a request that re-derived it would be a second
        // place for that rule to live.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', 'string', Rule::in(array_column(HelpCenterCompanyField::types(), 'value'))],
            'is_required' => ['boolean'],
            // Absent on create, sent by the row's Disable/Enable and by Save Changes.
            'is_active' => ['boolean'],
            'options' => ['array', 'max:'.(int) config('help-center.company_field_option_max', 50)],
            'options.*' => ['string', 'max:'.(int) config('help-center.company_field_option_max_length', 100)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = (string) $this->input('type');

            if (! HelpCenterCompanyField::hasOptions($type)) {
                return;
            }

            $options = (array) $this->input('options', []);

            if ($options === []) {
                $validator->errors()->add('options', 'Add at least one option before creating this field.');

                return;
            }

            $seen = [];

            foreach ($options as $i => $option) {
                $option = (string) $option;

                if (trim($option) === '') {
                    $validator->errors()->add("options.$i", 'Enter a value or remove this option.');

                    continue;
                }

                /*
                 * Compared case-insensitively.
                 *
                 * "Enterprise" and "enterprise" are one choice to everybody reading the form,
                 * and two rows nobody can tell apart in a dropdown.
                 */
                $key = mb_strtolower(trim($option));

                if (isset($seen[$key])) {
                    $validator->errors()->add("options.$i", 'This option already exists.');

                    continue;
                }

                $seen[$key] = true;
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $type = (string) $this->input('type');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'is_required' => $this->boolean('is_required'),
            /*
             * The list is DROPPED for a type that has none.
             *
             * Somebody who builds a Dropdown, fills in three choices and then switches the type
             * to Input has not asked to keep them — and a stored list behind a field that cannot
             * show it is a thing the next reader has to decide the meaning of.
             */
            'options' => HelpCenterCompanyField::hasOptions($type)
                ? array_values(array_map(fn ($o) => trim((string) $o), (array) $this->input('options', [])))
                : [],
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Field name is required.',
            'name.max' => 'A field name can be at most :max characters.',
            'type.required' => 'Choose a field type.',
            'type.in' => 'Choose a field type.',
            'options.max' => 'A field can hold at most :max options.',
        ];
    }
}
