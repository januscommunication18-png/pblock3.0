<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Models\UserPasswordCredential;
use App\Notifications\PasswordChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The account modal's Change password tab (Account §4).
 */
class PasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_the_password_writes_the_credential_notifies_and_signs_out(): void
    {
        Notification::fake();

        $user = $this->withPassword('old-password-1');

        $this->actingAs($user)->patchJson(route('account.password.update'), [
            'current_password' => 'old-password-1',
            'password' => 'a-much-better-password',
            'password_confirmation' => 'a-much-better-password',
        ])->assertOk()->assertJsonPath('redirect', route('signin'));

        // The hash lives in user_password_credentials, which is what SignInController checks.
        // Writing users.password instead would look like it worked and change nothing.
        $credential = $user->passwordCredential()->first();
        $this->assertTrue(Hash::check('a-much-better-password', $credential->password_hash));
        $this->assertNotNull($credential->password_set_at);

        // §4.1: the mail exists to reach the person who did NOT do this.
        Notification::assertSentTo($user, PasswordChanged::class);

        // §4.2: this session included — a password change that left the current browser signed
        // in would leave a stolen session alive exactly when someone is trying to close one.
        $this->assertGuest();
    }

    public function test_the_current_password_must_be_right(): void
    {
        Notification::fake();

        $user = $this->withPassword('old-password-1');

        $this->actingAs($user)->patchJson(route('account.password.update'), [
            'current_password' => 'not-the-password',
            'password' => 'a-much-better-password',
            'password_confirmation' => 'a-much-better-password',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(
            Hash::check('old-password-1', $user->passwordCredential()->first()->password_hash),
            'a refused change must not touch the stored password',
        );

        Notification::assertNothingSent();
        // Still signed in: a failed attempt is not a reason to throw the session away.
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_two_new_passwords_must_match(): void
    {
        $user = $this->withPassword('old-password-1');

        $this->actingAs($user)->patchJson(route('account.password.update'), [
            'current_password' => 'old-password-1',
            'password' => 'a-much-better-password',
            'password_confirmation' => 'a-different-password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /**
     * Someone who signs in with a login code can set a first password.
     *
     * They have no credential row at all (D-A2), so demanding the current one would demand
     * something that has never existed — locking out exactly the people who need this form.
     */
    public function test_a_user_without_a_password_can_set_one(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $this->assertFalse($user->hasPassword());

        $this->actingAs($user)->patchJson(route('account.password.update'), [
            'password' => 'my-first-password',
            'password_confirmation' => 'my-first-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('my-first-password', $user->passwordCredential()->first()->password_hash));
        Notification::assertSentTo($user, PasswordChanged::class);
        $this->assertGuest();
    }

    /** The endpoint takes the current password, so it must not be an unlimited guessing oracle. */
    public function test_the_endpoint_is_rate_limited(): void
    {
        $middleware = app('router')->getRoutes()->getByName('account.password.update')->gatherMiddleware();

        $this->assertContains('throttle:10,1', $middleware);
    }

    private function withPassword(string $password): User
    {
        $user = User::factory()->create();

        UserPasswordCredential::create([
            'user_id' => $user->id,
            'password_hash' => Hash::make($password),
            'password_set_at' => now(),
        ]);

        return $user->fresh();
    }
}
