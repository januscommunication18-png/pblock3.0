<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SessionTimeout;
use App\Services\WorkspaceCreator;
use App\Support\SessionReturnTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session idle timeout (docs/features/session-timeout.md).
 *
 * The tests that matter here are the ones about the SHAPE of the failure. A session that
 * expires correctly but answers with a bare "401 Unauthorized", or that signs somebody out and
 * then dumps them on the dashboard, has met the security requirement and failed the feature.
 */
class SessionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    /** An owner of a real workspace — the app redirects anybody less complete to onboarding. */
    private function signedIn(): User
    {
        $user = User::factory()->create(['full_name' => 'Rohit', 'email' => 'owner@example.com']);
        app(WorkspaceCreator::class)->create($user, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
        ]);

        $user = $user->fresh();
        $this->actingAs($user);

        return $user;
    }

    /** Put the session's activity stamp this many minutes into the past. */
    private function idleFor(int $minutes): void
    {
        $this->withSession([SessionTimeout::KEY => now()->subMinutes($minutes)->getTimestamp()]);
    }

    // ---- the window ------------------------------------------------------------------------

    public function test_the_default_idle_window_is_thirty_minutes(): void
    {
        $this->signedIn();

        $this->assertSame(30, app(SessionTimeout::class)->minutes());
    }

    public function test_activity_inside_the_window_keeps_the_session_alive(): void
    {
        $this->signedIn();
        $this->idleFor(29);

        $this->get('/session/status')->assertOk();
        $this->get(route('welcome'))->assertSuccessful();
    }

    public function test_a_session_idle_past_the_window_is_signed_out(): void
    {
        $this->signedIn();
        $this->idleFor(31);

        $this->get(route('welcome'))->assertRedirect(route('signin'));

        $this->assertGuest();
    }

    public function test_a_brand_new_session_is_not_treated_as_infinitely_old(): void
    {
        // No activity stamp at all — the session was created by this very request. Reading a
        // missing timestamp as "0 seconds since the epoch" would sign people out as they
        // signed in, which is the sort of thing that only shows up in production.
        $this->signedIn();

        $this->get(route('welcome'))->assertSuccessful();
        $this->assertAuthenticated();
    }

    // ---- SES-004: asking is not activity ---------------------------------------------------

    public function test_polling_the_status_endpoint_does_not_extend_the_session(): void
    {
        $this->signedIn();
        $this->idleFor(20);

        $first = $this->getJson('/session/status')->assertOk()->json('remaining');

        $this->travel(2)->minutes();

        // Still counting down from the original stamp. If polling refreshed the timer, an open
        // tab would keep itself alive forever and nothing would ever expire.
        $second = $this->getJson('/session/status')->assertOk()->json('remaining');

        $this->assertLessThan($first, $second);
    }

    public function test_extending_resets_the_timer(): void
    {
        $this->signedIn();
        $this->idleFor(29);

        $this->postJson('/session/extend')->assertOk()->assertJsonPath('remaining', 30 * 60);

        $this->travel(5)->minutes();
        $this->get(route('welcome'))->assertSuccessful();
    }

    // ---- SES-007: how the failure reads ----------------------------------------------------

    public function test_an_expired_fetch_returns_a_sentence_not_a_status_line(): void
    {
        $this->signedIn();
        $this->idleFor(31);

        $response = $this->getJson(route('inbox.list'))->assertStatus(401);

        $response->assertJsonPath('code', 'session_expired');
        $this->assertStringContainsString('session has expired', $response->json('message'));
    }

    public function test_an_expired_page_request_explains_itself_on_the_sign_in_screen(): void
    {
        $this->signedIn();
        $this->idleFor(31);

        $this->get(route('welcome'))
            ->assertRedirect(route('signin'))
            ->assertSessionHas('session_expired', true);

        $this->get(route('signin'))->assertSee('Your session has expired');
    }

    public function test_the_session_is_genuinely_invalidated_not_merely_redirected(): void
    {
        $user = $this->signedIn();
        $this->idleFor(31);

        $this->get(route('welcome'));

        $this->assertGuest();
        $this->assertNotSame($user->id, auth()->id());
    }

    // ---- SES-008: coming back to where you were --------------------------------------------

    public function test_expiring_on_a_page_remembers_it_as_the_return_target(): void
    {
        $this->signedIn();
        $this->idleFor(31);

        $this->get('/your-work?tab=assigned')
            ->assertRedirect(route('signin'))
            ->assertSessionHas(SessionReturnTarget::KEY, '/your-work?tab=assigned');
    }

    public function test_a_next_parameter_on_the_sign_in_page_is_remembered(): void
    {
        $this->get(route('signin', ['next' => '/projects/9/work-items/4']))
            ->assertOk()
            ->assertSessionHas(SessionReturnTarget::KEY, '/projects/9/work-items/4');
    }

    /**
     * An open redirect on a sign-in page is the classic phishing primitive: the URL somebody is
     * sent to immediately after typing their password is the one they trust most.
     */
    public function test_an_off_site_return_target_is_refused(): void
    {
        foreach ([
            'https://evil.example.com/harvest',
            '//evil.example.com/harvest',
            '/\\evil.example.com',
            "/legit\n/../evil",
            'javascript:alert(1)',
        ] as $hostile) {
            $this->assertNull(
                SessionReturnTarget::sanitize($hostile),
                "Accepted a hostile return target: {$hostile}",
            );
        }
    }

    public function test_auth_routes_are_refused_as_return_targets(): void
    {
        // Returning somebody to /logout after they sign in signs them straight back out.
        foreach (['/signin', '/logout', '/verify', '/session/status'] as $loop) {
            $this->assertNull(SessionReturnTarget::sanitize($loop));
        }

        $this->assertSame('/projects/1', SessionReturnTarget::sanitize('/projects/1'));
    }

    // ---- the guard is actually on the page -------------------------------------------------

    public function test_every_authenticated_page_carries_the_guard(): void
    {
        // The injection middleware exists because this application has ~16 full-page templates
        // and no single shell. If it regresses, the pages still render — they just never warn
        // anybody, which is invisible without this test.
        $this->signedIn();

        $this->get(route('welcome'))
            ->assertSee('PB_SESSION', false)
            ->assertSee('session-guard.js', false);
    }

    public function test_the_sign_in_page_does_not_carry_the_guard(): void
    {
        $this->get(route('signin'))->assertDontSee('session-guard.js', false);
    }

    /**
     * THE REGRESSION.
     *
     * Activity used to be stamped AFTER the response was built, so the page was told the time
     * remaining under the PREVIOUS stamp while the server had already reset the clock. Idle 29
     * minutes, click a link, and the new page announced 60 seconds against a session with a
     * full 30 minutes left — the tab then declared itself expired and the "Sign In Again"
     * button walked into a redirect loop.
     *
     * The original payload test could not catch this: it only ever ran against a fresh session,
     * which is the one case where the stale and fresh stamps agree.
     */
    public function test_a_page_loaded_after_a_long_idle_reports_the_full_window_not_the_old_one(): void
    {
        $this->signedIn();
        $this->idleFor(29);

        // Near the full window, not exactly it: the stamp is written and the payload rendered
        // in the same request, and under a slow suite a second can tick between the two. The
        // bug was reporting SIXTY seconds — a second of slack cannot hide that.
        $this->assertGreaterThanOrEqual(30 * 60 - 5, $this->guardPayload(route('welcome'))['remaining']);
    }

    public function test_the_browsers_deadline_matches_the_one_the_server_will_enforce(): void
    {
        $this->signedIn();
        $this->idleFor(20);

        // Whatever the page says is left, the server must still be honouring when it runs out.
        $remaining = $this->guardPayload(route('welcome'))['remaining'];

        $this->travel($remaining - 30)->seconds();
        $this->get(route('welcome'))->assertSuccessful();
    }

    // ---- "Sign In Again" ---------------------------------------------------------------------

    public function test_the_expiry_landing_signs_you_out_and_sends_you_to_sign_in(): void
    {
        $this->signedIn();

        $this->get('/session/expired?next=/your-work')
            ->assertRedirect(route('signin'))
            ->assertSessionHas(SessionReturnTarget::KEY, '/your-work');

        $this->assertGuest();
    }

    public function test_the_expiry_landing_works_when_the_session_is_already_gone(): void
    {
        // The button is clicked from a page whose session may have died minutes ago. Landing on
        // an error, or being bounced, would be the same failure it exists to prevent.
        $this->get('/session/expired?next=/your-work')->assertRedirect(route('signin'));

        $this->get(route('signin'))->assertOk()->assertSee('Your session has expired');
    }

    public function test_the_expiry_landing_still_refuses_a_hostile_return_target(): void
    {
        $this->signedIn();

        $this->get('/session/expired?next='.urlencode('https://evil.example.com/harvest'))
            ->assertRedirect(route('signin'))
            ->assertSessionMissing(SessionReturnTarget::KEY);
    }

    /**
     * The loop itself, independent of the timeout.
     *
     * `/signin` is a guest route, so a signed-in visitor is redirected away — and Laravel's
     * default target is `/`, which in this application is ALSO a guest route. Every guest URL
     * opened while signed in was an infinite redirect until AppServiceProvider named a real
     * destination.
     */
    public function test_a_signed_in_visitor_opening_a_guest_page_lands_somewhere_real(): void
    {
        $this->signedIn();

        foreach ([route('signin'), '/'] as $guestPage) {
            $target = $this->get($guestPage)->assertRedirect()->headers->get('Location');

            // The destination is decided by OnboardingRouter, so it depends on how far through
            // onboarding somebody is. What must hold for all of them is that it is not another
            // guest page — that is the loop.
            $this->assertNotSame($guestPage, $target, "{$guestPage} redirected to itself");

            // And following it has to arrive somewhere, rather than bouncing back.
            $next = $this->get($target);

            if ($next->isRedirect()) {
                $this->assertNotSame(
                    $target,
                    $next->headers->get('Location'),
                    "Following {$guestPage} looped at {$target}",
                );
            }
        }
    }

    // ---- one guard per tab ---------------------------------------------------------------------

    public function test_a_document_loaded_inside_an_iframe_does_not_get_its_own_guard(): void
    {
        // The Views panel opens work items in an iframe. A guard there would run a second
        // countdown and, worse, its "Sign In Again" would navigate only the panel — the sign-in
        // screen appearing inside a detail pane while the page around it never changes.
        $this->signedIn();

        $this->withHeader('Sec-Fetch-Dest', 'iframe')
            ->get(route('welcome'))
            ->assertSuccessful()
            ->assertDontSee('session-guard.js', false);
    }

    public function test_a_top_level_document_still_gets_the_guard(): void
    {
        $this->signedIn();

        $this->withHeader('Sec-Fetch-Dest', 'document')
            ->get(route('welcome'))
            ->assertSee('session-guard.js', false);
    }

    /** @return array<string, mixed> */
    private function guardPayload(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();

        preg_match('/window\.PB_SESSION = (\{.*?\});/s', $html, $m);

        return json_decode($m[1] ?? '{}', true);
    }

    public function test_the_guard_payload_states_the_window_and_the_warning_point(): void
    {
        // Read off a real page rather than from a synthetic request: `payload()` needs a
        // session, and the thing worth asserting is what the BROWSER is told, not what the
        // service would say in isolation.
        $this->signedIn();

        $payload = $this->guardPayload(route('welcome'));

        $this->assertSame(30, $payload['timeout_minutes']);
        $this->assertSame(300, $payload['warn_at']);
        $this->assertSame(60, $payload['ping_seconds']);
    }

    /**
     * A five-minute warning against a six-minute session is not a warning — it is the session.
     */
    public function test_the_warning_never_covers_most_of_a_short_session(): void
    {
        config(['settings.security.timeout_default' => 6, 'settings.security.timeout_options' => [6]]);

        $this->assertSame(180, app(SessionTimeout::class)->warningSeconds());
    }
}
