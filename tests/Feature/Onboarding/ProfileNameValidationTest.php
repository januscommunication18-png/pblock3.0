<?php

namespace Tests\Feature\Onboarding;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The onboarding profile Name field must reject email addresses (and non-names). */
class ProfileNameValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_cannot_be_an_email_address(): void
    {
        $user = User::factory()->create(['full_name' => null]);

        $this->actingAs($user)
            ->from(route('onboarding.profile'))
            ->post(route('onboarding.profile.store'), ['full_name' => 'zorainteractive@gmail.com'])
            ->assertRedirect(route('onboarding.profile'))
            ->assertSessionHasErrors('full_name');
    }

    public function test_name_must_contain_a_letter(): void
    {
        $user = User::factory()->create(['full_name' => null]);

        $this->actingAs($user)
            ->from(route('onboarding.profile'))
            ->post(route('onboarding.profile.store'), ['full_name' => '12345'])
            ->assertSessionHasErrors('full_name');
    }

    public function test_a_real_name_is_accepted(): void
    {
        $user = User::factory()->create(['full_name' => null]);

        $this->actingAs($user)
            ->post(route('onboarding.profile.store'), ['full_name' => 'Rohit Philip'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Rohit Philip', $user->fresh()->full_name);
    }
}
