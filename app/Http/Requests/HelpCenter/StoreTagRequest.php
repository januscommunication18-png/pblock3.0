<?php

namespace App\Http\Requests\HelpCenter;

use App\Models\HelpCenterTag;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding one tag to a Space (docs/features/help-center.md, P14).
 *
 * The duplicate check is the whole job. The table's unique index is the guarantee; this is what
 * turns a second "Billing" into a sentence somebody can act on instead of a 500.
 */
class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller asks the Space policy. A request that re-derived it would be a second
        // place for that rule to live.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $name = HelpCenterTag::key((string) $this->input('name'));

            if ($name === '' || $validator->errors()->has('name')) {
                return;
            }

            /*
             * Scoped to THIS Space, and compared on `name_key`.
             *
             * Two Spaces may each have a "Billing" tag — different teams, different vocabulary
             * — so a workspace-wide check would refuse a name the other Space merely happens to
             * use. Comparing the key rather than the name is what makes "billing" and "Billing"
             * one tag on Postgres as well as MySQL (D1).
             */
            $exists = HelpCenterTag::query()
                ->where('help_center_space_id', $this->route('space')?->id)
                ->where('name_key', $name)
                ->exists();

            if ($exists) {
                $validator->errors()->add('name', 'This Space already has a tag called that.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        // Trimmed once, here, so what is checked is what is stored — a trailing space is not a
        // different tag, and storing one would make the list hold a word twice.
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }
}
