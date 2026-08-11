<?php

namespace Tests\Feature\Auth;

use App\Services\AccessGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The access gate — the "secure page" in front of Sign up / Sign in on Dev and UAT.
 *
 * The point of the feature is that a non-production copy is not reachable, so the tests that
 * matter most are the ones about what is NOT reachable, and about production never being
 * gated by accident.
 */
class AccessGateTest extends TestCase
{
    use RefreshDatabase;

    /** Switch the module on for this test, as a Dev/UAT deploy would have it. */
    private function gateOn(array $codes = ['1000', '2000', '3000', '4000', '5000']): void
    {
        config()->set('access_gate.enabled', true);
        config()->set('access_gate.environments', ['testing']);
        config()->set('access_gate.codes', $codes);
    }

    public function test_the_site_is_not_reachable_until_a_code_is_entered(): void
    {
        $this->gateOn();

        $this->get('/')->assertRedirect(route('access.show'));
        $this->get('/signin')->assertRedirect(route('access.show'));
        $this->get('/signup')->assertRedirect(route('access.show'));

        // The gate's own screen has to answer, or the redirect loops forever.
        $this->get(route('access.show'))->assertOk()->assertSee('Secure page', false);
    }

    public function test_a_valid_code_opens_the_site_and_lands_on_the_page_that_was_asked_for(): void
    {
        $this->gateOn();

        // Asked for sign in, was sent to the gate…
        $this->get('/signin')->assertRedirect(route('access.show'));

        // …so passing the gate should land there, not at the front door.
        $this->post(route('access.store'), ['code' => '3000'])
            ->assertRedirect(url('/signin'));

        $this->get('/signin')->assertOk();
        $this->get('/')->assertOk();
    }

    public function test_every_configured_code_is_accepted(): void
    {
        foreach (['1000', '2000', '3000', '4000', '5000'] as $code) {
            $this->flushSession();
            $this->gateOn();

            $this->post(route('access.store'), ['code' => $code])->assertRedirect();
            $this->get('/')->assertOk();
        }
    }

    public function test_a_wrong_or_missing_code_is_refused_and_the_site_stays_closed(): void
    {
        $this->gateOn();

        $this->post(route('access.store'), ['code' => '9999'])
            ->assertSessionHasErrors('code');
        $this->get('/')->assertRedirect(route('access.show'));

        $this->post(route('access.store'), ['code' => ''])
            ->assertSessionHasErrors('code');
        $this->get('/')->assertRedirect(route('access.show'));
    }

    public function test_withdrawing_a_code_revokes_the_passes_it_already_granted(): void
    {
        $this->gateOn();
        $this->post(route('access.store'), ['code' => '5000']);
        $this->get('/')->assertOk();

        // The code leaks and is rotated out. Whoever used it must be locked out again —
        // otherwise removing it from the list changes nothing for the person who has it.
        config()->set('access_gate.codes', ['1000', '2000']);

        $this->get('/')->assertRedirect(route('access.show'));
    }

    public function test_the_gate_never_runs_in_production(): void
    {
        // Even explicitly switched on: the allow-list is what stops a stray env var from
        // putting the live site behind a code page.
        config()->set('access_gate.enabled', true);
        config()->set('access_gate.environments', ['local', 'uat']);
        app()->detectEnvironment(fn () => 'production');

        $this->assertFalse(app(AccessGate::class)->active());
        $this->get('/')->assertOk();
    }

    public function test_the_gate_is_off_when_the_module_is_switched_off(): void
    {
        config()->set('access_gate.enabled', false);
        config()->set('access_gate.environments', ['testing']);

        $this->get('/')->assertOk();
        // …and its screen sends anyone who finds it back to sign up, rather than showing a
        // code box that does nothing.
        $this->get(route('access.show'))->assertRedirect(route('signup'));
    }

    public function test_an_unset_switch_gates_the_listed_environments_and_only_those(): void
    {
        // The default a fresh Dev/UAT deploy runs with: nothing set, still private.
        config()->set('access_gate.enabled', null);
        config()->set('access_gate.environments', ['testing']);
        $this->assertTrue(app(AccessGate::class)->active());

        config()->set('access_gate.environments', ['uat']);
        $this->assertFalse(app(AccessGate::class)->active());
    }

    public function test_a_blank_switch_counts_as_unset_rather_than_off(): void
    {
        // `ACCESS_GATE_ENABLED=` in an env file arrives as an empty STRING. Read as "off",
        // it would leave a Dev site open while the config file said it was private — the
        // failure mode this whole feature exists to prevent.
        config()->set('access_gate.environments', ['testing']);

        foreach ([null, ''] as $blank) {
            config()->set('access_gate.enabled', $blank);
            $this->assertTrue(app(AccessGate::class)->active(), 'blank switch should gate the listed environments');
        }

        // A real value still wins, in either notation.
        foreach ([false, 'false', '0'] as $off) {
            config()->set('access_gate.enabled', $off);
            $this->assertFalse(app(AccessGate::class)->active());
        }
        foreach ([true, 'true', '1'] as $on) {
            config()->set('access_gate.enabled', $on);
            $this->assertTrue(app(AccessGate::class)->active());
        }
    }

    public function test_the_health_check_answers_through_the_gate(): void
    {
        $this->gateOn();

        // A deploy probe is not a member of the public, and a gate that fails the health
        // check takes the environment down to protect it.
        $this->get('/up')->assertOk();
    }

    public function test_a_json_caller_is_told_rather_than_redirected_to_a_page(): void
    {
        $this->gateOn();

        $this->getJson('/')->assertStatus(403)
            ->assertJsonPath('message', 'This environment is protected. Enter the access code to continue.');
    }

    public function test_passing_the_gate_regenerates_the_session(): void
    {
        $this->gateOn();
        $this->get(route('access.show'));
        $before = session()->getId();

        $this->post(route('access.store'), ['code' => '1000']);

        // A session fixed before the gate cannot be used to ride in behind someone else.
        $this->assertNotSame($before, session()->getId());
    }
}
