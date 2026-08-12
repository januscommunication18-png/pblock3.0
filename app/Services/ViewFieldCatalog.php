<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectViewColumn;

/**
 * Every property a View can map into a column (Views §9, §10).
 *
 * This is the ONE definition. The Add Column selector offers what it lists, column writes are
 * validated against it, the grid renders from its `type`, and the inline-edit endpoint decides
 * what a cell may write from its `editable` and `writes` keys. A field that existed in one of
 * those and not another is the bug this class exists to make impossible — and it is what §30
 * means by one reusable View Engine rather than four half-agreeing ones.
 *
 * §9.1 also lists "custom / project fields". The application has no custom-field system yet, so
 * there is nothing to list; when there is, it becomes extra entries returned from here and the
 * grid, the column model and the API do not move.
 *
 * ## The three keys that carry the rules
 *
 * - `feature` — the project feature this field needs (§9.2). Null means always available.
 *   A field whose feature is off cannot be ADDED; a column already saved for it is kept and
 *   marked unavailable (§9.3), which is decided in ViewColumnLayout, not here.
 * - `editable` — whether the field can EVER be edited inline (§11.2). False here cannot be
 *   overridden by a column's `is_editable`, which is a ceiling rather than a grant (§11.3).
 * - `writes` — the work item attribute an edit actually sets, so the inline-edit endpoint
 *   never has to map a column key to a column name by hand.
 */
class ViewFieldCatalog
{
    /** §9.1's categories, in the order the Add Column selector groups them. */
    public const SOURCES = ['work_item', 'epic', 'cycle', 'module', 'estimate', 'label', 'member'];

    public function __construct(private readonly ProjectFeatureState $featureState) {}

    /**
     * The whole catalog, keyed by `source.field`.
     *
     * `row` is the key to read from the row payload; `type` is what the grid renders it as.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            // ---- Work item (§9.1) ----
            'work_item.identifier' => $this->field('work_item', 'identifier', 'ID', 'text', null, false, width: 110),
            'work_item.title' => $this->field('work_item', 'title', 'Title', 'title', null, true, width: 340, writes: 'title'),
            'work_item.state' => $this->field('work_item', 'state', 'Status', 'state', null, true, width: 150, writes: 'state_id'),
            'work_item.priority' => $this->field('work_item', 'priority', 'Priority', 'priority', null, true, width: 130, writes: 'priority'),
            'work_item.start_date' => $this->field('work_item', 'start_date', 'Start date', 'date', null, true, width: 130, writes: 'start_date'),
            'work_item.due_date' => $this->field('work_item', 'due_date', 'Due date', 'date', null, true, width: 130, writes: 'due_date'),
            // Rich text: readable as a stripped preview, but not editable in a grid cell —
            // §11.1 does not list it, and a cell is the wrong place for a document.
            'work_item.description' => $this->field('work_item', 'description', 'Description', 'longtext', null, false, width: 280),
            'work_item.parent' => $this->field('work_item', 'parent', 'Parent', 'work_item_ref', null, false, width: 200),
            'work_item.created_at' => $this->field('work_item', 'created_at', 'Created', 'datetime', null, false, width: 140),
            'work_item.updated_at' => $this->field('work_item', 'updated_at', 'Updated', 'datetime', null, false, width: 140),

            // ---- Epic (§9.1), only when Epics are enabled ----
            'epic.title' => $this->field('epic', 'title', 'Epic', 'epic', 'epics', true, width: 200, writes: 'epic_id'),
            'epic.status' => $this->field('epic', 'status', 'Epic status', 'badge', 'epics', false, width: 140),
            'epic.lead' => $this->field('epic', 'lead', 'Epic lead', 'member', 'epics', false, width: 160),

            // ---- Cycle (§9.1) ----
            'cycle.name' => $this->field('cycle', 'name', 'Cycle', 'cycle', 'cycles', true, width: 180, writes: 'cycle_id'),
            'cycle.status' => $this->field('cycle', 'status', 'Cycle status', 'badge', 'cycles', false, width: 140),
            'cycle.start_date' => $this->field('cycle', 'start_date', 'Cycle start', 'date', 'cycles', false, width: 130),
            'cycle.end_date' => $this->field('cycle', 'end_date', 'Cycle end', 'date', 'cycles', false, width: 130),

            // ---- Module (§9.1) — many per work item, unlike the single cycle and epic ----
            'module.title' => $this->field('module', 'title', 'Modules', 'modules', 'modules', true, width: 200, writes: 'module_ids'),
            'module.status' => $this->field('module', 'status', 'Module status', 'badge_list', 'modules', false, width: 150),
            'module.lead' => $this->field('module', 'lead', 'Module lead', 'member_list', 'modules', false, width: 160),

            // ---- Estimation (§9.1) ----
            'estimate.value' => $this->field('estimate', 'value', 'Estimate', 'estimate', 'estimates', true, width: 120, writes: 'estimate_value_id'),

            // ---- Labels (§9.1) ----
            'label.labels' => $this->field('label', 'labels', 'Labels', 'labels', 'labels', true, width: 220, writes: 'label_ids'),

            // ---- Members (§9.1) ----
            'member.assignees' => $this->field('member', 'assignees', 'Assignee', 'assignees', null, true, width: 160, writes: 'assignee_ids'),
            'member.created_by' => $this->field('member', 'created_by', 'Created by', 'member', null, false, width: 160),
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * The catalog as the Add Column selector needs it (§9): grouped by source, every field
     * carrying whether it is currently available and why not.
     *
     * Disabled groups are RETURNED rather than filtered out. §9.2 says their properties cannot
     * be added; a selector that simply omitted them would leave the user wondering where Epic
     * went, so they are shown, unselectable, saying which feature to switch on.
     *
     * @param  array<int, string>  $used  keys already mapped into this View (§8.3)
     * @return array<int, array<string, mixed>>
     */
    public function selector(Project $project, array $used = []): array
    {
        $labels = [
            'work_item' => 'Work item', 'epic' => 'Epic', 'cycle' => 'Cycle', 'module' => 'Module',
            'estimate' => 'Estimation', 'label' => 'Labels', 'member' => 'Members',
        ];

        $catalog = collect($this->all());
        $groups = [];

        foreach (self::SOURCES as $source) {
            $inSource = $catalog->filter(fn (array $f) => $f['source'] === $source);

            if ($inSource->isEmpty()) {
                continue;
            }

            // Every field in a source shares one feature, so the group is available exactly
            // when that feature is — which is what lets the selector grey a whole section with
            // one explanation instead of repeating it on each field.
            $feature = $inSource->first()['feature'];
            $available = $this->isAvailable($project, $inSource->first());

            $groups[] = [
                'source' => $source,
                'label' => $labels[$source] ?? $source,
                'available' => $available,
                'feature' => $feature,
                'reason' => $available ? null : $this->unavailableReason($inSource->first()),
                'fields' => $inSource->map(fn (array $f, string $key) => [
                    'key' => $key,
                    'label' => $f['label'],
                    'type' => $f['type'],
                    'available' => $available,
                    'used' => in_array($key, $used, true),
                ])->values()->all(),
            ];
        }

        return $groups;
    }

