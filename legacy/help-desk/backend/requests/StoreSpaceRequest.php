<?php

namespace App\Http\Requests\HelpDesk;

use App\Models\HelpDeskSpace;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating or editing a space (Workspace & Inbox Assignment requirements §5, §6).
 *
 * Name is the only required field, which is the requirements' own shape: everything else on the
 * creation page — description, type, colour, the inboxes to assign — is marked optional there,
 * and a form that insists on them would turn "separate our brands" into a form-filling exercise.
 *
 * Name uniqueness stays with the database (HelpDeskSpaceManager::guardDuplicateName): it is
 * scoped to one Help Desk and can lose a race between two administrators, which a pre-flight
 * query cannot see.
 */
class StoreSpaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],

            /*
             * FREE TEXT, and several of them (the chip field on both space forms).
             *
             * Deliberately not `Rule::in(...)` any more. The six the form suggests are the
             * requirements' examples, not an exhaustive list of how organizations describe
             * their support operations, and a space is frequently more than one of them at
             * once. What is still enforced is shape: strings, bounded in length and in number,
             * because these are rendered as chips and stored on the row.
             */
            'types' => ['nullable', 'array', 'max:'.HelpDeskSpace::MAX_TYPES],
            'types.*' => ['string', 'max:'.HelpDeskSpace::MAX_TYPE_LENGTH],

            // A hex colour for the avatar. Anything else is refused rather than sanitized —
            // this value goes into a style attribute.
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],

            // Checked against THIS Help Desk in the manager, where the Help Desk is known; a
            // rule here could only check that the ids exist somewhere.
            'inbox_ids' => ['nullable', 'array'],
            'inbox_ids.*' => ['integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['description', 'color'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }

        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }

        /*
         * Chips are tidied BEFORE validation, not after.
         *
         * A field people type into collects stray whitespace, blank entries from a stray comma,
         * and the same value twice in two spellings. Cleaning here means the length and count
         * limits are checked against what will actually be stored — validating the raw input
         * and cleaning afterwards would reject nine tidy values dressed up as eleven messy ones.
         */
        if ($this->has('types')) {
            $this->merge(['types' => HelpDeskSpace::cleanTypes((array) $this->input('types'))]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the space a name — it is what people switch between.',
            'color.regex' => 'Pick a colour from the list.',
            'types.max' => 'A space can carry up to '.HelpDeskSpace::MAX_TYPES.' types.',
            'types.*.max' => 'Each type can be up to '.HelpDeskSpace::MAX_TYPE_LENGTH.' characters.',
        ];
    }
}
