<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        /*
         * The Back Office Super Admin (docs/features/backoffice-auth.md, §7).
         *
         * Idempotent, so `db:seed` on an existing environment confirms the account rather than
         * failing on the unique index — see the seeder for why it sets no password.
         */
        $this->call(BackofficeSuperAdminSeeder::class);
    }
}