    /**
     * Is this field usable on this project right now (§9.2)?
     *
     * Availability is DERIVED every time rather than stored, which is what makes §9.3 work
     * without a migration or a backfill on every feature toggle: switch Cycles off and the
     * saved cycle columns become unavailable at the next read; switch it on and they are back,
     * in the same place, at the same width.
     *
     * @param  array<string, mixed>  $field
     */
    public function isAvailable(Project $project, array $field): bool
    {
        if ($field['feature'] === null) {
            return true;
        }

        return $this->featureState->state($project, $field['feature']) === ProjectFeatureState::ENABLED;
    }

    public function isColumnAvailable(Project $project, ProjectViewColumn $column): bool
    {
        $field = $this->find($column->key());

        // A column whose field no longer exists in the catalog is unavailable rather than
        // fatal — the same non-destructive treatment §9.3 asks for on a disabled feature.
        return $field !== null && $this->isAvailable($project, $field);
    }

    /**
     * Can a cell in this column be written at all (§11.2, §11.3)?
     *
     * Three independent gates, all of which must pass: the field is editable in principle, the
     * user's configuration did not switch it off, and the feature behind it is on. Whether
     * THIS user may edit THIS work item is a separate question, answered by the policy — never
     * here, so that a configuration change can never become an authorization change.
     */
    public function isCellEditable(Project $project, ProjectViewColumn $column): bool
    {
        $field = $this->find($column->key());

        return $field !== null
            && $field['editable'] === true
            && $column->is_editable === true
            && $this->isAvailable($project, $field);
    }

    /** @param  array<string, mixed>  $field */
    private function unavailableReason(array $field): string
    {
        $names = [
            'epics' => 'Epics', 'cycles' => 'Cycles', 'modules' => 'Modules',
            'estimates' => 'Estimation', 'labels' => 'Labels',
        ];

        $name = $names[$field['feature']] ?? $field['feature'];

        return "{$name} is turned off for this project.";
    }

    /** @return array<string, mixed> */
    private function field(
        string $source,
        string $field,
        string $label,
        string $type,
        ?string $feature,
        bool $editable,
        int $width = 160,
        ?string $writes = null,
    ): array {
        return [
            'source' => $source,
            'field' => $field,
            'label' => $label,
            'type' => $type,
            'feature' => $feature,
            'editable' => $editable,
            'width' => $width,
            'writes' => $writes,
        ];
    }
}
