<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * What one Customer or Company answered to one custom field (P75 §8). TENANT-SCOPED.
 *
 * One table for both sides (HC-D54), discriminated by `field_kind`. The value is always TEXT:
 * what it MEANS is the field's `type`, which is one lookup away and is already the single source
 * for that question.
 */
class HelpCenterCustomFieldValue extends Model
{
    use BelongsToTenant;

    public const KIND_CUSTOMER = 'customer';

    public const KIND_COMPANY = 'company';

    protected $fillable = [
        'tenant_id',
        'field_kind',
        'field_id',
        'owner_id',
        'value',
    ];

    /** @return array<int, string> */
    public static function kinds(): array
    {
        return [self::KIND_CUSTOMER, self::KIND_COMPANY];
    }

    /** The field model class behind a kind — the one place the two tables are named together. */
    public static function fieldModel(string $kind): string
    {
        return $kind === self::KIND_COMPANY ? HelpCenterCompanyField::class : HelpCenterCustomerField::class;
    }

    public function scopeFor(Builder $query, string $kind, int $ownerId): Builder
    {
        return $query->where('field_kind', $kind)->where('owner_id', $ownerId);
    }

    /**
     * One record's answers, keyed by field id.
     *
     * @return array<int, string|null>
     */
    public static function mapFor(string $kind, int $ownerId): array
    {
        return self::query()->for($kind, $ownerId)->pluck('value', 'field_id')->all();
    }

    /**
     * Write one answer, creating the row or replacing it.
     *
     * `updateOrCreate` over the unique key rather than a read-then-write: the mapping engine
     * runs inside ingest, two messages from the same sender can be ingested at once, and a
     * check-then-insert is the race the unique index exists to stop.
     */
    public static function put(string $tenantId, string $kind, int $fieldId, int $ownerId, ?string $value): self
    {
        return self::updateOrCreate(
            ['field_kind' => $kind, 'field_id' => $fieldId, 'owner_id' => $ownerId],
            ['tenant_id' => $tenantId, 'value' => $value],
        );
    }

    /**
     * The answers a panel renders — the FIELD and its value together, active fields only.
     *
     * Driven off the field list rather than off the stored values, so a field with no answer
     * still appears (an empty row is information: nobody has filled it in) and a value left
     * behind by a deleted field does not.
     *
     * @param  \Illuminate\Support\Collection<int, HelpCenterCompanyField|HelpCenterCustomerField>  $fields
     * @return array<int, array<string, mixed>>
     */
    public static function panel($fields, string $kind, int $ownerId): array
    {
        $values = self::mapFor($kind, $ownerId);

        return $fields->map(fn ($field) => $field->toPayload() + [
            'value' => $values[$field->id] ?? null,
        ])->values()->all();
    }
}
