<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            // Every workspace belongs to an account (§6 of
            // docs/features/tenant-workspace-ownership.md). Ownerless unless a test says
            // otherwise — the real creation path is WorkspaceCreator, which provisions the
            // creator's own account and is what most tests should use.
            'account_id' => Account::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 999999),
            'company_size' => fake()->randomElement(config('workspace.team_sizes')),
            'timezone' => 'UTC',
            'view_type' => Workspace::VIEW_AGILE,
            'status' => 'active',
        ];
    }
}
