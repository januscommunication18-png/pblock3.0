<?php

namespace App\Services;

use App\Models\Epic;
use App\Models\EpicActivity;
use App\Models\User;
use App\Models\WorkItem;

/**
 * Writes an Epic's activity feed (Epic §8/§22).
 *
 * Every entry stores the **label** it should read with, resolved now: a lead's display name, a
 * status's label, a work item's identifier and title. Storing only ids would make the feed a
 * live view of the present rather than a record of the past — renaming a status or deleting a
 * work item would silently rewrite history, and history that changes is not history.
 *
 * §22's list maps to `event` plus, for updates, the `field` that changed.
 */
class EpicActivityRecorder
{
    /** The properties worth a feed entry (§22), with how each one reads. */
    private const LABELS = [
        'title' => 'title',
        'description' => 'description',
        'status' => 'status',
        'priority' => 'priority',
        'lead_user_id' => 'lead',
        'start_date' => 'start date',
        'target_date' => 'target date',
        'members' => 'members',
    ];

    public function created(Epic $epic, ?User $actor): void
    {
        $this->write($epic, $actor, EpicActivity::EVENT_CREATED);
    }

    public function archived(Epic $epic, ?User $actor, bool $restored = false): void
    {
        $this->write($epic, $actor, $restored ? EpicActivity::EVENT_RESTORED : EpicActivity::EVENT_ARCHIVED);
    }

    /**
     * One entry per changed property (§22).
     *
     * @param  array<string, mixed>  $before  the values as they were, keyed by column
     * @param  array<string, mixed>  $after
     */
    public function updated(Epic $epic, ?User $actor, array $before, array $after): void
    {
        foreach (self::LABELS as $field => $label) {
            if (! array_key_exists($field, $after)) {
                continue;
            }

            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            // Compared loosely on purpose: a date arrives as a string and leaves as a Carbon,
            // and an unchanged field must not manufacture an entry saying it changed.
            if ($this->same($old, $new)) {
                continue;
            }

            $this->write($epic, $actor, EpicActivity::EVENT_UPDATED, $field, $old, $new, [
                'label' => $label,
                'old_label' => $this->label($field, $old),
                'new_label' => $this->label($field, $new),
            ]);
        }
    }

    /** §22: work item associated / disassociated. */
    public function workItemLinked(Epic $epic, ?User $actor, WorkItem $item, bool $removed = false): void
    {
        $this->write(
            $epic,
            $actor,
            $removed ? EpicActivity::EVENT_ITEM_REMOVED : EpicActivity::EVENT_ITEM_ADDED,
            null,
            null,
            (string) $item->id,
            ['item_label' => $item->identifier.' · '.$item->title],
        );
    }

    private function write(
        Epic $epic,
        ?User $actor,
        string $event,
        ?string $field = null,
        mixed $old = null,
        mixed $new = null,
        ?array $meta = null,
    ): void {
        EpicActivity::create([
            'epic_id' => $epic->id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'field' => $field,
            'old_value' => $this->scalar($old),
            'new_value' => $this->scalar($new),
            'meta' => $meta,
        ]);
    }

    /** How a value should READ in the feed, resolved now rather than at display time. */
    private function label(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($field) {
            'status' => config("projects.epic_statuses.{$value}.label") ?? (string) $value,
            'priority' => config("projects.work_item_priorities.{$value}") ?? (string) $value,
            'lead_user_id' => User::find($value)?->displayName(),
            'members' => collect(User::whereIn('id', (array) $value)->get())
                ->map(fn (User $u) => $u->displayName())->implode(', '),
            default => $this->scalar($value),
        };
    }

    private function scalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return implode(',', $value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }

    private function same(mixed $old, mixed $new): bool
    {
        if (is_array($old) || is_array($new)) {
            $a = array_map('strval', (array) $old);
            $b = array_map('strval', (array) $new);
            sort($a);
            sort($b);

            return $a === $b;
        }

        return $this->scalar($old) === $this->scalar($new);
    }
}
