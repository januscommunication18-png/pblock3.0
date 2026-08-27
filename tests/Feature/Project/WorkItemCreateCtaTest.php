<?php

namespace Tests\Feature\Project;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * "Create Work Item" — the label, and when the action is available
 * (docs/features/work-item-create-cta.md).
 *
 * The naming standard this guards:
 *   Create Work Item — makes a new one.
 *   Add work items   — assigns ones that already exist to a Cycle, Epic or Module.
 *
 * Half of these surfaces are rendered by Vue from static JS, so they never appear in a
 * response body. Those are asserted against the asset files instead: a weaker test than a
 * rendered one, but it still fails the moment somebody sweeps the wrong label through, which
 * is the mistake worth catching.
 */
class WorkItemCreateCtaTest extends ProjectTestCase
{
    use RefreshDatabase;

    private function asset(string $path): string
    {
        return file_get_contents(public_path($path));
    }

    // ================= the sidebar action =================

    public function test_the_sidebar_action_is_enabled_and_labelled_create_work_item(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->makeProject($owner, $workspace, ['identifier' => 'web']);

        $html = $this->actingAs($owner)->get(route('welcome'))->assertOk()->getContent();

        $this->assertStringContainsString('Create Work Item', $html);
        $this->assertStringContainsString('id="new-work-item-btn"', $html);
        // The old wording is gone from the navigation.
        $this->assertStringNotContainsString('New work item', $html);
    }

    public function test_the_sidebar_action_is_disabled_when_the_workspace_has_no_project(): void
    {
        [$owner] = $this->owner();

        $html = $this->actingAs($owner)->get(route('welcome'))->assertOk()->getContent();

        // Shown, not hidden — it is about to come back, and disappearing leaves somebody
        // hunting for it.
        $this->assertStringContainsString('Create Work Item', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringContainsString('Create a project before creating a work item.', $html);

        // Nothing to click and nothing for the create scripts to bind to: no href, and no id.
        $this->assertStringNotContainsString('id="new-work-item-btn"', $html);
    }

    // ================= the naming standard =================

    public function test_the_screens_that_create_a_work_item_say_create(): void
    {
        $workItems = $this->asset('assets/js/projects/work-items.js');

        // The page CTA and the empty-state CTA.
        $this->assertStringContainsString("'Create Work Item</button>' +", $workItems);
        $this->assertSame(2, substr_count($workItems, "'Create Work Item</button>' +"));

        // The per-state "+" on a group header, on both grids that draw one.
        $this->assertStringContainsString('data-tip="Create Work Item"', $workItems);
        $this->assertStringContainsString(
            'data-tip="Create Work Item"',
            $this->asset('assets/js/projects/work-item-list.js'),
        );

        // The quick-create modal reached from the sidebar.
        $this->assertStringContainsString('title="Create Work Item"', $this->asset('assets/js/work-item-create.js'));
    }

    public function test_the_containers_still_say_add_because_they_assign_existing_work(): void
    {
        foreach (['cycles', 'epics', 'modules'] as $screen) {
            $js = $this->asset("assets/js/projects/{$screen}.js");

            $this->assertStringContainsString('Add work items', $js, "{$screen} lost its Add label");
            $this->assertStringNotContainsString('Create Work Item', $js, "{$screen} wrongly says Create");
        }
    }
}
