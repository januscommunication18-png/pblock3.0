<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * "Which account do this user's workspaces belong to?" — asked once, answered here
 * (docs/features/tenant-workspace-ownership.md §3, §11, §21).
 *
 * The whole rule is find-or-create, and the reason it is a service rather than two lines
 * inside WorkspaceCreator is that getting it wrong is silent: a second account for somebody
 * who already has one splits their workspaces across two owners, and nothing on screen would
 * say so until a subscription or a usage total came out wrong.
 *
 *   - Mike signs up and creates his first workspace  → account created, Mike is the owner (§2)
 *   - Mike creates Marketing Workspace               → the SAME account (§3, §11)
 *   - Sarah, invited-only, creates her first         → account created then, not at signup (§21)
 */
class AccountProvisioner
{
    /** How many times a losing insert is retried before the error is somebody else's. */
    private const ATTEMPTS = 3;

    /**
     * The user's own account, created on first use.
     *
     * Both unique indexes — `owner_user_id` and `code` — are the real guarantee here, and the
     * retry is what turns each of them into an answer rather than a 500: a double-submit that
     * loses the owner race returns the row the winner wrote, and one that loses the code race
     * simply takes the next code.
     */
    public function forOwner(User $user): Account
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $existing = Account::query()->where('owner_user_id', $user->id)->first();

            if ($existing) {
                return $existing;
            }

            try {
                return Account::create([
                    'code' => $this->nextCode(),
                    'owner_user_id' => $user->id,
                    // A snapshot, so the account still has a label if the user is later
                    // deleted and `owner_user_id` goes null.
                    'name' => $this->label($user),
                    'status' => Account::STATUS_ACTIVE,
                ]);
            } catch (QueryException $e) {
                // Somebody else inserted between the read and the write. Round again: either
                // it was this user's account (returned above) or it only took our code.
                if ($attempt === self::ATTEMPTS - 1) {
                    throw $e;
                }
            }
        }

        // Unreachable: the loop either returns or rethrows on its last pass.
        throw new \RuntimeException('Could not provision an account for user '.$user->id.'.');
    }

    /** Does this user own an account yet? (§11 — the question "Create workspace" asks.) */
    public function owns(User $user): bool
    {
        return Account::query()->where('owner_user_id', $user->id)->exists();
    }

    private function label(User $user): string
    {
        // displayName() already falls back through display name → full name → the local part
        // of the email, so this never has to decide what somebody is called.
        $name = trim($user->displayName());

        return $name === '' ? 'Account' : $name.'’s Account';
    }

    /**
     * `AC-000123`.
     *
     * Derived from the highest code in use rather than the row count, so codes never repeat
     * after a delete. The unique index on `code` is what makes a race fail loudly instead of
     * duplicating; `forOwner()` retries the whole insert through its own catch.
     */
    private function nextCode(): string
    {
        $highest = (int) substr((string) Account::query()->max('code'), 3);

        return 'AC-'.str_pad((string) ($highest + 1), 6, '0', STR_PAD_LEFT);
    }
}
