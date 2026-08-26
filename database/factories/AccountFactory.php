<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'code' => 'AC-'.fake()->unique()->numerify('######'),
            // Ownerless by default. `owner_user_id` is UNIQUE, so a factory that invented a
            // user per account would collide the moment a test made two for the same person —
            // and most tests only need the workspace to belong to *something*.
            'owner_user_id' => null,
            'name' => fake()->company().'’s Account',
            'status' => Account::STATUS_ACTIVE,
        ];
    }

    public function ownedBy(User $user): self
    {
        return $this->state(fn () => [
            'owner_user_id' => $user->id,
            'name' => $user->displayName().'’s Account',
        ]);
    }
}
