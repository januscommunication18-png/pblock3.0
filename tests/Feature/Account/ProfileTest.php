<?php

namespace Tests\Feature\Account;

use App\Http\Controllers\Account\ProfileController;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The account modal's Profile tab (Account §1).
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_profile_saves_and_keeps_full_name_in_step(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com', 'full_name' => null]);

        $this->actingAs($user)->patchJson(route('account.profile.update'), [
            'first_name' => ' Ada ',
            'last_name' => 'Lovelace',
            'display_name' => 'Ada L.',
        ])->assertOk()->assertJsonPath('profile.display_name', 'Ada L.');

        $user->refresh();

        $this->assertSame('Ada', $user->first_name, 'the name should be trimmed');
        $this->assertSame('Lovelace', $user->last_name);

        // Everything already in the app reads `full_name` — displayName(), initial(), every row
        // avatar. The two-field form must not leave it stale.
        $this->assertSame('Ada Lovelace', $user->full_name);

        // …and a display name, once given, is what the person is called everywhere.
        $this->assertSame('Ada L.', $user->displayName());
        $this->assertSame('A', $user->initial());
    }

    /** Clearing the display name falls back to the real name rather than to nothing. */
    public function test_clearing_the_display_name_falls_back_to_the_full_name(): void
    {
        $user = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'display_name' => 'Ada L.']);

        $this->actingAs($user)->patchJson(route('account.profile.update'), [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'display_name' => '',
        ])->assertOk();

        $this->assertNull($user->refresh()->display_name);
        $this->assertSame('Ada Lovelace', $user->displayName());
    }

    /**
     * The email is displayed, never accepted.
     *
     * The form shows it read-only. A read-only input is a rendering decision, not a guard —
     * so the request must not accept the field either, or a hand-made POST changes the address
     * someone signs in with, with no verification of the new one.
     */
    public function test_the_email_address_cannot_be_changed_here(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->actingAs($user)->patchJson(route('account.profile.update'), [
            'first_name' => 'Ada',
            'email' => 'attacker@example.com',
        ])->assertOk();

        $this->assertSame('ada@example.com', $user->refresh()->email);
    }

    /**
     * The banner gradient comes from the palette, and only from the palette.
     *
     * This string is rendered straight into a `style` attribute to paint the banner. A
     * free-text value there is CSS injection with the user's own profile as the vector, so the
     * server checks the choice against `projects.cover_gradients` rather than trusting that the
     * client only ever sends a swatch it was given.
     */
    public function test_the_cover_gradient_must_be_one_of_the_palette(): void
    {
        $user = User::factory()->create();
        $palette = config('projects.cover_gradients');

        $this->actingAs($user)->patchJson(route('account.profile.update'), [
            'cover_gradient' => $palette[2],
        ])->assertOk();

        $this->assertSame($palette[2], $user->refresh()->cover_gradient);

        $this->actingAs($user)->patchJson(route('account.profile.update'), [
            'cover_gradient' => 'red; background-image:url(https://evil.example/x.png)',
        ])->assertStatus(422);

        $this->assertSame($palette[2], $user->refresh()->cover_gradient, 'a refused value must not be stored');

        // The banner falls back to the palette rather than to bare grey, the same way an
        // uncovered project tile does.
        $fresh = User::factory()->create();
        $this->assertSame($palette[0], ProfileController::payload($fresh)['cover_gradient']);
    }

    public function test_images_upload_replace_and_delete(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('account.profile.image.store'), [
            'kind' => 'avatar',
            'image' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        // The RAW column: `avatar_url` now answers with the serving URL, because a dozen
        // screens read it straight into an <img src>. What is on disk is still the path.
        $first = $user->refresh()->getRawOriginal('avatar_url');
        $this->assertNotNull($first);
        Storage::disk('local')->assertExists($first);

        $firstUrl = ProfileController::payload($user->refresh())['avatar_url'];

        // Replacing removes what it replaced — an account that quietly accumulates every photo
        // its owner ever tried is a storage leak nobody ever notices.
        $this->actingAs($user)->post(route('account.profile.image.store'), [
            'kind' => 'avatar',
            'image' => UploadedFile::fake()->image('me2.jpg'),
        ])->assertOk();

        $second = $user->refresh()->getRawOriginal('avatar_url');
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);

        // …and the URL handed to the browser changes with it. The route names the SLOT, not the
        // file, so without a version stamp a replacement is a new image behind an identical URL
        // and the cached copy of the old one keeps being shown.
        $this->assertNotSame(
            $firstUrl,
            ProfileController::payload($user->refresh())['avatar_url'],
            'replacing an image must change its URL, or the browser goes on showing the old one',
        );

        $this->actingAs($user)->deleteJson(route('account.profile.image.destroy'), ['kind' => 'avatar'])->assertOk();

        $this->assertNull($user->refresh()->avatar_url);
        $this->assertNull($user->refresh()->getRawOriginal('avatar_url'));
        Storage::disk('local')->assertMissing($second);
    }

    /**
     * A stored image is private, and readable by the people who can see its owner.
     *
     * The file is on the private disk and streamed behind this route (CLAUDE.md §7), so the
     * question "who may see this face?" has an answer instead of being whoever holds the URL.
     */
    public function test_a_profile_image_is_visible_to_workspace_peers_and_nobody_else(): void
    {
        Storage::fake('local');

        [$owner, $workspace] = $this->workspaceOwner();
        $this->actingAs($owner)->post(route('account.profile.image.store'), [
            'kind' => 'avatar',
            'image' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $url = route('account.image', ['user' => $owner->id, 'kind' => 'avatar']);

        // Themselves.
        $this->actingAs($owner)->get($url)->assertOk();

        // A colleague — an avatar has to work wherever that person appears.
        $peer = $this->join($workspace, 'member', 'peer@example.com');
        $this->actingAs($peer)->get($url)->assertOk();

        // A stranger gets 404, not 403: the response must not confirm the file is there.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get($url)->assertNotFound();

        // …and a guest never reaches the controller at all. Asserted on the route rather than
        // by requesting it: this app has no route named `login`, so the guest redirect throws
        // before it can answer, which would test the auth scaffolding rather than this route.
        $this->assertContains('auth', app('router')->getRoutes()->getByName('account.image')->gatherMiddleware());
    }

    /**
     * The disk is whatever `filesystems.profile_disk` says, and the write is private.
     *
     * Both halves matter. The disk was hardcoded to 'local' in four places, so setting
     * PROFILE_DISK=spaces moved nothing and uploads silently stayed on the local box. And the
     * `spaces` disk defaults writes to public-read, so a store() that does not say 'private'
     * publishes every face in the account table to an unauthenticated URL — undoing the access
     * check that test_a_profile_image_is_visible_to_workspace_peers_and_nobody_else asserts.
     */
    public function test_profile_images_honour_the_configured_disk_and_stay_private(): void
    {
        config(['filesystems.profile_disk' => 'profile_test']);
        Storage::fake('profile_test');
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('account.profile.image.store'), [
            'kind' => 'avatar',
            'image' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $path = $user->refresh()->getRawOriginal('avatar_url');

        Storage::disk('profile_test')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('private', Storage::disk('profile_test')->getVisibility($path));

        // The read path must follow the same disk, or a migrated image 404s behind a URL the
        // rest of the app is still rendering.
        $this->actingAs($user)
            ->get(route('account.image', ['user' => $user->id, 'kind' => 'avatar']))
            ->assertOk();

        // …and so must the delete, or switching disks starts leaking orphans into the bucket.
        $this->actingAs($user)
            ->deleteJson(route('account.profile.image.destroy'), ['kind' => 'avatar'])
            ->assertOk();
        Storage::disk('profile_test')->assertMissing($path);
    }

    /** @return array{0: User, 1: Workspace} */
    private function workspaceOwner(): array
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $workspace = Workspace::create(['name' => 'Acme Inc', 'slug' => 'acme-inc', 'owner_id' => $user->id]);

        WorkspaceMembership::create([
            'workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return [$user->fresh(), $workspace];
    }

    private function join(Workspace $workspace, string $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);

        return $user->fresh();
    }
}
