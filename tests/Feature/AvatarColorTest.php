<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The fallback avatar colour — the background behind someone's initial.
 *
 * Two implementations exist because one badge is drawn by Blade (the topbar) and the rest by
 * Vue, and they have to agree: the same person must be the same colour everywhere, or the
 * colour stops being a way to recognise anybody. These tests are what hold the two together.
 */
class AvatarColorTest extends TestCase
{
    use RefreshDatabase;

    private const JS = 'assets/js/settings/app.js';

    public function test_every_colour_is_legible_with_white_text(): void
    {
        foreach (config('projects.avatar_colors') as $hex) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $hex);

            // The initial sits on this in 10px bold white, so WCAG AA for small text applies.
            // Checked rather than assumed: the obvious mid-tone picks (orange-600, emerald-600,
            // teal-600) all land near 3.6:1 and had to be stepped darker.
            $this->assertGreaterThanOrEqual(
                4.5,
                $this->contrastWithWhite($hex),
                "{$hex} does not reach 4.5:1 against white text.",
            );
        }
    }

    public function test_the_javascript_palette_is_the_same_list_in_the_same_order(): void
    {
        $js = (string) file_get_contents(public_path(self::JS));

        preg_match('/var AVATAR_COLORS = \[(.*?)\];/s', $js, $m);
        $this->assertNotEmpty($m, 'PB.avatarColor no longer declares AVATAR_COLORS.');

        preg_match_all('/#[0-9A-Fa-f]{6}/', $m[1], $found);

        // Order matters as much as membership: both sides index the same hash into it.
        $this->assertSame(
            array_map('strtoupper', config('projects.avatar_colors')),
            array_map('strtoupper', $found[0]),
            'public/'.self::JS.' has drifted from config(projects.avatar_colors).',
        );
    }

    public function test_the_two_implementations_hash_identically(): void
    {
        $palette = array_values(config('projects.avatar_colors'));

        // A numeric id indexes the palette directly, exactly as PB.avatarColor does in the
        // browser. Computed here independently of the model, so a change to either breaks this.
        foreach (range(1, 60) as $id) {
            $user = (new User)->forceFill(['id' => $id]);
            $this->assertSame($palette[$id % count($palette)], $user->avatarColor(), "id {$id}");
        }
    }

    public function test_neighbouring_people_do_not_share_a_colour(): void
    {
        // Sequential ids are the common case — a team invited one after another — and they
        // must not walk into each other, which is the whole point of doing this.
        $colors = [];
        foreach (range(1, 12) as $id) {
            $colors[] = (new User)->forceFill(['id' => $id])->avatarColor();
        }

        $this->assertCount(12, array_unique($colors), 'Twelve consecutive users should get twelve colours.');
    }

    public function test_the_colour_follows_the_person_not_their_name(): void
    {
        $user = User::factory()->create(['full_name' => 'Priya Nair']);
        $before = $user->avatarColor();

        $user->forceFill(['full_name' => 'Priya Menon', 'email' => 'new@example.com'])->save();

        // Renaming yourself must not repaint you — the colour is an identity, not a label.
        $this->assertSame($before, $user->fresh()->avatarColor());
    }

    private function contrastWithWhite(string $hex): float
    {
        $lum = function (string $hex): float {
            $channels = array_map(
                fn (string $pair) => hexdec($pair) / 255,
                str_split(ltrim($hex, '#'), 2),
            );

            $linear = array_map(
                fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
                $channels,
            );

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };

        return (1.0 + 0.05) / ($lum($hex) + 0.05);
    }
}
