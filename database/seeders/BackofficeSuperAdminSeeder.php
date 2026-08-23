<?php

namespace Database\Seeders;

use App\Models\BackofficeAuditLog;
use App\Models\BackofficeUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * The first Super Admin (docs/features/backoffice-auth.md, §7).
 *
 * The address comes from `BACKOFFICE_SUPER_ADMIN_EMAIL`, with the requirement's own value as the
 * fallback so a fresh checkout seeds something usable.
 *
 * NO PASSWORD IS SET (BO-D5). §7 forbids hard-coding one, and an env-supplied password would
 * live in plaintext in a deploy config that more people can read than should ever hold platform
 * credentials. The account is created with a null hash, which `Auth::attempt` can never match,
 * and the Super Admin sets their own through the reset flow — the only path that proves they
 * hold the mailbox.
 *
 * `BACKOFFICE_SUPER_ADMIN_PASSWORD` IS honoured if set, because a scripted local or CI
 * environment needs a way in without a mailbox. It is deliberately not documented in
 * `.env.example` as a production option, and it is logged loudly when used.
 *
 * Idempotent: re-running promotes and re-activates the configured address rather than failing on
 * the unique index or creating a second account. Running a seeder twice is normal; being locked
 * out because you did is not.
 */
class BackofficeSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = mb_strtolower(trim(
            (string) env('BACKOFFICE_SUPER_ADMIN_EMAIL', 'rohitcphilip@gmail.com'),
        ));

        if ($email === '') {
            $this->command?->warn('BACKOFFICE_SUPER_ADMIN_EMAIL is empty — no Super Admin seeded.');

            return;
        }

        $user = BackofficeUser::query()->where('email', $email)->first();

        $attributes = [
            'name' => (string) env('BACKOFFICE_SUPER_ADMIN_NAME', 'Super Admin'),
            'role' => BackofficeUser::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ];

        /*
         * The escape hatch, for environments with no mailbox.
         *
         * Applied only when the account has no password yet, so re-seeding a live environment
         * cannot silently reset a password somebody has already chosen.
         */
        $bootstrapPassword = (string) env('BACKOFFICE_SUPER_ADMIN_PASSWORD', '');

        if ($bootstrapPassword !== '' && ($user === null || trim((string) $user->password_hash) === '')) {
            $attributes['password_hash'] = Hash::make($bootstrapPassword);
            $attributes['password_set_at'] = now();

            Log::warning('backoffice.seed.bootstrap_password_used', ['email' => $email]);
            $this->command?->warn(
                'A bootstrap password was set from BACKOFFICE_SUPER_ADMIN_PASSWORD. '
                .'Change it after first sign-in and remove the variable.',
            );
        }

        if ($user === null) {
            $user = BackofficeUser::create($attributes + ['email' => $email]);

            BackofficeAuditLog::create([
                'email' => $email,
                'action' => BackofficeAuditLog::SUPER_ADMIN_CREATED,
                'succeeded' => true,
                'meta' => ['via' => 'seeder'],
            ]);

            $this->command?->info('Back Office Super Admin created: '.$email);
        } else {
            $user->forceFill($attributes)->save();
            $this->command?->info('Back Office Super Admin confirmed: '.$email);
        }

        if (trim((string) $user->password_hash) === '') {
            $this->command?->warn(
                'No password is set. Use "Forgot Password" at /backoffice to set one — '
                .'this account cannot sign in until then.',
            );
        }
    }
}
