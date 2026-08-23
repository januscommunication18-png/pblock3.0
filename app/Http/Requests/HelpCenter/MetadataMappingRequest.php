<?php

namespace App\Http\Requests\HelpCenter;

use App\Models\HelpCenterCustomFieldValue;
use App\Models\HelpCenterMetadataMapping;
use App\Models\HelpCenterSpace;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing one Ticket Metadata Mapping row (docs/features/help-center.md, P75 §4).
 *
 * The three dropdowns are validated against the same config the pickers are drawn from, so a
 * hand-rolled request cannot store a source or a destination the engine will not recognise —
 * which would be a mapping that silently does nothing.
 *
 * The two rules worth stating: `destination = custom_field` needs a field, and that field must
 * belong to THIS Space and THIS record type. Without the second check, an id from another Space
 * in the same workspace would be writable by whoever may manage this one.
 */
class MetadataMappingRequest extends FormRequest
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
            'source' => ['required', 'string', Rule::in(array_column(HelpCenterMetadataMapping::sources(), 'key'))],
            'source_key' => ['nullable', 'string', 'max:80'],
            'record_type' => ['required', 'string', Rule::in(HelpCenterMetadataMapping::recordTypes())],
            'destination' => ['required', 'string'],
            'custom_field_id' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $type = (string) $this->input('record_type');
            $destination = (string) $this->input('destination');

            if (! HelpCenterMetadataMapping::hasDestination($type, $destination)) {
                $validator->errors()->add('destination', 'Choose a destination field.');

                return;
            }

            $this->checkSourceKey($validator);
            $this->checkCustomField($validator, $type, $destination);
            $this->checkDuplicate($validator, $type, $destination);
        });
    }

    /** `Custom / Integration Field` is the one source that also needs the payload key naming. */
    private function checkSourceKey(Validator $validator): void
    {
        if (! HelpCenterMetadataMapping::sourceIsNamed((string) $this->input('source'))) {
            return;
        }

        if (trim((string) $this->input('source_key')) === '') {
            $validator->errors()->add('source_key', 'Name the field this mapping reads.');
        }
    }

    /**
     * One mapping per destination per Space.
     *
     * The schema carries the same unique key, and for a Custom Field destination that key does
     * the work. It does NOT for an attribute destination: `custom_field_id` is null there, and
     * MySQL treats repeated NULLs in a unique index as distinct — so two mappings both writing
     * Customer → Email were accepted by the database and only discovered at ingest, where the
     * winner depends on `position`.
     *
     * Checked here instead, where it can name the destination and be reported as a field error.
     */
    private function checkDuplicate(Validator $validator, string $type, string $destination): void
    {
        $space = $this->route('space');
        $spaceId = $space instanceof HelpCenterSpace ? (int) $space->id : (int) $space;

        $existing = $this->route('mapping');
        $existingId = $existing instanceof HelpCenterMetadataMapping ? (int) $existing->id : (int) $existing;

        $clash = HelpCenterMetadataMapping::query()
            ->where('help_center_space_id', $spaceId)
            ->where('record_type', $type)
            ->where('destination', $destination)
            ->when(
                $destination === HelpCenterMetadataMapping::DESTINATION_CUSTOM_FIELD,
                fn ($q) => $q->where('custom_field_id', (int) $this->input('custom_field_id')),
            )
            // Editing a row is not a clash with itself.
            ->when($existingId > 0, fn ($q) => $q->where('id', '!=', $existingId))
            ->exists();

        if ($clash) {
            $validator->errors()->add('destination', 'Another mapping already writes to that field.');
        }
    }

    private function checkCustomField(Validator $validator, string $type, string $destination): void
    {
        if ($destination !== HelpCenterMetadataMapping::DESTINATION_CUSTOM_FIELD) {
            return;
        }

        $id = (int) $this->input('custom_field_id');

        if ($id <= 0) {
            $validator->errors()->add('custom_field_id', 'Choose which custom field to write to.');

            return;
        }

        $space = $this->route('space');
        $spaceId = $space instanceof HelpCenterSpace ? (int) $space->id : (int) $space;

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
        $model = HelpCenterCustomFieldValue::fieldModel($type);

        $exists = $model::query()
            ->where('id', $id)
            ->where('help_center_space_id', $spaceId)
            ->exists();

        if (! $exists) {
            $validator->errors()->add('custom_field_id', 'That custom field is not on this Space.');
        }
    }

    protected function prepareForValidation(): void
    {
        $source = (string) $this->input('source');
        $destination = (string) $this->input('destination');

        $this->merge([
            /*
             * Defaulted to ON when the caller does not say.
             *
             * The column's default already makes the stored row active, but `create()` never
             * SETS the attribute when the key is absent — so the model handed back to the client
             * carried `is_active: null` and the row rendered as neither Active nor Inactive
             * until the page was reloaded. Defaulting here means the request, the row and the
             * response all agree.
             */
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
            /*
             * Both extra keys are DROPPED when the answer they belong to does not need them.
             *
             * A `source_key` left behind by a source somebody changed away from, or a
             * `custom_field_id` behind an attribute destination, is a stored value nothing reads
             * — and the next reader has to decide what it means. The unique index over
             * `(space, record_type, destination, custom_field_id)` also depends on it: a
             * leftover id would make two rows writing Customer → Email look distinct.
             */
            'source_key' => HelpCenterMetadataMapping::sourceIsNamed($source)
                ? trim((string) $this->input('source_key'))
                : null,
            'custom_field_id' => $destination === HelpCenterMetadataMapping::DESTINATION_CUSTOM_FIELD
                ? ($this->input('custom_field_id') === null ? null : (int) $this->input('custom_field_id'))
                : null,
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source.required' => 'Choose a source field.',
            'source.in' => 'Choose a source field.',
            'record_type.required' => 'Choose Customer or Company.',
            'record_type.in' => 'Choose Customer or Company.',
            'destination.required' => 'Choose a destination field.',
        ];
    }
}
