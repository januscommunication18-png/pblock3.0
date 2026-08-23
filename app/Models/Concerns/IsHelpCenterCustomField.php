<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\HelpCenterSpace;

/**
 * Everything a Help Center custom field does, whichever record it hangs off
 * (docs/features/help-center.md, P18 and P75 §2).
 *
 * `HelpCenterCompanyField` and `HelpCenterCustomerField` are the same model twice — same eight
 * types, same options rule, same ordering, same payload — over two tables (HC-D53). The tables
 * stay separate; the BEHAVIOUR does not, because "does a Radio need choices?" must have one
 * answer for both or the two forms validate differently.
 *
 * The type vocabulary is `help-center.company_field_types` for both. One list, deliberately: a
 * Customer field and a Company field are the same eight shapes, and a second identical config
 * key would be a second place to add the ninth.
 */
trait IsHelpCenterCustomField
{
    /** `customer` or `company` — what `help_center_custom_field_values.field_kind` holds. */
    abstract public static function fieldKind(): string;

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    /** In the order they are asked on the form; `id` breaks ties so the list never reshuffles. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Every configured type, as the pickers and the validator read them.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function types(): array
    {
        return array_values((array) config('help-center.company_field_types'));
    }

    /** @return array<string, mixed>|null */
    public static function type(string $value): ?array
    {
        foreach (self::types() as $type) {
            if ($type['value'] === $value) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Does this type need choices authored before the field means anything?
     *
     * The single answer behind three rules: whether the modal shows an Options section, whether
     * the request demands at least one, and whether the stored list is kept or nulled.
     */
    public static function hasOptions(string $type): bool
    {
        return (bool) (self::type($type)['options'] ?? false);
    }

    public function typeLabel(): string
    {
        return (string) (self::type((string) $this->type)['label'] ?? $this->type);
    }

    /** @return array<int, string> */
    public function optionList(): array
    {
        return array_values(array_map('strval', (array) $this->options));
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'kind' => static::fieldKind(),
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'is_required' => $this->is_required,
            'is_active' => $this->is_active,
            'options' => $this->optionList(),
            'position' => $this->position,
        ];
    }
}
