<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One row of Ticket Metadata Mapping (docs/features/help-center.md, P75 §3–§4). TENANT-SCOPED.
 *
 * "This field on an incoming request goes to that field on the Customer or the Company." The
 * three vocabularies it is built from live in `config/help-center.php` — `mapping_sources` and
 * `mapping_destinations` — because five things read them and a key that meant one thing to the
 * picker and another to the engine would be a mapping that quietly wrote the wrong column.
 */
class HelpCenterMetadataMapping extends Model
{
    use BelongsToTenant;

    /** The destination that needs a second answer: WHICH field. */
    public const DESTINATION_CUSTOM_FIELD = 'custom_field';

    /** The source that reads a named key out of the payload's own bag. */
    public const SOURCE_CUSTOM = 'custom';

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'source',
        'source_key',
        'record_type',
        'destination',
        'custom_field_id',
        'is_active',
        'position',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
            'custom_field_id' => 'integer',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Every source a mapping may read.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function sources(): array
    {
        return array_values((array) config('help-center.mapping_sources'));
    }

    /** @return array<string, mixed>|null */
    public static function source(string $key): ?array
    {
        foreach (self::sources() as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }

    /** Does this source need the payload key naming as well? Only `custom` does. */
    public static function sourceIsNamed(string $key): bool
    {
        return (bool) (self::source($key)['named'] ?? false);
    }

    /** @return array<string, mixed> */
    public static function destinations(): array
    {
        return (array) config('help-center.mapping_destinations');
    }

    /** @return array<int, string> */
    public static function recordTypes(): array
    {
        return array_keys(self::destinations());
    }

    /**
     * The attributes a record type offers.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fieldsFor(string $recordType): array
    {
        return array_values((array) (self::destinations()[$recordType]['fields'] ?? []));
    }

    public static function hasDestination(string $recordType, string $destination): bool
    {
        foreach (self::fieldsFor($recordType) as $field) {
            if ($field['key'] === $destination) {
                return true;
            }
        }

        return false;
    }

    public function writesCustomField(): bool
    {
        return $this->destination === self::DESTINATION_CUSTOM_FIELD;
    }

    /**
     * The key this mapping reads out of the parsed metadata.
     *
     * For every source but `custom` it IS the source; for `custom` it is whatever the person
     * typed, which is the only thing this product can know about an integration's own field
     * names.
     */
    public function metadataKey(): string
    {
        return $this->source === self::SOURCE_CUSTOM
            ? (string) $this->source_key
            : (string) $this->source;
    }

    /**
     * The custom field this writes to, or null.
     *
     * Null when the destination is an attribute, and ALSO when the field has since been deleted
     * — which is why the engine asks rather than assuming. A mapping pointing at a field that is
     * gone is skipped, not fatal: deleting a field should not stop mail being ingested.
     */
    public function destinationField(): HelpCenterCompanyField|HelpCenterCustomerField|null
    {
        if (! $this->writesCustomField() || $this->custom_field_id === null) {
            return null;
        }

        /** @var class-string<HelpCenterCompanyField|HelpCenterCustomerField> $model */
        $model = HelpCenterCustomFieldValue::fieldModel((string) $this->record_type);

        return $model::query()->find($this->custom_field_id);
    }

    public function sourceLabel(): string
    {
        $label = (string) (self::source((string) $this->source)['label'] ?? $this->source);

        return $this->source === self::SOURCE_CUSTOM && $this->source_key
            ? $label.' · '.$this->source_key
            : $label;
    }

    public function recordTypeLabel(): string
    {
        return (string) (self::destinations()[$this->record_type]['label'] ?? $this->record_type);
    }

    public function destinationLabel(): string
    {
        if ($this->writesCustomField()) {
            // The field's own name, not "Custom Field" — the point of the row is WHICH field,
            // and a table of six rows all reading "Custom Field" says nothing.
            return $this->destinationField()?->name ?? 'Custom Field (deleted)';
        }

        foreach (self::fieldsFor((string) $this->record_type) as $field) {
            if ($field['key'] === $this->destination) {
                return (string) $field['label'];
            }
        }

        return (string) $this->destination;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'source_key' => $this->source_key,
            'source_label' => $this->sourceLabel(),
            'record_type' => $this->record_type,
            'record_type_label' => $this->recordTypeLabel(),
            'destination' => $this->destination,
            'custom_field_id' => $this->custom_field_id,
            'destination_label' => $this->destinationLabel(),
            'is_active' => $this->is_active,
            'position' => $this->position,
        ];
    }
}
