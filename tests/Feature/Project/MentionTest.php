<?php

namespace Tests\Feature\Project;

use App\Mail\MentionedMail;
use App\Models\Mention;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use App\Services\RichTextSanitizer;
use App\Services\WorkItemCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/**
 * @mention — the backend half (docs/features/mentions.md).
 *
 * The rule underneath most of these is §23/§24: the editor is not the source of truth. A
 * `<span data-user-id>` arriving from a browser is a CLAIM, and a mention exists only because
 * the backend agreed with it. So the tests that matter most are the ones where the markup says
 * one thing and the answer is nothing.
 */
class MentionTest extends ProjectTestCase
{
    use RefreshDatabase;

    private function chip(User $user): string
    {
        // What the editor's autocomplete inserts.
        return '<span class="pb-mention" data-mention-type="user" data-user-id="'.$user->id.'">@'
            .e($user->displayName()).'</span>';
    }

    private function makeItem($ws, $creator, $project, array $data = []): WorkItem
    {
        return $ws->run(fn () => app(WorkItemCreator::class)->create($creator, $project, array_merge([
            'title' => 'Chase the invoice PDF bug',
        ], $data)));
    }

    /** A member of the workspace AND of the project, so they are mentionable. */
    private function collaborator($ws, $project, string $email, string $role = ProjectMember::ROLE_CONTRIBUTOR): User
    {
        $user = $this->member($ws, 'member', $email);
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'role' => $role,
        ]));

        return $user;
    }

    // ---- The search endpoint (§5, §16) --------------------------------------------------

    public function test_the_autocomplete_offers_only_people_with_access_to_the_project(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['visibility' => 'private']);
        $onProject = $this->collaborator($ws, $project, 'on@example.com');
        // In the workspace, never added to this project.
        $outsider = $this->member($ws, 'member', 'outsider@example.com');

        $ids = collect($this->actingAs($owner)
            ->getJson(route('projects.mentionable-users', $project))
            ->assertOk()
            ->json('users'))->pluck('id');

        // The owner administers the workspace, so they reach every project (§38).
        $this->assertTrue($ids->contains($owner->id));
        $this->assertTrue($ids->contains($onProject->id));
        $this->assertFalse($ids->contains($outsider->id), 'A workspace user with no project access was offered.');
    }

    public function test_the_autocomplete_searches_name_and_email(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $priya = $this->collaborator($ws, $project, 'priya@example.com');
        $priya->forceFill(['full_name' => 'Priya Nair', 'display_name' => 'Priya Nair'])->save();

        $byName = $this->actingAs($owner)
            ->getJson(route('projects.mentionable-users', $project).'?search=pri')
            ->assertOk()->json('users');
        $this->assertSame([$priya->id], array_column($byName, 'id'));

        $byEmail = $this->actingAs($owner)
            ->getJson(route('projects.mentionable-users', $project).'?search=priya@ex')
            ->assertOk()->json('users');
        $this->assertSame([$priya->id], array_column($byEmail, 'id'));

        // §6: enough to render [avatar] name / email.
        $this->assertArrayHasKey('avatar_url', $byName[0]);
        $this->assertArrayHasKey('initial', $byName[0]);
        $this->assertSame('priya@example.com', $byName[0]['email']);
    }

    public function test_someone_who_cannot_open_the_project_cannot_list_its_people(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['visibility' => 'private']);
        $outsider = $this->member($ws, 'member', 'outsider2@example.com');

        $this->actingAs($outsider)->getJson(route('projects.mentionable-users', $project))->assertNotFound();
    }

    // ---- The editor integration (§1, §21, §28) --------------------------------------------

    public function test_the_work_item_screen_ships_the_mention_engine_and_its_endpoint(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        $response = $this->actingAs($owner)->get(route('projects.work-items', $project))->assertOk();

        // Vendored, not fetched — the app loads nothing from a CDN at runtime.
        $response->assertSee('assets/vendor/tribute/tribute.min.js', false);

        // §21/§22: the endpoint is configured centrally, in the payload every editor reads,
        // rather than by each form for itself.
        $response->assertViewHas('bootstrap', fn (array $b) => ($b['endpoints']['mentionUsers'] ?? null)
            === route('projects.mentionable-users', $project));
    }

    // ---- Sanitizing (§25) ---------------------------------------------------------------

    public function test_the_mention_chip_survives_sanitizing_and_nothing_else_does(): void
    {
        $sanitizer = app(RichTextSanitizer::class);

        $clean = (string) $sanitizer->sanitize(
            '<p><span class="pb-mention" data-user-id="247" data-mention-type="user" onclick="alert(1)">@Rohit</span></p>'
        );

        $this->assertStringContainsString('data-user-id="247"', $clean);
        $this->assertStringContainsString('data-mention-type="user"', $clean);
        $this->assertStringContainsString('pb-mention', $clean);
        // The attributes are DATA, and the element is still just a span.
        $this->assertStringNotContainsString('onclick', $clean);
    }

    // ---- Creating (§10, §13, §14, §15, §27) ----------------------------------------------

    public function test_a_description_mention_is_recorded_and_emailed_after_the_item_exists(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $priya = $this->collaborator($ws, $project, 'priya2@example.com');

        $item = $this->makeItem($ws, $owner, $project, [
            'description' => '<p>'.$this->chip($priya).' please review.</p>',
        ]);

        $mention = Mention::where('source_type', Mention::SOURCE_WORK_ITEM)->where('source_id', $item->id)->firstOrFail();
        $this->assertSame($priya->id, $mention->user_id);
        $this->assertSame($owner->id, $mention->mentioned_by);
        $this->assertSame($item->id, $mention->work_item_id);

        Mail::assertSent(MentionedMail::class, fn ($mail) => $mail->hasTo($priya->email));
    }

    public function test_naming_several_people_creates_one_record_each(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $a = $this->collaborator($ws, $project, 'a@example.com');
        $b = $this->collaborator($ws, $project, 'b@example.com');

        $item = $this->makeItem($ws, $owner, $project, [
            'description' => '<p>'.$this->chip($a).' '.$this->chip($b).' please review.</p>',
        ]);

        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            Mention::forSource(Mention::SOURCE_WORK_ITEM, $item->id)->pluck('user_id')->all(),
        );
        Mail::assertSent(MentionedMail::class, 2);
    }

    public function test_naming_the_same_person_twice_is_one_mention(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $priya = $this->collaborator($ws, $project, 'priya3@example.com');

        // §14: the content may show them twice; the notification is one.
        $item = $this->makeItem($ws, $owner, $project, [
            'description' => '<p>'.$this->chip($priya).' review.</p><p>Also '.$this->chip($priya).' to confirm.</p>',
        ]);

        $this->assertSame(1, Mention::forSource(Mention::SOURCE_WORK_ITEM, $item->id)->count());
        Mail::assertSent(MentionedMail::class, 1);
    }

    public function test_mentioning_yourself_records_it_but_sends_no_email(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        $item = $this->makeItem($ws, $owner, $project, [
            'description' => '<p>'.$this->chip($owner).' remember to update this.</p>',
        ]);

        // §15: the record is real — the content genuinely names them — the email is not sent.
        $this->assertSame(1, Mention::forSource(Mention::SOURCE_WORK_ITEM, $item->id)->count());
        Mail::assertNotSent(MentionedMail::class);
    }

    public function test_typing_an_at_name_by_hand_mentions_nobody(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $priya = $this->collaborator($ws, $project, 'priya4@example.com');

        // §27: only a structured mention counts. Plain text is plain text.
        $item = $this->makeItem($ws, $owner, $project, [
            'description' => '<p>@'.$priya->displayName().' please review.</p>',
        ]);

        $this->assertSame(0, Mention::forSource(Mention::SOURCE_WORK_ITEM, $item->id)->count());
        Mail::assertNotSent(MentionedMail::class);
    }

    public function test_a_comment_mention_email_carries_both_what_was_said_and_the_description(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $priya = $this->collaborator($ws, $project, 'context@example.com');
        $item = $this->makeItem($ws, $owner, $project, [
            'title' => 'Configure Stale Ticket Automation',
            'description' => '<p>Close tickets with no customer reply after 7 days.</p>',
        ]);

        $this->actingAs($owner)->postJson(
            route('projects.work-items.comments.store', ['project' => $project->id, 'workItem' => $item->id]),
            ['content' => '<p>'.$this->chip($priya).' can you review the automation rules?</p>'],
        )->assertOk();

        Mail::assertSent(MentionedMail::class, function ($mail) use ($priya) {
            return $mail->hasTo($priya->email)
                // What was said where they were named…
                && str_contains($mail->excerpt, 'review the automation rules')
                // …and what the work item is about, so the mention can be judged without
                // opening the app.
                && str_contains((string) $mail->description, 'no customer reply after 7 days');
        });
    }

    public function test_a_description_mention_email_does_not_print_the_description_twice(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $priya = $this->collaborator($ws, $project, 'twice@example.com');

        $this->makeItem($ws, $owner, $project, [
            'description' => '<p>'.$this->chip($priya).' please validate the automation rules.</p>',
        ]);

        // The excerpt already IS the description here; repeating it under a second heading
        // would read as a bug rather than as context.
        Mail::assertSent(MentionedMail::class, function ($mail) {
            return str_contains($mail->excerpt, 'validate the automation rules')
                && $mail->description === null;
        });
    }

    // ---- Security (§23, §24) -------------------------------------------------------------

    public function test_a_crafted_id_for_someone_outside_the_project_is_refused(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['visibility' => 'private']);
        $outsider = $this->member($ws, 'member', 'outsider3@example.com');

        // §24: the browser said so, which is not a reason.
        $item = $this->makeItem($ws, $owner, $project, [
            'description' => '<p>'.$this->chip($outsider).' look at this.</p>',
        ]);

        $this->assertSame(0, Mention::forSource(Mention::SOURCE_WORK_ITEM, $item->id)->count());
        Mail::assertNotSent(MentionedMail::class);
    }

    public function test_a_user_id_that_does_not_exist_is_refused(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        $item = $this->makeItem($ws, $owner, $project, [
            'description' => '<p><span class="pb-mention" data-user-id="999999">@Administrator</span></p>',
        ]);

        $this->assertSame(0, Mention::forSource(Mention::SOURCE_WORK_ITEM, $item->id)->count());
        Mail::assertNotSent(MentionedMail::class);
    }

    // ---- Editing (§11, §12) --------------------------------------------------------------

    public function test_editing_a_description_notifies_only_the_newly_named(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $robert = $this->collaborator($ws, $project, 'robert@example.com');
        $rohit = $this->collaborator($ws, $project, 'rohit@example.com');

        $item = $this->makeItem($ws, $owner, $project, [
            'description' => '<p>'.$this->chip($robert).' please review.</p>',
        ]);

        Mail::fake();

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['description' => '<p>'.$this->chip($robert).' and '.$this->chip($rohit).' please review.</p>'],
        )->assertOk();

        // §11: Robert has already been told. Rohit has not.
        Mail::assertSent(MentionedMail::class, 1);
        Mail::assertSent(MentionedMail::class, fn ($mail) => $mail->hasTo($rohit->email));
        Mail::assertNotSent(MentionedMail::class, fn ($mail) => $mail->hasTo($robert->email));

        $this->assertEqualsCanonicalizing(
            [$robert->id, $rohit->id],
            Mention::forSource(Mention::SOURCE_WORK_ITEM, $item->id)->pluck('user_id')->all(),
        );
    }

    public function test_removing_a_mention_removes_its_record(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $robert = $this->collaborator($ws, $project, 'robert2@example.com');

        $item = $this->makeItem($ws, $owner, $project, [
            'description' => '<p>'.$this->chip($robert).' please review.</p>',
        ]);
        $this->assertSame(1, Mention::forSource(Mention::SOURCE_WORK_ITEM, $item->id)->count());

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item->id]),
            ['description' => '<p>Never mind.</p>'],
        )->assertOk();

        // The records must not outlive what the content says.
        $this->assertSame(0, Mention::forSource(Mention::SOURCE_WORK_ITEM, $item->id)->count());
    }

    // ---- Comments (§9, §12, §20) ---------------------------------------------------------

    public function test_a_comment_mention_is_recorded_and_links_back_to_the_comment(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $priya = $this->collaborator($ws, $project, 'priya5@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        $this->actingAs($owner)->postJson(
            route('projects.work-items.comments.store', ['project' => $project->id, 'workItem' => $item->id]),
            ['content' => '<p>'.$this->chip($priya).' can you review this workflow?</p>'],
        )->assertOk();

        $comment = WorkItemComment::where('work_item_id', $item->id)->firstOrFail();
        $mention = Mention::forSource(Mention::SOURCE_COMMENT, $comment->id)->firstOrFail();
        $this->assertSame($priya->id, $mention->user_id);
        // §20: the work item is denormalised onto the row so the Inbox can navigate without
        // joining through every possible source table.
        $this->assertSame($item->id, $mention->work_item_id);

        // …and the mail points at the comment, not just the item.
        Mail::assertSent(MentionedMail::class, fn ($mail) => $mail->hasTo($priya->email)
            && str_contains($mail->url, '#comment-'.$comment->id));
    }

    public function test_editing_a_comment_notifies_only_the_newly_named(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $robert = $this->collaborator($ws, $project, 'robert3@example.com');
        $laura = $this->collaborator($ws, $project, 'laura@example.com');
        $item = $this->makeItem($ws, $owner, $project);

        $this->actingAs($owner)->postJson(
            route('projects.work-items.comments.store', ['project' => $project->id, 'workItem' => $item->id]),
            ['content' => '<p>'.$this->chip($robert).' can you test this?</p>'],
        )->assertOk();
        $comment = WorkItemComment::where('work_item_id', $item->id)->firstOrFail();

        Mail::fake();

        $this->actingAs($owner)->patchJson(
            route('projects.work-items.comments.update', [
                'project' => $project->id, 'workItem' => $item->id, 'comment' => $comment->id,
            ]),
            ['content' => '<p>'.$this->chip($robert).' '.$this->chip($laura).' can you test this?</p>'],
        )->assertOk();

        // §12: Robert does not hear again; Laura does.
        Mail::assertSent(MentionedMail::class, 1);
        Mail::assertSent(MentionedMail::class, fn ($mail) => $mail->hasTo($laura->email));
    }
}
