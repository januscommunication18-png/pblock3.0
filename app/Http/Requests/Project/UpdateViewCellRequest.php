<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectView;
use App\Models\ProjectViewColumn;
use App\Services\ViewFieldCatalog;

/**
 * An inline cell edit in a View's grid (Views §11).
 *
 * **Extends the ordinary work item update deliberately.** §11.4 says an inline edit should be
 * recorded in normal work item activity, and §11.3 that grid editability never overrides normal
 * authorization — both of which are simply true if a cell edit IS a work item edit. So every
 * rule, every prepareForValidation step and the whole feature-disable after-hook are inherited
 * rather than restated, and a rule added there tomorrow reaches the grid without anyone
 * remembering to copy it.
 *
 * What this class adds is the part that only exists because the edit came through a View:
 * §11.3's six checks, in the order that lets the cheapest refusal come first.
 */
class UpdateViewCellRequest extends UpdateWorkItemRequest
{
    /**
     * §11.3, checks 1–4: project access, View access, View data-edit permission, and
     * permission on the underlying work item.
     *
     * Returning false here produces a 403 BEFORE any rule runs, so an unauthorized caller
     * never learns anything from a validation message about fields they cannot reach.
     */
    public function authorize(): bool
    {
        $view = $this->route('view');
        $item = $this->route('workItem');
        $project = $this->route('project');
        $user = $this->user();

        if (! $view || ! $item || ! $project || ! $user) {
            return false;
        }

        // The View and the work item must both belong to the project in the URL. Without this
        // a caller could edit any work item they can reach by naming a View they can edit.
        if ((int) $view->project_id !== (int) $project->id || (int) $item->project_id !== (int) $project->id) {
            return false;
        }

        return $user->can('updateData', $view) && $user->can('update', $item);
    }

    /**
     * The fields the CLIENT actually sent.
     *
     * Captured before the parent's prepareForValidation runs, because that legitimately adds
     * keys — sending only `due_date` merges the stored `start_date` in so `after:start_date`
     * compares against reality. Checking the post-merge payload against the column would fail
     * a perfectly ordinary date edit for carrying a field the user never touched.
     *
     * @var array<int, string>
     */
    private array $submitted = [];

    protected function prepareForValidation(): void
    {
        $this->submitted = array_values(array_diff($this->keys(), ['column_id', '_method']));

        parent::prepareForValidation();
    }

    /**
     * §11.3, checks 5 and 6: the column may be written, and the payload matches it.
     *
     * The client names the column it is editing. That is not trusted as permission — it is
     * turned back into the catalog's own definition, and the request is refused unless the
     * submitted field is exactly the one that column writes. It closes the gap where a cell in
     * an innocuous column carries a payload for a different, restricted field.
     *
     * `is_editable` false, a read-only field, and a disabled feature all land here as the same
     * refusal, because from the caller's side they are the same answer: not this column.
     */
    public function after(): array
    {
        return array_merge(parent::after(), [
            function ($validator) {
                $view = $this->route('view');
                $project = $this->route('project');

                if (! $view instanceof ProjectView || ! $project) {
                    return;
                }

                $column = ProjectViewColumn::query()
                    ->where('project_view_id', $view->id)
                    ->find($this->input('column_id'));

                if ($column === null) {
                    $validator->errors()->add('column_id', 'That column is not part of this view.');

                    return;
                }

                $catalog = app(ViewFieldCatalog::class);

                if (! $catalog->isCellEditable($project, $column)) {
                    $validator->errors()->add('column_id', 'This column cannot be edited.');

                    return;
                }

                $writes = $catalog->find($column->key())['writes'] ?? null;

                // Exactly the field this column writes, and nothing else. Naming an innocuous
                // column while sending a payload for a different, restricted field is the gap
                // this closes.
                if ($writes === null || $this->submitted !== [$writes]) {
                    $validator->errors()->add('column_id', 'This edit does not match the column being edited.');
                }
            },
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'column_id' => ['required', 'integer'],
        ];
    }
}
